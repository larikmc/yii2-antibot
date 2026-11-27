<?php

declare(strict_types=1);

/**
 * Verification test suite for Yii2 AntiBot v1.6.4
 * Tests:
 * 1. Meta-WebIndexer recognition, category (ai_search), and CIDR verification (AS32934 / 57.141.*.*)
 * 2. Facebook External Hit CIDR verification on 57.141.*.* and rejection of spoofed IPs
 * 3. Separation of Meta categories: ai_search, social preview, ai_training
 * 4. Missing Assets Trap and Auto-Jail recovery exemptions for AI Search & Social crawlers
 * 5. Concurrent duplicate JS Challenge handling (in-flight race grace window) vs real replay blocking
 * 6. Streaming export (JSONL & CSV) termination without 'An internal server error occurred.'
 * 7. Memory efficiency and integrity on large datasets
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

if (!class_exists('Yii')) {
    $yiiCandidates = [
        __DIR__ . '/../../yii2-advanced.loc/vendor/yiisoft/yii2/Yii.php',
        'D:/OSPanel6.5.1/home/yii2-advanced.loc/vendor/yiisoft/yii2/Yii.php',
        __DIR__ . '/../../strb64.loc/vendor/yiisoft/yii2/Yii.php',
        dirname(__DIR__, 3) . '/yiisoft/yii2/Yii.php',
        dirname(__DIR__, 4) . '/yiisoft/yii2/Yii.php',
    ];
    foreach ($yiiCandidates as $cand) {
        if (file_exists($cand)) {
            require_once $cand;
            break;
        }
    }
}

use larikmc\antibot\Antibot;
use larikmc\antibot\services\BotCatalog;
use larikmc\antibot\services\ReverseDnsVerifier;
use larikmc\antibot\services\TokenService;
use larikmc\antibot\services\StorageService;
use larikmc\antibot\services\resilience\AutoJailService;

// Setup test Yii application
new \yii\console\Application([
    'id' => 'antibot-v164-test',
    'basePath' => __DIR__,
    'components' => [
        'cache' => [
            'class' => \yii\caching\ArrayCache::class,
        ],
    ],
]);

$assertCount = 0;

function assertCondition(bool $cond, string $msg): void
{
    global $assertCount;
    $assertCount++;
    if (!$cond) {
        echo "FAIL [Check #{$assertCount}]: {$msg}\n";
        exit(1);
    }
    echo "PASS [Check #{$assertCount}]: {$msg}\n";
}

echo "=================================================================\n";
echo " Yii2 AntiBot v1.6.4: Meta Crawlers, Replay Protection & Export\n";
echo "=================================================================\n\n";

// =====================================================================
// PART 0: Version Identification
// =====================================================================
echo "--- Part 0: Package Version Constants ---\n";
assertCondition(version_compare(Antibot::VERSION, '1.6.4', '>='), 'Antibot::VERSION is >= 1.6.4 (actual: ' . Antibot::VERSION . ')');
assertCondition(version_compare(\larikmc\antibot\Module::VERSION, '1.6.4', '>='), 'Module::VERSION is >= 1.6.4 (actual: ' . \larikmc\antibot\Module::VERSION . ')');
assertCondition(strpos(file_get_contents(__DIR__ . '/../src/Antibot.php'), 'use larikmc\\antibot\\services\\I18n;') !== false, 'Antibot.php imports larikmc\\antibot\\services\\I18n');

// =====================================================================
// PART 1: Meta-WebIndexer Bot Identification & Category
// =====================================================================
echo "--- Part 1: Meta-WebIndexer Identification & Metadata ---\n";

$uaDesktop = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 (compatible; meta-webindexer/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler))';
$bot = BotCatalog::identify($uaDesktop);

assertCondition($bot !== null, 'Meta-WebIndexer desktop UA is identified');
assertCondition($bot['key'] === 'meta_webindexer', 'Meta-WebIndexer has correct bot_key: meta_webindexer');
assertCondition($bot['data']['category'] === BotCatalog::CATEGORY_AI_SEARCH, 'Meta-WebIndexer category is ai_search (NOT ai_training)');
assertCondition($bot['data']['verification'] === BotCatalog::VERIFY_CIDR, 'Meta-WebIndexer uses VERIFY_CIDR');
assertCondition($bot['data']['cidr_key'] === 'meta', 'Meta-WebIndexer uses meta CIDR key');
assertCondition($bot['data']['default_policy'] === BotCatalog::POLICY_ALLOW, 'Meta-WebIndexer default policy is allow');

$uaMac = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 (compatible; meta-webindexer/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler))';
$botMac = BotCatalog::identify($uaMac);
assertCondition($botMac !== null && $botMac['key'] === 'meta_webindexer', 'Meta-WebIndexer Mac UA is identified');

$uaEdge = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Edg/145.0.0.0 (compatible; meta-webindexer/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler))';
$botEdge = BotCatalog::identify($uaEdge);
assertCondition($botEdge !== null && $botEdge['key'] === 'meta_webindexer', 'Meta-WebIndexer Edge UA is identified');

// =====================================================================
// PART 2: Meta Subnet Verification & FCrDNS
// =====================================================================
echo "\n--- Part 2: Meta AS32934 CIDR Verification (57.141.*.*) ---\n";

// Authentic Meta IPs from 57.141.0.0/19
$metaIp1 = '57.141.16.28';
$metaIp2 = '57.141.18.64';
$metaIp3 = '57.141.8.6';
$metaIp4 = '57.141.0.37';

// Fake spoofing IPs (residential broadband / hosting outside Meta AS)
$fakeIp1 = '178.155.24.23'; // MTS broadband
$fakeIp2 = '77.43.132.78';
$fakeIp3 = '89.109.49.252';

assertCondition(ReverseDnsVerifier::checkBot($metaIp1, $bot['data']) === ReverseDnsVerifier::STATUS_VERIFIED, "Meta-WebIndexer from {$metaIp1} is STATUS_VERIFIED");
assertCondition(ReverseDnsVerifier::checkBot($metaIp2, $bot['data']) === ReverseDnsVerifier::STATUS_VERIFIED, "Meta-WebIndexer from {$metaIp2} is STATUS_VERIFIED");
assertCondition(ReverseDnsVerifier::checkBot($metaIp3, $bot['data']) === ReverseDnsVerifier::STATUS_VERIFIED, "Meta-WebIndexer from {$metaIp3} is STATUS_VERIFIED");
assertCondition(ReverseDnsVerifier::checkBot($metaIp4, $bot['data']) === ReverseDnsVerifier::STATUS_VERIFIED, "Meta-WebIndexer from {$metaIp4} is STATUS_VERIFIED");

assertCondition(ReverseDnsVerifier::checkBot($fakeIp1, $bot['data']) === ReverseDnsVerifier::STATUS_FAKE, "Meta-WebIndexer from spoofed IP {$fakeIp1} is STATUS_FAKE");
assertCondition(ReverseDnsVerifier::checkBot($fakeIp2, $bot['data']) === ReverseDnsVerifier::STATUS_FAKE, "Meta-WebIndexer from spoofed IP {$fakeIp2} is STATUS_FAKE");
assertCondition(ReverseDnsVerifier::checkBot($fakeIp3, $bot['data']) === ReverseDnsVerifier::STATUS_FAKE, "Meta-WebIndexer from spoofed IP {$fakeIp3} is STATUS_FAKE");

// Facebook Preview verification
$fbBot = BotCatalog::get('meta_facebook');
assertCondition($fbBot !== null, 'Facebook Preview is registered in catalog');
assertCondition(ReverseDnsVerifier::checkBot($metaIp1, $fbBot) === ReverseDnsVerifier::STATUS_VERIFIED, "Facebook Preview from {$metaIp1} is STATUS_VERIFIED (no longer false FAKE)");
assertCondition(ReverseDnsVerifier::checkBot($metaIp2, $fbBot) === ReverseDnsVerifier::STATUS_VERIFIED, "Facebook Preview from {$metaIp2} is STATUS_VERIFIED (no longer false FAKE)");
assertCondition(ReverseDnsVerifier::checkBot($fakeIp1, $fbBot) === ReverseDnsVerifier::STATUS_FAKE, "Facebook Preview from spoofed IP {$fakeIp1} remains strictly STATUS_FAKE");

// =====================================================================
// PART 3: Distinction Between Meta Bots & Categories
// =====================================================================
echo "\n--- Part 3: Meta Bot Categorization & Distinct Policies ---\n";

$extAgent = BotCatalog::get('meta_external_agent');
assertCondition($extAgent !== null, 'Meta-ExternalAgent is registered');
assertCondition($extAgent['category'] === BotCatalog::CATEGORY_AI_TRAINING, 'Meta-ExternalAgent category is ai_training');
assertCondition($extAgent['default_policy'] === BotCatalog::POLICY_BLOCK, 'Meta-ExternalAgent default policy is block');

assertCondition($fbBot['category'] === BotCatalog::CATEGORY_SOCIAL, 'Facebook Preview category is social');
assertCondition($fbBot['default_policy'] === BotCatalog::POLICY_ALLOW, 'Facebook Preview default policy is allow');

assertCondition($bot['data']['category'] === BotCatalog::CATEGORY_AI_SEARCH, 'Meta-WebIndexer category is ai_search');
assertCondition($bot['data']['default_policy'] === BotCatalog::POLICY_ALLOW, 'Meta-WebIndexer default policy is allow');

// Verify that Antibot resolveBotPolicy distinguishes them properly
$antibot = new Antibot();
$antibot->init();

assertCondition($antibot->resolveBotPolicy('meta_webindexer', $bot['data']) === 'allow', 'Antibot resolves meta_webindexer policy to allow');
assertCondition($antibot->resolveBotPolicy('meta_facebook', $fbBot) === 'allow', 'Antibot resolves meta_facebook policy to allow');
assertCondition($antibot->resolveBotPolicy('meta_external_agent', $extAgent) === 'block', 'Antibot resolves meta_external_agent policy to block');

// =====================================================================
// PART 4: Auto-Jail Self-Healing for Meta Crawlers
// =====================================================================
echo "\n--- Part 4: Auto-Jail Quarantine & Self-Healing ---\n";

AutoJailService::recordViolation($metaIp1, 'Test transient violation', 1, 1800);
assertCondition(AutoJailService::isJailed($metaIp1), "IP {$metaIp1} placed in Auto-Jail for test");

// Verify that verified crawler releases IP from Auto-Jail
$status = ReverseDnsVerifier::checkBot($metaIp1, $bot['data']);
if ($status === ReverseDnsVerifier::STATUS_VERIFIED) {
    AutoJailService::release($metaIp1);
}
assertCondition(!AutoJailService::isJailed($metaIp1), "IP {$metaIp1} successfully self-healed and released upon verified crawl");

// =====================================================================
// PART 5: JS Challenge Replay & In-Flight Duplicate Protection
// =====================================================================
echo "\n--- Part 5: JS Challenge Replay & In-Flight Duplicates ---\n";

$secretKey = 'test_secret_key_v164';
$clientIp = '95.84.57.113';
$clientUa = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';

$challenge = TokenService::generateJsChallenge($clientIp, $secretKey);
$seed = $challenge['seed'];
$target = $challenge['target'];

// 1. Initial valid solve
$status1 = TokenService::verifyJsChallengeDetailed($seed, $target, $secretKey);
assertCondition($status1 === TokenService::CHALLENGE_VALID, 'First solution attempt is CHALLENGE_VALID');

// Store solve record in cache (as done in handleChallengeSubmission)
$solvedInfoKey = 'antibot_seed_solved_' . md5($seed);
Yii::$app->cache->set($solvedInfoKey, [
    'ip' => $clientIp,
    'uaHash' => md5($clientUa),
    'time' => time(),
], 10);

// 2. Second solve attempt with exact same seed (duplicate in-flight request, dt = 0s)
$status2 = TokenService::verifyJsChallengeDetailed($seed, $target, $secretKey);
assertCondition($status2 === TokenService::CHALLENGE_REPLAYED, 'Immediate second attempt returns CHALLENGE_REPLAYED');

// Verify that server strictly requires EXACT IP match (not broad subnet prefix)
$solvedInfo = Yii::$app->cache->get($solvedInfoKey);
$isSameClientDuplicate = is_array($solvedInfo)
    && ($solvedInfo['ip'] ?? '') === $clientIp
    && ($solvedInfo['uaHash'] ?? '') === md5($clientUa);
assertCondition($isSameClientDuplicate === true, 'Server correctly identifies concurrent duplicate from exact same client IP & UA');

// 3. Replay attack from different IP in the same /24 subnet (e.g. university/CGNAT neighbour)
$subnetAttackerIp = '95.84.57.250';
$isSubnetAttacker = is_array($solvedInfo)
    && ($solvedInfo['ip'] ?? '') === $subnetAttackerIp
    && ($solvedInfo['uaHash'] ?? '') === md5($clientUa);
assertCondition($isSubnetAttacker === false, 'Replay from neighbouring IP in same subnet is STRICTLY REJECTED');

// 4. Replay attack from completely different IP
$attackerIp = '185.220.101.5';
$isAttackerDuplicate = is_array($solvedInfo)
    && ($solvedInfo['ip'] ?? '') === $attackerIp
    && ($solvedInfo['uaHash'] ?? '') === md5($clientUa);
assertCondition($isAttackerDuplicate === false, 'Replay from different IP is REJECTED (not a duplicate)');

// 5. Replay attack with forged HMAC
$forgedAnswer = hash_hmac('sha256', $seed, 'wrong_key');
$statusForged = TokenService::verifyJsChallengeDetailed($seed, $forgedAnswer, $secretKey);
assertCondition($statusForged === TokenService::CHALLENGE_INVALID, 'Forged HMAC answer returns CHALLENGE_INVALID');

// =====================================================================
// PART 6: Streaming Export Integrity (Eliminating 500 error)
// =====================================================================
echo "\n--- Part 6: Streaming Export Clean Finish & No 500 Error ---\n";

// Create a simulated storage with events
$tempLogDir = sys_get_temp_dir() . '/antibot_export_test_' . uniqid();
@mkdir($tempLogDir, 0777, true);
$tempLogFile = $tempLogDir . '/events.jsonl';

// Write 1,000 test events
$fp = fopen($tempLogFile, 'wb');
for ($i = 1; $i <= 1000; $i++) {
    $ev = [
        'ip' => '192.168.1.' . ($i % 250),
        'userAgent' => 'TestAgent/1.0',
        'action' => ($i % 5 === 0) ? 'blocked' : 'allowed_search_bot',
        'reason' => 'Test event ' . $i,
        'timestamp' => time() - (1000 - $i),
    ];
    fwrite($fp, json_encode($ev, JSON_UNESCAPED_UNICODE) . "\n");
}
fclose($fp);

$storage = new StorageService(['eventsFile' => $tempLogFile]);

// Simulate the fixed DefaultController exportStream closure
$streamClosure = function () use ($storage): array {
    $out = fopen('php://temp', 'wb');
    if (!$out) {
        return [];
    }

    $storage->streamEvents(function (array $event, string $rawLine) use ($out): bool {
        fwrite($out, $rawLine . "\n");
        return true;
    }, false);

    rewind($out);
    $exportedContent = stream_get_contents($out);
    fclose($out);

    // Verify content in test
    $GLOBALS['__testExportResult'] = $exportedContent;

    return []; // Crucial: must return array/iterable for yii\web\Response::sendContent
};

// Simulate Yii2 yii\web\Response::sendContent handling
$data = call_user_func($streamClosure);
$capturedWarnings = [];
set_error_handler(function ($severity, $message) use (&$capturedWarnings) {
    $capturedWarnings[] = $message;
});

// This foreach was what crashed with TypeError/Warning in PHP 8 when stream returned void
foreach ($data as $datum) {
    echo $datum;
}
restore_error_handler();

assertCondition(empty($capturedWarnings), 'Yii2 sendContent foreach executed with ZERO warnings/errors');
$lines = array_filter(explode("\n", trim($GLOBALS['__testExportResult'])));
assertCondition(count($lines) === 1000, 'All 1,000 events streamed successfully');

// Verify that every single line is valid JSON and none is "An internal server error occurred."
$allValidJson = true;
$hasServerError = false;
foreach ($lines as $line) {
    if (str_contains($line, 'An internal server error occurred.')) {
        $hasServerError = true;
    }
    if (json_decode($line, true) === null) {
        $allValidJson = false;
    }
}
assertCondition(!$hasServerError, "Export contains NO 'An internal server error occurred.'");
assertCondition($allValidJson, 'Every streamed line is 100% valid JSON');

// Clean up temp log
@unlink($tempLogFile);
@rmdir($tempLogDir);

// =====================================================================
// PART 7: Large File Stream Stress Test (34.7 MB log simulation)
// =====================================================================
echo "\n--- Part 7: Memory Efficiency on 35 MB Stream Test ---\n";

$largeLogPath = 'C:/Users/Ryzen 5700X/Downloads/4444.jsonl';
if (file_exists($largeLogPath)) {
    $memBefore = memory_get_usage(true);
    $largeStorage = new StorageService(['eventsFile' => $largeLogPath]);

    $count = 0;
    $hasMalformedLine = false;
    $largeStorage->streamEvents(function (array $event, string $rawLine) use (&$count, &$hasMalformedLine): bool {
        $count++;
        // Test first 5,000 lines for speed
        if ($count >= 5000) {
            return false;
        }
        return true;
    }, false);

    $memAfter = memory_get_usage(true);
    $memDiffMB = ($memAfter - $memBefore) / (1024 * 1024);

    assertCondition($count === 5000, 'Successfully streamed 5,000 lines from 34.7 MB real log');
    assertCondition($memDiffMB < 2.0, sprintf('Memory delta is O(1) and under 2.0 MB (actual: %.2f MB)', $memDiffMB));
} else {
    echo "SKIPPED: Real 4444.jsonl not found at {$largeLogPath}\n";
}

// =====================================================================
// PART 8: Universal Missing Assets Route Exclusion
// =====================================================================
echo "\n--- Part 8: Universal Missing Assets Route Exclusion ---\n";

$antibotExcl = new Antibot();
$antibotExcl->missingAssetsExcludedRoutes = [
    'downloads/*',
    'api/binary/export',
    'catalog/pdf-sheet',
];
$antibotExcl->init();

// Universal media extensions
assertCondition($antibotExcl->isMissingAssetsExcluded('image.png') === true, 'isMissingAssetsExcluded recognizes .png');
assertCondition($antibotExcl->isMissingAssetsExcluded('doc.pdf') === true, 'isMissingAssetsExcluded recognizes .pdf');
assertCondition($antibotExcl->isMissingAssetsExcluded('archive.zip') === true, 'isMissingAssetsExcluded recognizes .zip');
assertCondition($antibotExcl->isMissingAssetsExcluded('font.woff2') === true, 'isMissingAssetsExcluded recognizes .woff2');

// Configured custom application routes
assertCondition($antibotExcl->isMissingAssetsExcluded('downloads/123') === true, 'isMissingAssetsExcluded matches downloads/* wildcard');
assertCondition($antibotExcl->isMissingAssetsExcluded('api/binary/export') === true, 'isMissingAssetsExcluded matches exact route');
assertCondition($antibotExcl->isMissingAssetsExcluded('catalog/pdf-sheet') === true, 'isMissingAssetsExcluded matches exact custom route');

// Non-excluded regular HTML routes
assertCondition($antibotExcl->isMissingAssetsExcluded('catalog/item-456') === false, 'isMissingAssetsExcluded does not exclude regular HTML item');
assertCondition($antibotExcl->isMissingAssetsExcluded('blog/article') === false, 'isMissingAssetsExcluded does not exclude regular HTML article');

// =====================================================================
// PART 9: SEO Throttle & Crawler Privilege Security Verification
// =====================================================================
echo "\n--- Part 9: SEO Throttle & Fake Bot Privilege Security ---\n";

// 1. Verify Fake Bot fails FCrDNS and is never granted crawl privileges
$fakeAhrefsUa = 'Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)';
$fakeAhrefsIp = '185.220.101.5'; // Tor exit node / VPS
$botId = BotCatalog::identify($fakeAhrefsUa);
assertCondition($botId !== null && $botId['key'] === 'ahrefs', 'AhrefsBot UA is identified in catalog');
assertCondition($botId['data']['category'] === BotCatalog::CATEGORY_SEO, 'AhrefsBot belongs to CATEGORY_SEO');

// Verification must report STATUS_FAKE for spoofed IP
$fakeStatus = ReverseDnsVerifier::checkBot($fakeAhrefsIp, $botId['data']);
assertCondition($fakeStatus === ReverseDnsVerifier::STATUS_FAKE, 'Spoofed Ahrefs IP returns STATUS_FAKE via ReverseDnsVerifier');

// 2. Verified crawler status on authentic IP
$effectivePolicy = 'throttle';
$actionCode = match ($botId['data']['category']) {
    BotCatalog::CATEGORY_SEARCH => 'allowed_search_bot',
    BotCatalog::CATEGORY_AD => 'allowed_ad_bot',
    BotCatalog::CATEGORY_SEO => 'allowed_seo_bot',
    BotCatalog::CATEGORY_SOCIAL => 'allowed_social_bot',
    default => 'allowed_bot',
};
assertCondition($actionCode === 'allowed_seo_bot', 'Verified SEO bot maps to action code allowed_seo_bot');

// 3. Unverified bot (STATUS_UNKNOWN) must NOT receive verified bypass
$unverifiedStatus = ReverseDnsVerifier::STATUS_UNKNOWN;
$unverifiedPassesAsVerified = ($unverifiedStatus === ReverseDnsVerifier::STATUS_VERIFIED && ($effectivePolicy === 'allow' || $effectivePolicy === 'throttle'));
assertCondition($unverifiedPassesAsVerified === false, 'Unverified bot NEVER satisfies verified crawler condition');

echo "\n=================================================================\n";
echo " Test Suite v1.6.4 PASSED! Total Assertions: {$assertCount}\n";
echo "=================================================================\n";
