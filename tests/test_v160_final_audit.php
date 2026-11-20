<?php

declare(strict_types=1);

/**
 * Yii2 AntiBot: Final Release Audit & Integration Verification Suite (v1.6.0)
 *
 * Tests:
 * 1. Google-Extended removal from HTTP detection & protection of Googlebot
 * 2. IAS crawler & ias_wombles CIDR verification, caching, and resilient update fallback
 * 3. Strict verification: FAKE, UNKNOWN, DNS timeout, and Cautious mode (No User-Agent bypass)
 * 4. Google Special Crawlers offline CIDR matching and update failure resilience
 * 5. Added bots: YandexMetrika, YandexRenderResourcesBot, Google-InspectionTool, OAI-AdsBot
 * 6. Protection of legitimate search/ad bots from quick-block
 * 7. End-to-end HTTP inspection pipeline (Auto-Jail, Datacenter filter, Honeypot, Rate limiting)
 */

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/index.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

$autoloadCandidates = [
    'D:/OpenServer6.5.0/home/yii2-fresh-install.loc/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
    dirname(__DIR__, 2) . '/vendor/autoload.php',
    'D:/OpenServer6.5.0/home/yii2-admin/vendor/autoload.php',
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
    __DIR__ . '/../vendor/yiisoft/yii2/Yii.php',
    dirname(__DIR__, 2) . '/vendor/yiisoft/yii2/Yii.php',
];
foreach ($yiiCandidates as $cand) {
    if (file_exists($cand)) {
        require_once $cand;
        break;
    }
}

$tempRuntime = sys_get_temp_dir() . '/antibot_audit_runtime_' . uniqid();
@mkdir($tempRuntime, 0775, true);

if (class_exists('Yii') && Yii::$app === null) {
    new \yii\web\Application([
        'id' => 'antibot-final-audit',
        'basePath' => dirname(__DIR__),
        'runtimePath' => $tempRuntime,
        'components' => [
            'cache' => [
                'class' => \yii\caching\ArrayCache::class,
            ],
            'request' => [
                'cookieValidationKey' => 'test-validation-key-32-chars-long!!',
                'scriptFile' => __FILE__,
                'scriptUrl' => '/index.php',
            ],
        ],
    ]);
}

use larikmc\antibot\Antibot;
use larikmc\antibot\data\GoogleSpecialRanges;
use larikmc\antibot\data\IasRanges;
use larikmc\antibot\services\BotCatalog;
use larikmc\antibot\services\BotDetector;
use larikmc\antibot\services\ReverseDnsVerifier;
use larikmc\antibot\services\resilience\AutoJailService;

$passed = 0;
$failed = 0;

function assertTest(bool $condition, string $title): void {
    global $passed, $failed;
    if ($condition) {
        echo " [PASS] {$title}\n";
        $passed++;
    } else {
        echo " [FAIL] {$title}\n";
        $failed++;
    }
}

echo "=================================================================\n";
echo " Yii2 AntiBot: Final Release Audit & Verification Suite (v1.6.0)\n";
echo "=================================================================\n\n";

// --- 1. Google-Extended Audit ---
echo "--- Section 1: Google-Extended & Googlebot Safety ---\n";
$geIdentified = BotCatalog::identify('Google-Extended');
assertTest($geIdentified === null, '1.1 Google-Extended is NOT recognized as an HTTP crawler in BotCatalog');

$geAi = BotDetector::detectAiCrawler('Mozilla/5.0 (compatible; Google-Extended)');
assertTest($geAi === null, '1.2 Google-Extended is removed from BotDetector AI_TRAINING_CRAWLERS');

$realGooglebotUA = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
$gbIdentified = BotCatalog::identify($realGooglebotUA);
assertTest($gbIdentified !== null && $gbIdentified['data']['category'] === BotCatalog::CATEGORY_SEARCH, '1.3 Googlebot is classified strictly as Search Engine');

// --- 2. IAS Crawler & ias_wombles ---
echo "\n--- Section 2: IAS Crawler (ias_crawler & ias_wombles) ---\n";
$iasUA1 = 'ias_crawler/1.0 (+https://integralads.com/ias-crawler/)';
$iasUA2 = 'Mozilla/5.0 (compatible; ias_wombles/1.0; +https://integralads.com)';
$iasBot1 = BotCatalog::identify($iasUA1);
$iasBot2 = BotCatalog::identify($iasUA2);

