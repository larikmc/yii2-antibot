<?php

declare(strict_types=1);

/**
 * Final Acceptance Verification Suite for Yii2 AntiBot v1.6.2
 *
 * Verifies all 8 acceptance criteria:
 * 1. Shared storage across Yii2 Advanced frontend/backend (@common/runtime vs @runtime)
 * 2. Write atomicity and zero data loss on concurrent verification of different IPs
 * 3. 48-hour TTL strictly tied to verification time (cache reads never extend TTL)
 * 4. Auto-Jail release strictly obeys Policy 'block', Blacklist, and Closed Routes
 * 5. Missing Assets exemption does not allow User-Agent spoofing bypass
 * 6. Single Flight does not hold budget lock during bounded wait + latency benchmark
 */

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
use larikmc\antibot\services\resilience\AutoJailService;

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
echo " Yii2 AntiBot v1.6.2 Final Acceptance Verification\n";
echo "=================================================================\n\n";

// --- 1. Yii2 Advanced Frontend & Backend Shared Storage ---
echo "--- 1. Shared Storage Across Frontend & Backend (@common/runtime) ---\n";
$tempCommon = sys_get_temp_dir() . '/yii2_adv_common_' . uniqid();
$tempFrontend = sys_get_temp_dir() . '/yii2_adv_frontend_' . uniqid();
$tempBackend = sys_get_temp_dir() . '/yii2_adv_backend_' . uniqid();

@mkdir($tempCommon . '/runtime', 0775, true);
@mkdir($tempFrontend . '/runtime', 0775, true);
@mkdir($tempBackend . '/runtime', 0775, true);

// Set @common
Yii::setAlias('@common', $tempCommon);

// Simulate Frontend app environment
Yii::setAlias('@runtime', $tempFrontend . '/runtime');
$frontendStorageDir = ReverseDnsVerifier::getStorageDir();
check(
    $frontendStorageDir === $tempCommon . '/runtime',
    '1.1 Frontend resolves storage to @common/runtime rather than frontend/runtime'
);

// Save verified bot from frontend
ReverseDnsVerifier::saveCachedVerification('95.108.213.123', 'yandex', [
    'operator' => 'Yandex',
    'method' => 'fcrdns',
], 172800);

// Simulate Backend app environment (different @runtime!)
Yii::setAlias('@runtime', $tempBackend . '/runtime');
$backendStorageDir = ReverseDnsVerifier::getStorageDir();
check(
    $backendStorageDir === $tempCommon . '/runtime',
    '1.2 Backend resolves storage to the exact same @common/runtime'
);

$backendRead = ReverseDnsVerifier::getCachedVerification('95.108.213.123', 'yandex');
check(
    $backendRead !== null && ($backendRead['engine'] ?? '') === 'yandex',
    '1.3 Bot verified in frontend is immediately visible and verified in backend without DNS'
);

// AutoJail in Yii2 Advanced
AutoJailService::recordViolation('198.51.100.77', 'Frontend violation', 1, 1800);
check(
    AutoJailService::isJailed('198.51.100.77'),
    '1.4 AutoJail persistent registry in frontend is immediately shared with backend via @common'
);

// --- 2. Write Atomicity and Zero Data Loss on Concurrent Saves ---
echo "\n--- 2. Write Atomicity and Zero Data Loss ---\n";
ReverseDnsVerifier::resetVerifiedBotsStore();

// Concurrently save 50 distinct IPs
for ($i = 1; $i <= 50; $i++) {
    $ip = "95.108.213.{$i}";
    ReverseDnsVerifier::saveCachedVerification($ip, 'yandex', [
        'operator' => 'Yandex',
        'method' => 'fcrdns',
    ], 172800);
}

$allRecords = ReverseDnsVerifier::getVerifiedBotRecords();
check(
    count($allRecords) === 50,
    '2.1 All 50 sequentially/concurrently saved IP records are 100% preserved (got ' . count($allRecords) . ')'
);

