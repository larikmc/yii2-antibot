<?php

declare(strict_types=1);

/**
 * Verification test suite for Yii2 AntiBot v1.5.14
 */

$autoloadCandidates = [
    'D:/OpenServer6.5.0/home/yii2-fresh-install.loc/vendor/autoload.php',
    __DIR__ . '/../../strb64.loc/vendor/autoload.php',
    __DIR__ . '/../../yii2-advanced.loc/vendor/autoload.php',
    dirname(__DIR__, 3) . '/vendor/autoload.php',
    dirname(__DIR__, 4) . '/vendor/autoload.php',
];

$loader = null;
foreach ($autoloadCandidates as $cand) {
    if (file_exists($cand)) {
        $loader = require_once $cand;
        break;
    }
}

if ($loader === null) {
    echo "ERROR: Unable to find Composer autoload.php in candidates.\n";
    exit(1);
}

$loader->setPsr4('larikmc\\antibot\\', [dirname(__DIR__) . '/src']);

$yiiCandidates = [
    'D:/OpenServer6.5.0/home/yii2-fresh-install.loc/vendor/yiisoft/yii2/Yii.php',
    __DIR__ . '/../../strb64.loc/vendor/yiisoft/yii2/Yii.php',
    __DIR__ . '/../../yii2-advanced.loc/vendor/yiisoft/yii2/Yii.php',
    dirname(__DIR__, 3) . '/vendor/yiisoft/yii2/Yii.php',
    dirname(__DIR__, 4) . '/vendor/yiisoft/yii2/Yii.php',
];

foreach ($yiiCandidates as $cand) {
    if (file_exists($cand)) {
        require_once $cand;
        break;
    }
}

if (!class_exists('Yii')) {
    echo "ERROR: Unable to find Yii.php in candidates.\n";
    exit(1);
}

$testRuntime = __DIR__ . '/runtime';
if (!is_dir($testRuntime)) {
    mkdir($testRuntime, 0777, true);
}
Yii::setAlias('@runtime', $testRuntime);
Yii::setAlias('@common', $testRuntime . '/common');

new \yii\console\Application([
    'id' => 'test-antibot-v1514',
    'basePath' => __DIR__,
]);

use larikmc\antibot\Antibot;
use larikmc\antibot\Module;
use larikmc\antibot\services\trap\RobotsTrapService;
use larikmc\antibot\services\trap\MissingAssetsTrap;
use larikmc\antibot\services\behavior\DatacenterDetector;
use larikmc\antibot\services\behavior\HeadlessDetector;
use larikmc\antibot\services\I18n;
use larikmc\antibot\services\StorageService;

echo "========================================================\n";
echo " Yii2 AntiBot v1.5.14 Comprehensive Verification Suite\n";
echo "========================================================\n\n";

$passed = 0;
$failed = 0;

function assertTest(string $name, bool $condition, string $detail = ''): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "[PASS] {$name}" . ($detail !== '' ? " ({$detail})" : "") . "\n";
    } else {
        $failed++;
        echo "[FAIL] {$name}" . ($detail !== '' ? " ({$detail})" : "") . "\n";
    }
}

// ----------------------------------------------------
// Section 0: Version Alignment
// ----------------------------------------------------
echo "--- Section 0: Version Alignment ---\n";
assertTest('0.1 Module::VERSION is >= 1.5.14', version_compare(Module::VERSION, '1.5.14', '>='), Module::VERSION);
assertTest('0.2 Antibot::VERSION is >= 1.5.14', version_compare(Antibot::VERSION, '1.5.14', '>='), Antibot::VERSION);

// ----------------------------------------------------
// Section 1: Robots.txt Honeypot Disallow Trap
// ----------------------------------------------------
echo "\n--- Section 1: Robots.txt Disallow Trap ---\n";
$trapRoute = '/antibot-trap-disallow';

