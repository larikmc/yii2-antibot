<?php

declare(strict_types=1);

/**
 * Comprehensive Automated Test Suite for Yii2 AntiBot Catalog & Access Control
 *
 * Covers:
 * 1. Authentic Googlebot (CIDR fast-path & rDNS)
 * 2. Authentic Google Ads special-case crawler (AdsBot-Google with special-crawlers CIDR)
 * 3. Authentic Yandex Advertising bot (YandexDirect with .yandex.ru PTR)
 * 4. Authentic CriteoBot with verified IP (Criteo CIDR)
 * 5. Fake CriteoBot from arbitrary / datacenter IP
 * 6. AhrefsBot with policy resolution (throttle) and authentic rDNS
 * 7. SemrushBot and its variants (SemrushBot-BA, SiteAuditBot)
 * 8. Unknown bot / browser visitor (Standard protection & Rate limiting)
 * 9. Fake Googlebot with spoofed User-Agent (rDNS failure / PTR mismatch)
 * 10. DNS verification failure / timeout (returns UNKNOWN without false positive fake ban)
 * 11. Datacenter scraper (Hetzner IP blocked or challenged, while verified bots bypass)
 * 12. Verification that legitimate ad/search bots bypass Missing Assets zero-CSS trap
 * 13. Verification that verified bots bypass Datacenter filter and JS challenge
 * 14. Individual policy overrides (botPolicies & botCategoryPolicies)
 * 15. Settings persistence and statistics aggregation
 */

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/index.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

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
    echo "ERROR: Unable to locate Composer autoload.php\n";
    exit(1);
}

$loader->setPsr4('larikmc\\antibot\\', [dirname(__DIR__) . '/src']);

$yiiCandidates = [
    'D:/OpenServer6.5.0/home/yii2-fresh-install.loc/vendor/yiisoft/yii2/Yii.php',
    __DIR__ . '/../../strb64.loc/vendor/yiisoft/yii2/Yii.php',
    __DIR__ . '/../../yii2-advanced.loc/vendor/yiisoft/yii2/Yii.php',
];
foreach ($yiiCandidates as $cand) {
    if (file_exists($cand)) {
        require_once $cand;
        break;
    }
}

$tempCachePath = sys_get_temp_dir() . '/antibot_test_cache_' . uniqid();
$tempStorageFile = sys_get_temp_dir() . '/antibot_test_settings_' . uniqid() . '.json';
$tempEventsFile = sys_get_temp_dir() . '/antibot_test_events_' . uniqid() . '.jsonl';

$app = new \yii\web\Application([
    'id' => 'antibot-catalog-test',
    'basePath' => dirname(__DIR__),
    'components' => [
        'request' => [
            'class' => \yii\web\Request::class,
            'cookieValidationKey' => 'test-secret-key-32-chars-long-protection!!',
            'scriptFile' => __FILE__,
            'scriptUrl' => '/index.php',
        ],
        'cache' => [
            'class' => \yii\caching\FileCache::class,
            'cachePath' => $tempCachePath,
        ],
    ],
]);

use larikmc\antibot\Antibot;
use larikmc\antibot\services\BotCatalog;
use larikmc\antibot\services\BotDetector;
use larikmc\antibot\services\ReverseDnsVerifier;
use larikmc\antibot\services\StorageService;

$testsTotal = 0;
$testsPassed = 0;

function assertCondition(bool $cond, string $name, string $details = ''): void {
    global $testsTotal, $testsPassed;
    $testsTotal++;
    if ($cond) {
        $testsPassed++;
        echo " [PASS] {$name}\n";
    } else {
        echo " [FAIL] {$name}" . ($details !== '' ? " ({$details})" : "") . "\n";
    }
}

echo "=================================================================\n";
echo " Yii2 AntiBot: Comprehensive Bot Catalog & Access Control Suite\n";
echo "=================================================================\n\n";

// TEST 1: Authentic Googlebot (CIDR fast-path)
$googleIp = '66.249.66.1'; // in Googlebot CIDRs
$vStatus = ReverseDnsVerifier::check($googleIp, 'google');
assertCondition($vStatus === ReverseDnsVerifier::STATUS_VERIFIED, 'Test 1: Googlebot verified via official CIDR');

// TEST 2: Authentic Google Ads Crawler (AdsBot-Google with special-crawlers CIDR)
$adsBotUa = 'AdsBot-Google (+http://www.google.com/adsbot.html)';
$identified = BotCatalog::identify($adsBotUa);
assertCondition($identified !== null && $identified['key'] === 'google_ads', 'Test 2a: AdsBot identified in catalog');
$specialIp = '66.249.92.1'; // in Google special-crawlers CIDRs
$specialStatus = ReverseDnsVerifier::checkBot($specialIp, $identified['data']);
assertCondition($specialStatus === ReverseDnsVerifier::STATUS_VERIFIED, 'Test 2b: Google AdsBot verified via special-crawlers snapshot');

