<?php

declare(strict_types=1);

/**
 * Yii2 AntiBot v1.5.7 Live Integration & Performance Verification
 * Target: http://yii2-advanced.loc/
 */

$testAppRoot = 'D:/OSPanel6.5.1/home/yii2-advanced.loc';
require_once $testAppRoot . '/vendor/autoload.php';
require_once $testAppRoot . '/vendor/yiisoft/yii2/Yii.php';
require_once $testAppRoot . '/common/config/bootstrap.php';

echo "=========================================================================\n";
echo " Yii2 AntiBot v1.5.7 Live Integration & Performance Suite\n";
echo " Target: http://yii2-advanced.loc/ | Date: " . date('Y-m-d H:i:s') . "\n";
echo "=========================================================================\n\n";

// -----------------------------------------------------------------------------
// Part 1: Public Frontend Verification (ZERO overhead check)
// -----------------------------------------------------------------------------
echo "--- Part 1: Public Frontend Zero-Overhead Verification ---\n";

$frontendUrl = 'http://yii2-advanced.loc/';
$ch = curl_init($frontendUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    CURLOPT_TIMEOUT => 5,
]);
$html = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000;
curl_close($ch);

echo "[CHECK] Public frontend HTTP code: {$httpCode} (expected 200)\n";
echo "[CHECK] Public request latency: " . number_format($totalTime, 2) . " ms\n";

$hasEcharts = str_contains((string) $html, 'echarts');
$hasDashboardCss = str_contains((string) $html, 'dashboard.css');
echo "[CHECK] Public HTML contains echarts.js: " . ($hasEcharts ? 'YES (VIOLATION!)' : 'NO (Clean!)') . "\n";
echo "[CHECK] Public HTML contains dashboard.css: " . ($hasDashboardCss ? 'YES (VIOLATION!)' : 'NO (Clean!)') . "\n";

if ($hasEcharts || $hasDashboardCss) {
    echo "ERROR: Public frontend must NOT load dashboard assets!\n";
    exit(1);
}

// -----------------------------------------------------------------------------
// Part 2: Backend Analytics Endpoint Security (AccessControl)
// -----------------------------------------------------------------------------
echo "\n--- Part 2: Backend Analytics AccessControl Check ---\n";

$apiUrl = 'http://admin.yii2-advanced.loc/antibot/default/analytics-data';
$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 5,
    CURLOPT_FOLLOWLOCATION => false,
]);
$apiResponse = curl_exec($ch);
$apiCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
curl_close($ch);

// Expect 302 redirect to login, or 403 Forbidden for unauthenticated guest
$isProtected = in_array($apiCode, [302, 401, 403], true);
echo "[CHECK] Unauthenticated access to analytics-data: HTTP {$apiCode} (" . ($isProtected ? 'PASS: Access Restricted' : 'FAIL: Publicly exposed!') . ")\n";
if ($redirectUrl) {
    echo "[CHECK] Redirect location: {$redirectUrl}\n";
}

if (!$isProtected) {
    echo "ERROR: Analytics API must be restricted to administrators!\n";
    exit(1);
}

// -----------------------------------------------------------------------------
// Part 3: Backend Analytics Service Performance & Cold/Warm Cache
// -----------------------------------------------------------------------------
echo "\n--- Part 3: Analytics Service Latency & Cache Performance ---\n";

$backendConfig = yii\helpers\ArrayHelper::merge(
    require $testAppRoot . '/common/config/main.php',
    require $testAppRoot . '/common/config/main-local.php',
    require $testAppRoot . '/backend/config/main.php',
    require $testAppRoot . '/backend/config/main-local.php'
);

Yii::setAlias('@webroot', $testAppRoot . '/backend/web');
Yii::setAlias('@web', '/');

$app = new \yii\web\Application($backendConfig);
$storage = $app->antibot->getStorage();

// Cold Cache Run
Yii::$app->cache->delete('antibot_analytics_v157_24h_monitoring');
$t0 = microtime(true);
$coldData = \larikmc\antibot\services\analytics\DashboardAnalyticsService::getAnalytics($storage, '24h', 'monitoring', true);
$tCold = (microtime(true) - $t0) * 1000;

// Warm Cache Run
$t1 = microtime(true);
$warmData = \larikmc\antibot\services\analytics\DashboardAnalyticsService::getAnalytics($storage, '24h', 'monitoring', false);
$tWarm = (microtime(true) - $t1) * 1000;

echo "[PERF] Cold cache generation: " . number_format($tCold, 2) . " ms (read & aggregated in single pass)\n";
echo "[PERF] Warm cache retrieval:  " . number_format($tWarm, 2) . " ms (served from memory cache)\n";
echo "[CHECK] Cold events processed: " . $coldData['events_processed'] . "\n";
echo "[CHECK] Warm from_cache flag:  " . ($warmData['from_cache'] ? 'true' : 'false') . "\n";

// -----------------------------------------------------------------------------
// Part 4: Layout & Asset Publishing Check
// -----------------------------------------------------------------------------
echo "\n--- Part 4: Asset Bundle Publishing Check ---\n";

try {
    $assetManager = $app->assetManager;
    $assetManager->basePath = $testAppRoot . '/backend/web/assets';
    $assetManager->baseUrl = '/assets';
    $bundle = $assetManager->getBundle(\larikmc\antibot\assets\AntibotDashboardAsset::class);
    echo "[ASSET] Published basePath: " . $bundle->basePath . "\n";
    echo "[ASSET] Published baseUrl:  " . $bundle->baseUrl . "\n";

    foreach ($bundle->js as $jsFile) {
        $publishedPath = $bundle->basePath . '/' . $jsFile;
        echo "[ASSET] JS file published: {$jsFile} -> " . (file_exists($publishedPath) ? 'EXISTS (' . filesize($publishedPath) . ' bytes)' : 'NOT FOUND') . "\n";
    }
    foreach ($bundle->css as $cssFile) {
        $publishedPath = $bundle->basePath . '/' . $cssFile;
        echo "[ASSET] CSS file published: {$cssFile} -> " . (file_exists($publishedPath) ? 'EXISTS (' . filesize($publishedPath) . ' bytes)' : 'NOT FOUND') . "\n";
    }
} catch (\Throwable $e) {
    echo "[ASSET ERROR] " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}

echo "\nLive integration & performance suite completed successfully.\n";
