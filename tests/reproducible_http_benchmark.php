<?php

declare(strict_types=1);

/**
 * Reproducible HTTP Load Benchmark Suite for Yii2 AntiBot v1.5.4
 *
 * Executes multi-concurrency load tests against real local application http://yii2-advanced.loc/
 * using native curl_multi parallel workers with 3-run median aggregation.
 */

$baseUrl = 'http://yii2-advanced.loc/';
$settingsFile = 'D:/OSPanel6.5.1/home/yii2-advanced.loc/common/config/antibot.json';

function configureAntibot(string $mode, bool $enabled, bool $pow = false, bool $behavior = true, ?string $file = null): void
{
    $file = $file ?? 'D:/OSPanel6.5.1/home/yii2-advanced.loc/common/config/antibot.json';
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
        'behaviorAnalysisEnabled' => $behavior,
        'powEnabled' => $pow,
        'trustedProxies' => ['127.0.0.1'],
        'whitelist' => [],
    ];
    @file_put_contents($file, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

/**
 * Runs a concurrent batch of HTTP requests via curl_multi.
 *
 * @param string $url
 * @param int $totalRequests
 * @param int $concurrency
 * @param array<string, string> $headers
 * @return array{
 *     rps: float,
 *     avg_ms: float,
 *     p50_ms: float,
 *     p95_ms: float,
 *     p99_ms: float,
 *     status_counts: array<int, int>,
 *     total: int,
 *     duration_s: float
 * }
 */
function runBatch(string $url, int $totalRequests, int $concurrency, array $headers = []): array
{
    $formattedHeaders = [];
    foreach ($headers as $k => $v) {
        $formattedHeaders[] = "{$k}: {$v}";
    }

    $latencies = [];
    $statusCounts = [];
    $completed = 0;
    $started = 0;

    $mh = curl_multi_init();
    $activeHandles = [];

    $batchStart = microtime(true);

    while ($completed < $totalRequests) {
        while ($started < $totalRequests && count($activeHandles) < $concurrency) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            if (!empty($formattedHeaders)) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $formattedHeaders);
            }

            $reqStart = microtime(true);
            curl_multi_add_handle($mh, $ch);
            $id = (int) $ch;
            $activeHandles[$id] = ['handle' => $ch, 'start' => $reqStart];
            $started++;
        }

        curl_multi_exec($mh, $running);

        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            $id = (int) $ch;
            $reqEnd = microtime(true);
            $durationMs = ($reqEnd - $activeHandles[$id]['start']) * 1000.0;
            $latencies[] = $durationMs;

            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $statusCounts[$statusCode] = ($statusCounts[$statusCode] ?? 0) + 1;

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            unset($activeHandles[$id]);
            $completed++;
        }

        if ($running) {
            curl_multi_select($mh, 0.05);
        }
    }

    curl_multi_close($mh);
    $batchEnd = microtime(true);
    $totalDuration = max(0.001, $batchEnd - $batchStart);

    sort($latencies, SORT_NUMERIC);
    $count = count($latencies);
    $avgMs = $count > 0 ? (array_sum($latencies) / $count) : 0.0;
    $p50Ms = $count > 0 ? $latencies[(int) floor($count * 0.50)] : 0.0;
    $p95Ms = $count > 0 ? $latencies[min($count - 1, (int) floor($count * 0.95))] : 0.0;
    $p99Ms = $count > 0 ? $latencies[min($count - 1, (int) floor($count * 0.99))] : 0.0;
    $rps = $totalRequests / $totalDuration;

    return [
        'rps' => round($rps, 1),
        'avg_ms' => round($avgMs, 2),
        'p50_ms' => round($p50Ms, 2),
        'p95_ms' => round($p95Ms, 2),
        'p99_ms' => round($p99Ms, 2),
        'status_counts' => $statusCounts,
        'total' => $totalRequests,
        'duration_s' => round($totalDuration, 3),
    ];
}

/**
 * Runs 3 series passes with pre-warmup and calculates median metrics.
 */
function benchmarkSeries(string $url, int $totalRequests, int $concurrency, array $headers = []): array
{
    // Pre-warmup
    runBatch($url, 10, min(5, $concurrency), $headers);

    $runs = [];
    for ($run = 1; $run <= 3; $run++) {
        usleep(50000); // 50ms cooldown
        $res = runBatch($url, $totalRequests, $concurrency, $headers);
        $runs[] = $res;
    }

    // Sort by RPS and take the median run (index 1)
    usort($runs, static fn($a, $b) => $a['rps'] <=> $b['rps']);
    $median = $runs[1];

    // Compute variance in RPS across runs
    $rpsValues = array_column($runs, 'rps');
    $rpsMin = min($rpsValues);
    $rpsMax = max($rpsValues);
    $variance = round(($rpsMax - $rpsMin) / max(1.0, $median['rps']) * 100, 1);
    $median['variance_percent'] = $variance;

    return $median;
}

echo "=========================================================================\n";
echo " Yii2 AntiBot v1.5.4 Reproducible HTTP Load Benchmark Suite\n";
echo " Target: {$baseUrl} | Date: " . date('Y-m-d H:i:s') . "\n";
echo "=========================================================================\n\n";