assertTest('1.1 Exact trap route match', RobotsTrapService::isTrapHit('/antibot-trap-disallow', $trapRoute));
assertTest('1.2 Trap route with leading and trailing slashes', RobotsTrapService::isTrapHit('antibot-trap-disallow/', $trapRoute));
assertTest('1.3 Subpath traversal inside trap route', RobotsTrapService::isTrapHit('/antibot-trap-disallow/admin/secret.php', $trapRoute));
assertTest('1.4 Normal non-trap route returns false', !RobotsTrapService::isTrapHit('/catalog/item-42', $trapRoute));
assertTest('1.5 Empty path returns false', !RobotsTrapService::isTrapHit('', $trapRoute));

$snippet = RobotsTrapService::getRobotsTxtSnippet($trapRoute);
assertTest('1.6 robots.txt snippet generated with Disallow directive', str_contains($snippet, 'Disallow: /antibot-trap-disallow/'));

// ----------------------------------------------------
// Section 2: Datacenter / ASN Offline Detection
// ----------------------------------------------------
echo "\n--- Section 2: Datacenter & ASN Filter ---\n";

$resHetzner = DatacenterDetector::detect('95.216.4.1');
assertTest('2.1 Hetzner IP detected', $resHetzner !== null && $resHetzner['provider'] === 'hetzner' && $resHetzner['asn'] === 'AS24940');

$resDO = DatacenterDetector::detect('138.68.10.20');
assertTest('2.2 DigitalOcean IP detected', $resDO !== null && $resDO['provider'] === 'digitalocean' && $resDO['asn'] === 'AS14061');

$resOVH = DatacenterDetector::detect('51.77.10.10');
assertTest('2.3 OVHcloud IP detected', $resOVH !== null && $resOVH['provider'] === 'ovh' && $resOVH['asn'] === 'AS16276');

$resAWS = DatacenterDetector::detect('3.120.40.50');
assertTest('2.4 AWS EC2 IP detected', $resAWS !== null && $resAWS['provider'] === 'aws');

$resScaleway = DatacenterDetector::detect('51.15.20.30');
assertTest('2.5 Scaleway IP detected', $resScaleway !== null && $resScaleway['provider'] === 'scaleway');

$resSelectel = DatacenterDetector::detect('82.202.162.1');
assertTest('2.6 Selectel IP detected', $resSelectel !== null && $resSelectel['provider'] === 'selectel');

$resResidential = DatacenterDetector::detect('85.140.1.1');
assertTest('2.7 Residential IP not flagged as datacenter', $resResidential === null && !DatacenterDetector::isDatacenter('85.140.1.1'));

assertTest('2.8 Invalid IP handled safely', DatacenterDetector::detect('999.999.999.999') === null);

// ----------------------------------------------------
// Section 3: Headless & WebGL GPU Detection
// ----------------------------------------------------
echo "\n--- Section 3: Headless & WebGL GPU Detection ---\n";

$resSwift = HeadlessDetector::analyze([
    'gpu' => 'Google SwiftShader (Direct3D11)',
    'webdriver' => 0,
    'screen_w' => 1920,
    'screen_h' => 1080,
]);
assertTest('3.1 SwiftShader software GPU detected', $resSwift['isHeadless'] && str_contains($resSwift['reason'], 'SwiftShader'));

$resMesa = HeadlessDetector::analyze([
    'gpu' => 'llvmpipe (LLVM 12.0.0, 256 bits)',
    'webdriver' => 0,
    'screen_w' => 1280,
    'screen_h' => 720,
]);
assertTest('3.2 llvmpipe software rasterizer detected', $resMesa['isHeadless'] && str_contains($resMesa['reason'], 'llvmpipe'));

$resWd = HeadlessDetector::analyze([
    'gpu' => 'ANGLE (Intel, Intel(R) UHD Graphics 630 Direct3D11 vs_5_0 ps_5_0, D3D11)',
    'webdriver' => 1,
    'screen_w' => 1920,
    'screen_h' => 1080,
]);
assertTest('3.3 navigator.webdriver flag detected', $resWd['isHeadless'] && str_contains($resWd['reason'], 'navigator.webdriver'));