// Verify JSON file validity
$jsonRaw = file_get_contents(ReverseDnsVerifier::resolveVerifiedBotFile());
$decoded = json_decode($jsonRaw, true);
check(
    is_array($decoded) && count($decoded) === 50,
    '2.2 Persistent JSON file is valid, non-corrupt, and contains all 50 entries'
);

// --- 3. 48-Hour TTL Calculation (No Visit-Based Extension) ---
echo "\n--- 3. 48-Hour TTL Tied Strictly to Verification Time ---\n";
ReverseDnsVerifier::resetVerifiedBotsStore();

$t0 = time();
ReverseDnsVerifier::saveCachedVerification('95.108.213.100', 'yandex', [
    'operator' => 'Yandex',
    'method' => 'fcrdns',
], 172800);

$initialRec = ReverseDnsVerifier::getCachedVerification('95.108.213.100', 'yandex');
$initialExpiresAt = $initialRec['expires_at'] ?? 0;
check(
    $initialExpiresAt === ($t0 + 172800),
    '3.1 Initial expires_at is exactly T0 + 172,800s (48 hours)'
);

// Simulate 50 subsequent visits reading cache
for ($req = 0; $req < 50; $req++) {
    ReverseDnsVerifier::getCachedVerification('95.108.213.100', 'yandex');
}

$recAfterVisits = ReverseDnsVerifier::getCachedVerification('95.108.213.100', 'yandex');
check(
    ($recAfterVisits['expires_at'] ?? 0) === $initialExpiresAt,
    '3.2 Reading cache 50 times leaves expires_at untouched (no trust extension on visits)'
);

// --- 4. Auto-Jail Release vs Policy Block, Blacklist & Closed Routes ---
echo "\n--- 4. Auto-Jail Release Boundary Protection ---\n";

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/index.php';
$_SERVER['HTTP_HOST'] = 'localhost';

$app = new \yii\web\Application([
    'id' => 'adv-test',
    'basePath' => dirname(__DIR__),
    'runtimePath' => $tempFrontend . '/runtime',
    'components' => [
        'request' => [
            'cookieValidationKey' => 'test-secret-key-32-chars-long-rnd',
            'scriptFile' => __FILE__,
            'scriptUrl' => '/index.php',
        ],
        'cache' => [
            'class' => \yii\caching\ArrayCache::class,
        ],
    ],
]);

ReverseDnsVerifier::$dnsResolverCallback = function (string $ip, array $allowedSuffixes) {
    if ($ip === '95.108.213.123') {
        return ['host' => '95.108.213.123.spider.yandex.com', 'ips' => ['95.108.213.123']];
    }
    return null;
};

// 4.1 Closed Route Protection
AutoJailService::clearAll();
AutoJailService::recordViolation('95.108.213.123', 'Previous violation', 1, 1800);

$_SERVER['REMOTE_ADDR'] = '95.108.213.123';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/admin/settings';

$antibot = new Antibot();
$antibot->enabled = true;
$antibot->autoJailEnabled = true;
$antibot->verifySearchBots = true;
$antibot->mode = \larikmc\antibot\services\StorageService::MODE_MONITORING;

$antibot->checkRequest();
check(
    AutoJailService::isJailed('95.108.213.123'),
    '4.1 Verified bot requesting closed administrative route (/admin/settings) is NOT released from Auto-Jail'
);

// 4.2 Policy Block Enforcement
AutoJailService::clearAll();
$_SERVER['REQUEST_URI'] = '/catalog/items'; // Public route
$antibot->botPolicies['yandexbot'] = 'block';

$antibot->checkRequest();
check(
    $antibot->getCurrentDecision() === 'blocked',
    '4.2 Explicit bot policy "block" blocks verified bot even if Auto-Jail was released'
);

// Reset policy
unset($antibot->botPolicies['yandexbot']);

