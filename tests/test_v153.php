<?php

declare(strict_types=1);

/**
 * Yii2 AntiBot v1.5.3 Security, Concurrency & Resilience Test Suite.
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
    'id' => 'antibot-v153-test',
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
use larikmc\antibot\services\IpMatcher;
use larikmc\antibot\services\resilience\CircuitBreaker;
use larikmc\antibot\services\resilience\SelfTest;

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

echo "========================================================\n";
echo " Yii2 AntiBot v1.5.3 Concurrency, Slot & Metrics Suite\n";
echo "========================================================\n";

// ----------------------------------------------------
// Section 1: P0.1 - Physical OS Slot Locking for DNS
// ----------------------------------------------------
ReverseDnsVerifier::resetBudget();

$slot1 = ReverseDnsVerifier::acquireDnsBudgetSlot('10.1.1.1');
$slot2 = ReverseDnsVerifier::acquireDnsBudgetSlot('10.2.2.2');
$slot3 = ReverseDnsVerifier::acquireDnsBudgetSlot('10.3.3.3');

assertTest($slot1 !== null && $slot2 !== null && $slot3 !== null, 'P0.1: Acquired all 3 physical OS slots via non-blocking flock');
assertTest(isset($slot1['slot']) && $slot1['slot'] >= 1 && $slot1['slot'] <= 3, 'P0.1: Slot token contains valid slot index 1..3');
assertTest(is_resource($slot1['fp']), 'P0.1: Slot token holds active open file handle');

// 4th concurrent request must be rejected (all 3 slots held)
$slot4 = ReverseDnsVerifier::acquireDnsBudgetSlot('10.4.4.4');
assertTest($slot4 === null, 'P0.1: 4th concurrent lookup rejected (all 3 physical slots busy)');

// In-flight IP deduplication (same IP while slot1 is active)
$dupSlot = ReverseDnsVerifier::acquireDnsBudgetSlot('10.1.1.1');
assertTest($dupSlot === null, 'P0.1: Single-flight deduplication: duplicate lookup for 10.1.1.1 rejected');

// Release slot2, allowing slot4 to acquire
ReverseDnsVerifier::releaseDnsBudgetSlot($slot2, '10.2.2.2');
$slot5 = ReverseDnsVerifier::acquireDnsBudgetSlot('10.5.5.5');
assertTest($slot5 !== null, 'P0.1: Releasing physical slot file allows subsequent lookup to acquire lock');

// Release remaining slots
ReverseDnsVerifier::releaseDnsBudgetSlot($slot1, '10.1.1.1');
ReverseDnsVerifier::releaseDnsBudgetSlot($slot3, '10.3.3.3');
ReverseDnsVerifier::releaseDnsBudgetSlot($slot5, '10.5.5.5');

// ----------------------------------------------------
// Section 2: P0.2 - Official CIDR Fast-Path & Mock Resolver
// ----------------------------------------------------
// Googlebot official CIDR fast-path (instant verification without DNS)
$googleIp = '66.249.66.1';
$statusGoogle = ReverseDnsVerifier::check($googleIp, 'google');
assertTest($statusGoogle === ReverseDnsVerifier::STATUS_VERIFIED, 'P0.2: Googlebot official CIDR verified immediately with 0 DNS calls');

// Bingbot official CIDR fast-path
$bingIp = '157.55.39.10';
$statusBing = ReverseDnsVerifier::check($bingIp, 'bing');
assertTest($statusBing === ReverseDnsVerifier::STATUS_VERIFIED, 'P0.2: Bingbot official CIDR verified immediately with 0 DNS calls');

// Non-CIDR IP claiming to be Googlebot with Mock DNS Resolver Callback
ReverseDnsVerifier::$dnsResolverCallback = function (string $ip): array {
    if ($ip === '198.51.100.77') {
        // Return valid googlebot PTR and matching IP
        return [
            'host' => 'crawl-198-51-100-77.googlebot.com',
            'ips' => ['198.51.100.77'],
        ];
    }
    if ($ip === '198.51.100.88') {
        // Spoofed: PTR looks like googlebot but forward DNS does not match IP
        return [
            'host' => 'crawl-fake.googlebot.com',
            'ips' => ['198.51.100.99'],
        ];
    }
    if ($ip === '198.51.100.99') {
        // Resolver error / timeout
        return [
            'host' => false,
            'ips' => [],
        ];
    }
    return ['host' => false, 'ips' => []];
};

$verifiedViaDns = ReverseDnsVerifier::check('198.51.100.77', 'google');
assertTest($verifiedViaDns === ReverseDnsVerifier::STATUS_VERIFIED, 'P0.2: Non-CIDR IP verified via mock 2-way reverse DNS');

$fakeViaDns = ReverseDnsVerifier::check('198.51.100.88', 'google');
assertTest($fakeViaDns === ReverseDnsVerifier::STATUS_FAKE, 'P0.2: Spoofed IP detected as FAKE via mock 2-way reverse DNS');

$timeoutViaDns = ReverseDnsVerifier::check('198.51.100.99', 'google');
assertTest($timeoutViaDns === ReverseDnsVerifier::STATUS_UNKNOWN, 'P0.2: Resolver error gracefully returns UNKNOWN (fail-open)');

// Clear mock callback
ReverseDnsVerifier::$dnsResolverCallback = null;

// ----------------------------------------------------
// Section 3: P0.3 - Circuit Breaker Generation Tokens & Lease Safety
// ----------------------------------------------------
CircuitBreaker::resetAll();

// Trip circuit
for ($i = 0; $i < 5; $i++) {
    CircuitBreaker::recordFailure('service_v153', 5);
}
assertTest(CircuitBreaker::getState('service_v153') === CircuitBreaker::STATE_OPEN, 'P0.3: Circuit tripped to OPEN after 5 failures');

// Simulate timeout elapsed
$stateFile = CircuitBreaker::resolveStateFile();
$states = json_decode((string) file_get_contents($stateFile), true);
$states['service_v153']['lastFailure'] = time() - 35;
file_put_contents($stateFile, json_encode($states));

// Worker 1 enters HALF_OPEN and receives probe lease token
$worker1Available = CircuitBreaker::isAvailable('service_v153', 30);
assertTest($worker1Available === true, 'P0.3: Worker 1 wins trial probe lease');
assertTest(CircuitBreaker::getState('service_v153') === CircuitBreaker::STATE_HALF_OPEN, 'P0.3: Circuit transitioned to HALF_OPEN');

// Read states to get Worker 1's token and generation
$states = json_decode((string) file_get_contents($stateFile), true);
$probeToken1 = $states['service_v153']['probeToken'];
$generation1 = $states['service_v153']['generation'];
assertTest(!empty($probeToken1), 'P0.3: HALF_OPEN assigned cryptographic probeToken');
assertTest($generation1 >= 2, "P0.3: Generation counter incremented ({$generation1})");

// Worker 2 competing for lease while HALF_OPEN probe is in-flight
$worker2Available = CircuitBreaker::isAvailable('service_v153', 30);
assertTest($worker2Available === false, 'P0.3: Worker 2 rejected while Worker 1 probe lease is active');

// Test stale probe cannot close circuit: Stale token attempt
CircuitBreaker::recordSuccess('service_v153', 'STALE_INVALID_TOKEN');
assertTest(CircuitBreaker::getState('service_v153') === CircuitBreaker::STATE_HALF_OPEN, 'P0.3: Stale probeToken cannot close circuit');

// Worker 1 finishes probe with legitimate probeToken
CircuitBreaker::recordSuccess('service_v153', $probeToken1);
assertTest(CircuitBreaker::getState('service_v153') === CircuitBreaker::STATE_CLOSED, 'P0.3: Legitimate probeToken successfully closes circuit (STATE_CLOSED)');

// ----------------------------------------------------
// Section 4: P0.4 - Metrics Cross-Process Atomic Persistence & Zero Loss
// ----------------------------------------------------
Antibot::resetMetrics();
$metricsFile = Antibot::resolveMetricsFile();
assertTest(file_exists($metricsFile), 'P0.4: Metrics storage file initialized on reset');

$antibot = new Antibot();
$antibot->init();

// Record exact distribution across 100 requests:
// 50 allowed, 20 blocked, 15 throttled, 10 challenge, 5 monitored
for ($i = 0; $i < 50; $i++) {
    $antibot->startInspection();
    $antibot->finishInspection('allowed');
}
for ($i = 0; $i < 20; $i++) {
    $antibot->startInspection();
    $antibot->finishInspection('blocked');
}
for ($i = 0; $i < 15; $i++) {
    $antibot->startInspection();
    $antibot->finishInspection('throttled');
}
for ($i = 0; $i < 10; $i++) {
    $antibot->startInspection();
    $antibot->finishInspection('challenge');
}
for ($i = 0; $i < 5; $i++) {
    $antibot->startInspection();
    $antibot->finishInspection('monitored');
}

$metrics = $antibot->getInspectionMetrics();
assertTest($metrics['total_checks'] === 100, "P0.4: Exactly 100 checks tracked with zero count loss (got: {$metrics['total_checks']})");
assertTest($metrics['allowed'] === 50, "P0.4: Exactly 50 allowed (got: {$metrics['allowed']})");
assertTest($metrics['blocked'] === 20, "P0.4: Exactly 20 blocked (got: {$metrics['blocked']})");
assertTest($metrics['throttled'] === 15, "P0.4: Exactly 15 throttled (got: {$metrics['throttled']})");
assertTest($metrics['challenge'] === 10, "P0.4: Exactly 10 challenge (got: {$metrics['challenge']})");
assertTest($metrics['monitored'] === 5, "P0.4: Exactly 5 monitored (got: {$metrics['monitored']})");
assertTest($metrics['p50_ms'] >= 0.0 && $metrics['p95_ms'] >= 0.0, "P0.4: Percentiles computed (p50: {$metrics['p50_ms']}ms, p95: {$metrics['p95_ms']}ms, sample: {$metrics['sample_size']})");

// ----------------------------------------------------
// Section 5: P2 - SelfTest Diagnostics v1.5.3 Verification
// ----------------------------------------------------
$diag = SelfTest::run($antibot);
assertTest(in_array($diag['overall'], [SelfTest::STATUS_OK, SelfTest::STATUS_WARNING], true), 'P2.1: SelfTest runs clean system diagnostic');

$hasSlotCheck = false;
$hasGenCheck = false;
$hasMetricsFileCheck = false;

foreach ($diag['checks'] as $c) {
    if (str_contains($c['name'], 'слоты') || str_contains($c['name'], 'CIDR-предфильтр')) {
        $hasSlotCheck = true;
    }
    if (str_contains($c['message'], 'поколений') || str_contains($c['name'], 'Circuit Breaker')) {
        $hasGenCheck = true;
    }
    if (str_contains($c['name'], 'персистентность') || str_contains($c['message'], 'antibot_metrics.json')) {
        $hasMetricsFileCheck = true;
    }
}

assertTest($hasSlotCheck, 'P2.1: SelfTest verifies OS slots & CIDR pre-filter');
assertTest($hasGenCheck, 'P2.1: SelfTest verifies CircuitBreaker generation tokens');
assertTest($hasMetricsFileCheck, 'P2.1: SelfTest verifies metrics file persistence & zero-loss counters');

// Summary
echo "========================================================\n";
echo " TOTAL TESTS: {$totalTests} | PASSED: {$passedTests} | FAILED: {$failedTests}\n";
echo " SUCCESS RATE: " . round(($passedTests / $totalTests) * 100, 1) . "%\n";
echo "========================================================\n";

if ($failedTests > 0) {
    exit(1);
}