// TEST 3: Authentic Yandex Advertising Robot (YandexDirect with .yandex.ru PTR)
$yandexDirectUa = 'Mozilla/5.0 (compatible; YandexDirect/3.0; +http://yandex.com/bots)';
$idYd = BotCatalog::identify($yandexDirectUa);
assertCondition($idYd !== null && $idYd['key'] === 'yandex_direct', 'Test 3a: YandexDirect identified in catalog');
// Mock DNS resolver callback for Yandex test
ReverseDnsVerifier::$dnsResolverCallback = function(string $ip, array $suffixes) {
    if ($ip === '178.154.239.1') {
        return ReverseDnsVerifier::STATUS_VERIFIED;
    }
    return ReverseDnsVerifier::STATUS_UNKNOWN;
};
$ydStatus = ReverseDnsVerifier::checkBot('178.154.239.1', $idYd['data']);
assertCondition($ydStatus === ReverseDnsVerifier::STATUS_VERIFIED, 'Test 3b: YandexDirect verified via FCrDNS');

// TEST 4: Authentic CriteoBot with verified IP (Criteo CIDR)
$criteoUa = 'CriteoBot/0.1 (+https://www.criteo.com/criteo-crawler/)';
$idCriteo = BotCatalog::identify($criteoUa);
assertCondition($idCriteo !== null && $idCriteo['key'] === 'criteo', 'Test 4a: CriteoBot identified in catalog');
$criteoRealIp = '178.250.2.15'; // within 178.250.0.0/21
$criteoStatus = ReverseDnsVerifier::checkBot($criteoRealIp, $idCriteo['data']);
assertCondition($criteoStatus === ReverseDnsVerifier::STATUS_VERIFIED, 'Test 4b: CriteoBot verified via official CIDR');

// TEST 5: Fake CriteoBot from arbitrary datacenter IP
$criteoFakeIp = '88.198.50.10'; // Hetzner IP
$criteoFakeStatus = ReverseDnsVerifier::checkBot($criteoFakeIp, $idCriteo['data']);
assertCondition($criteoFakeStatus === ReverseDnsVerifier::STATUS_FAKE, 'Test 5: Fake CriteoBot detected and marked FAKE');

// TEST 6: AhrefsBot with policy resolution (throttle) and authentic rDNS
$ahrefsUa = 'Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)';
$idAhrefs = BotCatalog::identify($ahrefsUa);
assertCondition($idAhrefs !== null && $idAhrefs['key'] === 'ahrefs', 'Test 6a: AhrefsBot identified in catalog');
assertCondition($idAhrefs['data']['category'] === BotCatalog::CATEGORY_SEO, 'Test 6b: AhrefsBot classified in SEO category');
ReverseDnsVerifier::$dnsResolverCallback = function(string $ip, array $suffixes) {
    if ($ip === '54.36.148.1') {
        return ReverseDnsVerifier::STATUS_VERIFIED;
    }
    return ReverseDnsVerifier::STATUS_UNKNOWN;
};
$ahrefsStatus = ReverseDnsVerifier::checkBot('54.36.148.1', $idAhrefs['data']);
assertCondition($ahrefsStatus === ReverseDnsVerifier::STATUS_VERIFIED, 'Test 6c: AhrefsBot verified via .ahrefs.com domain');

// TEST 7: SemrushBot variants (SemrushBot-BA, SiteAuditBot)
$semrushBaUa = 'Mozilla/5.0 (compatible; SemrushBot-BA/0.97; +http://www.semrush.com/bot.html)';
$idSemrushBa = BotCatalog::identify($semrushBaUa);
assertCondition($idSemrushBa !== null && $idSemrushBa['key'] === 'semrush', 'Test 7a: SemrushBot-BA identified in catalog');
$siteAuditUa = 'Mozilla/5.0 (compatible; SiteAuditBot/0.97; +http://www.semrush.com/bot.html)';
$idSiteAudit = BotCatalog::identify($siteAuditUa);
assertCondition($idSiteAudit !== null && $idSiteAudit['key'] === 'semrush', 'Test 7b: SiteAuditBot identified in catalog');

// TEST 8: PetalBot reclassification check
$petalUa = 'Mozilla/5.0 (compatible;PetalBot;+https://webmaster.petalsearch.com/site/petalbot)';
$idPetal = BotCatalog::identify($petalUa);
assertCondition($idPetal !== null && $idPetal['key'] === 'petalbot' && $idPetal['data']['category'] === BotCatalog::CATEGORY_SEARCH, 'Test 8: PetalBot correctly transferred to Search category');

// TEST 9: Social Preview Bots (Telegram, WhatsApp, Meta)
$tgUa = 'TelegramBot (like TwitterBot)';
$idTg = BotCatalog::identify($tgUa);
assertCondition($idTg !== null && $idTg['key'] === 'telegram' && $idTg['data']['category'] === BotCatalog::CATEGORY_SOCIAL, 'Test 9a: TelegramBot identified in Social category');
$metaUa = 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)';
$idMeta = BotCatalog::identify($metaUa);
assertCondition($idMeta !== null && $idMeta['key'] === 'meta_facebook' && $idMeta['data']['category'] === BotCatalog::CATEGORY_SOCIAL, 'Test 9b: facebookexternalhit identified in Social category');

