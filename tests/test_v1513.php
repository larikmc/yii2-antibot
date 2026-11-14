<?php

declare(strict_types=1);

$autoloadCandidates = [
    __DIR__ . '/../../strb64.loc/vendor/autoload.php',
    __DIR__ . '/../../yii2-advanced.loc/vendor/autoload.php',
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
    __DIR__ . '/../../strb64.loc/vendor/yiisoft/yii2/Yii.php',
    __DIR__ . '/../../yii2-advanced.loc/vendor/yiisoft/yii2/Yii.php',
    dirname(__DIR__, 4) . '/vendor/yiisoft/yii2/Yii.php',
];

foreach ($yiiCandidates as $cand) {
    if (file_exists($cand)) {
        require_once $cand;
        break;
    }
}

$testRuntime = __DIR__ . '/runtime';
if (!is_dir($testRuntime)) {
    mkdir($testRuntime, 0777, true);
}
Yii::setAlias('@runtime', $testRuntime);
Yii::setAlias('@common', $testRuntime . '/common');

use larikmc\antibot\Antibot;
use larikmc\antibot\Module;
use larikmc\antibot\services\trap\RobotsTrapService;
use larikmc\antibot\services\trap\MissingAssetsTrap;
use larikmc\antibot\services\behavior\DatacenterDetector;
use larikmc\antibot\services\behavior\HeadlessDetector;
use larikmc\antibot\services\I18n;

echo "========================================================\n";
echo " Yii2 AntiBot v1.5.13 Comprehensive Verification Suite\n";
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
assertTest('0.1 Module::VERSION is 1.5.13', Module::VERSION === '1.5.13', Module::VERSION);
assertTest('0.2 Antibot::VERSION is 1.5.13', Antibot::VERSION === '1.5.13', Antibot::VERSION);

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

// 2.1 Hetzner IP
$resHetzner = DatacenterDetector::detect('95.216.4.1');
assertTest('2.1 Hetzner IP detected', $resHetzner !== null && $resHetzner['provider'] === 'hetzner' && $resHetzner['asn'] === 'AS24940');

// 2.2 DigitalOcean IP
$resDO = DatacenterDetector::detect('138.68.10.20');
assertTest('2.2 DigitalOcean IP detected', $resDO !== null && $resDO['provider'] === 'digitalocean' && $resDO['asn'] === 'AS14061');

// 2.3 OVHcloud IP
$resOVH = DatacenterDetector::detect('51.77.10.10');
assertTest('2.3 OVHcloud IP detected', $resOVH !== null && $resOVH['provider'] === 'ovh' && $resOVH['asn'] === 'AS16276');

// 2.4 Amazon AWS IP
$resAWS = DatacenterDetector::detect('3.120.40.50');
assertTest('2.4 AWS EC2 IP detected', $resAWS !== null && $resAWS['provider'] === 'aws');

// 2.5 Scaleway IP
$resScaleway = DatacenterDetector::detect('51.15.20.30');
assertTest('2.5 Scaleway IP detected', $resScaleway !== null && $resScaleway['provider'] === 'scaleway');

// 2.6 Selectel IP
$resSelectel = DatacenterDetector::detect('82.202.162.1');
assertTest('2.6 Selectel IP detected', $resSelectel !== null && $resSelectel['provider'] === 'selectel');

// 2.7 Residential / Non-Datacenter IP (e.g. Russian domestic ISP Rostelecom)
$resResidential = DatacenterDetector::detect('85.140.1.1');
assertTest('2.7 Residential IP not flagged as datacenter', $resResidential === null && !DatacenterDetector::isDatacenter('85.140.1.1'));

// 2.8 Invalid IP handled safely
assertTest('2.8 Invalid IP handled safely', DatacenterDetector::detect('999.999.999.999') === null);

// ----------------------------------------------------
// Section 3: Headless & WebGL GPU Detection
// ----------------------------------------------------
echo "\n--- Section 3: Headless & WebGL GPU Detection ---\n";

// 3.1 Google SwiftShader software rasterizer (Puppeteer on cloud VPS)
$resSwift = HeadlessDetector::analyze([
    'gpu' => 'Google SwiftShader (Direct3D11)',
    'webdriver' => 0,
    'screen_w' => 1920,
    'screen_h' => 1080,
]);
assertTest('3.1 SwiftShader software GPU detected', $resSwift['isHeadless'] && str_contains($resSwift['reason'], 'SwiftShader'));

// 3.2 Mesa OffScreen / llvmpipe virtualized driver
$resMesa = HeadlessDetector::analyze([
    'gpu' => 'llvmpipe (LLVM 12.0.0, 256 bits)',
    'webdriver' => 0,
    'screen_w' => 1280,
    'screen_h' => 720,
]);
assertTest('3.2 llvmpipe software rasterizer detected', $resMesa['isHeadless'] && str_contains($resMesa['reason'], 'llvmpipe'));