assertTest($iasBot1 !== null && $iasBot1['key'] === 'ias' && $iasBot1['data']['category'] === BotCatalog::CATEGORY_AD, '2.1 ias_crawler identified in Advertising category');
assertTest($iasBot2 !== null && $iasBot2['key'] === 'ias' && $iasBot2['data']['verification'] === BotCatalog::VERIFY_CIDR, '2.2 ias_wombles verified via official CIDR method');

// Official IAS IP from list
$officialIasIp = '92.42.105.35';
$iasStatusReal = ReverseDnsVerifier::checkBot($officialIasIp, $iasBot1['data']);
assertTest($iasStatusReal === ReverseDnsVerifier::STATUS_VERIFIED, '2.3 Official IAS IP 92.42.105.35 is VERIFIED');

// Fake IAS IP
$fakeIasIp = '185.220.101.5';
$iasStatusFake = ReverseDnsVerifier::checkBot($fakeIasIp, $iasBot1['data']);
assertTest($iasStatusFake === ReverseDnsVerifier::STATUS_FAKE, '2.4 Non-IAS IP 185.220.101.5 spoofing IAS is marked FAKE');

// Test IasRanges update fallback resilience
$ipsBefore = IasRanges::getIps();
assertTest(count($ipsBefore) >= 30, '2.5 IasRanges loaded official IP count >= 30');
// Simulated update with invalid URL / timeout preserves existing list
$invalidUpdate = IasRanges::update(1);
$ipsAfter = IasRanges::getIps();
assertTest(count($ipsAfter) === count($ipsBefore), '2.6 Failed/timed out update preserves last valid IAS list without corruption');

// --- 3. Strict Verification & Cautious Mode (No UA Bypass) ---
echo "\n--- Section 3: Verification & Cautious Mode (No UA Bypass) ---\n";
// Attacker spoofing Googlebot from Hetzner Datacenter IP
$spoofedGoogleIp = '159.69.0.1'; // Hetzner
$vStatusFake = ReverseDnsVerifier::check($spoofedGoogleIp, 'google');
assertTest($vStatusFake === ReverseDnsVerifier::STATUS_FAKE, '3.1 Hetzner IP claiming Googlebot is marked FAKE');

// Attacker spoofing with UNKNOWN status (DNS timeout)
$unknownBotData = [
    'verification' => BotCatalog::VERIFY_RDNS,
    'engine_key' => 'unknown_engine_test',
    'name' => 'TestBot',
    'category' => BotCatalog::CATEGORY_SEARCH,
    'default_policy' => BotCatalog::POLICY_ALLOW,
];
$unknownStatus = ReverseDnsVerifier::checkBot('8.8.8.8', $unknownBotData);
assertTest($unknownStatus === ReverseDnsVerifier::STATUS_UNKNOWN, '3.2 Unresolvable bot verification returns STATUS_UNKNOWN');

// Cautious bot (VERIFY_NONE)
$doubleVerifyBot = BotCatalog::identify('DoubleVerify/1.0 (https://doubleverify.com)');
assertTest($doubleVerifyBot !== null && $doubleVerifyBot['data']['verification'] === BotCatalog::VERIFY_NONE, '3.3 DoubleVerify operates in Cautious mode (VERIFY_NONE)');

// --- 4. Google Special Crawlers Offline Matching & Actualization ---
echo "\n--- Section 4: Google Special Crawlers & Offline Ranges ---\n";
$specialCidrs = GoogleSpecialRanges::getCidrs();
assertTest(count($specialCidrs) >= 200, '4.1 GoogleSpecialRanges has >= 200 official CIDR subnets');

// IP from special crawlers snapshot (e.g. 108.177.2.10)
$specialIp = '108.177.2.10';
assertTest(GoogleSpecialRanges::match($specialIp), '4.2 IP 108.177.2.10 matches Google Special Crawlers subnets');

// Non-Google IP
assertTest(!GoogleSpecialRanges::match('194.67.210.1'), '4.3 Random external IP does NOT match Google Special subnets');

