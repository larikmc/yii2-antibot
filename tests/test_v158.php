<?php

declare(strict_types=1);

/**
 * Yii2 AntiBot v1.5.8 Verification Suite
 *
 * Verifies:
 * 1. Default value of $autoTrustLocalProxy is true.
 * 2. getEffectiveTrustedProxies() includes 127.0.0.1, ::1, SERVER_ADDR, and LOCAL_ADDR when enabled.
 * 3. Reverse proxy IP unwrapping: REMOTE_ADDR = SERVER_ADDR unwraps X-Forwarded-For / X-Real-IP.
 * 4. Loopback proxy IP unwrapping: REMOTE_ADDR = 127.0.0.1 unwraps X-Forwarded-For.
 * 5. Spoofing protection: Direct external connections strictly ignore proxy headers.
 * 6. Loopback header spoofing rejection: Headers claiming 127.0.0.1 or ::1 are discarded.
 * 7. Disabling autoTrustLocalProxy restores strict manual trustedProxies behavior.
 * 8. StorageService persistence and defaults for autoTrustLocalProxy.
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */

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
if (!is_dir($testRuntime . '/common/config')) {
    mkdir($testRuntime . '/common/config', 0777, true);
}
if (!is_dir($testRuntime . '/common/runtime/logs')) {
    mkdir($testRuntime . '/common/runtime/logs', 0777, true);
}

$config = [
    'id' => 'antibot-v158-test',
    'basePath' => dirname(__DIR__),
    'components' => [
        'cache' => [
            'class' => \yii\caching\ArrayCache::class,
        ],
        'request' => [
            'class' => \yii\web\Request::class,
            'cookieValidationKey' => 'test-validation-key-for-v158-verification',
        ],
    ],
    'modules' => [
        'antibot' => [
            'class' => \larikmc\antibot\Module::class,
        ],
    ],
];

new \yii\web\Application($config);

$passed = 0;
$failed = 0;

function assertCondition(bool $cond, string $msg): void {
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "[PASS] {$msg}\n";
    } else {
        $failed++;
        echo "[FAIL] {$msg}\n";
    }
}

echo "========================================================\n";
echo " Yii2 AntiBot v1.5.8 Verification Suite\n";
echo "========================================================\n\n";

use larikmc\antibot\Antibot;
use larikmc\antibot\services\StorageService;

// -----------------------------------------------------------------------------
// SECTION 1: Defaults and getEffectiveTrustedProxies()
// -----------------------------------------------------------------------------
echo "--- Section 1: Auto-Trust Defaults & Effective Proxies ---\n";

$antibot = new Antibot();
assertCondition($antibot->autoTrustLocalProxy === true, '1.1: $autoTrustLocalProxy defaults to true');
assertCondition($antibot->trustedProxies === [], '1.2: $trustedProxies defaults to empty array');

// With auto-trust enabled and SERVER_ADDR set
$_SERVER['SERVER_ADDR'] = '188.120.233.91';
$_SERVER['LOCAL_ADDR'] = '10.0.0.2';
$effective = $antibot->getEffectiveTrustedProxies();

assertCondition(in_array('127.0.0.1', $effective, true), '1.3: 127.0.0.1 in effective trusted proxies');
assertCondition(in_array('::1', $effective, true), '1.4: ::1 in effective trusted proxies');
assertCondition(in_array('188.120.233.91', $effective, true), '1.5: SERVER_ADDR in effective trusted proxies');
assertCondition(in_array('10.0.0.2', $effective, true), '1.6: LOCAL_ADDR in effective trusted proxies');

// When user adds custom proxies
$antibot->trustedProxies = ['173.245.48.0/20'];
$effectiveWithCustom = $antibot->getEffectiveTrustedProxies();
assertCondition(in_array('173.245.48.0/20', $effectiveWithCustom, true), '1.7: Custom trustedProxies merged');
assertCondition(in_array('188.120.233.91', $effectiveWithCustom, true), '1.8: SERVER_ADDR preserved with custom proxies');

// -----------------------------------------------------------------------------
// SECTION 2: Reverse Proxy Resolution (Nginx / HestiaCP / Apache)
// -----------------------------------------------------------------------------
echo "\n--- Section 2: Reverse Proxy IP Resolution ---\n";

$antibot = new Antibot();
$antibot->autoTrustLocalProxy = true;
$_SERVER['SERVER_ADDR'] = '188.120.233.91';

