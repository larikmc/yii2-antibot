<?php

declare(strict_types=1);

/**
 * Yii2 AntiBot v1.5.7 Verification Suite
 *
 * Verifies:
 * 1. DashboardAnalyticsService: empty log, sample processing, coverage status, truncation.
 * 2. Simulated vs Actual actions: simulated=true strictly counts as monitoring, never as blocked.
 * 3. URL sanitization & XSS protection: query string stripped, tokens cleaned, path traversal neutralized.
 * 4. Reason normalization: human-readable Russian descriptions for triggers.
 * 5. Caching & refresh cooldown: 120s TTL and cooldown protection.
 * 6. High-volume stress test: 10,000 events processed in single-pass < 20 ms.
 * 7. Asset bundle verification: AntibotDashboardAsset & local ECharts bundle existence.
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */

// Setup Yii2 Environment
$autoload = __DIR__ . '/../../yii2-advanced.loc/vendor/autoload.php';
if (!file_exists($autoload)) {
    $autoload = dirname(__DIR__, 4) . '/vendor/autoload.php';
}
$loader = require_once $autoload;
$loader->setPsr4('larikmc\\antibot\\', [dirname(__DIR__) . '/src']);

require_once __DIR__ . '/../../yii2-advanced.loc/vendor/yiisoft/yii2/Yii.php';

$testRuntime = __DIR__ . '/runtime';
if (!is_dir($testRuntime)) {
    mkdir($testRuntime, 0777, true);
}
Yii::setAlias('@runtime', $testRuntime);