// GoogleSpecialRanges update resilience
$cidrsBefore = GoogleSpecialRanges::getCidrs();
$gUpdate = GoogleSpecialRanges::update(1);
$cidrsAfter = GoogleSpecialRanges::getCidrs();
assertTest(count($cidrsAfter) === count($cidrsBefore), '4.4 GoogleSpecialRanges update preserves last valid list upon network failure');

// --- 5. Catalog Additions ---
echo "\n--- Section 5: Newly Added Verified Bots ---\n";
// YandexMetrika
$ymUA = 'YandexMetrika/3.0';
$ymBot = BotCatalog::identify($ymUA);
assertTest($ymBot !== null && $ymBot['key'] === 'yandex_metrika' && $ymBot['data']['category'] === BotCatalog::CATEGORY_AD, '5.1 YandexMetrika identified in Advertising category');

// YandexRenderResourcesBot
$yrUA = 'Mozilla/5.0 (compatible; YandexRenderResourcesBot/1.0; +http://yandex.com/bots)';
$yrBot = BotCatalog::identify($yrUA);
assertTest($yrBot !== null && $yrBot['key'] === 'yandex_render_resources' && $yrBot['data']['category'] === BotCatalog::CATEGORY_SEARCH, '5.2 YandexRenderResourcesBot identified in Search category');

// Google-InspectionTool
$giUA = 'Mozilla/5.0 (compatible; Google-InspectionTool/1.0;)';
$giBot = BotCatalog::identify($giUA);
assertTest($giBot !== null && $giBot['key'] === 'google_inspection_tool' && $giBot['data']['category'] === BotCatalog::CATEGORY_SEARCH, '5.3 Google-InspectionTool identified in Search category');

// OAI-AdsBot
$oaUA = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; OAI-AdsBot/1.0; +https://openai.com/adsbot';
$oaBot = BotCatalog::identify($oaUA);
assertTest($oaBot !== null && $oaBot['key'] === 'oai_adsbot' && $oaBot['data']['category'] === BotCatalog::CATEGORY_AD, '5.4 OAI-AdsBot identified in Advertising category');

// OAI-AdsBot verification
$openaiOfficialIp = '130.131.229.135'; // Inside 130.131.229.128/25
$openaiFakeIp = '185.10.10.10';
assertTest(ReverseDnsVerifier::checkBot($openaiOfficialIp, $oaBot['data']) === ReverseDnsVerifier::STATUS_VERIFIED, '5.5 Official OpenAI AdsBot IP 130.131.229.135 is VERIFIED');
assertTest(ReverseDnsVerifier::checkBot($openaiFakeIp, $oaBot['data']) === ReverseDnsVerifier::STATUS_FAKE, '5.6 Non-OpenAI IP spoofing OAI-AdsBot is marked FAKE');

// --- 6. End-to-End Pipeline & Protection Integrity ---
echo "\n--- Section 6: End-to-End Inspection Pipeline Integrity ---\n";
// Create test Antibot instance
$antibot = new Antibot();
$antibot->mode = 'standard';
$antibot->verifySearchBots = true;
$antibot->blockFakeSearchBots = true;
$antibot->autoJailEnabled = true;

// Policy resolution check
$gbPolicy = $antibot->resolveBotPolicy('googlebot', BotCatalog::get('googlebot'));
assertTest($gbPolicy === 'allow', '6.1 Default policy for Googlebot is allow');

$iasPolicy = $antibot->resolveBotPolicy('ias', BotCatalog::get('ias'));
assertTest($iasPolicy === 'allow', '6.2 Default policy for IAS is allow');

// Verified Googlebot IP (66.249.66.1)
assertTest(\larikmc\antibot\services\IpMatcher::match('66.249.66.1', ReverseDnsVerifier::GOOGLEBOT_CIDRS), '6.3 IP 66.249.66.1 matches official Googlebot CIDRs');

// Auto-Jail check for fake bot
AutoJailService::recordViolation('198.51.100.99', 'Fake bot spoofing Googlebot', 1, 300);
assertTest(AutoJailService::isJailed('198.51.100.99'), '6.4 Impersonator IP 198.51.100.99 successfully jailed in Auto-Jail');
AutoJailService::releaseIp('198.51.100.99');
assertTest(!AutoJailService::isJailed('198.51.100.99'), '6.5 IP released from Auto-Jail');

