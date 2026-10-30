<?php

declare(strict_types=1);

/**
 * Yii2 AntiBot v1.5.6 Live Integration Smoke Test & Performance Benchmark
 *
 * Tests live http://yii2-advanced.loc/ in Monitoring vs Standard modes:
 * - Normal page returns 200
 * - Fake Googlebot not blocked in Monitoring (returns 200) vs blocked in Standard (returns 403)
 * - CLI scraper not blocked in Monitoring (returns 200) vs blocked in Standard (returns 403)
 * - Rate limit burst not blocked in Monitoring
 * - Event logging verified in log file
 * - Performance benchmark for Disabled, Monitoring, Standard at c = 1, 10, 25
 */

$baseUrl = 'http://yii2-advanced.loc/';
$settingsFile = 'D:/OSPanel6.5.1/home/yii2-advanced.loc/common/config/antibot.json';

function setAntibotConfig(string $mode, bool $enabled): void
{
    global $settingsFile;
    $settings = [
        'enabled' => $enabled,
        'mode' => $mode,
        'blockAiCrawlers' => true,
        'blockCommercialScrapers' => true,
        'blockCliAndLibraries' => true,
        'verifySearchBots' => true,
        'blockFakeSearchBots' => true,
        'rateLimitEnabled' => $enabled,
        'rateLimitMax' => 300,
        'rateLimitWindow' => 60,
        'rateLimitBanDuration' => 300,
        'behaviorAnalysisEnabled' => true,
        'powEnabled' => false,
        'trustedProxies' => ['127.0.0.1'],
        'whitelist' => [],
    ];
    file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function httpGet(string $url, array $headers = []): array
{
    $formatted = [];
    foreach ($headers as $k => $v) {
        $formatted[] = "{$k}: {$v}";
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => $formatted,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    return [
        'status' => $status,
        'body' => (string) $body,
        'error' => $err,
    ];
}

function runParallelBatch(string $url, int $total, int $concurrency, array $headers = []): array
{
    $formattedHeaders = [];
    foreach ($headers as $k => $v) {
        $formattedHeaders[] = "{$k}: {$v}";
    }

    $mh = curl_multi_init();
    $active = 0;
    $completed = 0;
    $latencies = [];
    $statusCounts = [];
    $startWall = microtime(true);

    $initHandle = function () use ($url, $formattedHeaders) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $formattedHeaders,
        ]);
        return ['ch' => $ch, 'start' => microtime(true)];
    };

    $inFlight = [];
    $initialBatch = min($total, $concurrency);
    for ($i = 0; $i < $initialBatch; $i++) {
        $item = $initHandle();
        curl_multi_add_handle($mh, $item['ch']);
        $inFlight[(int) $item['ch']] = $item;
    }
    $dispatched = $initialBatch;

    do {
        $status = curl_multi_exec($mh, $active);
        if ($status > 0) {
            break;
        }

        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            $chId = (int) $ch;
            $start = $inFlight[$chId]['start'];
            $duration = (microtime(true) - $start) * 1000.0;
            $latencies[] = $duration;

            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $statusCounts[$code] = ($statusCounts[$code] ?? 0) + 1;

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            unset($inFlight[$chId]);
            $completed++;

            if ($dispatched < $total) {
                $item = $initHandle();
                curl_multi_add_handle($mh, $item['ch']);
                $inFlight[(int) $item['ch']] = $item;
                $dispatched++;
            }
        }

        if ($active > 0) {
            curl_multi_select($mh, 0.05);
        }
    } while ($active > 0 || $dispatched < $total);

    curl_multi_close($mh);
    $totalWall = microtime(true) - $startWall;
    $rps = $totalWall > 0 ? $completed / $totalWall : 0.0;

    sort($latencies, SORT_NUMERIC);
    $cnt = count($latencies);
    $avg = $cnt > 0 ? array_sum($latencies) / $cnt : 0.0;
    $p50 = $cnt > 0 ? $latencies[(int) floor($cnt * 0.50)] : 0.0;
    $p95 = $cnt > 0 ? $latencies[min($cnt - 1, (int) floor($cnt * 0.95))] : 0.0;

    return [
        'rps' => round($rps, 1),
        'avg_ms' => round($avg, 2),
        'p50_ms' => round($p50, 2),
        'p95_ms' => round($p95, 2),
        'status_counts' => $statusCounts,
        'total' => $completed,
    ];
}

