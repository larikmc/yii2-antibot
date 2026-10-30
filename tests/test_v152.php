<?php

declare(strict_types=1);

/**
 * Yii2 AntiBot v1.5.2 Security, Concurrency & Resilience Test Suite.
 */

$loader = require_once __DIR__ . '/../../yii2-advanced.loc/vendor/autoload.php';
$loader->setPsr4('larikmc\\antibot\\', [dirname(__DIR__) . '/src']);
require_once __DIR__ . '/../../yii2-advanced.loc/vendor/yiisoft/yii2/Yii.php';

// Mock minimal Yii WebApplication
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/index.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '198.51.100.1';

$config = [
    'id' => 'antibot-v152-test',
    'basePath' => __DIR__,
    'components' => [
        'request' => [
            'class' => \yii\web\Request::class,
            'cookieValidationKey' => 'test-validation-key-32-chars-long!',
            'scriptFile' => __FILE__,
            'scriptUrl' => '/index.php',
        ],
        'response' => [
            'class' => \yii\web\Response::class,
        ],
        'cache' => [
            'class' => \yii\caching\FileCache::class,
            'cachePath' => sys_get_temp_dir() . '/antibot_test_cache_' . uniqid(),
        ],
    ],
];

new \yii\web\Application($config);

use larikmc\antibot\Antibot;
use larikmc\antibot\services\ReverseDnsVerifier;
use larikmc\antibot\services\resilience\CircuitBreaker;
use larikmc\antibot\services\resilience\SelfTest;
use larikmc\antibot\services\limiter\CacheLimiter;

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;

function assertTest(bool $condition, string $title): void
{
    global $totalTests, $passedTests, $failedTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo "[\033[32mPASS\033[0m] {$title}\n";
    } else {
        $failedTests++;
        echo "[\033[31mFAIL\033[0m] {$title}\n";
    }
}

echo "==================================================\n";
echo " Yii2 AntiBot v1.5.2 Concurrency & Reliability Suite\n";
echo "==================================================\n";

// ----------------------------------------------------
// Section 1: P0 - Atomic DNS Budget & Concurrency Limiter
// ----------------------------------------------------
ReverseDnsVerifier::resetBudget();

// Test 1: Concurrency slots (max simultaneous in-flight DNS lookups)
$slot1 = ReverseDnsVerifier::acquireDnsBudgetSlot('1.1.1.1');
$slot2 = ReverseDnsVerifier::acquireDnsBudgetSlot('2.2.2.2');
$slot3 = ReverseDnsVerifier::acquireDnsBudgetSlot('3.3.3.3');
assertTest($slot1 !== null && $slot2 !== null && $slot3 !== null, 'P0.1: Concurrency slots acquired up to max limit (3 slots)');

// 4th concurrent request should be rejected (concurrency limit reached)
$slot4 = ReverseDnsVerifier::acquireDnsBudgetSlot('4.4.4.4');
assertTest($slot4 === null, 'P0.1: 4th concurrent lookup rejected (concurrency limit = 3)');

// Test 2: In-flight deduplication (same IP while slot1 is active)
$dupSlot = ReverseDnsVerifier::acquireDnsBudgetSlot('1.1.1.1');
assertTest($dupSlot === null, 'P0.1: In-flight deduplication: duplicate request for active IP 1.1.1.1 rejected');

// Release slot2, allowing another IP to acquire slot
ReverseDnsVerifier::releaseDnsBudgetSlot($slot2);
$slot5 = ReverseDnsVerifier::acquireDnsBudgetSlot('5.5.5.5');
assertTest($slot5 !== null, 'P0.1: Releasing slot allows subsequent IP lookup to proceed');

// Clean up slots
ReverseDnsVerifier::releaseDnsBudgetSlot($slot1);
ReverseDnsVerifier::releaseDnsBudgetSlot($slot3);
ReverseDnsVerifier::releaseDnsBudgetSlot($slot5);

// Test 3: Rate limit window (25 per minute)
ReverseDnsVerifier::resetBudget();
$acquiredCount = 0;
for ($i = 1; $i <= 35; $i++) {
    $tok = ReverseDnsVerifier::acquireDnsBudgetSlot("10.0.0.{$i}");
    if ($tok !== null) {
        $acquiredCount++;
        ReverseDnsVerifier::releaseDnsBudgetSlot($tok); // immediately release concurrency to test rate limit
    }
}
assertTest($acquiredCount === 25, "P0.1: Atomic rate limit strictly caps at exactly 25 lookups/min (got: {$acquiredCount})");

// ----------------------------------------------------
// Section 2: P0 - Circuit Breaker Cross-Process Atomicity & HALF_OPEN Lease
// ----------------------------------------------------
CircuitBreaker::resetAll();

// Trip circuit to OPEN
for ($i = 0; $i < 5; $i++) {
    CircuitBreaker::recordFailure('test_svc', 5);
}
assertTest(CircuitBreaker::getState('test_svc') === CircuitBreaker::STATE_OPEN, 'P0.2: Circuit Breaker successfully tripped to OPEN');
assertTest(CircuitBreaker::isAvailable('test_svc', 30) === false, 'P0.2: Circuit is unavailable while in OPEN state');

// Manually modify state file to simulate recovery timeout elapsed
$stateFile = CircuitBreaker::resolveStateFile();
$states = json_decode(file_get_contents($stateFile), true);
$states['test_svc']['lastFailure'] = time() - 35; // 35s ago (timeout was 30s)
file_put_contents($stateFile, json_encode($states));