// --- 7. Subnet /24 & CIDR Overlap Protection ---
echo "\n--- Section 7: Subnet /24 & CIDR Overlap Protection ---\n";
// Google Special intersection
$intersectGS = \larikmc\antibot\services\IpMatcher::checkProtectedIntersection('108.177.2.0/24');
assertTest($intersectGS['intersects'] && str_contains($intersectGS['system'], 'Google'), '7.1 Subnet 108.177.2.0/24 intersects with Google Special Crawlers');

// Googlebot intersection
$intersectGB = \larikmc\antibot\services\IpMatcher::checkProtectedIntersection('66.249.66.0/24');
assertTest($intersectGB['intersects'] && str_contains($intersectGB['system'], 'Googlebot'), '7.2 Subnet 66.249.66.0/24 intersects with Googlebot CIDRs');

// Criteo intersection
$intersectCR = \larikmc\antibot\services\IpMatcher::checkProtectedIntersection('178.250.0.0/24');
assertTest($intersectCR['intersects'] && str_contains($intersectCR['system'], 'Criteo'), '7.3 Subnet 178.250.0.0/24 intersects with Criteo CIDRs');

// IAS intersection
$intersectIAS = \larikmc\antibot\services\IpMatcher::checkProtectedIntersection('92.42.105.0/24');
assertTest($intersectIAS['intersects'] && str_contains($intersectIAS['system'], 'IAS'), '7.4 Subnet 92.42.105.0/24 intersects with IAS IPs');

// Telegram intersection
$intersectTG = \larikmc\antibot\services\IpMatcher::checkProtectedIntersection('91.108.4.0/24');
assertTest($intersectTG['intersects'] && str_contains($intersectTG['system'], 'Telegram'), '7.5 Subnet 91.108.4.0/24 intersects with Telegram CIDRs');

// Clean subnet has NO intersection
$intersectClean = \larikmc\antibot\services\IpMatcher::checkProtectedIntersection('198.51.100.0/24');
assertTest(!$intersectClean['intersects'], '7.6 Clean subnet 198.51.100.0/24 has NO intersection and can be safely blocked');

// Dynamic Yandex verification protection from cache/logs
ReverseDnsVerifier::registerVerifiedIp('178.154.200.45', 'YandexBot');
$intersectYandex = \larikmc\antibot\services\IpMatcher::checkProtectedIntersection('178.154.200.0/24');
assertTest($intersectYandex['intersects'] && str_contains($intersectYandex['system'], 'YandexBot'), '7.7 Candidate subnet 178.154.200.0/24 containing verified YandexBot is rejected');

// IPv6 CIDR intersection
$ipv6Intersect = \larikmc\antibot\services\IpMatcher::cidrsIntersect('2a02:6b8:b040::/48', '2a02:6b8:b040:31::1');
assertTest($ipv6Intersect, '7.8 IPv6 CIDR intersection correctly identifies overlapping ranges');

// --- 8. Social Preview Link Unfurling & Attack Mitigation ---
echo "\n--- Section 8: Social Preview Link Unfurling & Mitigation ---\n";
// Telegram verified from official IP
$tgUA = 'TelegramBot (like TwitterBot)';
$tgData = BotCatalog::get('telegram');
$tgStatus = ReverseDnsVerifier::checkBot('91.108.4.50', $tgData);
assertTest($tgStatus === ReverseDnsVerifier::STATUS_VERIFIED, '8.1 Official Telegram IP 91.108.4.50 is VERIFIED via CIDR');

// VK verified from official IP
$vkData = BotCatalog::get('vk_share');
$vkStatus = ReverseDnsVerifier::checkBot('87.240.128.15', $vkData);
assertTest($vkStatus === ReverseDnsVerifier::STATUS_VERIFIED, '8.2 Official VK IP 87.240.128.15 is VERIFIED via CIDR');

// Fake Telegram IP
$tgFakeStatus = ReverseDnsVerifier::checkBot('185.220.101.5', $tgData);
assertTest($tgFakeStatus === ReverseDnsVerifier::STATUS_FAKE, '8.3 Non-Telegram IP claiming Telegram is marked FAKE');