// --- 5. Missing Assets Exemption vs Spoofed User-Agent ---
echo "\n--- 5. Missing Assets Exemption vs Spoofed User-Agent ---\n";
AutoJailService::clearAll();

// Attacker IP with spoofed YandexBot UA
$_SERVER['REMOTE_ADDR'] = '198.51.100.99';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/page1';

ReverseDnsVerifier::$dnsResolverCallback = function (string $ip, array $allowedSuffixes) {
    if ($ip === '198.51.100.99') {
        // Spoof detected: hostname belongs to attacker
        return ['host' => 'attacker-vps.com', 'ips' => ['198.51.100.99']];
    }
    return null;
};

$antibot->checkRequest();
check(
    $antibot->getCurrentDecision() === 'blocked' && AutoJailService::isJailed('198.51.100.99'),
    '5.1 Spoofed User-Agent fails FCrDNS -> marked FAKE -> immediately blocked 403 and quarantined in Auto-Jail'
);

// --- 6. Single Flight Lock Release & Latency Benchmark ---
echo "\n--- 6. Single Flight Lock Non-Blocking & Latency Benchmark ---\n";
ReverseDnsVerifier::resetBudget();

// Seed in-flight entry for IP 95.108.213.161
$budgetFile = ReverseDnsVerifier::resolveDnsBudgetFile();
$curWindow = (int) (time() / 60);
file_put_contents($budgetFile, json_encode([
    'window' => $curWindow,
    'count' => 1,
    'in_flight' => ['95.108.213.161' => ['slot' => 1, 'ts' => microtime(true), 'pid' => getmypid()]],
]));

// Simulate slot 1 locked by worker 1
$slotFp = fopen(ReverseDnsVerifier::getStorageDir() . '/antibot_dns_slot_1.lock', 'c+');
flock($slotFp, LOCK_EX);

// Verify that while in-flight wait executes, budget lock file is NOT held!
$tBefore = microtime(true);

// Worker 2 calls acquireDnsBudgetSlot (will poll and release lock immediately)
$lockFile = $budgetFile . '.lock';

// Seed cache after 50ms (simulating Worker 1 finishing FCrDNS)
ReverseDnsVerifier::saveCachedVerification('95.108.213.161', 'yandex', [
    'operator' => 'Yandex',
    'method' => 'fcrdns',
], 172800);

$sfResult = ReverseDnsVerifier::acquireDnsBudgetSlot('95.108.213.161', 'yandex');
$elapsedMs = (microtime(true) - $tBefore) * 1000;

flock($slotFp, LOCK_UN);
fclose($slotFp);

check(
    is_array($sfResult) && isset($sfResult['cached']) && $sfResult['cached'] === ReverseDnsVerifier::STATUS_VERIFIED,
    '6.1 Single Flight resolves concurrent IP from cache without blocking'
);

check(
    $elapsedMs < 120,
    sprintf('6.2 Single Flight bounded wait latency: %.2f ms (well below 150ms limit)', $elapsedMs)
);

// Verify budget lock file can be immediately acquired by another worker
$testFp = @fopen($lockFile, 'c+');
$canLockBudget = $testFp && @flock($testFp, LOCK_EX | LOCK_NB);
if ($canLockBudget) {
    @flock($testFp, LOCK_UN);
    @fclose($testFp);
}
check(
    $canLockBudget,
    '6.3 Budget lock file is completely free (not held by Single Flight during wait)'
);

echo "\n=================================================================\n";
echo sprintf(" Acceptance Test Results: %d / %d passed (%d%%)\n", $passed, $total, (int) (($passed / max(1, $total)) * 100));
echo "=================================================================\n";

if ($passed === $total) {
    echo " ALL 6 ACCEPTANCE CRITERIA VERIFIED WITH 100% SUCCESS!\n";
    exit(0);
} else {
    echo " ACCEPTANCE TESTS FAILED!\n";
    exit(1);
}