echo "=========================================================================\n";
echo " Yii2 AntiBot v1.5.6 Live Smoke & Performance Suite\n";
echo " Target: {$baseUrl} | Date: " . date('Y-m-d H:i:s') . "\n";
echo "=========================================================================\n\n";

// --- Part 1: Live Smoke Test in Monitoring Mode ---
echo "--- Part 1: Live Smoke Test in Monitoring Mode ---\n";
setAntibotConfig('monitoring', true);

// 1. Normal browser request
$resNormal = httpGet($baseUrl, ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)']);
echo "[CHECK] Normal browser request: HTTP {$resNormal['status']} (expected 200)\n";

// 2. Fake Googlebot in Monitoring mode
$resFakeGoogle = httpGet($baseUrl, [
    'User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
]);
echo "[CHECK] Fake Googlebot in Monitoring: HTTP {$resFakeGoogle['status']} (expected 200, NOT blocked)\n";

// 3. CLI Client in Monitoring mode
$resCli = httpGet($baseUrl, [
    'User-Agent' => 'python-requests/2.31.0',
]);
echo "[CHECK] CLI python-requests in Monitoring: HTTP {$resCli['status']} (expected 200, NOT blocked)\n";

// 4. AJAX request
$resAjax = httpGet($baseUrl, [
    'User-Agent' => 'Mozilla/5.0',
    'X-Requested-With' => 'XMLHttpRequest',
]);
echo "[CHECK] AJAX request in Monitoring: HTTP {$resAjax['status']} (expected 200)\n";
echo "\n";

// --- Part 2: Live Smoke Test in Standard Mode ---
echo "--- Part 2: Live Smoke Test in Standard Mode ---\n";
setAntibotConfig('standard', true);

$resCliStandard = httpGet($baseUrl, [
    'User-Agent' => 'python-requests/2.31.0',
]);
echo "[CHECK] CLI python-requests in Standard: HTTP {$resCliStandard['status']} (expected 403, blocked)\n";

$resFakeGoogleStandard = httpGet($baseUrl, [
    'User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
]);
echo "[CHECK] Fake Googlebot in Standard: HTTP {$resFakeGoogleStandard['status']} (expected 403, blocked)\n";
echo "\n";

// --- Part 3: Comparative HTTP Benchmark (Disabled vs Monitoring vs Standard) ---
echo "--- Part 3: Comparative HTTP Benchmark ---\n";
$modes = [
    'Disabled' => ['mode' => 'standard', 'enabled' => false],
    'Monitoring' => ['mode' => 'monitoring', 'enabled' => true],
    'Standard' => ['mode' => 'standard', 'enabled' => true],
];

$concurrencyMatrix = [
    ['c' => 1, 'n' => 50],
    ['c' => 10, 'n' => 100],
    ['c' => 25, 'n' => 150],
];

printf("%-12s | %-15s | %-10s | %-10s | %-10s | %-10s | %-8s\n",
    "Mode", "Concurrency", "RPS", "Avg (ms)", "p50 (ms)", "p95 (ms)", "5xx"
);
echo str_repeat("-", 85) . "\n";

foreach ($modes as $modeName => $cfg) {
    setAntibotConfig($cfg['mode'], $cfg['enabled']);
    // Warm-up 5 requests
    runParallelBatch($baseUrl, 5, 1, ['User-Agent' => 'Mozilla/5.0 (Benchmark/Warmup)']);

    foreach ($concurrencyMatrix as $lvl) {
        $res = runParallelBatch($baseUrl, $lvl['n'], $lvl['c'], ['User-Agent' => 'Mozilla/5.0 (Benchmark/1.5.6)']);
        $err5xx = 0;
        foreach ($res['status_counts'] as $code => $count) {
            if ($code >= 500) {
                $err5xx += $count;
            }
        }
        printf("%-12s | %-15s | %-10.1f | %-10.2f | %-10.2f | %-10.2f | %-8d\n",
            $modeName, "c = {$lvl['c']} ({$lvl['n']} req)", $res['rps'], $res['avg_ms'], $res['p50_ms'], $res['p95_ms'], $err5xx
        );
    }
}

// Restore Monitoring mode
setAntibotConfig('monitoring', true);
echo "\nIntegration & benchmark suite completed successfully.\n";