// Case A: Nginx connects to Apache on SERVER_ADDR with X-Forwarded-For
$_SERVER['REMOTE_ADDR'] = '188.120.233.91';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.195';
unset($_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_REAL_IP']);

$resolved = $antibot->resolveClientIp();
assertCondition($resolved === '203.0.113.195', "2.1: Resolved client IP from X-Forwarded-For via SERVER_ADDR (expected 203.0.113.195, got {$resolved})");

// Case B: Localhost proxy (127.0.0.1)
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '95.26.144.17';
$resolved = $antibot->resolveClientIp();
assertCondition($resolved === '95.26.144.17', "2.2: Resolved client IP from 127.0.0.1 reverse proxy (expected 95.26.144.17, got {$resolved})");

// Case C: X-Real-IP priority / header
$_SERVER['REMOTE_ADDR'] = '188.120.233.91';
unset($_SERVER['HTTP_X_FORWARDED_FOR']);
$_SERVER['HTTP_X_REAL_IP'] = '198.51.100.88';
$resolved = $antibot->resolveClientIp();
assertCondition($resolved === '198.51.100.88', "2.3: Resolved client IP from X-Real-IP (expected 198.51.100.88, got {$resolved})");

// Case D: Proxy chain (Client -> Proxy1 -> Nginx)
$_SERVER['REMOTE_ADDR'] = '188.120.233.91';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.42, 127.0.0.1';
unset($_SERVER['HTTP_X_REAL_IP']);
$resolved = $antibot->resolveClientIp();
assertCondition($resolved === '198.51.100.42', "2.4: Proxy chain resolved untrusted leftmost client IP (expected 198.51.100.42, got {$resolved})");

// -----------------------------------------------------------------------------
// SECTION 3: Security & Anti-Spoofing Verification
// -----------------------------------------------------------------------------
echo "\n--- Section 3: Anti-Spoofing & Security Checks ---\n";

// Case A: Attacker connects directly from untrusted IP sending forged headers
$_SERVER['REMOTE_ADDR'] = '198.51.100.99'; // Untrusted external IP
$_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8'; // Spoofed victim IP
$resolved = $antibot->resolveClientIp();
assertCondition($resolved === '198.51.100.99', "3.1: Untrusted REMOTE_ADDR strictly ignores forged X-Forwarded-For (expected 198.51.100.99, got {$resolved})");

// Case B: Attacker behind trusted proxy tries to claim loopback (127.0.0.1)
$_SERVER['REMOTE_ADDR'] = '188.120.233.91';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '127.0.0.1';
$resolved = $antibot->resolveClientIp();
assertCondition($resolved === '188.120.233.91', "3.2: Spoofed loopback in proxy header is rejected (expected 188.120.233.91, got {$resolved})");

// Case C: Attacker sends malformed/garbage IP
$_SERVER['REMOTE_ADDR'] = '188.120.233.91';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '<script>alert(1)</script>';
$resolved = $antibot->resolveClientIp();
assertCondition($resolved === '188.120.233.91', "3.3: Malformed X-Forwarded-For safely falls back to REMOTE_ADDR (expected 188.120.233.91, got {$resolved})");

// -----------------------------------------------------------------------------
// SECTION 4: Disabling Auto-Trust
// -----------------------------------------------------------------------------
echo "\n--- Section 4: Disabling autoTrustLocalProxy ---\n";

$antibotDisabled = new Antibot();
$antibotDisabled->autoTrustLocalProxy = false;
$antibotDisabled->trustedProxies = []; // Empty explicit proxies

$effectiveDisabled = $antibotDisabled->getEffectiveTrustedProxies();
assertCondition(empty($effectiveDisabled), '4.1: When autoTrustLocalProxy is false, effective proxies list is strictly empty');

$_SERVER['REMOTE_ADDR'] = '188.120.233.91';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.195';
$resolvedDisabled = $antibotDisabled->resolveClientIp();
assertCondition($resolvedDisabled === '188.120.233.91', "4.2: When autoTrustLocalProxy is false and trustedProxies is empty, proxy headers are ignored (expected 188.120.233.91, got {$resolvedDisabled})");

// -----------------------------------------------------------------------------
// SECTION 5: StorageService Defaults & Persistence
// -----------------------------------------------------------------------------
echo "\n--- Section 5: StorageService Integration ---\n";

$testStorageFile = $testRuntime . '/test_storage_' . uniqid() . '.json';
$storage = new StorageService([
    'storageFile' => $testStorageFile,
]);

$loaded = $storage->loadSettings();
assertCondition(isset($loaded['autoTrustLocalProxy']), '5.1: autoTrustLocalProxy exists in loaded defaults');
assertCondition($loaded['autoTrustLocalProxy'] === true, '5.2: autoTrustLocalProxy is true by default in StorageService');

$loaded['autoTrustLocalProxy'] = false;
$saved = $storage->saveSettings($loaded);
assertCondition($saved === true, '5.3: saveSettings() returned true');

// Reset cached settings and reload from disk
$storage2 = new StorageService([
    'storageFile' => $testStorageFile,
]);
$reloaded = $storage2->loadSettings();
assertCondition(isset($reloaded['autoTrustLocalProxy']), '5.4: autoTrustLocalProxy persisted in storage file');
assertCondition($reloaded['autoTrustLocalProxy'] === false, '5.5: autoTrustLocalProxy persisted value (false) reloaded correctly');

// Cleanup test storage file
@unlink($testStorageFile);

echo "\n========================================================\n";
echo " Tests finished: {$passed} passed, {$failed} failed\n";
echo "========================================================\n";

exit($failed > 0 ? 1 : 0);
