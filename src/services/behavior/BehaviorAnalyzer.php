<?php

declare(strict_types=1);

namespace larikmc\antibot\services\behavior;

use Yii;

/**
 * High-speed, in-memory/cache-backed Behavioral Intelligence Analyzer.
 *
 * Tracks a bounded sliding window (max 15 requests, 300s TTL) of client navigation patterns
 * to identify automated crawlers, fast scrapers, and abuse heuristics without heavy I/O.
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class BehaviorAnalyzer
{
    public const WINDOW_SIZE = 15;
    public const WINDOW_TTL = 300;

    /**
     * Records a request and evaluates behavioral anomalies.
     *
     * @param string $ip Client IP.
     * @param string $path Clean request URI path (without query string).
     * @param bool $isSearch Whether this request is a search query.
     * @param bool $isError Whether this request resulted in an error (403/404/429).
     * @return array{
     *     signals: array<int, string>,
     *     risk: array{score: int, level: string, action: string, signals: array<int, string>}
     * }
     */
    public static function analyze(string $ip, string $path, bool $isSearch = false, bool $isError = false): array
    {
        $defaultResult = [
            'signals' => [],
            'risk' => ['score' => 0, 'level' => 'low', 'action' => 'allow', 'signals' => []],
        ];

        if (Yii::$app === null || !Yii::$app->has('cache') || Yii::$app->cache === null) {
            return $defaultResult;
        }

        try {
            $now = microtime(true);
            $cache = Yii::$app->cache;
            $historyKey = 'antibot_bh_' . md5($ip);
            $violationsKey = 'antibot_viols_' . md5($ip);

            // Compact entry: [timestamp, path_crc32, is_search, is_error]
            $currentEntry = [
                $now,
                crc32($path),
                $isSearch ? 1 : 0,
                $isError ? 1 : 0,
            ];

            $history = $cache->get($historyKey);
            $history = is_array($history) ? $history : [];

            // Append and trim to bounded size
            $history[] = $currentEntry;
            if (count($history) > self::WINDOW_SIZE) {
                $history = array_slice($history, -self::WINDOW_SIZE);
            }
            $cache->set($historyKey, $history, self::WINDOW_TTL);

            // Detect anomalies
            $signals = [];
            $count = count($history);

            if ($count >= 4) {
                // 1. Rapid Navigation (Fast automated clicks)
                $intervals = [];
                for ($i = 1; $i < $count; $i++) {
                    $intervals[] = max(0.001, $history[$i][0] - $history[$i - 1][0]);
                }
                sort($intervals);
                $medianInterval = $intervals[(int) (count($intervals) / 2)];

                // Humans rarely browse at < 250ms median across multiple pages
                if ($medianInterval < 0.25) {
                    $signals[] = 'rapid_navigation';
                }

                // 2. High URL Diversity (Crawler sweeping distinct links rapidly)
                if ($count >= 8) {
                    $uniquePaths = [];
                    foreach ($history as $h) {
                        $uniquePaths[$h[1]] = true;
                    }
                    $diversityRatio = count($uniquePaths) / (float) $count;
                    if ($diversityRatio > 0.85 && $medianInterval < 1.5) {
                        $signals[] = 'high_url_diversity';
                    }
                }

                // 3. Search Abuse
                $searchCount = 0;
                $searchWindowStart = $now - 30; // within last 30 seconds
                foreach ($history as $h) {
                    if ($h[2] === 1 && $h[0] >= $searchWindowStart) {
                        $searchCount++;
                    }
                }
                if ($searchCount >= 5) {
                    $signals[] = 'search_abuse';
                }

                // 4. Error Burst (scanning for vulnerabilities / 404s)
                $errorCount = 0;
                foreach ($history as $h) {
                    if ($h[3] === 1) {
                        $errorCount++;
                    }
                }
                if ($errorCount >= 3) {
                    $signals[] = 'error_burst';
                }

                // 5. Repeated Violations
                $violations = $cache->get($violationsKey);
                if ($violations !== false && is_int($violations) && $violations >= 2) {
                    $signals[] = 'repeated_violations';
                }
            }

            $risk = RiskEngine::evaluate($signals);

            return [
                'signals' => $signals,
                'risk' => $risk,
            ];
        } catch (\Throwable $e) {
            Yii::warning('BehaviorAnalyzer error: ' . $e->getMessage(), __METHOD__);
            return $defaultResult;
        }
    }

    /**
     * Records a security violation for the client IP to track repeated abuse.
     */
    public static function recordViolation(string $ip): void
    {
        if (Yii::$app === null || !Yii::$app->has('cache') || Yii::$app->cache === null) {
            return;
        }

        try {
            $key = 'antibot_viols_' . md5($ip);
            $count = Yii::$app->cache->get($key);
            $count = is_int($count) ? $count + 1 : 1;
            Yii::$app->cache->set($key, $count, 600); // 10 minutes TTL
        } catch (\Throwable) {
            // Best-effort
        }
    }
}