// Test 4: Concurrency in HALF_OPEN (simulate 50 simultaneous workers)
// Exactly 1 process should receive 'true' (lease granted), all other 49 must receive 'false'
$firstClaim = CircuitBreaker::isAvailable('test_svc', 30);
assertTest($firstClaim === true, 'P0.2: First worker entering HALF_OPEN wins probe lease');
assertTest(CircuitBreaker::getState('test_svc') === CircuitBreaker::STATE_HALF_OPEN, 'P0.2: State transitioned to HALF_OPEN');

$competingClaims = 0;
for ($w = 0; $w < 49; $w++) {
    if (CircuitBreaker::isAvailable('test_svc', 30)) {
        $competingClaims++;
    }
}
assertTest($competingClaims === 0, "P0.2: 49 competing workers all rejected probe lease in HALF_OPEN (claimed: {$competingClaims})");

// Test 5: Corrupted JSON resilience
file_put_contents($stateFile, '{CORRUPT_INVALID_JSON:::');
$stateOnCorrupt = CircuitBreaker::getState('test_svc');
assertTest($stateOnCorrupt === CircuitBreaker::STATE_CLOSED, 'P0.2: Circuit Breaker safely handles corrupted JSON without crashing');

// Reset and close
CircuitBreaker::resetAll();
assertTest(CircuitBreaker::getState('test_svc') === CircuitBreaker::STATE_CLOSED, 'P0.2: Circuit Breaker resets cleanly to CLOSED');

// ----------------------------------------------------
// Section 3: P1 - Reverse DNS Check Order & 3-State Semantics
// ----------------------------------------------------
// Official Googlebot CIDR match (fast-path)
$googleIp = '66.249.66.1';
assertTest(\larikmc\antibot\services\IpMatcher::match($googleIp, ReverseDnsVerifier::GOOGLEBOT_CIDRS), 'P1.1: Official Googlebot CIDR matches googlebot IP');

// Official Bingbot CIDR match
$bingIp = '157.55.39.10';
assertTest(\larikmc\antibot\services\IpMatcher::match($bingIp, ReverseDnsVerifier::BINGBOT_CIDRS), 'P1.1: Official Bingbot CIDR matches bingbot IP');

// Fake Googlebot check (PTR mismatch)
$fakeStatus = ReverseDnsVerifier::check('198.51.100.99', 'google');
assertTest($fakeStatus === ReverseDnsVerifier::STATUS_FAKE || $fakeStatus === ReverseDnsVerifier::STATUS_UNKNOWN, 'P1.1: Untrusted host claims googlebot returns FAKE or UNKNOWN');

// ----------------------------------------------------
// Section 4: P1 - Performance Metrics & Telemetry
// ----------------------------------------------------
$antibot = new Antibot();
$antibot->init();

// Clear any previous metrics in cache
Yii::$app->cache->delete('antibot_v152_metrics_summary');

// Simulate inspection completions
$antibot->startInspection();
$antibot->finishInspection('allowed');
$antibot->startInspection();
$antibot->finishInspection('blocked');
$antibot->startInspection();
$antibot->finishInspection('throttled');
$antibot->startInspection();
$antibot->finishInspection('challenge');
$antibot->flushMetrics();

$metrics = $antibot->getInspectionMetrics();
assertTest($metrics['total_checks'] >= 4, "P1.2: Metrics engine recorded all decisions (total: {$metrics['total_checks']})");
assertTest($metrics['allowed'] >= 1, 'P1.2: Metrics tracked allowed requests');
assertTest($metrics['blocked'] >= 1, 'P1.2: Metrics tracked blocked requests');
assertTest($metrics['throttled'] >= 1, 'P1.2: Metrics tracked throttled requests');
assertTest($metrics['challenge'] >= 1, 'P1.2: Metrics tracked challenge requests');
assertTest($metrics['p50_ms'] > 0 && $metrics['p95_ms'] > 0, "P1.2: Percentiles computed (p50: {$metrics['p50_ms']}ms, p95: {$metrics['p95_ms']}ms)");

// ----------------------------------------------------
// Section 5: P1 - CacheLimiter multiGet Optimization
// ----------------------------------------------------
$limiter = new CacheLimiter();
$res1 = $limiter->check('client_test_key_1', 10, 60);
$res2 = $limiter->check('client_test_key_1', 10, 60);
assertTest($res1['exceeded'] === false && $res1['current'] === 1, 'P1.3: CacheLimiter multiGet records first request correctly');
assertTest($res2['exceeded'] === false && $res2['current'] === 2, 'P1.3: CacheLimiter multiGet increments sliding bucket count');

// ----------------------------------------------------
// Section 6: P2 - SelfTest v1.5.2 Diagnostics
// ----------------------------------------------------
$diag = SelfTest::run($antibot);
assertTest($diag['overall'] === SelfTest::STATUS_OK || $diag['overall'] === SelfTest::STATUS_WARNING, 'P2.1: SelfTest overall diagnostic status is OK/Warning');

$hasDnsCheck = false;
$hasMetricsCheck = false;
foreach ($diag['checks'] as $c) {
    if (str_contains($c['name'], 'DNS-бюджет')) {
        $hasDnsCheck = true;
    }
    if (str_contains($c['name'], 'Метрики инспекции')) {
        $hasMetricsCheck = true;
    }
}
assertTest($hasDnsCheck, 'P2.1: SelfTest verifies atomic DNS budget and concurrency');
assertTest($hasMetricsCheck, 'P2.1: SelfTest verifies inspection telemetry & metrics');

// Summary
echo "==================================================\n";
echo " TOTAL TESTS: {$totalTests} | PASSED: {$passedTests} | FAILED: {$failedTests}\n";
echo " SUCCESS RATE: " . round(($passedTests / $totalTests) * 100, 1) . "%\n";
echo "==================================================\n";

if ($failedTests > 0) {
    exit(1);
}