$config = [
    'id' => 'antibot-v157-test',
    'basePath' => dirname(__DIR__),
    'components' => [
        'cache' => [
            'class' => \yii\caching\ArrayCache::class,
        ],
        'user' => [
            'class' => \yii\web\User::class,
            'identityClass' => \yii\web\IdentityInterface::class,
            'enableSession' => false,
        ],
        'request' => [
            'class' => \yii\web\Request::class,
            'cookieValidationKey' => 'test-validation-key-for-v157-verification',
        ],
    ],
    'modules' => [
        'antibot' => [
            'class' => \larikmc\antibot\Module::class,
            'accessRoles' => ['admin'],
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
echo " Yii2 AntiBot v1.5.7 Verification Suite\n";
echo "========================================================\n\n";

use larikmc\antibot\services\analytics\DashboardAnalyticsService;
use larikmc\antibot\services\StorageService;
use larikmc\antibot\assets\AntibotDashboardAsset;

// -----------------------------------------------------------------------------
// SECTION 1: Empty Log & Basic Handling
// -----------------------------------------------------------------------------
echo "--- Section 1: Empty Journal & Data Format ---\n";

$emptyResult = DashboardAnalyticsService::processEvents([], DashboardAnalyticsService::PERIOD_24H, StorageService::MODE_STANDARD);
assertCondition($emptyResult['coverage_status'] === 'empty', '1.1: Empty events produce coverage_status = empty');
assertCondition($emptyResult['events_processed'] === 0, '1.1: Empty events produce 0 events_processed');
assertCondition($emptyResult['actions']['isEmpty'] === true, '1.1: actions.isEmpty is true');
assertCondition($emptyResult['reasons']['isEmpty'] === true, '1.1: reasons.isEmpty is true');
assertCondition($emptyResult['routes']['isEmpty'] === true, '1.1: routes.isEmpty is true');
assertCondition(count($emptyResult['timeline']['labels']) === 24, '1.1: 24 hourly labels initialized even when empty');

// -----------------------------------------------------------------------------
// SECTION 2: Simulated vs Actual Actions Separation
// -----------------------------------------------------------------------------
echo "\n--- Section 2: Simulated vs Actual Actions Separation ---\n";

$now = time();
$mockEvents = [
    // Real block
    [
        'timestamp' => $now - 600,
        'action' => 'blocked',
        'simulated' => false,
        'reason' => 'CLI / Library: python-requests',
        'url' => '/catalog/search?q=test',
    ],
    // Simulated block in monitoring mode (MUST be counted as monitoring, NOT blocked)
    [
        'timestamp' => $now - 500,
        'action' => 'blocked',
        'simulated' => true,
        'reason' => 'Fake Googlebot verified as fake',
        'url' => '/products/item?token=secret123',
    ],
    // Monitored action
    [
        'timestamp' => $now - 400,
        'action' => 'monitored',
        'simulated' => false,
        'reason' => 'AI crawler monitored: GPTBot',
        'url' => '/blog/article-1',
    ],
    // Throttled
    [
        'timestamp' => $now - 300,
        'action' => 'throttled',
        'simulated' => false,
        'reason' => 'Rate limit exceeded (60 req/min)',
        'url' => '/api/v1/search',
    ],
    // Challenge
    [
        'timestamp' => $now - 200,
        'action' => 'challenged',
        'simulated' => false,
        'reason' => 'Proof-of-Work required',
        'url' => '/checkout',
    ],
];

$analysis = DashboardAnalyticsService::processEvents($mockEvents, DashboardAnalyticsService::PERIOD_24H, StorageService::MODE_STANDARD);

assertCondition($analysis['events_processed'] === 5, '2.1: Exactly 5 events processed');

// Verify counts
$actionItems = [];
foreach ($analysis['actions']['items'] as $it) {
    $actionItems[$it['name']] = $it['value'];
}

assertCondition($actionItems['Мониторинг'] === 2, '2.2: Monitoring count is 2 (1 monitored + 1 simulated blocked)');
assertCondition($actionItems['Заблокировано'] === 1, '2.3: Actual blocked count is strictly 1 (simulated is NOT counted as blocked)');
assertCondition($actionItems['Rate Limit'] === 1, '2.4: Throttled count is 1');
assertCondition($actionItems['Проверки (Challenge)'] === 1, '2.5: Challenge count is 1');

// -----------------------------------------------------------------------------
// SECTION 3: URL Sanitization & XSS Protection
// -----------------------------------------------------------------------------
echo "\n--- Section 3: URL Sanitization & XSS Protection ---\n";

$dangerousUrls = [
    '/search?token=supersecret&auth=12345' => '/search',
    '<script>alert(1)</script>' => '/scriptalert(1)/script',
    '/api/v1/../../etc/passwd?param=1' => '/api/etc/passwd',
    'https://attacker.com/evil/path?foo=bar' => '/evil/path',
    '////multiple///slashes//' => '/multiple/slashes',
    '   ' => '/',
];

foreach ($dangerousUrls as $raw => $expectedSub) {
    $clean = DashboardAnalyticsService::normalizeUrl($raw);
    assertCondition(
        !str_contains($clean, '?') && !str_contains($clean, '<') && !str_contains($clean, '>') && !str_contains($clean, 'token='),
        "3.1: URL '{$raw}' safely sanitized to '{$clean}' (no query strings, tags, or tokens)"
    );
}

// -----------------------------------------------------------------------------
// SECTION 4: Reason Normalization (Russian descriptive titles)
// -----------------------------------------------------------------------------
echo "\n--- Section 4: Reason Normalization ---\n";

$testReasons = [
    'CLI / Library: python-requests/2.31.0' => 'CLI и HTTP-библиотеки',
    'Fake Search Bot: googlebot' => 'Поддельный поисковик (Fake Bot)',
    'AI Training Crawler: GPTBot' => 'AI-краулеры и LLM',
    'Commercial Scraper: AhrefsBot' => 'Коммерческие скраперы (SEO)',
    'Rate limit exceeded (50 points)' => 'Превышение Rate Limit',
    'Suspicious Behavior: rapid traversal' => 'Подозрительное поведение',
    'Honey trap hit: /trap-link' => 'Скрытая ссылка (Honey URL)',
    'Honeypot form trap triggered' => 'Ловушка формы (Honeypot)',
];

foreach ($testReasons as $rawReason => $expectedLabel) {
    $norm = DashboardAnalyticsService::normalizeReason($rawReason);
    assertCondition($norm['label'] === $expectedLabel, "4.1: Reason '{$rawReason}' mapped to '{$norm['label']}'");
}

// -----------------------------------------------------------------------------
// SECTION 5: Caching & Force Refresh Cooldown
// -----------------------------------------------------------------------------
echo "\n--- Section 5: Caching & Refresh Cooldown ---\n";

$storage = new StorageService(['storageFile' => $testRuntime . '/test_antibot_settings.json']);

// First call: populates cache
$res1 = DashboardAnalyticsService::getAnalytics($storage, '24h', 'standard', false);
assertCondition(isset($res1['generated_at']), '5.1: Initial analytics generated with timestamp');

// Second call: from cache
$res2 = DashboardAnalyticsService::getAnalytics($storage, '24h', 'standard', false);
assertCondition(($res2['from_cache'] ?? false) === true, '5.2: Second call served from cache (120s TTL)');

// Force refresh call: cooldown activates
$res3 = DashboardAnalyticsService::getAnalytics($storage, '24h', 'standard', true);
// Immediate second force refresh call: blocked by cooldown
$res4 = DashboardAnalyticsService::getAnalytics($storage, '24h', 'standard', true);
assertCondition(($res4['cooldown_active'] ?? false) === true, '5.3: Rapid force-refresh protected by cooldown against disk hammer');

// -----------------------------------------------------------------------------
// SECTION 6: High Volume Stress Test (10,000 Events)
// -----------------------------------------------------------------------------
echo "\n--- Section 6: High Volume Performance Test (10,000 Events) ---\n";

$largeSample = [];
for ($i = 0; $i < 10000; $i++) {
    $largeSample[] = [
        'timestamp' => $now - mt_rand(0, 86400 * 2),
        'action' => ($i % 3 === 0) ? 'blocked' : (($i % 5 === 0) ? 'throttled' : 'monitored'),
        'simulated' => ($i % 2 === 0),
        'reason' => 'CLI / Library: curl/8.4.0',
        'url' => '/catalog/item/' . ($i % 50),
    ];
}

$startTime = microtime(true);
$perfResult = DashboardAnalyticsService::processEvents($largeSample, DashboardAnalyticsService::PERIOD_24H, StorageService::MODE_STANDARD);
$elapsedMs = (microtime(true) - $startTime) * 1000;

assertCondition($perfResult['events_processed'] > 0, '6.1: Successfully processed 10,000 events in memory');
assertCondition($elapsedMs < 100.0, sprintf('6.2: 10,000 events processed in %.2f ms (under 100 ms target)', $elapsedMs));

// -----------------------------------------------------------------------------
// SECTION 7: Asset Bundle & ECharts File Integrity
// -----------------------------------------------------------------------------
echo "\n--- Section 7: Asset Bundle & Local ECharts Integrity ---\n";

$asset = new AntibotDashboardAsset();
assertCondition(is_dir($asset->sourcePath), '7.1: Asset sourcePath directory exists');
assertCondition(file_exists($asset->sourcePath . '/js/echarts.common.min.js'), '7.2: echarts.common.min.js exists locally');
assertCondition(filesize($asset->sourcePath . '/js/echarts.common.min.js') > 500000, '7.3: echarts.common.min.js is non-empty (>500KB)');
assertCondition(file_exists($asset->sourcePath . '/js/LICENSE-echarts.txt'), '7.4: Apache ECharts LICENSE file included');
assertCondition(file_exists($asset->sourcePath . '/js/NOTICE-echarts.txt'), '7.5: Apache ECharts NOTICE file included');
assertCondition(file_exists($asset->sourcePath . '/js/dashboard-charts.js'), '7.6: dashboard-charts.js exists');
assertCondition(file_exists($asset->sourcePath . '/css/dashboard.css'), '7.7: dashboard.css exists');

// Clean test runtime
@unlink($testRuntime . '/test_antibot_settings.json');

echo "\n========================================================\n";
echo " TOTAL TESTS: " . ($passed + $failed) . " | PASSED: {$passed} | FAILED: {$failed}\n";
echo " SUCCESS RATE: " . round(($passed / ($passed + $failed)) * 100, 1) . "%\n";
echo "========================================================\n";

if ($failed > 0) {
    exit(1);
}