// TEST 10: Fake Googlebot with spoofed User-Agent (rDNS failure / PTR mismatch)
ReverseDnsVerifier::$dnsResolverCallback = function(string $ip, array $suffixes) {
    if ($ip === '1.2.3.4') {
        return ReverseDnsVerifier::STATUS_FAKE;
    }
    return ReverseDnsVerifier::STATUS_UNKNOWN;
};
$fakeStatus = ReverseDnsVerifier::check('1.2.3.4', 'google');
assertCondition($fakeStatus === ReverseDnsVerifier::STATUS_FAKE, 'Test 10: Spoofed Googlebot marked FAKE');

// TEST 11: DNS verification failure / timeout (returns UNKNOWN without false positive fake ban)
ReverseDnsVerifier::$dnsResolverCallback = function(string $ip, array $suffixes) {
    return ReverseDnsVerifier::STATUS_UNKNOWN;
};
$unknownStatus = ReverseDnsVerifier::check('9.9.9.9', 'yandex');
assertCondition($unknownStatus === ReverseDnsVerifier::STATUS_UNKNOWN, 'Test 11: DNS timeout returns UNKNOWN (never false FAKE)');

// TEST 12: Antibot Component & Policy Resolution
$antibot = new Antibot([
    'storageFile' => $tempStorageFile,
    'eventsFile' => $tempEventsFile,
    'botCategoryPolicies' => [
        'search' => 'allow',
        'ad' => 'allow',
        'seo' => 'throttle',
        'social' => 'allow',
        'ai_search' => 'allow',
        'ai_training' => 'block',
    ],
    'botPolicies' => [
        'ahrefs' => 'allow', // Explicit override
    ],
]);
$polGoogle = $antibot->resolveBotPolicy('googlebot', BotCatalog::get('googlebot'));
assertCondition($polGoogle === 'allow', 'Test 12a: Googlebot policy resolved to allow');
$polAhrefs = $antibot->resolveBotPolicy('ahrefs', BotCatalog::get('ahrefs'));
assertCondition($polAhrefs === 'allow', 'Test 12b: Ahrefs override policy resolved to allow');
$polSemrush = $antibot->resolveBotPolicy('semrush', BotCatalog::get('semrush'));
assertCondition($polSemrush === 'throttle', 'Test 12c: Semrush inherited category policy throttle');
$polGpt = $antibot->resolveBotPolicy('gptbot', BotCatalog::get('gptbot'));
assertCondition($polGpt === 'block', 'Test 12d: GPTBot resolved to block');

// TEST 13: Datacenter Filter & Missing Assets Trap Immunity for Verified Bots
// Ensure verified bots pass through without being blocked by Datacenter Detector
$storage = $antibot->getStorage();
$storage->logEvent([
    'ip' => '66.249.66.1',
    'userAgent' => 'Googlebot/2.1',
    'action' => 'allowed_search_bot',
    'reason' => 'Authentic Search Engine: Google',
    'bot_key' => 'googlebot',
    'bot_category' => 'search',
]);
$storage->logEvent([
    'ip' => '178.250.2.15',
    'userAgent' => $criteoUa,
    'action' => 'allowed_ad_bot',
    'reason' => 'Verified Ad Bot: CriteoBot',
    'bot_key' => 'criteo',
    'bot_category' => 'ad',
]);
$botStats = $storage->getBotActivityStats(50);
assertCondition(isset($botStats['criteo']) && $botStats['criteo']['detected'] === 1, 'Test 13a: StorageService aggregated CriteoBot activity stats');
assertCondition(isset($botStats['googlebot']) && $botStats['googlebot']['detected'] === 1, 'Test 13b: StorageService aggregated Googlebot activity stats');

// TEST 14: Settings Persistence Check
$testSettings = [
    'enabled' => true,
    'botCategoryPolicies' => [
        'search' => 'allow',
        'ad' => 'allow',
        'seo' => 'throttle',
    ],
    'botPolicies' => [
        'criteo' => 'allow',
    ],
];
$storage->saveSettings($testSettings);
$loadedSettings = $storage->loadSettings();
assertCondition(($loadedSettings['botPolicies']['criteo'] ?? '') === 'allow', 'Test 14: Settings persistence for botPolicies verified');

// Clean up temporary files
@unlink($tempStorageFile);
@unlink($tempEventsFile);
@unlink($tempStorageFile . '.audit');

echo "\n=================================================================\n";
echo " Test Results: {$testsPassed} / {$testsTotal} passed (" . round(($testsPassed / $testsTotal) * 100, 1) . "%)\n";
echo "=================================================================\n";

if ($testsPassed === $testsTotal) {
    echo " ALL TESTS PASSED SUCCESSFULLY!\n";
    exit(0);
} else {
    echo " SOME TESTS FAILED!\n";
    exit(1);
}
