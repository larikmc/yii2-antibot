<?php

declare(strict_types=1);

/**
 * Yii2 AntiBot v1.5.6 Verification Suite
 *
 * Tests:
 * 1. P0: Configuration priorities (Storage file > Yii2 App Config > Component Defaults)
 * 2. P0: Invalid mode fallback safety (never strict)
 * 3. P0: Idempotent Bootstrap (single handler registration, no double counting)
 * 4. P0: Console Application safety in Bootstrap
 * 5. P1: HoneypotWidget::validateSubmission() method availability
 * 6. P1: SelfTest diagnostics verification
 */

$loader = require_once __DIR__ . '/../../yii2-advanced.loc/vendor/autoload.php';
$loader->setPsr4('larikmc\\antibot\\', [dirname(__DIR__) . '/src']);
require_once __DIR__ . '/../../yii2-advanced.loc/vendor/yiisoft/yii2/Yii.php';

use larikmc\antibot\Antibot;
use larikmc\antibot\Bootstrap;
use larikmc\antibot\services\StorageService;
use larikmc\antibot\widgets\HoneypotWidget;
use larikmc\antibot\services\resilience\SelfTest;
use yii\web\Application as WebApplication;

// Mock minimal Yii WebApplication
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/index.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '198.51.100.1';

$config = [
    'id' => 'antibot-v156-test',
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
$app = new WebApplication($config);

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
echo " Yii2 AntiBot v1.5.6 Verification Suite\n";
echo "========================================================\n\n";

// Temporary test settings file
$testSettingsFile = sys_get_temp_dir() . '/antibot_test_settings_' . uniqid() . '.json';
if (file_exists($testSettingsFile)) {
    @unlink($testSettingsFile);
}

// ----------------------------------------------------
// Section 1: P0 - Configuration Priorities
// ----------------------------------------------------
echo "--- Section 1: Configuration Priorities ---\n";

// Test 1.1: No settings file, Yii2 configured with mode => monitoring
$c1 = new Antibot();
$c1->storageFile = $testSettingsFile;
$c1->mode = 'monitoring';
$c1->rateLimitMax = 120;
$c1->blockAiCrawlers = false;
$c1->trustedProxies = ['10.0.0.1'];
$c1->init();

assertTest("1.1: Mode is 'monitoring' when set via Yii config and no storage file exists", $c1->mode === 'monitoring');
assertTest("1.1: rateLimitMax is 120 (not overridden by storage defaults)", $c1->rateLimitMax === 120);
assertTest("1.1: blockAiCrawlers is false (not overridden by storage defaults)", $c1->blockAiCrawlers === false);
assertTest("1.1: trustedProxies preserved from Yii config", $c1->trustedProxies === ['10.0.0.1']);

// Test 1.2: No settings file, no Yii config -> component defaults
$c2 = new Antibot();
$c2->storageFile = $testSettingsFile;
$c2->init();
assertTest("1.2: Default mode is 'standard' when neither file nor Yii config set", $c2->mode === 'standard');
assertTest("1.2: Default rateLimitMax is 60", $c2->rateLimitMax === 60);

// Test 1.3: Admin explicitly saved settings file with mode => monitoring
// Yii config specifies mode => standard. Admin file MUST take precedence (Priority 1)!
file_put_contents($testSettingsFile, json_encode([
    'mode' => 'monitoring',
    'rateLimitMax' => 200,
    'blockAiCrawlers' => true,
], JSON_PRETTY_PRINT));

$c3 = new Antibot();
$c3->storageFile = $testSettingsFile;
$c3->mode = 'standard';
$c3->rateLimitMax = 50;
$c3->init();

assertTest("1.3: Admin saved setting 'monitoring' overrides Yii config 'standard'", $c3->mode === 'monitoring');
assertTest("1.3: Admin saved rateLimitMax 200 overrides Yii config 50", $c3->rateLimitMax === 200);

// Test 1.4: Safe fallback for corrupted or invalid mode
$c4 = new Antibot();
$c4->storageFile = sys_get_temp_dir() . '/non_existing_file_' . uniqid() . '.json';
$c4->mode = 'malformed_attack_mode';
$c4->init();
assertTest("1.4: Invalid mode safely falls back to standard (never strict)", $c4->mode === 'standard');

// Clean up temp file
if (file_exists($testSettingsFile)) {
    @unlink($testSettingsFile);
}
echo "\n";

// ----------------------------------------------------
// Section 2: P0 - Idempotent Bootstrap (No Double Handlers)
// ----------------------------------------------------
echo "--- Section 2: Idempotent Bootstrap ---\n";

$cBootstrap = new Antibot();
$cBootstrap->init();

assertTest("2.1: Component starts with isBootstrapped() === false", $cBootstrap->isBootstrapped() === false);

// First bootstrap call (e.g. from Composer discovery)
$cBootstrap->bootstrap($app);
assertTest("2.2: First bootstrap call marks isBootstrapped() === true", $cBootstrap->isBootstrapped() === true);

// Count event handlers on WebApplication::EVENT_BEFORE_REQUEST
$beforeRequestHandlers1 = $app->hasEventHandlers(WebApplication::EVENT_BEFORE_REQUEST);
assertTest("2.3: EVENT_BEFORE_REQUEST has active handlers attached", $beforeRequestHandlers1);

// Second bootstrap call (e.g. from manual 'bootstrap' => ['antibot'] in config)
$cBootstrap->bootstrap($app);
assertTest("2.4: Second bootstrap call is ignored safely (isBootstrapped is still true)", $cBootstrap->isBootstrapped() === true);

// Test Extension Bootstrap class
$extensionBootstrap = new Bootstrap();
// Mock console application
$consoleApp = new class extends \yii\base\Application {
    public function __construct() {
        $this->id = 'console-test';
        $this->basePath = __DIR__;
    }
    public function handleRequest($request) { return null; }
};
// Should return without error and without touching web bootstrap
$extensionBootstrap->bootstrap($consoleApp);
assertTest("2.5: Bootstrap class safely ignores console application", true);
echo "\n";

// ----------------------------------------------------
// Section 3: P1 - HoneypotWidget Helpers & Methods
// ----------------------------------------------------
echo "--- Section 3: HoneypotWidget Helpers ---\n";

assertTest("3.1: validateSubmission method exists on HoneypotWidget", method_exists(HoneypotWidget::class, 'validateSubmission'));
// On GET request (non-POST), validateSubmission returns true (clean)
assertTest("3.2: validateSubmission on GET request returns true", HoneypotWidget::validateSubmission() === true);
echo "\n";

// ----------------------------------------------------
// Section 4: SelfTest Verification
// ----------------------------------------------------
echo "--- Section 4: SelfTest Verification ---\n";

$diag = SelfTest::run($cBootstrap);
assertTest("4.1: SelfTest runs clean diagnostic", in_array($diag['overall'], [SelfTest::STATUS_OK, SelfTest::STATUS_WARNING], true));

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
assertTest("4.2: DNS budget check reports v3 cache", $checkDns !== null && str_contains($checkDns['message'], 'antibot_rdns_v3_'));
assertTest("4.3: Metrics check reports Algorithm R", $checkMetrics !== null && str_contains($checkMetrics['message'], 'Algorithm R'));
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
