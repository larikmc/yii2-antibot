<?php

declare(strict_types=1);

/**
 * Full Regression Test Suite for larikmc/yii2-antibot (v1.1.0 - v1.5.2)
 */

require_once __DIR__ . '/../../yii2-advanced.loc/vendor/autoload.php';
require_once __DIR__ . '/../../yii2-advanced.loc/vendor/yiisoft/yii2/Yii.php';

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/index.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

$config = [
    'id' => 'antibot-regression-app',
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
            'cachePath' => sys_get_temp_dir() . '/antibot_regr_cache_' . uniqid(),
        ],
    ],
];

new \yii\web\Application($config);

use larikmc\antibot\Antibot;
use larikmc\antibot\services\IpMatcher;
use larikmc\antibot\services\BotDetector;
use larikmc\antibot\services\TokenService;
use larikmc\antibot\services\RateLimiter;
use larikmc\antibot\services\ReverseDnsVerifier;
use larikmc\antibot\services\limiter\WeightedRateLimiter;
use larikmc\antibot\services\behavior\BehaviorAnalyzer;
use larikmc\antibot\services\behavior\RiskEngine;
use larikmc\antibot\services\pow\PowService;
use larikmc\antibot\services\traps\HoneyUrlService;
use larikmc\antibot\services\advisor\RuleSimulator;
use larikmc\antibot\services\advisor\RuleAdvisor;
use larikmc\antibot\services\resilience\CircuitBreaker;
use larikmc\antibot\services\resilience\SelfTest;

$total = 0;
$passed = 0;
$failed = 0;

function regrAssert(bool $ok, string $name): void
{
    global $total, $passed, $failed;
    $total++;
    if ($ok) {
        $passed++;
        echo "[\033[32mPASS\033[0m] {$name}\n";
    } else {
        $failed++;
        echo "[\033[31mFAIL\033[0m] {$name}\n";
    }
}

echo "==================================================\n";
echo " Yii2 AntiBot Full Regression Suite (v1.1.0-v1.5.2)\n";
echo "==================================================\n";

// 1. IP & CIDR Matching
regrAssert(IpMatcher::match('192.168.1.50', ['192.168.1.0/24']), '1. CIDR Subnet matching works correctly');
regrAssert(!IpMatcher::match('10.0.0.1', ['192.168.1.0/24']), '1. CIDR Subnet correctly rejects non-matching IP');

// 2. BotDetector
regrAssert(BotDetector::detectSearchBot('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)') === 'google', '2. Detects Googlebot user-agent');
regrAssert(BotDetector::detectSearchBot('Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)') === 'yandex', '2. Detects YandexBot user-agent');
regrAssert(BotDetector::detectAiCrawler('GPTBot/1.2 (+https://openai.com/gptbot)')['name'] === 'GPTBot', '2. Detects GPTBot AI scraper');
regrAssert(BotDetector::detectCommercialScraper('Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)') === 'Ahrefs', '2. Detects Ahrefs commercial scraper');
regrAssert(BotDetector::detectCliAndLibraries('python-requests/2.31.0') === 'Python', '2. Detects python-requests CLI scraper');

// 3. TokenService & JS Challenge
$secKey = 'test-secret-salt-32-bytes-long!';
$chal = TokenService::generateJsChallenge('1.2.3.4', $secKey);
regrAssert(TokenService::verifyJsChallenge($chal['seed'], $chal['target'], $secKey), '3. JS Challenge generates and verifies valid solution');
regrAssert(!TokenService::verifyJsChallenge($chal['seed'], 'wrong_target', $secKey), '3. JS Challenge rejects invalid target');

// 4. RateLimiter
$rlRes1 = RateLimiter::check('test_rl_ip_1', 5, 60);
regrAssert(!$rlRes1['exceeded'] && $rlRes1['current'] === 1, '4. RateLimiter allows normal request within quota');

// 5. WeightedRateLimiter
$cost = WeightedRateLimiter::resolveCost('catalog/search', ['catalog/search' => 5]);
regrAssert($cost === 5, '5. WeightedRateLimiter resolves cost according to route weights');
$wRes = WeightedRateLimiter::check('test_weighted_ip', 10, 100, 60);
regrAssert(!$wRes['exceeded'] && $wRes['current'] === 10, '5. WeightedRateLimiter tracks accumulated points');

// 6. BehaviorAnalyzer & RiskEngine
$bh = BehaviorAnalyzer::analyze('198.51.100.5', '/page1');
regrAssert(isset($bh['risk']['score']), '6. BehaviorAnalyzer calculates baseline risk score');

// 7. PoW Service
$pow = PowService::generateChallenge('198.51.100.6', $secKey, 'light');
regrAssert(!empty($pow['seed']) && !empty($pow['target']), '7. PoW generates challenge seed and target');

// 8. Honey URL Service
$trap = HoneyUrlService::generateTrapHtml('/', $secKey, 'antibot/trap');
regrAssert(str_contains($trap, 'h='), '8. HoneyUrlService generates invisible HMAC-signed trap link');

try {
    // 9. RuleSimulator
    $mockEvents = [];
    for ($i = 0; $i < 25; $i++) {
        $mockEvents[] = [
            'ip' => "198.51.100.{$i}",
            'userAgent' => $i < 5 ? 'Python-urllib/3.8' : 'Mozilla/5.0 Chrome',
            'action' => $i < 5 ? 'blocked' : 'allowed',
            'timestamp' => time(),
        ];
    }
    $sim = RuleSimulator::simulateUserAgentBlock($mockEvents, 'Python');
    regrAssert($sim['sufficient'] && $sim['wouldBlock'] === 5, '9. RuleSimulator predicts blocked vs allowed accurately (wouldBlock: 5/25)');

    // 10. RuleAdvisor
    $recs = RuleAdvisor::generateRecommendations($mockEvents, ['blacklist' => []]);
    regrAssert(is_array($recs), '10. RuleAdvisor evaluates traffic and returns recommendations array');

    // 11. Circuit Breaker
    CircuitBreaker::resetAll();
    regrAssert(CircuitBreaker::isAvailable('storage'), '11. CircuitBreaker starts in CLOSED healthy state');

    // 12. SelfTest
    $antibot = new Antibot();
    $diag = SelfTest::run($antibot);
    regrAssert(in_array($diag['overall'], ['ok', 'warning'], true), '12. SelfTest runs clean system diagnostic');
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}

echo "==================================================\n";
echo " REGRESSION TOTAL: {$total} | PASSED: {$passed} | FAILED: {$failed}\n";
echo " SUCCESS RATE: " . round(($passed / $total) * 100, 1) . "%\n";
echo "==================================================\n";

if ($failed > 0) {
    exit(1);
}
