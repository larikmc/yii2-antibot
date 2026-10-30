<?php

declare(strict_types=1);

/**
 * Yii2 AntiBot v1.5.5 Comparative HTTP Performance & Verification Test
 *
 * Runs controlled curl_multi benchmarks against http://yii2-advanced.loc/
 * for required matrices:
 * - 100 sequential requests (c = 1)
 * - 100 requests at c = 10
 * - 150 requests at c = 25
 * Across Monitoring and Standard modes.
 */

$baseUrl = 'http://yii2-advanced.loc/';
$settingsFile = 'D:/OSPanel6.5.1/home/yii2-advanced.loc/common/config/antibot.json';

function configureAntibotMode(string $mode, bool $enabled = true): void
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
    @file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function runHttpBatch(string $url, int $total, int $concurrency, array $headers = []): array
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

    $queue = [];
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
        'duration_s' => round($totalWall, 2),
    ];
}

echo "=========================================================================\n";
echo " Yii2 AntiBot v1.5.5 Comparative HTTP Performance Suite\n";
echo " Target: {$baseUrl} | Date: " . date('Y-m-d H:i:s') . "\n";
echo "=========================================================================\n\n";

$matrix = [
    ['total' => 100, 'c' => 1, 'desc' => '100 sequential (c = 1)'],
    ['total' => 100, 'c' => 10, 'desc' => '100 concurrent (c = 10)'],
    ['total' => 150, 'c' => 25, 'desc' => '150 concurrent (c = 25)'],
];

$modes = ['monitoring', 'standard'];

printf("%-12s | %-24s | %-10s | %-10s | %-10s | %-10s | %-8s\n",
    "Mode", "Load Profile", "RPS", "Avg (ms)", "p50 (ms)", "p95 (ms)", "5xx"
);
echo str_repeat("-", 95) . "\n";

foreach ($modes as $mode) {
    configureAntibotMode($mode);
    // Warm-up 10 requests
    runHttpBatch($baseUrl, 10, 2, ['User-Agent' => 'Mozilla/5.0 (Benchmark/1.5.5-warmup)']);

    foreach ($matrix as $test) {
        $res = runHttpBatch($baseUrl, $test['total'], $test['c'], ['User-Agent' => 'Mozilla/5.0 (Benchmark/1.5.5)']);
        $err5xx = 0;
        foreach ($res['status_counts'] as $code => $num) {
            if ($code >= 500) {
                $err5xx += $num;
            }
        }
        printf("%-12s | %-24s | %-10.1f | %-10.2f | %-10.2f | %-10.2f | %-8d\n",
            ucfirst($mode), $test['desc'], $res['rps'], $res['avg_ms'], $res['p50_ms'], $res['p95_ms'], $err5xx
        );
    }
}

echo "\nBenchmark completed successfully.\n";
