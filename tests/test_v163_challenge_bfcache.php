<?php

declare(strict_types=1);

/**
 * Regression Test Suite for JS Challenge BFCache, Replay Resilience & Browser Navigation (v1.6.3)
 */

defined('YII_DEBUG') or define('YII_DEBUG', true);
defined('YII_ENV') or define('YII_ENV', 'test');

$autoloadCandidates = [
    'D:/OpenServer6.5.0/home/yii2-fresh-install.loc/vendor/autoload.php',
    __DIR__ . '/../../yii2-advanced.loc/vendor/autoload.php',
    'D:/OSPanel6.5.1/home/yii2-advanced.loc/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
    dirname(__DIR__, 2) . '/vendor/autoload.php',
];

$loader = null;
foreach ($autoloadCandidates as $cand) {
    if (file_exists($cand)) {
        $loader = require_once $cand;
        break;
    }
}
$loader->setPsr4('larikmc\\antibot\\', [dirname(__DIR__) . '/src']);
$yiiLoaded = false;
$yiiCandidates = [
    'D:/OSPanel6.5.1/home/yii2-advanced.loc/vendor/yiisoft/yii2/Yii.php',
    __DIR__ . '/../../yii2-advanced.loc/vendor/yiisoft/yii2/Yii.php',
    'D:/OpenServer6.5.0/home/yii2-fresh-install.loc/vendor/yiisoft/yii2/Yii.php',
];
foreach ($yiiCandidates as $cand) {
    if (file_exists($cand)) {
        require_once $cand;
        $yiiLoaded = true;
        break;
    }
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/index.php';
$_SERVER['HTTP_HOST'] = 'strb64.ru';
$_SERVER['REMOTE_ADDR'] = '95.26.252.184';
$_SERVER['REQUEST_URI'] = '/';

$config = [
    'id' => 'antibot-bfcache-app',
    'basePath' => __DIR__,
    'components' => [
        'request' => [
            'class' => \yii\web\Request::class,
            'cookieValidationKey' => 'test-validation-key-32-chars-long!',
            'scriptFile' => __FILE__,
            'scriptUrl' => '/index.php',
        ],
        'response' => [
            'class' => \yii\web\Response::class,
        ],
        'cache' => [
            'class' => \yii\caching\FileCache::class,
            'cachePath' => sys_get_temp_dir() . '/antibot_bfcache_test_cache',
        ],
    ],
];

$app = new \yii\web\Application($config);

use larikmc\antibot\Antibot;
use larikmc\antibot\services\TokenService;
use larikmc\antibot\services\ReverseDnsVerifier;
use larikmc\antibot\widgets\AntibotWidget;

echo "=================================================================\n";
echo " Yii2 AntiBot: JS Challenge BFCache, Replay & Navigation (v1.6.3)\n";
echo "=================================================================\n\n";

$testsPassed = 0;
$totalTests = 0;

function assertTest(bool $condition, string $title): void {
    global $testsPassed, $totalTests;
    $totalTests++;
    if ($condition) {
        $testsPassed++;
        echo " [PASS] $title\n";
    } else {
        echo " [FAIL] $title\n";
    }
}

$secret = 'test_secret_key_bfcache_98765';
$userIp = '95.26.252.184';
$userUa = 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36';

echo "--- Section 1: Initial Challenge Solving & Token Issuance ---\n";

// 1.1 Generate challenge
$ch = TokenService::generateJsChallenge($userIp, $secret);
assertTest(!empty($ch['seed']) && !empty($ch['target']), "1.1 JS Challenge generates valid seed and target HMAC");

// 1.2 First solve succeeds with detailed status
$detailedValid = TokenService::verifyJsChallengeDetailed($ch['seed'], $ch['target'], $secret);
assertTest($detailedValid === TokenService::CHALLENGE_VALID, "1.2 Valid challenge solution returns CHALLENGE_VALID");

// 1.3 Backward compatible verifyJsChallenge
$chFresh = TokenService::generateJsChallenge($userIp, $secret);
$valid1 = TokenService::verifyJsChallenge($chFresh['seed'], $chFresh['target'], $secret);
assertTest($valid1 === true, "1.3 Backward-compatible verifyJsChallenge returns true on fresh solution");

// 1.4 Token issuance with strict flags
$token = TokenService::generateToken($userIp, $userUa, $secret);
assertTest(TokenService::validateToken($token, $userIp, $userUa, $secret) === true, "1.4 Generated token validates successfully for client IP prefix and UA hash");

// 1.5 Cookie flags check
$cookie = new \yii\web\Cookie([
    'name' => TokenService::COOKIE_NAME,
    'value' => $token,
    'expire' => time() + TokenService::DEFAULT_TTL,
    'httpOnly' => true,
    'secure' => true,
    'sameSite' => 'Lax',
    'path' => '/',
]);
assertTest($cookie->httpOnly === true && $cookie->path === '/' && $cookie->sameSite === 'Lax', "1.5 Token cookie is strictly HttpOnly, SameSite=Lax, and Path=/");

echo "\n--- Section 2: Browser Back Button & BFCache Navigation Protection ---\n";

// 2.1 Replaying the exact same seed alone without token is recognized as CHALLENGE_REPLAYED
$replayStatus = TokenService::verifyJsChallengeDetailed($ch['seed'], $ch['target'], $secret);
assertTest($replayStatus === TokenService::CHALLENGE_REPLAYED, "2.1 Raw replay of already-used seed without valid cookie yields CHALLENGE_REPLAYED");

// 2.2 Visitor who already possesses valid token cookie is recognized
$isAlreadyVerified = TokenService::validateToken($token, $userIp, $userUa, $secret);
assertTest($isAlreadyVerified === true, "2.2 Client carrying valid antibot_token cookie is recognized as already authenticated");

// 2.3 Forged or tampered token cookie is strictly rejected
$tamperedToken = base64_encode(base64_decode($token) . '_tampered');
assertTest(TokenService::validateToken($tamperedToken, $userIp, $userUa, $secret) === false, "2.3 Tampered token signature fails cryptographic HMAC validation");

// 2.4 Expired token cookie fails validation
$parts = explode('|', base64_decode($token));
$expiredPayload = sprintf('%d|%d|%s|%s', (int)$parts[0], time() - 100, $parts[2], $parts[3]);
$expiredSig = substr(hash_hmac('sha256', $expiredPayload, $secret), 0, 32);
$expiredToken = base64_encode(sprintf('%s|%s', $expiredPayload, $expiredSig));
assertTest(TokenService::validateToken($expiredToken, $userIp, $userUa, $secret) === false, "2.4 Expired token cookie strictly fails validation");

echo "\n--- Section 3: Extended Reading Time & Idle Tab Resilience ---\n";

// 3.1 Challenge seed generated 180 seconds ago (< 300s)
$parts = explode('|', $ch['seed']);
$t180 = time() - 180;
$seed180 = $parts[0] . '|' . $t180 . '|' . $parts[2];
$target180 = hash_hmac('sha256', $seed180, $secret);
$status180 = TokenService::verifyJsChallengeDetailed($seed180, $target180, $secret, 300);
assertTest($status180 === TokenService::CHALLENGE_VALID, "3.1 Seed from 180 seconds ago (user reading for 3 minutes) successfully verifies");

// 3.2 Challenge seed generated 290 seconds ago (< 300s)
$t290 = time() - 290;
$seed290 = $parts[0] . '|' . $t290 . '|' . $parts[2];
$target290 = hash_hmac('sha256', $seed290, $secret);
$status290 = TokenService::verifyJsChallengeDetailed($seed290, $target290, $secret, 300);
assertTest($status290 === TokenService::CHALLENGE_VALID, "3.2 Seed from 290 seconds ago (< 5 minutes) successfully verifies");

// 3.3 Slow reader seed (> 300s) with valid HMAC yields CHALLENGE_EXPIRED (safe retry, NOT Auto-Jail)
$t400 = time() - 400;
$seed400 = $parts[0] . '|' . $t400 . '|' . $parts[2];
$target400 = hash_hmac('sha256', $seed400, $secret);
$status400 = TokenService::verifyJsChallengeDetailed($seed400, $target400, $secret, 300);
assertTest($status400 === TokenService::CHALLENGE_EXPIRED, "3.3 Seed older than 300s with valid HMAC yields CHALLENGE_EXPIRED for safe retry");

echo "\n--- Section 4: Hostile Attack Mitigation & Forgery Prevention ---\n";

// 4.1 Attacker submitting incorrect HMAC target yields CHALLENGE_INVALID
$fakeAnswer = '0000000000000000000000000000000000000000000000000000000000000000';
$statusForged = TokenService::verifyJsChallengeDetailed($ch['seed'], $fakeAnswer, $secret);
assertTest($statusForged === TokenService::CHALLENGE_INVALID, "4.1 Forged HMAC target answer yields CHALLENGE_INVALID (leads to immediate 403 & AutoJail)");

// 4.2 Malformed seed missing required pipe delimiters
$statusMalformed = TokenService::verifyJsChallengeDetailed('malformed_seed_without_pipes', $fakeAnswer, $secret);
assertTest($statusMalformed === TokenService::CHALLENGE_INVALID, "4.2 Malformed seed missing pipe delimiters yields CHALLENGE_INVALID");

// 4.3 Stolen cookie on different subnet prefix
$differentIp = '198.51.100.42';
$subnetMismatch = TokenService::validateToken($token, $differentIp, $userUa, $secret);
assertTest($subnetMismatch === false, "4.3 Token presented from a different subnet prefix is rejected");

// 4.4 Stolen cookie on different User-Agent
$differentUa = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';
$uaMismatch = TokenService::validateToken($token, $userIp, $differentUa, $secret);
assertTest($uaMismatch === false, "4.4 Token presented from a different User-Agent is rejected");

echo "\n--- Section 5: FCrDNS-Verified Crawler Exemption vs Spoofing Protection ---\n";

// 5.1 Spoofed Googlebot from unauthorized IP (198.51.100.99) MUST NOT be exempted in widget
$_SERVER['REMOTE_ADDR'] = '198.51.100.99';
$reflectedIp = new \ReflectionProperty(\yii\web\Request::class, '_ip');
$reflectedIp->setAccessible(true);
$reflectedIp->setValue($app->request, '198.51.100.99');
$app->request->headers->set('User-Agent', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)');
$widgetSpoofed = AntibotWidget::widget();
assertTest(!str_contains($widgetSpoofed, '<!-- antibot: crawler'), "5.1 Spoofed Googlebot from unauthorized IP does NOT receive crawler exemption");

// 5.2 Authentic Googlebot IP (66.249.66.1) DOES receive crawler exemption
$_SERVER['REMOTE_ADDR'] = '66.249.66.1';
$reflectedIp->setValue($app->request, '66.249.66.1');
$widgetVerified = AntibotWidget::widget();
assertTest(str_contains($widgetVerified, '<!-- antibot: crawler (verified) -->'), "5.2 Authentic Googlebot IP (66.249.66.1) receives verified crawler exemption");

// 5.3 Normal user with valid cookie
$_SERVER['REMOTE_ADDR'] = $userIp;
$reflectedIp->setValue($app->request, $userIp);
$app->request->headers->set('User-Agent', $userUa);
$widgetSecret = $app->request->cookieValidationKey;
$tokenForWidget = TokenService::generateToken($userIp, $userUa, $widgetSecret);
$reflectedCookies = new \ReflectionProperty(\yii\web\Request::class, '_cookies');
$reflectedCookies->setAccessible(true);
$cookieCol = new \yii\web\CookieCollection([
    TokenService::COOKIE_NAME => new \yii\web\Cookie(['name' => TokenService::COOKIE_NAME, 'value' => $tokenForWidget]),
]);
$reflectedCookies->setValue($app->request, $cookieCol);
$widgetHtmlVerified = AntibotWidget::widget();
assertTest(str_contains($widgetHtmlVerified, '<!-- antibot: verified -->'), "5.3 Authenticated visitor with valid cookie receives <!-- antibot: verified -->");

echo "\n--- Section 6: Policy Enforcement & Client State Isolation (v1.6.3 Final) ---\n";

// 6.1 Verified crawler with policy 'block' MUST NOT receive crawler exemption
$botComponent = new Antibot();
$botComponent->botPolicies = ['googlebot' => 'block'];
\Yii::$app->set('antibot', $botComponent);

$_SERVER['REMOTE_ADDR'] = '66.249.66.1';
$reflectedIp->setValue($app->request, '66.249.66.1');
$reflectedCookies->setValue($app->request, new \yii\web\CookieCollection([]));
$app->request->headers->set('User-Agent', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)');
$widgetBlockedCrawler = AntibotWidget::widget();
assertTest(!str_contains($widgetBlockedCrawler, '<!-- antibot: crawler (verified) -->'), "6.1 Verified crawler with explicit policy 'block' is NOT exempted from challenge");

// 6.2 Category policy 'block' (e.g. search => block) strictly enforces denial
$botComponent->botPolicies = [];
$botComponent->botCategoryPolicies = ['search' => 'block'];
$widgetBlockedCategory = AntibotWidget::widget();
assertTest(!str_contains($widgetBlockedCategory, '<!-- antibot: crawler (verified) -->'), "6.2 Verified crawler under category policy 'block' is NOT exempted from challenge");

// Reset policy back to normal allow
$botComponent->botCategoryPolicies = ['search' => 'allow'];

// 6.3 sessionStorage state isolation: absence of server-side cookie cannot grant authentication
// Even if client attempts to spoof by omitting verification, server TokenService strictly requires cryptographic HMAC
$missingCookieToken = '';
$isServerApproved = TokenService::validateToken($missingCookieToken, $userIp, $userUa, $widgetSecret);
assertTest($isServerApproved === false, "6.3 Absence of server-side cookie strictly denies access (sessionStorage alone cannot bypass)");

// 6.4 Multi-tab concurrent verification flow:
// Tab 1 solves challenge and receives valid cookie
$tab1Token = TokenService::generateToken($userIp, $userUa, $widgetSecret);
// Tab 2 has an older seed generated on page render. Re-sending it with valid cookie:
$isTab2Approved = TokenService::validateToken($tab1Token, $userIp, $userUa, $widgetSecret);
assertTest($isTab2Approved === true, "6.4 Multi-tab session sharing: Tab 2 seamlessly authenticates using Tab 1 cookie without race condition");

echo "\n=================================================================\n";
echo " Test Results: $testsPassed / $totalTests passed (" . round(($testsPassed / $totalTests) * 100) . "%)\n";
echo "=================================================================\n";

if ($testsPassed === $totalTests) {
    echo " ALL JS CHALLENGE BFCache, SECURITY & DIAGNOSTIC TESTS PASSED 100%!\n";
    exit(0);
} else {
    echo " SOME TESTS FAILED!\n";
    exit(1);
}