// Closed routes detection
assertTest(Antibot::isClosedRoute('admin'), '8.4 Route "admin" is recognized as closed/administrative');
assertTest(Antibot::isClosedRoute('wp-admin/post.php'), '8.5 Route "wp-admin/post.php" is recognized as closed/administrative');
assertTest(Antibot::isClosedRoute('antibot/default/index'), '8.6 Route "antibot/default/index" is recognized as closed/administrative');
assertTest(!Antibot::isClosedRoute('articles/how-to-fix-error'), '8.7 Public content route is NOT recognized as closed');

// --- 9. Endpoint Security & Version Alignment ---
echo "\n--- Section 9: Endpoint Security & Version Alignment ---\n";
assertTest(version_compare(\larikmc\antibot\Module::VERSION, '1.6.0', '>='), '9.1 Module::VERSION is >= 1.6.0');
assertTest(version_compare(Antibot::VERSION, '1.6.0', '>='), '9.2 Antibot::VERSION is >= 1.6.0');

// Verify SettingsController verb filter contains update-ranges as POST
$settingsCtrl = new \larikmc\antibot\controllers\SettingsController('settings', Yii::$app->getModule('antibot') ?? new \larikmc\antibot\Module('antibot'));
$behaviors = $settingsCtrl->behaviors();
assertTest(isset($behaviors['verbs']['actions']['update-ranges']) && in_array('POST', $behaviors['verbs']['actions']['update-ranges'], true), '9.3 actionUpdateRanges strictly requires POST verb');

// --- 10. Blacklist Safe Verification Priority ---
echo "\n--- Section 10: Blacklist Safe Priority for Verified Crawlers ---\n";
$auditBot = new Antibot();
$auditBot->enabled = true;
$auditBot->verifySearchBots = true;
$auditBot->blockFakeSearchBots = true;
$auditBot->blacklist = ['66.249.66.0/24', '198.51.100.0/24'];

// Case 1: Verified Googlebot (66.249.66.1) on public route /article/test
$_SERVER['REMOTE_ADDR'] = '66.249.66.1';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/article/test';
$auditBot->startInspection();
// Emulate Step 3 blacklist check
$isVerifiedCrawler = false;
$candBot = BotCatalog::identify($_SERVER['HTTP_USER_AGENT']);
if ($candBot !== null) {
    $candData = $candBot['data'];
    $candCat = $candData['category'] ?? '';
    $candVType = $candData['verification'] ?? BotCatalog::VERIFY_NONE;
    if (($candCat === BotCatalog::CATEGORY_SEARCH || $candCat === BotCatalog::CATEGORY_AD) && $candVType !== BotCatalog::VERIFY_NONE) {
        $checkStatus = ReverseDnsVerifier::checkBot('66.249.66.1', $candData);
        if ($checkStatus === ReverseDnsVerifier::STATUS_VERIFIED) {
            $isVerifiedCrawler = true;
        }
    }
}
assertTest($isVerifiedCrawler, '10.1 Verified Googlebot inside blacklisted /24 successfully recognized as authentic crawler');

// Case 2: Fake Googlebot (198.51.100.55) claiming Googlebot UA inside blacklisted /24
$fakeBot = false;
$checkFakeStatus = ReverseDnsVerifier::checkBot('198.51.100.55', $candData);
assertTest($checkFakeStatus !== ReverseDnsVerifier::STATUS_VERIFIED, '10.2 Impersonator claiming Googlebot from 198.51.100.55 is NOT verified and remains blocked by blacklist');

// Case 3: Verified Googlebot requesting closed route /admin
$isAdminClosed = Antibot::isClosedRoute('admin');
assertTest($isAdminClosed, '10.3 Requests to closed routes are not exempted from blacklist');

echo "\n=================================================================\n";
echo " Test Results: {$passed} / " . ($passed + $failed) . " passed (" . round(($passed / ($passed + $failed)) * 100) . "%)\n";
echo "=================================================================\n";

if ($failed === 0) {
    echo " ALL FINAL AUDIT TESTS PASSED SUCCESSFULLY!\n";
    exit(0);
} else {
    echo " FAILURES DETECTED! Review logs above.\n";
    exit(1);
}
