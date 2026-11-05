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
use larikmc\antibot\services\behavior\HeaderAnalyzer;
use larikmc\antibot\services\resilience\AutoJailService;
use larikmc\antibot\services\resilience\PanicModeService;
use larikmc\antibot\services\I18n;

echo "========================================================\n";
echo " Yii2 AntiBot v1.5.12 Comprehensive Verification Suite\n";
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
assertTest('0.1 Module::VERSION is 1.5.12', Module::VERSION === '1.5.12', Module::VERSION);
assertTest('0.2 Antibot::VERSION is 1.5.12', Antibot::VERSION === '1.5.12', Antibot::VERSION);

// ----------------------------------------------------
// Section 1: HeaderAnalyzer & Client Hints Anomaly Detection
// ----------------------------------------------------
echo "--- Section 1: HeaderAnalyzer & Client Hints ---\n";

// 1.1 Empty / short UA
$res1 = HeaderAnalyzer::analyze('', []);
assertTest('1.1 Empty UA detected', $res1['isAnomaly'] && str_contains($res1['reason'], 'Empty or missing'));

$res2 = HeaderAnalyzer::analyze('curl', []);
assertTest('1.2 Short UA detected', $res2['isAnomaly'] && str_contains($res2['reason'], 'short User-Agent'));

// 1.3 Platform Mismatch: UA says Windows, Sec-Ch-Ua-Platform says "Linux"
$desktopUa = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
$resPlatformMismatch = HeaderAnalyzer::analyze($desktopUa, [
    'sec-ch-ua-platform' => '"Linux"',
    'accept-language' => 'ru-RU,ru;q=0.9',
    'accept-encoding' => 'gzip, deflate, br',
]);
assertTest('1.3 Sec-Ch-Ua-Platform mismatch caught', $resPlatformMismatch['isAnomaly'] && str_contains($resPlatformMismatch['reason'], 'Platform inconsistency'));

// 1.4 Missing Accept-Language on purported desktop browser
$resMissingLang = HeaderAnalyzer::analyze($desktopUa, [
    'sec-ch-ua-platform' => '"Windows"',
    'accept-encoding' => 'gzip, deflate, br',
]);
assertTest('1.4 Missing Accept-Language on desktop caught', $resMissingLang['isAnomaly'] && str_contains($resMissingLang['reason'], 'Accept-Language'));

// 1.5 Missing Accept-Encoding on purported desktop browser
$resMissingEncoding = HeaderAnalyzer::analyze($desktopUa, [
    'sec-ch-ua-platform' => '"Windows"',
    'accept-language' => 'ru-RU,ru;q=0.9',
]);
assertTest('1.5 Missing gzip Accept-Encoding caught', $resMissingEncoding['isAnomaly'] && str_contains($resMissingEncoding['reason'], 'gzip compression'));

// 1.6 Legitimate Googlebot not flagged by HeaderAnalyzer
$resGoogle = HeaderAnalyzer::analyze('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', []);
assertTest('1.6 Authentic Googlebot exempt from client hints', !$resGoogle['isAnomaly']);

// 1.7 Clean desktop request passing all checks
$resClean = HeaderAnalyzer::analyze($desktopUa, [
    'sec-ch-ua-platform' => '"Windows"',
    'sec-ch-ua-mobile' => '?0',
    'accept-language' => 'ru-RU,ru;q=0.9',
    'accept-encoding' => 'gzip, deflate, br',
]);
assertTest('1.7 Normal desktop browser passes without anomalies', !$resClean['isAnomaly']);

// ----------------------------------------------------
// Section 2: AutoJailService Quarantine Logic
// ----------------------------------------------------
echo "\n--- Section 2: AutoJailService Quarantine ---\n";

$testIp = '203.0.113.88';
AutoJailService::releaseIp($testIp);
assertTest('2.1 Initial test IP not jailed', !AutoJailService::isJailed($testIp));

// Record 1st violation (threshold 2)
$ret1 = AutoJailService::recordViolation($testIp, 'Rate limit breach', 2, 600);
assertTest('2.2 1st violation below threshold', $ret1 === null && !AutoJailService::isJailed($testIp));

// Record 2nd violation -> detains IP
$ret2 = AutoJailService::recordViolation($testIp, 'Honey URL trap hit', 2, 600);
assertTest('2.3 2nd violation triggers Auto-Jail', $ret2 !== null && AutoJailService::isJailed($testIp));

// Verify getJailedIps
$jailedList = AutoJailService::getJailedIps();
$found = false;
foreach ($jailedList as $entry) {
    if ($entry['ip'] === $testIp) {
        $found = true;
        break;
    }
}
assertTest('2.4 Jailed IP present in active detentions registry', $found);

// Release IP
$released = AutoJailService::releaseIp($testIp);
assertTest('2.5 Release IP from Auto-Jail', $released && !AutoJailService::isJailed($testIp));

// ----------------------------------------------------
// Section 3: PanicModeService Under Attack Mode
// ----------------------------------------------------
echo "\n--- Section 3: PanicModeService Under Attack ---\n";

PanicModeService::deactivate();
assertTest('3.1 Panic Mode initially inactive', !PanicModeService::isActive());

$exp = PanicModeService::activate(300); // 5 mins
assertTest('3.2 Panic Mode activated', PanicModeService::isActive() && PanicModeService::getRemainingSeconds() > 250);

PanicModeService::deactivate();
assertTest('3.3 Panic Mode deactivated', !PanicModeService::isActive() && PanicModeService::getRemainingSeconds() === 0);

// ----------------------------------------------------
// Section 4: Antibot Route Pattern Matching
// ----------------------------------------------------
echo "\n--- Section 4: Route Pattern Matching ---\n";

assertTest('4.1 Exact match', Antibot::matchRoutePattern('site/login', 'site/login'));
assertTest('4.2 Trailing slash normalization', Antibot::matchRoutePattern('site/login/', 'site/login'));
assertTest('4.3 Prefix wildcard match site/login*', Antibot::matchRoutePattern('site/login/submit', 'site/login*'));
assertTest('4.4 Prefix wildcard match on bare root', Antibot::matchRoutePattern('site/login', 'site/login*'));
assertTest('4.5 Search wildcard match *search*', Antibot::matchRoutePattern('catalog/search/item', '*search*'));
assertTest('4.6 Non-matching route returns false', !Antibot::matchRoutePattern('about/company', 'site/login*'));

// ----------------------------------------------------
// Section 5: Bilingual Translation Coverage for New Features
// ----------------------------------------------------
echo "\n--- Section 5: Bilingual Translation Keys ---\n";

$keys = [
    'autojail_title',
    'autojail_desc',
    'panic_mode_title',
    'panic_mode_active_badge',
    'settings_header_consistency',
    'settings_autojail_enabled',
    'settings_sec_12',
    'settings_route_rules_title',
];

foreach ($keys as $k) {
    $ru = I18n::t($k, 'ru');
    $en = I18n::t($k, 'en');
    assertTest("5. Translation '{$k}'", $ru !== $k && $en !== $k, "RU: " . mb_substr($ru, 0, 20) . "... | EN: " . mb_substr($en, 0, 20) . "...");
}

echo "\n========================================================\n";
echo " Tests finished: {$passed} passed, {$failed} failed\n";
echo "========================================================\n";

exit($failed > 0 ? 1 : 0);