// ----------------------------------------------------
// Part 1: Concurrency Benchmark (c = 1, 10, 25, 50, 100)
// ----------------------------------------------------
echo "--- PART 1: Concurrency Scaling (Mode: Standard) ---\n";
configureAntibot('standard', true, false, true);

$concurrencyLevels = [
    1 => 50,
    10 => 100,
    25 => 150,
    50 => 200,
    100 => 200,
];

printf("%-12s | %-8s | %-10s | %-10s | %-10s | %-10s | %-8s | %-10s\n",
    "Concurrency", "Total", "RPS", "Avg (ms)", "p50 (ms)", "p95 (ms)", "5xx", "Variance"
);
echo str_repeat("-", 85) . "\n";

$concurrencyResults = [];
foreach ($concurrencyLevels as $c => $reqCount) {
    $res = benchmarkSeries($baseUrl, $reqCount, $c, ['User-Agent' => 'Mozilla/5.0 (Benchmark/1.5.4)']);
    $err5xx = 0;
    foreach ($res['status_counts'] as $code => $cnt) {
        if ($code >= 500) {
            $err5xx += $cnt;
        }
    }
    $concurrencyResults[$c] = $res;
    printf("%-12s | %-8d | %-10.1f | %-10.2f | %-10.2f | %-10.2f | %-8d | %-6.1f%%\n",
        "c = {$c}", $reqCount, $res['rps'], $res['avg_ms'], $res['p50_ms'], $res['p95_ms'], $err5xx, $res['variance_percent']
    );
}
echo "\n";

// ----------------------------------------------------
// Part 2: 4 Protection Modes Comparison (at c = 25)
// ----------------------------------------------------
echo "--- PART 2: Mode Comparison at Concurrency c = 25 (100 requests) ---\n";

$modes = [
    'Disabled' => ['mode' => 'standard', 'enabled' => false, 'pow' => false, 'behavior' => false],
    'Monitoring' => ['mode' => 'monitoring', 'enabled' => true, 'pow' => false, 'behavior' => true],
    'Standard' => ['mode' => 'standard', 'enabled' => true, 'pow' => false, 'behavior' => true],
    'Full Defense' => ['mode' => 'standard', 'enabled' => true, 'pow' => true, 'behavior' => true],
];

printf("%-15s | %-10s | %-10s | %-10s | %-10s | %-12s | %-10s\n",
    "Mode", "RPS", "Avg (ms)", "p50 (ms)", "p95 (ms)", "Overhead", "5xx Errors"
);
echo str_repeat("-", 85) . "\n";

$baseAvg = 0.0;
$modeResults = [];
foreach ($modes as $modeName => $cfg) {
    configureAntibot($cfg['mode'], $cfg['enabled'], $cfg['pow'], $cfg['behavior']);
    $res = benchmarkSeries($baseUrl, 100, 25, ['User-Agent' => 'Mozilla/5.0 (Benchmark/1.5.4)']);
    $modeResults[$modeName] = $res;

    if ($modeName === 'Disabled') {
        $baseAvg = $res['avg_ms'];
        $overheadStr = "0.00 ms (base)";
    } else {
        $diff = max(0.0, $res['avg_ms'] - $baseAvg);
        $overheadStr = sprintf("+%.2f ms", $diff);
    }

    $err5xx = 0;
    foreach ($res['status_counts'] as $code => $cnt) {
        if ($code >= 500) {
            $err5xx += $cnt;
        }
    }

    printf("%-15s | %-10.1f | %-10.2f | %-10.2f | %-10.2f | %-12s | %-10d\n",
        $modeName, $res['rps'], $res['avg_ms'], $res['p50_ms'], $res['p95_ms'], $overheadStr, $err5xx
    );
}
echo "\n";

// ----------------------------------------------------
// Part 3: Attack Mitigation Benchmark
// ----------------------------------------------------
echo "--- PART 3: Attack Mitigation & Defense Verification (c = 10, 50 requests) ---\n";
configureAntibot('standard', true, false, true);

$attacks = [
    'Scraper Bot' => [
        'ua' => 'python-requests/2.31.0',
        'expected' => 403,
    ],
    'Fake Googlebot' => [
        'ua' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        'expected' => 403,
    ],
    'Fake YandexBot' => [
        'ua' => 'Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)',
        'expected' => 403,
    ],
    'Commercial SEO' => [
        'ua' => 'Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)',
        'expected' => 403,
    ],
];

printf("%-18s | %-10s | %-10s | %-15s | %-12s\n",
    "Attack Type", "RPS", "Avg (ms)", "Status Codes", "Block Rate"
);
echo str_repeat("-", 75) . "\n";

foreach ($attacks as $attName => $attCfg) {
    $res = benchmarkSeries($baseUrl, 50, 10, ['User-Agent' => $attCfg['ua']]);
    $expectedCount = $res['status_counts'][$attCfg['expected']] ?? 0;
    $blockRate = round(($expectedCount / $res['total']) * 100, 1);
    $statusSummary = [];
    foreach ($res['status_counts'] as $sc => $cnt) {
        $statusSummary[] = "{$sc}: {$cnt}";
    }

    printf("%-18s | %-10.1f | %-10.2f | %-15s | %-10.1f%%\n",
        $attName, $res['rps'], $res['avg_ms'], implode(', ', $statusSummary), $blockRate
    );
}
echo "\n";

// Restore standard settings
configureAntibot('standard', true, false, true);
echo "Benchmark completed successfully.\n";