// 3.3 Explicit navigator.webdriver flag
$resWd = HeadlessDetector::analyze([
    'gpu' => 'ANGLE (Intel, Intel(R) UHD Graphics 630 Direct3D11 vs_5_0 ps_5_0, D3D11)',
    'webdriver' => 1,
    'screen_w' => 1920,
    'screen_h' => 1080,
]);
assertTest('3.3 navigator.webdriver flag detected', $resWd['isHeadless'] && str_contains($resWd['reason'], 'navigator.webdriver'));

// 3.4 Zero screen dimensions (headless virtual display)
$resZeroScreen = HeadlessDetector::analyze([
    'gpu' => '',
    'webdriver' => 0,
    'screen_w' => 0,
    'screen_h' => 0,
]);
assertTest('3.4 Zero screen dimensions detected', $resZeroScreen['isHeadless'] && str_contains($resZeroScreen['reason'], 'screen dimensions'));

// 3.5 Automation artifact (Selenium / PhantomJS)
$resArtifact = HeadlessDetector::analyze([
    'gpu' => '',
    'webdriver' => 0,
    'screen_w' => 1920,
    'screen_h' => 1080,
    'artifacts' => 'Selenium',
]);
assertTest('3.5 Automation framework artifact caught', $resArtifact['isHeadless'] && str_contains($resArtifact['reason'], 'Selenium'));

// 3.6 Clean legitimate desktop browser (NVIDIA GPU)
$resCleanNvidia = HeadlessDetector::analyze([
    'gpu' => 'ANGLE (NVIDIA, NVIDIA GeForce RTX 3080 Direct3D11 vs_5_0 ps_5_0, D3D11)',
    'webdriver' => 0,
    'screen_w' => 2560,
    'screen_h' => 1440,
    'outer_w' => 2560,
    'outer_h' => 1400,
]);
assertTest('3.6 Clean legitimate NVIDIA browser passes', !$resCleanNvidia['isHeadless'] && $resCleanNvidia['reason'] === null);

// 3.7 Clean Apple Silicon browser (M2 Pro)
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

// 4.1 First 3 consecutive requests below threshold (6)
for ($i = 1; $i <= 3; $i++) {
    $count = MissingAssetsTrap::recordHtmlRequest($testScraperIp, 60);
}
assertTest('4.1 3 consecutive requests count is 3', $count === 3 && !MissingAssetsTrap::isAnomaly($testScraperIp, 6, 60));

// 4.2 3 more requests reach threshold of 6
for ($i = 4; $i <= 6; $i++) {
    $count = MissingAssetsTrap::recordHtmlRequest($testScraperIp, 60);
}
assertTest('4.2 6 consecutive requests triggers anomaly', $count === 6 && MissingAssetsTrap::isAnomaly($testScraperIp, 6, 60));

// 4.3 Browser fetches beacon / static asset -> counter immediately resets
MissingAssetsTrap::recordAssetHit($testScraperIp);
assertTest('4.3 Asset hit resets anomaly state', !MissingAssetsTrap::isAnomaly($testScraperIp, 6, 60));

// 4.4 Subsequent request starts fresh
$freshCount = MissingAssetsTrap::recordHtmlRequest($testScraperIp, 60);
assertTest('4.4 Next request resumes with count = 1', $freshCount === 1);

// 4.5 Beacon HTML snippet and transparent PNG binary
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

// Reason formatting decoders
$reasonRobots = I18n::formatReason('Robots.txt Disallow violation (Scraper Trap)', 'ru');
assertTest('5.1 Reason: Robots.txt Trap formatted', str_contains($reasonRobots['title'], 'robots.txt') && $reasonRobots['badge'] === 'Ловушка robots.txt');

$reasonHeadless = I18n::formatReason('Headless browser emulator detected: SwiftShader', 'ru');
assertTest('5.2 Reason: Headless detector formatted', str_contains($reasonHeadless['title'], 'headless-браузер') && $reasonHeadless['badge'] === 'Headless-бот');

$reasonAssets = I18n::formatReason('Missing static assets anomaly (6 consecutive HTML requests without assets)', 'ru');
assertTest('5.3 Reason: Missing Assets formatted', str_contains($reasonAssets['title'], 'запросов HTML без загрузки статики') && $reasonAssets['badge'] === 'Скрапер без CSS');

$reasonDC = I18n::formatReason('Cloud hosting / Datacenter IP blocked: Hetzner Online (AS24940)', 'ru');
assertTest('5.4 Reason: Datacenter formatted', str_contains($reasonDC['title'], 'облачного датацентра') && $reasonDC['badge'] === 'Датацентр');

echo "\n========================================================\n";
echo " TOTAL TESTS: {$passed} | PASSED: {$passed} | FAILED: {$failed}\n";
echo " SUCCESS RATE: " . ($failed === 0 ? "100%" : round(($passed / ($passed + $failed)) * 100, 2) . "%") . "\n";
echo "========================================================\n";

exit($failed > 0 ? 1 : 0);
