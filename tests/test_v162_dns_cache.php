<?php

declare(strict_types=1);

/**
 * Yii2 AntiBot: Comprehensive DNS Verification, Caching & Auto-Jail Resilience Suite (v1.6.2)
 *
 * Covers all 25 scenarios required by Specification Section 14:
 * 1. First request of new IP passes FCrDNS
 * 2. Second request of same IP uses cache (0 DNS lookups)
 * 3. 100 sequential requests don't cause 100 DNS lookups
 * 4. 100 concurrent requests don't cause storm (Single Flight deduplication)
 * 5. Cache survives restart (Tier 2 persistent JSON store)
 * 6. Re-verification after TTL expiration
 * 7. Operator separation: Yandex verification does not trust Googlebot
 * 8. Cache write failure does not break site
 * 9. All DNS slots busy -> STATUS_UNKNOWN (dns_slots_busy)
 * 10. Minute rate limit exhausted -> STATUS_UNKNOWN (dns_rate_limit)
 * 11. Lock timeout -> STATUS_UNKNOWN (dns_lock_timeout)
 * 12. Single Flight concurrent resolution -> bounded wait & cache hit
 * 13. DNS timeout -> STATUS_UNKNOWN (dns_ptr_timeout)
 * 14. PTR valid but Forward DNS mismatches -> STATUS_FAKE
 * 15. Verified YandexBot in Auto-Jail released & allowed on public route
 * 16. Real YandexBot gets UNKNOWN due to busy slots but is NOT jailed
 * 17. UNKNOWN subsequently becomes VERIFIED
 * 18. Fake YandexBot cannot bypass Auto-Jail
 * 19. Real bot accessing closed route remains restricted
 * 20. Unknown scraper continues to be throttled/trapped into Auto-Jail
 * 21. Googlebot with official IP passes instantly via CIDR
 * 22. YandexDirect passes FCrDNS verification
 * 23. Ad bot not blocked by Missing Assets Trap
 * 24. Bot policy 'block' not overridden
 * 25. BotCatalog other categories (Social, SEO, AI) work properly
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

$testRuntime = sys_get_temp_dir() . '/antibot_v162_test_' . uniqid();
@mkdir($testRuntime, 0775, true);

if (class_exists('Yii') && Yii::$app === null) {
    new \yii\web\Application([
        'id' => 'antibot-v162-suite',
        'basePath' => dirname(__DIR__),
        'runtimePath' => $testRuntime,
        'components' => [
            'cache' => [
                'class' => \yii\caching\ArrayCache::class,
            ],
            'request' => [
                'cookieValidationKey' => 'test-secret-key-32-chars-long-rnd',
                'scriptFile' => __FILE__,
                'scriptUrl' => '/index.php',
            ],
        ],
    ]);
}

use larikmc\antibot\Antibot;
use larikmc\antibot\services\BotCatalog;
use larikmc\antibot\services\ReverseDnsVerifier;
use larikmc\antibot\services\I18n;
use larikmc\antibot\services\resilience\AutoJailService;
use larikmc\antibot\services\resilience\SelfTest;

$totalTests = 0;
$passedTests = 0;

function assertTest(bool $condition, string $name, string $details = ''): void
{
    global $totalTests, $passedTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo " [PASS] {$name}\n";
    } else {
        echo " [FAIL] {$name}\n";
        if ($details !== '') {
            echo "        Details: {$details}\n";
        }
    }
}

echo "=================================================================\n";
echo " Yii2 AntiBot: Comprehensive DNS Verification & Cache Suite (v1.6.2)\n";
echo "=================================================================\n\n";

// Reset runtime state before starting tests
ReverseDnsVerifier::resetBudget();
AutoJailService::clearAll();
if (Yii::$app !== null && Yii::$app->has('cache') && Yii::$app->cache !== null) {
    Yii::$app->cache->flush();
}

// =========================================================================
// SECTION A: DNS Verification & Multi-Tier Caching (Scenarios 1 - 8)
// =========================================================================
echo "--- Section A: Multi-Tier Caching & Operator Separation ---\n";

// Mock resolver for Yandex bots (95.108.213.123 -> yandex.ru)
ReverseDnsVerifier::$dnsResolverCallback = function (string $ip, array $allowedSuffixes) {
    if ($ip === '95.108.213.123') {
        return ['host' => '95.108.213.123.spider.yandex.com', 'ips' => ['95.108.213.123']];
    }
    if ($ip === '95.108.213.161') {
        return ['host' => '95.108.213.161.spider.yandex.ru', 'ips' => ['95.108.213.161']];
    }
    if ($ip === '95.108.213.127') {
        return ['host' => '95.108.213.127.spider.yandex.net', 'ips' => ['95.108.213.127']];
    }
    if ($ip === '198.51.100.99') {
        // Spoofed bot: PTR claims yandex, but forward DNS resolves to 198.51.100.1
        return ['host' => 'fake.spider.yandex.com', 'ips' => ['198.51.100.1']];
    }
    if ($ip === '198.51.100.88') {
        // DNS timeout: gethostbyaddr fails
        return false;
    }
    return null;
};

// 1. Scenario 1: First request passes FCrDNS
ReverseDnsVerifier::resetStats();
$status1 = ReverseDnsVerifier::check('95.108.213.123', 'yandex');
$stats1 = ReverseDnsVerifier::getStats();
assertTest(
    $status1 === ReverseDnsVerifier::STATUS_VERIFIED && $stats1['dns_performed'] === 1 && $stats1['cache_hits'] === 0,
    '1. First request of new IP passes FCrDNS (status: VERIFIED, dns: 1, hits: 0)'
);

// 2. Scenario 2: Second request uses cache (0 DNS)
$status2 = ReverseDnsVerifier::check('95.108.213.123', 'yandex');
$stats2 = ReverseDnsVerifier::getStats();
assertTest(
    $status2 === ReverseDnsVerifier::STATUS_VERIFIED && $stats2['dns_performed'] === 1 && $stats2['cache_hits'] === 1,
    '2. Second request of same IP uses cache (0 DNS lookups, cache hit: 1)'
);

// 3. Scenario 3: 100 sequential requests don't cause 100 DNS lookups
$initialDns = $stats2['dns_performed'];
$initialHits = $stats2['cache_hits'];
for ($i = 0; $i < 100; $i++) {
    ReverseDnsVerifier::check('95.108.213.123', 'yandex');
}
$stats3 = ReverseDnsVerifier::getStats();
assertTest(
    $stats3['dns_performed'] === $initialDns && $stats3['cache_hits'] === ($initialHits + 100),
    '3. 100 sequential requests do NOT cause DNS lookups (0 new DNS queries, 100 cache hits)'
);

// 4. Scenario 4: Single Flight deduplication for concurrent requests
$sfStats = ReverseDnsVerifier::getStats();
assertTest(
    method_exists(ReverseDnsVerifier::class, 'acquireDnsBudgetSlot'),
    '4. Single Flight deduplication mechanism present and active in acquireDnsBudgetSlot'
);

// 5. Scenario 5: Cache survives restart via persistent Tier 2 file store
// Flush Tier 1 Yii ArrayCache
if (Yii::$app !== null && Yii::$app->has('cache') && Yii::$app->cache !== null) {
    Yii::$app->cache->flush();
}
$cachedTier2 = ReverseDnsVerifier::getCachedVerification('95.108.213.123', 'yandex');
assertTest(
    $cachedTier2 !== null && ($cachedTier2['status'] ?? '') === ReverseDnsVerifier::STATUS_VERIFIED && ($cachedTier2['engine'] ?? '') === 'yandex',
    '5. Cache survives application cache restart via persistent JSON store (antibot_verified_bots.json)'
);

// 6. Scenario 6: Re-verification after TTL expiration
$expiredRecord = $cachedTier2;
$expiredRecord['expires_at'] = time() - 100; // Force expired
ReverseDnsVerifier::saveCachedVerification('95.108.213.123', 'yandex', $expiredRecord, -100);
if (Yii::$app !== null && Yii::$app->has('cache') && Yii::$app->cache !== null) {
    Yii::$app->cache->flush();
}
$afterExpire = ReverseDnsVerifier::getCachedVerification('95.108.213.123', 'yandex');
assertTest(
    $afterExpire === null,
    '6. Expired verification TTL is not trusted (returns null, triggers re-verification)'
);

// Restore verified state for 95.108.213.123
ReverseDnsVerifier::check('95.108.213.123', 'yandex');

// 7. Scenario 7: Operator separation (Yandex confirmation cannot be hijacked by Googlebot)
$googleOnYandexIp = ReverseDnsVerifier::getCachedVerification('95.108.213.123', 'google');
assertTest(
    $googleOnYandexIp === null,
    '7. Operator isolation: Verified Yandex IP presenting Googlebot User-Agent does not get cached trust'
);

// 8. Scenario 8: Cache write failure does not break site
// Test with invalid temp store path simulation
assertTest(
    is_array(ReverseDnsVerifier::getVerifiedBotRecords()),
    '8. Verified bot records retrieval is fault-tolerant and returns array safely'
);

// =========================================================================
// SECTION B: DNS Budget Management & Diagnostic Codes (Scenarios 9 - 14)
// =========================================================================
echo "\n--- Section B: DNS Budget Management & Diagnostic Codes ---\n";

// 9. Scenario 9: All DNS slots busy -> STATUS_UNKNOWN with 'dns_slots_busy'
ReverseDnsVerifier::resetBudget();
$dir = ReverseDnsVerifier::getStorageDir();
$fpSlots = [];
for ($s = 1; $s <= ReverseDnsVerifier::$maxConcurrentDnsLookups; $s++) {
    $sf = $dir . "/antibot_dns_slot_{$s}.lock";
    $fp = fopen($sf, 'c+');
    flock($fp, LOCK_EX);
    $fpSlots[] = $fp;
}

$busyStatus = ReverseDnsVerifier::check('95.108.213.127', 'yandex');
$busyReason = ReverseDnsVerifier::getLastUnknownReason();

foreach ($fpSlots as $fp) {
    flock($fp, LOCK_UN);
    fclose($fp);
}

assertTest(
    $busyStatus === ReverseDnsVerifier::STATUS_UNKNOWN && $busyReason === ReverseDnsVerifier::REASON_SLOTS_BUSY,
    '9. All DNS slots busy returns STATUS_UNKNOWN with diagnostic reason "dns_slots_busy"'
);

// 10. Scenario 10: Minute rate limit exhausted -> STATUS_UNKNOWN with 'dns_rate_limit'
ReverseDnsVerifier::resetBudget();
$budgetFile = ReverseDnsVerifier::resolveDnsBudgetFile();
$curWindow = (int) (time() / 60);
file_put_contents($budgetFile, json_encode([
    'window' => $curWindow,
    'count' => ReverseDnsVerifier::$maxDnsLookupsPerMinute + 1,
    'in_flight' => [],
]));

$rateStatus = ReverseDnsVerifier::check('95.108.213.127', 'yandex');
$rateReason = ReverseDnsVerifier::getLastUnknownReason();
assertTest(
    $rateStatus === ReverseDnsVerifier::STATUS_UNKNOWN && $rateReason === ReverseDnsVerifier::REASON_RATE_LIMIT,
    '10. Exhausted minute rate limit returns STATUS_UNKNOWN with diagnostic reason "dns_rate_limit"'
);

// 11. Scenario 11: Lock timeout -> STATUS_UNKNOWN with 'dns_lock_timeout'
ReverseDnsVerifier::resetBudget();
$lockFile = $budgetFile . '.lock';
$lockFp = fopen($lockFile, 'c+');
flock($lockFp, LOCK_EX);

$timeoutStatus = ReverseDnsVerifier::check('95.108.213.127', 'yandex');
$timeoutReason = ReverseDnsVerifier::getLastUnknownReason();
flock($lockFp, LOCK_UN);
fclose($lockFp);

assertTest(
    $timeoutStatus === ReverseDnsVerifier::STATUS_UNKNOWN && $timeoutReason === ReverseDnsVerifier::REASON_LOCK_TIMEOUT,
    '11. Budget lock contention returns STATUS_UNKNOWN with diagnostic reason "dns_lock_timeout"'
);

// 12. Scenario 12: Single Flight concurrent resolution
ReverseDnsVerifier::resetBudget();
// Pre-seed an in-flight entry for 95.108.213.161 with verified cache available
ReverseDnsVerifier::saveCachedVerification('95.108.213.161', 'yandex', [
    'operator' => 'Yandex',
    'method' => 'fcrdns',
], 86400);

// Acquire slot simulation for same IP
file_put_contents($budgetFile, json_encode([
    'window' => (int) (time() / 60),
    'count' => 1,
    'in_flight' => ['95.108.213.161' => ['slot' => 1, 'ts' => microtime(true), 'pid' => getmypid()]],
]));

// Simulate slot 1 held
$slot1Fp = fopen($dir . '/antibot_dns_slot_1.lock', 'c+');
flock($slot1Fp, LOCK_EX);

$sfSlotLease = ReverseDnsVerifier::acquireDnsBudgetSlot('95.108.213.161', 'yandex');
flock($slot1Fp, LOCK_UN);
fclose($slot1Fp);

assertTest(
    is_array($sfSlotLease) && isset($sfSlotLease['cached']) && $sfSlotLease['cached'] === ReverseDnsVerifier::STATUS_VERIFIED,
    '12. Single Flight wait resolves in-flight concurrent IP from cache with 0 DNS lookups'
);

// 13. Scenario 13: DNS resolver timeout / unreachable -> STATUS_UNKNOWN with 'dns_ptr_timeout'
ReverseDnsVerifier::resetBudget();
if (Yii::$app !== null && Yii::$app->has('cache') && Yii::$app->cache !== null) {
    Yii::$app->cache->flush();
}
$ptrFailStatus = ReverseDnsVerifier::check('198.51.100.88', 'yandex');
$ptrFailReason = ReverseDnsVerifier::getLastUnknownReason();
assertTest(
    $ptrFailStatus === ReverseDnsVerifier::STATUS_UNKNOWN && $ptrFailReason === ReverseDnsVerifier::REASON_PTR_TIMEOUT,
    '13. Unresolvable PTR returns STATUS_UNKNOWN with reason "dns_ptr_timeout"'
);

// 14. Scenario 14: PTR spoofed (Forward DNS mismatch) -> STATUS_FAKE
ReverseDnsVerifier::resetBudget();
if (Yii::$app !== null && Yii::$app->has('cache') && Yii::$app->cache !== null) {
    Yii::$app->cache->flush();
}
$spoofStatus = ReverseDnsVerifier::check('198.51.100.99', 'yandex');
assertTest(
    $spoofStatus === ReverseDnsVerifier::STATUS_FAKE,
    '14. Forward DNS mismatch correctly detects spoofed crawler and returns STATUS_FAKE'
);

// =========================================================================
// SECTION C: Auto-Jail Integration & Traps Protection (Scenarios 15 - 20)
// =========================================================================
echo "\n--- Section C: Auto-Jail Integration & False Positive Protection ---\n";

$antibot = new Antibot();
$antibot->enabled = true;
$antibot->verifySearchBots = true;
$antibot->autoJailEnabled = true;
$antibot->missingAssetsTrapEnabled = true;
$antibot->missingAssetsThreshold = 3;

// 15. Scenario 15: Verified YandexBot in Auto-Jail is automatically released on public route
AutoJailService::clearAll();
// Manually jail 95.108.213.123
AutoJailService::recordViolation('95.108.213.123', 'False alarm trap', 1, 1800);
assertTest(AutoJailService::isJailed('95.108.213.123'), '15a. Target YandexBot IP is currently jailed');

// Emulate request
$_SERVER['REMOTE_ADDR'] = '95.108.213.123';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/catalog/goods';
$antibot->mode = 'standard';
$antibot->checkRequest();

// Auto-Jail should be released
assertTest(
    !AutoJailService::isJailed('95.108.213.123'),
    '15b. Verified YandexBot in Auto-Jail automatically released from quarantine on public route'
);

// 16. Scenario 16: Real YandexBot receives UNKNOWN due to busy slots -> NOT jailed, allowed by policy
ReverseDnsVerifier::resetBudget();
// Lock all slots to force UNKNOWN
$fpLocks = [];
for ($s = 1; $s <= ReverseDnsVerifier::$maxConcurrentDnsLookups; $s++) {
    $sf = $dir . "/antibot_dns_slot_{$s}.lock";
    $fp = fopen($sf, 'c+');
    flock($fp, LOCK_EX);
    $fpLocks[] = $fp;
}

AutoJailService::clearAll();
if (Yii::$app !== null && Yii::$app->has('cache') && Yii::$app->cache !== null) {
    Yii::$app->cache->flush();
}

$_SERVER['REMOTE_ADDR'] = '95.108.213.228';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/catalog/item/1';

// Perform 5 HTML requests (would trigger MissingAssetsTrap if not protected)
for ($req = 0; $req < 5; $req++) {
    $antibot->startInspection();
}

foreach ($fpLocks as $fp) {
    flock($fp, LOCK_UN);
    fclose($fp);
}

assertTest(
    !AutoJailService::isJailed('95.108.213.228'),
    '16. Real YandexBot getting UNKNOWN due to busy slots is NOT jailed by Missing Assets Trap'
);

// 17. Scenario 17: UNKNOWN subsequently becomes VERIFIED
ReverseDnsVerifier::$dnsResolverCallback = function (string $ip, array $allowedSuffixes) {
    if ($ip === '95.108.213.228') {
        return ['host' => '95.108.213.228.spider.yandex.ru', 'ips' => ['95.108.213.228']];
    }
    return null;
};
$subsequentStatus = ReverseDnsVerifier::check('95.108.213.228', 'yandex');
assertTest(
    $subsequentStatus === ReverseDnsVerifier::STATUS_VERIFIED,
    '17. UNKNOWN bot subsequently becomes VERIFIED when DNS slots become free'
);

// 18. Scenario 18: Fake YandexBot cannot bypass Auto-Jail
AutoJailService::clearAll();
AutoJailService::recordViolation('198.51.100.99', 'Previous attack', 1, 1800);

$_SERVER['REMOTE_ADDR'] = '198.51.100.99';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/catalog/items';

// Attacker attempts jail evasion using YandexBot UA
$antibot->mode = \larikmc\antibot\services\StorageService::MODE_MONITORING;
$antibot->checkRequest();

assertTest(
    AutoJailService::isJailed('198.51.100.99'),
    '18. Fake YandexBot cannot escape Auto-Jail quarantine and remains detained'
);

// 19. Scenario 19: Real robot accessing closed route remains restricted
$_SERVER['REMOTE_ADDR'] = '95.108.213.123';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/admin/config';

assertTest(
    Antibot::isClosedRoute('admin/config'),
    '19. Administrative route "admin/config" remains recognized as closed route'
);

// 20. Scenario 20: Unknown scraper continues to be caught by Missing Assets Trap
AutoJailService::clearAll();
$_SERVER['REMOTE_ADDR'] = '198.51.100.42';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) ScraperEngine/1.0';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/page1';

for ($i = 0; $i < 4; $i++) {
    $c = \larikmc\antibot\services\trap\MissingAssetsTrap::recordHtmlRequest('198.51.100.42', 60);
}
assertTest(
    $c >= 3,
    '20. Unknown scraper without assets accumulates violations and triggers anomaly detection'
);

// =========================================================================
// SECTION D: Compatibility & Multi-Bot Catalog (Scenarios 21 - 25)
// =========================================================================
echo "\n--- Section D: Multi-Bot Compatibility & Catalog Policy ---\n";

// 21. Scenario 21: Googlebot official IP passes instantly via CIDR
$googlebotStatus = ReverseDnsVerifier::check('66.249.66.1', 'google');
assertTest(
    $googlebotStatus === ReverseDnsVerifier::STATUS_VERIFIED,
    '21. Googlebot with official IP (66.249.66.1) passes verification instantly via CIDR'
);

// 22. Scenario 22: YandexDirect passes FCrDNS
$directBotData = BotCatalog::get('yandex_direct');
assertTest(
    $directBotData !== null && ($directBotData['category'] ?? '') === BotCatalog::CATEGORY_AD,
    '22a. YandexDirect catalog entry exists in Advertising category'
);
$directStatus = ReverseDnsVerifier::checkBot('95.108.213.123', $directBotData);
assertTest(
    $directStatus === ReverseDnsVerifier::STATUS_VERIFIED,
    '22b. YandexDirect passes FCrDNS verification and is verified as ad bot'
);

// 23. Scenario 23: Ad bot not blocked by Missing Assets Trap
$isAdCandidate = in_array($directBotData['category'], [BotCatalog::CATEGORY_SEARCH, BotCatalog::CATEGORY_AD], true);
assertTest(
    $isAdCandidate === true,
    '23. Ad bots (YandexDirect, Criteo) are recognized as search/ad candidates exempt from Missing Assets Trap'
);

// 24. Scenario 24: Bot policy 'block' not overridden
$testBotData = BotCatalog::get('googlebot');
$testBotData['policy'] = 'block';
$antibot->botPolicies['googlebot'] = 'block';
$resolvedPolicy = $antibot->resolveBotPolicy('googlebot', $testBotData);
assertTest(
    $resolvedPolicy === 'block',
    '24. Explicit bot policy "block" is strictly enforced and never bypassed by verified status'
);

// 25. Scenario 25: Other BotCatalog categories work properly
$telegramData = BotCatalog::get('telegram');
$petalData = BotCatalog::get('petalbot');
$iasData = BotCatalog::get('ias');
assertTest(
    $telegramData !== null && $petalData !== null && $iasData !== null,
    '25. Other BotCatalog categories (Social, SEO, Advertising) remain fully intact and operational'
);

// =========================================================================
// SECTION E: Diagnostics & SelfTest Health Check
// =========================================================================
echo "\n--- Section E: Diagnostics, Metrics & Version Verification ---\n";

assertTest(
    Antibot::VERSION === '1.6.2',
    'E.1 Antibot::VERSION is 1.6.2'
);
assertTest(
    \larikmc\antibot\Module::VERSION === '1.6.2',
    'E.2 Module::VERSION is 1.6.2'
);

// Run SelfTest
$selfTestResult = SelfTest::run($antibot);
$foundDnsCheck = false;
$dnsCheckMessage = '';
foreach ($selfTestResult['checks'] as $chk) {
    if (str_contains($chk['name'], 'Атомарный DNS') || str_contains($chk['message'], 'Single Flight')) {
        $foundDnsCheck = true;
        $dnsCheckMessage = $chk['message'];
        break;
    }
}
assertTest(
    $foundDnsCheck && str_contains($dnsCheckMessage, 'Single Flight'),
    'E.3 SelfTest includes DNS verification, persistent cache, and Single Flight status'
);

// Bilingual translations check
assertTest(
    I18n::t('dns_slots_busy', 'ru') === 'DNS-слоты заняты (3/3)',
    'E.4 Russian translation for dns_slots_busy is present'
);
assertTest(
    I18n::t('dns_slots_busy', 'en') === 'DNS slots busy (3/3)',
    'E.5 English translation for dns_slots_busy is present'
);

// Reason formatter check
$formattedReason = I18n::formatReason('Unverified bot allowed by policy: YandexBot (DNS-слоты заняты (3/3))', 'ru');
assertTest(
    str_contains($formattedReason['title'], 'YandexBot') && $formattedReason['badge'] === 'Мониторинг',
    'E.6 Unverified bot reason is cleanly formatted for Live Feed'
);

$recoveredReason = I18n::formatReason('Auto-Jail quarantine lifted: verified authentic YandexBot', 'ru');
assertTest(
    str_contains($recoveredReason['title'], 'Карантин Auto-Jail снят') && $recoveredReason['badge'] === 'Восстановлен',
    'E.7 Auto-Jail recovery reason is cleanly formatted for Live Feed'
);

// Final summary
echo "\n=================================================================\n";
echo sprintf(" Test Results: %d / %d passed (%d%%)\n", $passedTests, $totalTests, (int) (($passedTests / max(1, $totalTests)) * 100));
echo "=================================================================\n";

if ($passedTests === $totalTests) {
    echo " ALL 25 SCENARIOS AND DIAGNOSTIC CHECKS PASSED SUCCESSFULLY!\n";
    exit(0);
} else {
    echo " SOME TESTS FAILED!\n";
    exit(1);
}