$resZeroScreen = HeadlessDetector::analyze([
    'gpu' => '',
    'webdriver' => 0,
    'screen_w' => 0,
    'screen_h' => 0,
]);
assertTest('3.4 Zero screen dimensions detected', $resZeroScreen['isHeadless'] && str_contains($resZeroScreen['reason'], 'screen dimensions'));

$resArtifact = HeadlessDetector::analyze([
    'gpu' => '',
    'webdriver' => 0,
    'screen_w' => 1920,
    'screen_h' => 1080,
    'artifacts' => 'Selenium',
]);
assertTest('3.5 Automation framework artifact caught', $resArtifact['isHeadless'] && str_contains($resArtifact['reason'], 'Selenium'));

$resCleanNvidia = HeadlessDetector::analyze([
    'gpu' => 'ANGLE (NVIDIA, NVIDIA GeForce RTX 3080 Direct3D11 vs_5_0 ps_5_0, D3D11)',
    'webdriver' => 0,
    'screen_w' => 2560,
    'screen_h' => 1440,
    'outer_w' => 2560,
    'outer_h' => 1400,
]);
assertTest('3.6 Clean legitimate NVIDIA browser passes', !$resCleanNvidia['isHeadless'] && $resCleanNvidia['reason'] === null);

$resCleanApple = HeadlessDetector::analyze([
    'gpu' => 'Apple M2 Pro',
    'webdriver' => 0,
    'screen_w' => 1512,
    'screen_h' => 982,
]);
assertTest('3.7 Clean legitimate Apple GPU browser passes', !$resCleanApple['isHeadless']);

// ----------------------------------------------------
// Section 4: Missing Assets Trap (Zero-CSS Scraper Detector)
// ----------------------------------------------------
echo "\n--- Section 4: Missing Assets Scraper Trap ---\n";

$testScraperIp = '198.51.100.77';
MissingAssetsTrap::reset($testScraperIp);

for ($i = 1; $i <= 3; $i++) {
    $count = MissingAssetsTrap::recordHtmlRequest($testScraperIp, 60);
}
assertTest('4.1 3 consecutive requests count is 3', $count === 3 && !MissingAssetsTrap::isAnomaly($testScraperIp, 6, 60));

for ($i = 4; $i <= 6; $i++) {
    $count = MissingAssetsTrap::recordHtmlRequest($testScraperIp, 60);
}
assertTest('4.2 6 consecutive requests triggers anomaly', $count === 6 && MissingAssetsTrap::isAnomaly($testScraperIp, 6, 60));

MissingAssetsTrap::recordAssetHit($testScraperIp);
assertTest('4.3 Asset hit resets anomaly state', !MissingAssetsTrap::isAnomaly($testScraperIp, 6, 60));

$freshCount = MissingAssetsTrap::recordHtmlRequest($testScraperIp, 60);
assertTest('4.4 Next request resumes with count = 1', $freshCount === 1);

$beaconHtml = MissingAssetsTrap::renderBeaconTag('/antibot-beacon.png');
assertTest('4.5 Beacon tag renders valid img element', str_contains($beaconHtml, 'src="/antibot-beacon.png"'));

$pngBytes = MissingAssetsTrap::getTransparentPngBytes();
assertTest('4.5 Transparent PNG binary valid (>0 bytes)', strlen($pngBytes) > 10 && str_starts_with($pngBytes, "\x89PNG"));

// ----------------------------------------------------
// Section 5: Bilingual Translation Coverage
// ----------------------------------------------------
echo "\n--- Section 5: Bilingual Translation Keys ---\n";

$keys = [
    'settings_sec_13',
    'settings_robots_trap_desc',
    'settings_robots_trap_enabled',
    'settings_sec_14',
    'settings_headless_desc',
    'settings_headless_enabled',
    'settings_sec_15',
    'settings_missing_assets_desc',
    'settings_missing_assets_enabled',
    'settings_sec_16',
    'settings_datacenter_desc',
    'settings_datacenter_enabled',
    'settings_datacenter_action',
];

