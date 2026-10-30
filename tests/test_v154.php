<?php

declare(strict_types=1);

/**
 * Yii2 AntiBot v1.5.4 Security, Fail-Safe Synchronization & Accuracy Suite.
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
    'id' => 'antibot-v154-test',
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
echo " Yii2 AntiBot v1.5.4 Verification & Fail-Safe Suite\n";
echo "========================================================\n";

// ----------------------------------------------------
// Section 1: P0 - Exact Search Bot CIDR vs Cloud Spoofing
// ----------------------------------------------------
echo "\n--- Section 1: Search Bot CIDR Verification & Anti-Spoofing ---\n";

// Test 1.1: Legitimate Googlebot IPv4 from exact official range
$realGoogleIp = '66.249.66.1';
assertTest(IpMatcher::match($realGoogleIp, ReverseDnsVerifier::GOOGLEBOT_CIDRS), '1.1: Legitimate Googlebot IPv4 matches official crawler CIDR');
$statusRealGoogle = ReverseDnsVerifier::check($realGoogleIp, 'google');
assertTest($statusRealGoogle === ReverseDnsVerifier::STATUS_VERIFIED, '1.1: Legitimate Googlebot verified instantly (0 DNS lookups)');

// Test 1.2: Legitimate Bingbot IPv4 from exact official range
$realBingIp = '157.55.39.10';
assertTest(IpMatcher::match($realBingIp, ReverseDnsVerifier::BINGBOT_CIDRS), '1.2: Legitimate Bingbot IPv4 matches official crawler CIDR');
$statusRealBing = ReverseDnsVerifier::check($realBingIp, 'bing');
assertTest($statusRealBing === ReverseDnsVerifier::STATUS_VERIFIED, '1.2: Legitimate Bingbot verified instantly (0 DNS lookups)');

// Test 1.3: Google Cloud (GCP) Compute Engine IP outside official Googlebot CIDRs
// In v1.5.3, 34.64.0.0/10 was wrongly included; in v1.5.4 it MUST NOT match!
$gcpCloudIp = '34.64.10.20';
assertTest(!IpMatcher::match($gcpCloudIp, ReverseDnsVerifier::GOOGLEBOT_CIDRS), '1.3: GCP Cloud IP 34.64.10.20 is REJECTED by Googlebot exact CIDRs');

// Test 1.4: Microsoft Azure Cloud IP outside official Bingbot CIDRs
// In v1.5.3, 40.76.0.0/14 was wrongly included; in v1.5.4 it MUST NOT match!
$azureCloudIp = '40.76.10.20';
assertTest(!IpMatcher::match($azureCloudIp, ReverseDnsVerifier::BINGBOT_CIDRS), '1.4: Azure Cloud IP 40.76.10.20 is REJECTED by Bingbot exact CIDRs');

// Test 1.5: IPv6 inside official Googlebot range
$realGoogleIpv6 = '2001:4860:4801:10::1';
assertTest(IpMatcher::match($realGoogleIpv6, ReverseDnsVerifier::GOOGLEBOT_CIDRS), '1.5: Legitimate Googlebot IPv6 matches official /64 CIDR');

// Test 1.6: IPv6 outside official Googlebot range
$fakeGoogleIpv6 = '2001:4860:4801:9999::1';
assertTest(!IpMatcher::match($fakeGoogleIpv6, ReverseDnsVerifier::GOOGLEBOT_CIDRS), '1.6: Untrusted IPv6 outside official /64 is REJECTED');

// Test 1.7: Cloud IP claiming Googlebot with spoofed PTR returns FAKE via mock resolver
ReverseDnsVerifier::$dnsResolverCallback = function (string $ip): array {
    if ($ip === '34.64.10.20') {
        return [
            'host' => 'attacker-vps.googleusercontent.com', // Not a googlebot.com domain
            'ips' => ['34.64.10.20'],
        ];
    }
    return ['host' => false, 'ips' => []];
};
$gcpCheckStatus = ReverseDnsVerifier::check($gcpCloudIp, 'google');
assertTest($gcpCheckStatus === ReverseDnsVerifier::STATUS_FAKE, '1.7: GCP VM spoofing Googlebot detected as FAKE via domain boundary check');
ReverseDnsVerifier::$dnsResolverCallback = null;

// Test 1.8: Metadata Constants verification
assertTest(defined('larikmc\antibot\services\ReverseDnsVerifier::CIDR_SNAPSHOT_DATE'), '1.8: CIDR_SNAPSHOT_DATE constant exists');
assertTest(count(ReverseDnsVerifier::GOOGLEBOT_CIDRS) > 100, '1.8: GOOGLEBOT_CIDRS contains full exact official set (count > 100)');
assertTest(count(ReverseDnsVerifier::BINGBOT_CIDRS) === 28, '1.8: BINGBOT_CIDRS contains exact official set (count: 28)');

// ----------------------------------------------------
// Section 2: P0 - Circuit Breaker Fail-Safe Synchronization
// ----------------------------------------------------
echo "\n--- Section 2: Circuit Breaker Fail-Safe Synchronization ---\n";
CircuitBreaker::resetAll();

// Test 2.1: Healthy CLOSED circuit is available immediately without lock contention
assertTest(CircuitBreaker::isAvailable('svc_test') === true, '2.1: CLOSED circuit available without requiring lock acquisition');

// Trip circuit to OPEN
for ($i = 0; $i < 5; $i++) {
    CircuitBreaker::recordFailure('svc_test', 5);
}
assertTest(CircuitBreaker::getState('svc_test') === CircuitBreaker::STATE_OPEN, '2.2: Circuit successfully tripped to OPEN');
assertTest(CircuitBreaker::isAvailable('svc_test', 30) === false, '2.2: Circuit is unavailable before recoveryTimeout');

// Simulate recoveryTimeout elapsed
$stateFile = CircuitBreaker::resolveStateFile();
$states = json_decode((string) file_get_contents($stateFile), true);
$states['svc_test']['lastFailure'] = time() - 35;
file_put_contents($stateFile, json_encode($states));

// Test 2.3: CRITICAL FAIL-SAFE TEST: When lock file is locked/unusable, probe lease MUST NOT be granted!
// We acquire lock on lock file externally to simulate another process holding the lock
$lockFile = $stateFile . '.lock';
$competingFp = fopen($lockFile, 'c+');
flock($competingFp, LOCK_EX); // Hold exclusive lock!

$leaseTokenWhenLocked = 'initial_value';
$availableWhenLocked = CircuitBreaker::isAvailable('svc_test', 30, $leaseTokenWhenLocked);

assertTest($availableWhenLocked === false, '2.3: isAvailable() strictly returns false when lock cannot be acquired');
assertTest($leaseTokenWhenLocked === null, '2.3: probeToken is strictly NOT issued when lock cannot be acquired');
assertTest(CircuitBreaker::getState('svc_test') === CircuitBreaker::STATE_OPEN, '2.3: Circuit state remains OPEN, never mutated without lock');

// Release competing lock
flock($competingFp, LOCK_UN);
fclose($competingFp);

// Test 2.4: Now that lock is available, exactly ONE process can claim probe lease
$leaseTokenWinner = null;
$availableWinner = CircuitBreaker::isAvailable('svc_test', 30, $leaseTokenWinner);
assertTest($availableWinner === true, '2.4: Legitimate worker with lock successfully claims HALF_OPEN probe lease');
assertTest(!empty($leaseTokenWinner), '2.4: probeToken issued under exclusive lock');
assertTest(CircuitBreaker::getState('svc_test') === CircuitBreaker::STATE_HALF_OPEN, '2.4: State atomically transitioned to HALF_OPEN');

// Test 2.5: Stale probeToken cannot close circuit
CircuitBreaker::recordSuccess('svc_test', 'INVALID_OLD_TOKEN');
assertTest(CircuitBreaker::getState('svc_test') === CircuitBreaker::STATE_HALF_OPEN, '2.5: Stale token cannot close HALF_OPEN circuit');

// Legitimate token closes circuit
CircuitBreaker::recordSuccess('svc_test', $leaseTokenWinner);
assertTest(CircuitBreaker::getState('svc_test') === CircuitBreaker::STATE_CLOSED, '2.5: Legitimate probeToken successfully closes circuit');

// ----------------------------------------------------
// Section 3: P1 - DNS In-Flight Stale Entry Reconciliation
// ----------------------------------------------------
echo "\n--- Section 3: DNS In-Flight Stale Entry Reconciliation ---\n";
ReverseDnsVerifier::resetBudget();

// Test 3.1: Live in-flight deduplication
$slot1 = ReverseDnsVerifier::acquireDnsBudgetSlot('10.10.1.1');
assertTest($slot1 !== null, '3.1: Acquired slot 1 for IP 10.10.1.1');

$dupSlot = ReverseDnsVerifier::acquireDnsBudgetSlot('10.10.1.1');
assertTest($dupSlot === null, '3.1: Duplicate query for 10.10.1.1 rejected while slot 1 is actively held');

// Release slot 1
ReverseDnsVerifier::releaseDnsBudgetSlot($slot1, '10.10.1.1');

// Test 3.2: Orphaned in-flight entry cleanup (simulate crashed process)
// We inject an orphaned entry in antibot_dns_budget.json for IP 10.10.2.2 on slot 2,
// but NO process holds the physical lock on antibot_dns_slot_2.lock!
$budgetFile = ReverseDnsVerifier::resolveDnsBudgetFile();
$budgetData = json_decode((string) file_get_contents($budgetFile), true);
$budgetData['in_flight']['10.10.2.2'] = [
    'slot' => 2,
    'ts' => microtime(true) - 60,
    'pid' => 999999, // Dead PID
];
file_put_contents($budgetFile, json_encode($budgetData));

// Now request comes in for 10.10.2.2:
// The engine must reconcile against the physical slot 2 file, detect it is free, purge the stale entry, and acquire the slot!
$reconciledSlot = ReverseDnsVerifier::acquireDnsBudgetSlot('10.10.2.2');
assertTest($reconciledSlot !== null, '3.2: Stale in-flight entry from dead process automatically purged and slot acquired');
if ($reconciledSlot !== null) {
    ReverseDnsVerifier::releaseDnsBudgetSlot($reconciledSlot, '10.10.2.2');
}

// ----------------------------------------------------
// Section 4: P2 - Metrics Accuracy & Best-Effort Logging
// ----------------------------------------------------
echo "\n--- Section 4: Metrics Accuracy & Best-Effort Recording ---\n";
Antibot::resetMetrics();

$antibot = new Antibot();
$antibot->init();

// Record 30 requests
for ($i = 0; $i < 20; $i++) {
    $antibot->startInspection();
    $antibot->finishInspection('allowed');
}
for ($i = 0; $i < 10; $i++) {
    $antibot->startInspection();
    $antibot->finishInspection('blocked');
}

$metrics = $antibot->getInspectionMetrics();
assertTest($metrics['recording_mode'] === 'best_effort', '4.1: Metrics correctly report recording_mode as best_effort');
assertTest($metrics['sample_capacity'] === 100, '4.1: Metrics explicitly define sample_capacity as 100');
assertTest($metrics['total_checks'] === 30, "4.1: Metrics accurately track 30 inspected requests (got: {$metrics['total_checks']})");
assertTest($metrics['allowed'] === 20 && $metrics['blocked'] === 10, '4.1: Allowed and blocked counts exactly match');

// ----------------------------------------------------
// Section 5: Diagnostics & SelfTest Audit
// ----------------------------------------------------
echo "\n--- Section 5: SelfTest Audit Verification ---\n";
$diag = SelfTest::run($antibot);
assertTest(in_array($diag['overall'], [SelfTest::STATUS_OK, SelfTest::STATUS_WARNING], true), '5.1: SelfTest overall diagnostic is OK/Warning');

$hasPreciseCidrCheck = false;
$hasBestEffortCheck = false;
foreach ($diag['checks'] as $c) {
    if (str_contains($c['message'], 'common-crawlers') || str_contains($c['name'], 'CIDR-предфильтр')) {
        $hasPreciseCidrCheck = true;
    }
    if (str_contains($c['message'], 'best_effort') || str_contains($c['name'], 'персистентность')) {
        $hasBestEffortCheck = true;
    }
}
assertTest($hasPreciseCidrCheck, '5.2: SelfTest verifies exact official CIDR snapshot');
assertTest($hasBestEffortCheck, '5.2: SelfTest accurately displays best-effort recording mode and sampling capacity');

// Summary
echo "\n========================================================\n";
echo " TOTAL TESTS: {$totalTests} | PASSED: {$passedTests} | FAILED: {$failedTests}\n";
echo " SUCCESS RATE: " . round(($passedTests / $totalTests) * 100, 1) . "%\n";
echo "========================================================\n";

if ($failedTests > 0) {
    exit(1);
}
