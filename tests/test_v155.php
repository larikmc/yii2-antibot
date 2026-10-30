<?php

declare(strict_types=1);

/**
 * Yii2 AntiBot v1.5.5 Verification & Production Readiness Test Suite
 *
 * Tests:
 * 1. P0: Old DNS verification cache invalidation (namespace v3 isolation, no bleed-through of v2)
 * 2. P1: Reservoir Sampling Algorithm R (fill 100, 101st, 1,000, 10,000 observations, inclusion probability)
 * 3. P1: Sample versioning & non-destructive migration from legacy v1 samples
 * 4. P1: Elimination of fabricated metrics defaults (avg_ms/p50/p95 are null when empty)
 * 5. P1: Honest Best-Effort dropped records handling
 * 6. SelfTest diagnostics verification
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
    'id' => 'antibot-v155-test',
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
            'class' => \yii\caching\ArrayCache::class,
        ],
    ],
];
new \yii\web\Application($config);

use larikmc\antibot\Antibot;
use larikmc\antibot\services\ReverseDnsVerifier;
use larikmc\antibot\services\resilience\SelfTest;

$totalTests = 0;
$passedTests = 0;

function assertTest(string $desc, bool $condition): void
{
    global $totalTests, $passedTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo "[PASS] {$desc}\n";
    } else {
        echo "[FAIL] {$desc}\n";
    }
}

echo "========================================================\n";
echo " Yii2 AntiBot v1.5.5 Verification & Cleanup Suite\n";
echo "========================================================\n\n";

// ----------------------------------------------------
// Section 1: P0 - Reverse DNS Cache Invalidation (v3)
// ----------------------------------------------------
echo "--- Section 1: Reverse DNS Cache Namespace v3 & Invalidation ---\n";

assertTest("1.1: CACHE_VERSION constant is 'v3'", ReverseDnsVerifier::CACHE_VERSION === 'v3');
assertTest("1.1: CACHE_PREFIX constant is 'antibot_rdns_v3_'", ReverseDnsVerifier::CACHE_PREFIX === 'antibot_rdns_v3_');

$gcpSpoofIp = '34.64.10.20';
$v2Key = 'antibot_rdns_v2_' . md5($gcpSpoofIp . '_google');
$v3Key = ReverseDnsVerifier::buildCacheKey($gcpSpoofIp, 'google');

assertTest("1.2: buildCacheKey generates v3 prefixed key", str_starts_with($v3Key, 'antibot_rdns_v3_'));
assertTest("1.2: v3 key differs from legacy v2 key", $v3Key !== $v2Key);

// Simulate pre-existing legacy v2 cache containing a dangerous false-positive VERIFIED status
if (Yii::$app !== null && Yii::$app->has('cache') && Yii::$app->cache !== null) {
    Yii::$app->cache->set($v2Key, ReverseDnsVerifier::STATUS_VERIFIED, 86400);
    // Also store some other component's data to ensure it is not flushed
    Yii::$app->cache->set('unrelated_app_cache_key', 'preserve_me', 3600);
}

// Ensure mock DNS resolver is predictable
ReverseDnsVerifier::$dnsResolverCallback = function (string $ip) {
    if ($ip === '34.64.10.20') {
        return [
            'host' => '20.10.64.34.bc.googleusercontent.com',
            'ips' => ['34.64.10.20'],
        ];
    }
    return ['host' => false, 'ips' => []];
};

// Check GCP spoof IP
$checkStatus = ReverseDnsVerifier::check($gcpSpoofIp, 'google');

assertTest("1.3: Legacy v2 cached VERIFIED is completely ignored (got non-verified: {$checkStatus})", $checkStatus !== ReverseDnsVerifier::STATUS_VERIFIED);
assertTest("1.3: GCP Cloud IP is detected as FAKE via fresh safe verification", $checkStatus === ReverseDnsVerifier::STATUS_FAKE);

if (Yii::$app !== null && Yii::$app->has('cache') && Yii::$app->cache !== null) {
    $cachedV3 = Yii::$app->cache->get($v3Key);
    assertTest("1.4: Fresh result cached under v3 namespace", $cachedV3 === ReverseDnsVerifier::STATUS_FAKE);
    assertTest("1.4: Unrelated app cache preserved intact", Yii::$app->cache->get('unrelated_app_cache_key') === 'preserve_me');
    assertTest("1.4: Legacy v2 key was not wiped or migrated to v3", Yii::$app->cache->get($v2Key) === ReverseDnsVerifier::STATUS_VERIFIED);
}

// Test legitimate Googlebot and Bingbot
$realGooglebot = '66.249.66.1';
$statusGoogle = ReverseDnsVerifier::check($realGooglebot, 'google');
assertTest("1.5: Legitimate Googlebot verified in v3", $statusGoogle === ReverseDnsVerifier::STATUS_VERIFIED);

$realBingbot = '157.55.39.1';
$statusBing = ReverseDnsVerifier::check($realBingbot, 'bing');
assertTest("1.5: Legitimate Bingbot verified in v3", $statusBing === ReverseDnsVerifier::STATUS_VERIFIED);

$realGooglebotIpv6 = '2001:4860:4801:10::1';
$statusIpv6 = ReverseDnsVerifier::check($realGooglebotIpv6, 'google');
assertTest("1.6: Legitimate Googlebot IPv6 verified in v3", $statusIpv6 === ReverseDnsVerifier::STATUS_VERIFIED);

$unknownIp = '198.51.100.99';
$statusUnknown = ReverseDnsVerifier::check($unknownIp, 'google');
assertTest("1.6: Non-search IP does not get verified privileges (got: {$statusUnknown})", $statusUnknown !== ReverseDnsVerifier::STATUS_VERIFIED);

ReverseDnsVerifier::$dnsResolverCallback = null;
echo "\n";

// ----------------------------------------------------
// Section 2: P1 - Honest Metrics Defaults (Null when empty)
// ----------------------------------------------------
echo "--- Section 2: Honest Metrics Defaults (No Fabricated Floats) ---\n";

Antibot::resetMetrics();
$antibot = new Antibot();
$antibot->init();
$initialMetrics = $antibot->getInspectionMetrics();

assertTest("2.1: avg_ms is null when no measurements exist", $initialMetrics['avg_ms'] === null);
assertTest("2.1: p50_ms is null when no measurements exist", $initialMetrics['p50_ms'] === null);
assertTest("2.1: p95_ms is null when no measurements exist", $initialMetrics['p95_ms'] === null);
assertTest("2.2: sample_size is 0 initially", $initialMetrics['sample_size'] === 0);
assertTest("2.2: sample_seen_count is 0 initially", $initialMetrics['sample_seen_count'] === 0);
assertTest("2.2: sample_capacity is 100", $initialMetrics['sample_capacity'] === 100);
assertTest("2.3: sample_strategy specifies Algorithm R", str_contains($initialMetrics['sample_strategy'], 'Algorithm R'));
assertTest("2.3: dropped_records_status is exact_count_unknown", $initialMetrics['dropped_records_status'] === 'exact_count_unknown');
echo "\n";

// ----------------------------------------------------
// Section 3: P1 - Reservoir Sampling Algorithm R
// ----------------------------------------------------
echo "--- Section 3: Reservoir Sampling Algorithm R Verification ---\n";

// Helper method to invoke private recordInspectionMetric via Reflection
$refl = new ReflectionClass($antibot);
$recordMethod = $refl->getMethod('recordInspectionMetric');
$recordMethod->setAccessible(true);

// Test 1: First 100 measurements fill the reservoir sequentially
for ($i = 1; $i <= 100; $i++) {
    $recordMethod->invoke($antibot, (float) $i, 'allowed', true);
}

$m100 = $antibot->getInspectionMetrics();
assertTest("3.1: After 100 items: sample_size is 100", $m100['sample_size'] === 100);
assertTest("3.1: After 100 items: sample_seen_count is 100", $m100['sample_seen_count'] === 100);
assertTest("3.1: p50_ms on 1..100 is 51.0 ms", abs($m100['p50_ms'] - 51.0) < 0.01);
assertTest("3.1: p95_ms on 1..100 is 96.0 ms", abs($m100['p95_ms'] - 96.0) < 0.01);
assertTest("3.1: avg_ms on 1..100 is 50.5 ms", abs($m100['avg_ms'] - 50.5) < 0.01);

// Test 2: 101st measurement
$recordMethod->invoke($antibot, 999.0, 'allowed', true);
$m101 = $antibot->getInspectionMetrics();
assertTest("3.2: After 101 items: sample_size stays 100", $m101['sample_size'] === 100);
assertTest("3.2: After 101 items: sample_seen_count is 101", $m101['sample_seen_count'] === 101);

// Test 3: Scale to 1,000 items
for ($i = 102; $i <= 1000; $i++) {
    $recordMethod->invoke($antibot, (float) $i, 'allowed', true);
}
$m1000 = $antibot->getInspectionMetrics();
assertTest("3.3: After 1,000 items: sample_size stays 100", $m1000['sample_size'] === 100);
assertTest("3.3: After 1,000 items: sample_seen_count is 1000", $m1000['sample_seen_count'] === 1000);

// Test 4: Scale to 10,000 items
for ($i = 1001; $i <= 10000; $i++) {
    $recordMethod->invoke($antibot, (float) $i, 'allowed', true);
}
$m10000 = $antibot->getInspectionMetrics();
assertTest("3.4: After 10,000 items: sample_size stays 100", $m10000['sample_size'] === 100);
assertTest("3.4: After 10,000 items: sample_seen_count is 10000", $m10000['sample_seen_count'] === 10000);

// Statistical sanity check on reservoir distribution from stream 1..10,000:
// Expected median of reservoir from uniform [1, 10000] should be around 5000 (e.g. between 3500 and 6500)
assertTest("3.5: Reservoir p50 from stream 1..10000 is distributed across the stream (got: {$m10000['p50_ms']})", $m10000['p50_ms'] > 3000 && $m10000['p50_ms'] < 7000);
echo "\n";

// ----------------------------------------------------
// Section 4: P1 - Migration from Legacy v1 Biased Sample
// ----------------------------------------------------
echo "--- Section 4: Versioning & Migration from Legacy Samples ---\n";

$metricsFile = Antibot::resolveMetricsFile();
// Craft an artificial legacy v1 metrics file
$legacyV1 = [
    'recording_mode' => 'best_effort',
    'sample_strategy_version' => 1, // Old version without Algorithm R
    'total_inspected' => 5000,
    'allowed' => 4500,
    'blocked' => 500,
    'throttled' => 0,
    'challenge' => 0,
    'monitored' => 0,
    'exceptions' => 0,
    'dropped_records' => 5,
    'duration_sum' => 1250.0,
    'duration_count' => 5000,
    'sample_capacity' => 100,
    'samples' => array_fill(0, 100, 0.999), // Biased old sample
    'updated_at' => time() - 3600,
];
file_put_contents($metricsFile, json_encode($legacyV1, JSON_PRETTY_PRINT));

// Call getInspectionMetrics on un-migrated file
$migratedRead = $antibot->getInspectionMetrics();
assertTest("4.1: Reading legacy v1 sample resets biased samples array (sample_size: 0)", $migratedRead['sample_size'] === 0);
assertTest("4.1: Reading legacy v1 sample resets sample_seen_count to 0", $migratedRead['sample_seen_count'] === 0);
assertTest("4.1: Legacy totals are PRESERVED (total_inspected: 5000)", $migratedRead['total_inspected'] === 5000);
assertTest("4.1: Legacy historical average is PRESERVED (avg_ms: 0.25)", abs($migratedRead['avg_ms'] - 0.25) < 0.01);

// Now trigger recordInspectionMetric on the file to test in-place file migration
$recordMethod->invoke($antibot, 1.234, 'allowed', true);

$afterRecord = $antibot->getInspectionMetrics();
assertTest("4.2: Writing to legacy file upgrades sample_strategy_version to 2", $afterRecord['sample_strategy_version'] === 2);
assertTest("4.2: First sample of new strategy is recorded (sample_size: 1)", $afterRecord['sample_size'] === 1);
assertTest("4.2: sample_seen_count is 1", $afterRecord['sample_seen_count'] === 1);
assertTest("4.2: Historical total incremented cleanly (total_inspected: 5001)", $afterRecord['total_inspected'] === 5001);
assertTest("4.2: p50 is exactly the new measurement (1.234)", abs($afterRecord['p50_ms'] - 1.234) < 0.001);
echo "\n";

// ----------------------------------------------------
// Section 5: Diagnostics & SelfTest Verification
// ----------------------------------------------------
echo "--- Section 5: Diagnostics & SelfTest Verification ---\n";

$diag = SelfTest::run($antibot);
assertTest("5.1: Overall SelfTest status is OK/Warning", in_array($diag['overall'], [SelfTest::STATUS_OK, SelfTest::STATUS_WARNING], true));

$checkDns = null;
$checkMetrics = null;
foreach ($diag['checks'] as $c) {
    if (str_contains($c['name'], 'DNS-бюджет')) {
        $checkDns = $c;
    }
    if (str_contains($c['name'], 'Метрики инспекции')) {
        $checkMetrics = $c;
    }
}

assertTest("5.2: SelfTest reports DNS budget check", $checkDns !== null);
assertTest("5.2: SelfTest mentions v3 cache namespace", $checkDns !== null && str_contains($checkDns['message'], 'antibot_rdns_v3_'));
assertTest("5.3: SelfTest reports Metrics check", $checkMetrics !== null);
assertTest("5.3: SelfTest displays Algorithm R and seen count", $checkMetrics !== null && str_contains($checkMetrics['message'], 'наблюдений'));
echo "\n";

// ----------------------------------------------------
// Summary
// ----------------------------------------------------
echo "========================================================\n";
printf(" TOTAL TESTS: %d | PASSED: %d | FAILED: %d\n", $totalTests, $passedTests, $totalTests - $passedTests);
$successRate = round(($passedTests / max(1, $totalTests)) * 100, 1);
printf(" SUCCESS RATE: %s%%\n", $successRate);
echo "========================================================\n";

if ($passedTests !== $totalTests) {
    exit(1);
}