foreach ($keys as $k) {
    $ru = I18n::t($k, 'ru');
    $en = I18n::t($k, 'en');
    assertTest("5. Translation '{$k}'", $ru !== $k && $en !== $k, "RU: " . mb_substr($ru, 0, 20) . "... | EN: " . mb_substr($en, 0, 20) . "...");
}

$reasonRobots = I18n::formatReason('Robots.txt Disallow violation (Scraper Trap)', 'ru');
assertTest('5.1 Reason: Robots.txt Trap formatted', str_contains($reasonRobots['title'], 'robots.txt') && $reasonRobots['badge'] === 'Ловушка robots.txt');

$reasonHeadless = I18n::formatReason('Headless browser emulator detected: SwiftShader', 'ru');
assertTest('5.2 Reason: Headless detector formatted', str_contains($reasonHeadless['title'], 'headless-браузер') && $reasonHeadless['badge'] === 'Headless-бот');

$reasonAssets = I18n::formatReason('Missing static assets anomaly (6 consecutive HTML requests without assets)', 'ru');
assertTest('5.3 Reason: Missing Assets formatted', str_contains($reasonAssets['title'], 'запросов HTML без загрузки статики') && $reasonAssets['badge'] === 'Скрапер без CSS');

$reasonDC = I18n::formatReason('Cloud hosting / Datacenter IP blocked: Hetzner Online (AS24940)', 'ru');
assertTest('5.4 Reason: Datacenter formatted', str_contains($reasonDC['title'], 'облачного датацентра') && $reasonDC['badge'] === 'Датацентр');

// ----------------------------------------------------
// Section 6: Settings Persistence & CLI Tools Toggle
// ----------------------------------------------------
echo "\n--- Section 6: Settings Persistence & CLI Tools Toggle ---\n";

$testSettingsFile = sys_get_temp_dir() . '/test_antibot_v1514_' . uniqid() . '.json';
$storage = new StorageService(['storageFile' => $testSettingsFile]);

$initial = $storage->loadSettings();
assertTest('6.1 Default blockCliAndLibraries is true', ($initial['blockCliAndLibraries'] ?? null) === true);

// Save with blockCliAndLibraries = true
$storage->saveSettings(['blockCliAndLibraries' => true, 'blockCliTools' => true]);
$loadedTrue = $storage->loadSettings();
assertTest('6.2 Storage loads blockCliAndLibraries = true', $loadedTrue['blockCliAndLibraries'] === true);
assertTest('6.3 Storage loads blockCliTools = true', ($loadedTrue['blockCliTools'] ?? false) === true);

// Fresh instance reading from disk
$storageFresh = new StorageService(['storageFile' => $testSettingsFile]);
$loadedFresh = $storageFresh->loadSettings();
assertTest('6.4 Fresh instance loads true from disk', $loadedFresh['blockCliAndLibraries'] === true);

// Save with blockCliAndLibraries = false
$storage->saveSettings(['blockCliAndLibraries' => false, 'blockCliTools' => false]);
$loadedFalse = $storage->loadSettings();
assertTest('6.5 Storage loads blockCliAndLibraries = false', $loadedFalse['blockCliAndLibraries'] === false);
assertTest('6.6 Storage loads blockCliTools = false', $loadedFalse['blockCliTools'] === false);

// Fresh instance reading false from disk
$storageFresh2 = new StorageService(['storageFile' => $testSettingsFile]);
$loadedFresh2 = $storageFresh2->loadSettings();
assertTest('6.7 Fresh instance loads false from disk', $loadedFresh2['blockCliAndLibraries'] === false);

@unlink($testSettingsFile);

echo "\n========================================================\n";
echo " TOTAL TESTS: {$passed} | PASSED: {$passed} | FAILED: {$failed}\n";
echo " SUCCESS RATE: " . ($failed === 0 ? "100%" : round(($passed / ($passed + $failed)) * 100, 2) . "%") . "\n";
echo "========================================================\n";

exit($failed > 0 ? 1 : 0);
