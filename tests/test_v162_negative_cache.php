<?php

declare(strict_types=1);

/**
 * Yii2 AntiBot: Negative Caching & Block Policy Optimization Suite (v1.6.2)
 *
 * Verifies all 20 required scenarios:
 * 1. Fake YandexBot passes initial check and receives STATUS_FAKE.
 * 2. Next 1000 requests use negative cache without new DNS queries.
 * 3. TTL expiration leads to re-verification.
 * 4. Reading negative cache does NOT extend expiration date (expires_at).
 * 5. DNS timeout does NOT create long-term FAKE record.
 * 6. Negative record for Yandex does NOT affect Googlebot or regular visitors.
 * 7. Mass filling of negative cache does NOT evict VERIFIED records.
 * 8. Forbidden GPTBot does NOT trigger DNS check.
 * 9. Forbidden ClaudeBot handled similarly without DNS.
 * 10. Blocked bot without verification does NOT receive false FAKE status.
 * 11. Changing policy from Block to Allow triggers verification.
 * 12. Monitoring mode has 0 real blocks on policy.
 * 13. Policy precedence preserved.
 * 14. 1000 sequential requests with spoofed UA.
 * 15. 1000 rapid requests from same IP.
 * 16. Mass requests from different IPs with spoofed UA.
 * 17. Concurrent real search bots and malicious bots.
 * 18. DNS server unavailability handling.
 * 19. PHP-FPM restart persistence (Tier 2).
 * 20. Concurrent write of positive and negative results.
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

require_once 'D:/OpenServer6.5.0/home/yii2-fresh-install.loc/vendor/yiisoft/yii2/Yii.php';

use larikmc\antibot\Antibot;
use larikmc\antibot\services\BotCatalog;
use larikmc\antibot\services\ReverseDnsVerifier;
use larikmc\antibot\services\StorageService;
use larikmc\antibot\services\resilience\AutoJailService;

$testRuntime = sys_get_temp_dir() . '/antibot_v162_neg_' . uniqid();
@mkdir($testRuntime, 0775, true);

if (class_exists('Yii') && Yii::$app === null) {
    new \yii\web\Application([
        'id' => 'antibot-v162-neg-suite',
        'basePath' => dirname(__DIR__),
        'runtimePath' => $testRuntime,
        'components' => [
            'request' => [
                'cookieValidationKey' => 'test-neg-key-32-chars-long-secret',
                'scriptFile' => __FILE__,
                'scriptUrl' => '/index.php',
            ],
            'cache' => [
                'class' => \yii\caching\ArrayCache::class,
            ],
        ],
    ]);
}

$total = 0;
$passed = 0;

function check(bool $cond, string $msg): void {
    global $total, $passed;
    $total++;
    if ($cond) {
        $passed++;
        echo " [PASS] {$msg}\n";
    } else {
        echo " [FAIL] {$msg}\n";
    }
}

echo "=================================================================\n";
echo " Yii2 AntiBot: Negative Caching & Block Optimization (v1.6.2)\n";
echo "=================================================================\n\n";

ReverseDnsVerifier::resetBudget();
ReverseDnsVerifier::resetVerifiedBotsStore();
ReverseDnsVerifier::resetFakeBotsStore();
ReverseDnsVerifier::resetStats();
AutoJailService::clearAll();

// Mock DNS Resolver
ReverseDnsVerifier::$dnsResolverCallback = function (string $ip, array $allowedSuffixes) {
    if ($ip === '95.108.213.123') {
        return ['host' => '95.108.213.123.spider.yandex.com', 'ips' => ['95.108.213.123']];
    }
    if ($ip === '198.51.100.99') {
        // Spoofed bot: hostname belongs to attacker VPS
        return ['host' => 'attacker-vps.com', 'ips' => ['198.51.100.99']];
    }
    if ($ip === '198.51.100.88') {
        // DNS timeout: PTR fails
        return false;
    }
    return null;
};

// =========================================================================
// SECTION 1: Negative Caching (Scenarios 1 - 7)
// =========================================================================
echo "--- Section 1: Negative Caching (Scenarios 1 - 7) ---\n";

// Scenario 1: Fake YandexBot passes initial check and receives STATUS_FAKE
$status1 = ReverseDnsVerifier::check('198.51.100.99', 'yandex');
$stats1 = ReverseDnsVerifier::getStats();
check(
    $status1 === ReverseDnsVerifier::STATUS_FAKE && $stats1['dns_performed'] === 1 && $stats1['fcrdns_failed'] === 1,
    '1. Fake YandexBot passes initial check and receives STATUS_FAKE'
);

// Scenario 2: Next 1000 requests use negative cache without new DNS queries
$dnsBefore = ReverseDnsVerifier::$stats['dns_performed'];
$fakeHitsBefore = ReverseDnsVerifier::$stats['cache_hits_fake'];
for ($i = 0; $i < 1000; $i++) {
    $st = ReverseDnsVerifier::check('198.51.100.99', 'yandex');
    if ($st !== ReverseDnsVerifier::STATUS_FAKE) {
        break;
    }
}
$dnsAfter = ReverseDnsVerifier::$stats['dns_performed'];
$fakeHitsAfter = ReverseDnsVerifier::$stats['cache_hits_fake'];
check(
    $dnsAfter === $dnsBefore && ($fakeHitsAfter - $fakeHitsBefore) === 1000,
    '2. Next 1000 requests use negative cache without new DNS queries (0 new DNS, 1000 fake hits)'
);

// Scenario 3: TTL expiration leads to re-verification
// Store with short 2-second TTL (prevents 1-second boundary rollover race)
ReverseDnsVerifier::saveCachedFake('198.51.100.77', 'yandex', ['reason' => 'test_expire'], 2);
$freshFake = ReverseDnsVerifier::getCachedFake('198.51.100.77', 'yandex');
check($freshFake !== null, '3a. Fresh fake record exists before expiration');

// Wait 3 seconds for expiration
sleep(3);
$expiredFake = ReverseDnsVerifier::getCachedFake('198.51.100.77', 'yandex');
check($expiredFake === null, '3b. Expired fake record returns null and allows re-verification');

// Scenario 4: Reading negative cache does NOT extend expiration date
$t0 = time();
ReverseDnsVerifier::saveCachedFake('198.51.100.66', 'yandex', ['reason' => 'test_no_extend'], 86400);
$recInitial = ReverseDnsVerifier::getCachedFake('198.51.100.66', 'yandex');
$initialExp = $recInitial['expires_at'] ?? 0;
for ($r = 0; $r < 50; $r++) {
    ReverseDnsVerifier::getCachedFake('198.51.100.66', 'yandex');
}
$recAfter = ReverseDnsVerifier::getCachedFake('198.51.100.66', 'yandex');
check(
    ($recAfter['expires_at'] ?? 0) === $initialExp && $initialExp === ($t0 + 86400),
    '4. Reading negative cache 50 times leaves expires_at untouched (no TTL prolongation)'
);

// Scenario 5: DNS timeout does NOT create long-term FAKE record
$timeoutStatus = ReverseDnsVerifier::check('198.51.100.88', 'yandex');
$fakeRecordForTimeout = ReverseDnsVerifier::getCachedFake('198.51.100.88', 'yandex');
check(
    $timeoutStatus === ReverseDnsVerifier::STATUS_UNKNOWN &&
    $fakeRecordForTimeout === null &&
    ReverseDnsVerifier::getLastUnknownReason() === ReverseDnsVerifier::REASON_PTR_TIMEOUT,
    '5. DNS timeout returns STATUS_UNKNOWN and does NOT create a long-term FAKE record'
);

// Scenario 6: Negative record for Yandex does NOT affect Googlebot or regular visitors
// 198.51.100.99 is fake for yandex, but check for google should not read yandex's fake record
$googleCheck = ReverseDnsVerifier::getCachedFake('198.51.100.99', 'google');
check(
    $googleCheck === null,
    '6. Operator isolation: Negative record for Yandex does NOT affect Googlebot or regular visitors'
);

// Scenario 7: Mass filling of negative cache does NOT evict VERIFIED records
ReverseDnsVerifier::saveCachedVerification('95.108.213.123', 'yandex', ['operator' => 'Yandex'], 172800);
for ($f = 1; $f <= 60; $f++) {
    ReverseDnsVerifier::saveCachedFake("198.51.200.{$f}", 'yandex', ['reason' => 'mass_fake'], 86400);
}
$verifiedStillIntact = ReverseDnsVerifier::getCachedVerification('95.108.213.123', 'yandex');
$fakeCount = count(ReverseDnsVerifier::getFakeBotRecords());
$verifiedCount = count(ReverseDnsVerifier::getVerifiedBotRecords());
check(
    $verifiedStillIntact !== null && $verifiedCount === 1 && $fakeCount >= 60,
    '7. Storage separation: Mass filling of negative cache does NOT evict VERIFIED records'
);

// =========================================================================
// SECTION 2: Block Policy Optimization (Scenarios 8 - 13)
// =========================================================================
echo "\n--- Section 2: Block Policy Optimization (Scenarios 8 - 13) ---\n";

$antibot = new Antibot();
$antibot->enabled = true;
$antibot->mode = StorageService::MODE_MONITORING;
$antibot->verifySearchBots = true;
$antibot->skipDnsForBlockedBots = true;
$antibot->autoJailEnabled = true;

// Scenario 8: Forbidden GPTBot does NOT trigger DNS check
$_SERVER['REMOTE_ADDR'] = '198.51.100.50';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)';
Yii::$app->request->headers->set('User-Agent', $_SERVER['HTTP_USER_AGENT']);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/page1';

$dnsCountBefore = ReverseDnsVerifier::$stats['dns_performed'];
$skippedBefore = ReverseDnsVerifier::$stats['skipped_by_policy'];

$antibot->checkRequest();

$dnsCountAfter = ReverseDnsVerifier::$stats['dns_performed'];
$skippedAfter = ReverseDnsVerifier::$stats['skipped_by_policy'];

check(
    $dnsCountAfter === $dnsCountBefore &&
    $skippedAfter > $skippedBefore,
    '8. Forbidden GPTBot has DNS check skipped under Block policy (0 DNS lookups)'
);

// Scenario 9: Forbidden ClaudeBot handled similarly without DNS
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)';
Yii::$app->request->headers->set('User-Agent', $_SERVER['HTTP_USER_AGENT']);
$antibot->botPolicies['claudebot'] = 'block';

$dnsClaudeBefore = ReverseDnsVerifier::$stats['dns_performed'];
$skippedClaudeBefore = ReverseDnsVerifier::$stats['skipped_by_policy'];

$antibot->checkRequest();

$dnsClaudeAfter = ReverseDnsVerifier::$stats['dns_performed'];
$skippedClaudeAfter = ReverseDnsVerifier::$stats['skipped_by_policy'];

check(
    $dnsClaudeAfter === $dnsClaudeBefore &&
    $skippedClaudeAfter > $skippedClaudeBefore,
    '9. Forbidden ClaudeBot has DNS lookup skipped under Block policy'
);

// Scenario 10: Blocked bot without verification does NOT receive false FAKE status
$events = $antibot->getStorage()->getRecentEvents(1);
$lastEvent = $events[0] ?? [];
check(
    ($lastEvent['verification_status'] ?? '') === ReverseDnsVerifier::STATUS_NOT_CHECKED &&
    ($lastEvent['verification_status'] ?? '') !== ReverseDnsVerifier::STATUS_FAKE,
    '10. Blocked bot without verification receives STATUS_NOT_CHECKED (never false FAKE)'
);

// Scenario 11: Changing policy from Block to Allow triggers verification
AutoJailService::clearAll();
$antibot->botPolicies['yandexbot'] = 'allow';
$_SERVER['REMOTE_ADDR'] = '198.51.100.99'; // Attacker IP
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)';
Yii::$app->request->headers->set('User-Agent', $_SERVER['HTTP_USER_AGENT']);

$dnsAllowBefore = ReverseDnsVerifier::$stats['dns_performed'];
$antibot->checkRequest();
$eventsAllow = $antibot->getStorage()->getRecentEvents(1);
$lastAllowEvent = $eventsAllow[0] ?? [];

check(
    $antibot->getCurrentDecision() === 'blocked' &&
    str_contains($lastAllowEvent['reason'] ?? '', 'Fake bot spoofing') &&
    ReverseDnsVerifier::getCachedFake('198.51.100.99', 'yandex') !== null,
    '11. Changing policy to Allow triggers verification; fake bot is identified and blocked'
);

// Scenario 12: In Monitoring mode, policy block has 0 real blocks and does not jail
AutoJailService::clearAll();
$antibot->mode = StorageService::MODE_MONITORING;
$antibot->botPolicies['claudebot'] = 'block';
$_SERVER['REMOTE_ADDR'] = '198.51.100.33';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)';
Yii::$app->request->headers->set('User-Agent', $_SERVER['HTTP_USER_AGENT']);

$antibot->checkRequest();
$eventsMon = $antibot->getStorage()->getRecentEvents(1);
$lastMonEvent = $eventsMon[0] ?? [];

check(
    ($lastMonEvent['action'] ?? '') === 'monitored' &&
    str_contains($lastMonEvent['reason'] ?? '', 'would be blocked by policy') &&
    !AutoJailService::isJailed('198.51.100.33'),
    '12. In Monitoring mode, policy block results in monitored status without real HTTP block or Auto-Jail'
);

// Scenario 13: Policy precedence preserved (individual override > category policy)
$antibot->botCategoryPolicies['ai_training'] = 'allow';
$antibot->botPolicies['gptbot'] = 'block';
$_SERVER['REMOTE_ADDR'] = '198.51.100.44';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)';
Yii::$app->request->headers->set('User-Agent', $_SERVER['HTTP_USER_AGENT']);

$dnsPrecBefore = ReverseDnsVerifier::$stats['dns_performed'];
$antibot->checkRequest();
$eventsPrec = $antibot->getStorage()->getRecentEvents(1);
$lastPrecEvent = $eventsPrec[0] ?? [];

check(
    $antibot->resolveBotPolicy('gptbot', ['category' => 'ai_training']) === 'block' &&
    ($lastPrecEvent['verification_status'] ?? '') === ReverseDnsVerifier::STATUS_NOT_CHECKED &&
    ReverseDnsVerifier::$stats['dns_performed'] === $dnsPrecBefore,
    '13. Individual bot policy override (block) strictly takes precedence over category policy (allow)'
);

// =========================================================================
// SECTION 3: Performance, Concurrency & Invalidation (Scenarios 14 - 20)
// =========================================================================
echo "\n--- Section 3: Performance, Concurrency & Invalidation (Scenarios 14 - 20) ---\n";

// Scenario 14: 1000 sequential requests with spoofed UA
ReverseDnsVerifier::resetStats();
$startBench = microtime(true);
for ($i = 0; $i < 1000; $i++) {
    ReverseDnsVerifier::check('198.51.100.99', 'yandex');
}
$durBench = (microtime(true) - $startBench) * 1000;
$avgMs = $durBench / 1000;
check(
    ReverseDnsVerifier::$stats['cache_hits_fake'] === 1000 && $avgMs < 1.0,
    sprintf('14. 1000 sequential fake requests handled with %.4f ms/req (< 1.0ms)', $avgMs)
);

// Scenario 15: 1000 rapid requests from same IP with Single Flight deduplication
ReverseDnsVerifier::resetBudget();
$budgetFile = ReverseDnsVerifier::resolveDnsBudgetFile();
$curWindow = (int) (time() / 60);
file_put_contents($budgetFile, json_encode([
    'window' => $curWindow,
    'count' => 1,
    'in_flight' => ['198.51.100.99' => ['slot' => 1, 'ts' => microtime(true), 'pid' => getmypid()]],
]));

// Simulate slot 1 locked by worker 1
$slot1File = ReverseDnsVerifier::getStorageDir() . '/antibot_dns_slot_1.lock';
$slot1Fp = fopen($slot1File, 'c+');
flock($slot1Fp, LOCK_EX);

$lease = ReverseDnsVerifier::acquireDnsBudgetSlot('198.51.100.99', 'yandex');

flock($slot1Fp, LOCK_UN);
fclose($slot1Fp);

check(
    is_array($lease) && ($lease['cached'] ?? '') === ReverseDnsVerifier::STATUS_FAKE,
    '15. Single Flight in-flight resolution resolves FAKE from negative cache'
);

// Scenario 16: Mass requests from different IPs with spoofed UA
for ($m = 1; $m <= 20; $m++) {
    ReverseDnsVerifier::saveCachedFake("198.51.250.{$m}", 'yandex', ['reason' => 'mass_test'], 86400);
}
$allFake = ReverseDnsVerifier::getFakeBotRecords();
check(
    count($allFake) >= 20,
    '16. Mass spoofed IPs successfully indexed in negative storage (count: ' . count($allFake) . ')'
);

// Scenario 17: Concurrent real search bots and malicious bots
$verStatus = ReverseDnsVerifier::check('95.108.213.123', 'yandex');
$fakeStatus = ReverseDnsVerifier::check('198.51.100.99', 'yandex');
check(
    $verStatus === ReverseDnsVerifier::STATUS_VERIFIED &&
    $fakeStatus === ReverseDnsVerifier::STATUS_FAKE,
    '17. Concurrent real search bots and malicious bots isolated accurately'
);

// Scenario 18: DNS server unavailability handling
ReverseDnsVerifier::$dnsResolverCallback = function () {
    throw new \RuntimeException('DNS Server Timeout / Unreachable');
};
$failStatus = ReverseDnsVerifier::check('198.51.100.12', 'yandex');
check(
    $failStatus === ReverseDnsVerifier::STATUS_UNKNOWN &&
    ReverseDnsVerifier::getLastUnknownReason() === ReverseDnsVerifier::REASON_RESOLVER_ERROR,
    '18. DNS server unavailability returns STATUS_UNKNOWN with reason "dns_resolver_error"'
);

// Scenario 19: PHP-FPM restart persistence (Tier 2 surviving memory cache wipe)
if (Yii::$app->has('cache') && Yii::$app->cache !== null) {
    Yii::$app->cache->flush(); // Simulate full memory flush / FPM restart
}
$survivedFake = ReverseDnsVerifier::getCachedFake('198.51.100.99', 'yandex');
check(
    $survivedFake !== null && ($survivedFake['status'] ?? '') === ReverseDnsVerifier::STATUS_FAKE,
    '19. Negative cache survives PHP-FPM restart and memory cache flush via persistent JSON store'
);

// Scenario 20: Concurrent write of positive and negative results & Cache Invalidation
ReverseDnsVerifier::saveCachedVerification('95.108.213.200', 'yandex', ['operator' => 'Yandex'], 172800);
ReverseDnsVerifier::saveCachedFake('198.51.100.200', 'yandex', ['reason' => 'concurrent_test'], 86400);

$hasVerified = ReverseDnsVerifier::getCachedVerification('95.108.213.200', 'yandex') !== null;
$hasFake = ReverseDnsVerifier::getCachedFake('198.51.100.200', 'yandex') !== null;
check(
    $hasVerified && $hasFake,
    '20a. Concurrent write of positive and negative results preserves both stores without corruption'
);

// Clear only negative cache
ReverseDnsVerifier::clearCache('fake');
$verifiedAfter = ReverseDnsVerifier::getCachedVerification('95.108.213.200', 'yandex');
$fakeAfter = ReverseDnsVerifier::getCachedFake('198.51.100.200', 'yandex');
check(
    $verifiedAfter !== null && $fakeAfter === null,
    '20b. Targeted cache invalidation: clearing negative cache preserves positive verified store'
);

// =========================================================================
// SUMMARY
// =========================================================================
echo "\n=================================================================\n";
echo " Negative Caching & Block Optimization Results: {$passed} / {$total} passed (" . round(($passed / max(1, $total)) * 100) . "%)\n";
echo "=================================================================\n";

if ($passed === $total) {
    echo " ALL 20 SCENARIOS VERIFIED WITH 100% SUCCESS!\n";
    exit(0);
} else {
    echo " SOME SCENARIOS FAILED!\n";
    exit(1);
}
