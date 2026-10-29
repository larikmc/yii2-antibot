<?php

declare(strict_types=1);

namespace larikmc\antibot\services\limiter;

use Yii;

/**
 * Weighted Rate Limiter (Load-Budget Limiter).
 *
 * Tracks client resource consumption in "cost points" rather than raw HTTP hits:
 * - Simple static page: 1 point
 * - Category / listing: 2 points
 * - Search query: 5 points
 * - Complex filter: 8 points
 * - Heavy API / export: 10 points
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class WeightedRateLimiter
{
    public const DEFAULT_BUDGET = 150;
    public const DEFAULT_WINDOW = 60;

    /**
     * Resolves the point cost for a given request path.
     *
     * @param string $path
     * @param array<string, int> $customWeights
     * @return int Cost in points (minimum 1).
     */
    public static function resolveCost(string $path, array $customWeights = []): int
    {
        $path = strtolower(trim($path, '/'));

        // Check user-configured weights first
        foreach ($customWeights as $pattern => $weight) {
            $pattern = strtolower(trim($pattern, '/'));
            if ($path === $pattern || fnmatch($pattern, $path) || str_contains($path, $pattern)) {
                return max(1, (int) $weight);
            }
        }

        // Built-in heuristic weights
        if (str_contains($path, 'search') || str_contains($path, 'find')) {
            return 5;
        }

        if (str_contains($path, 'filter') || str_contains($path, 'sort')) {
            return 8;
        }

        if (str_starts_with($path, 'api/') || str_contains($path, 'export') || str_contains($path, 'report')) {
            return 10;
        }

        if (str_starts_with($path, 'catalog') || str_starts_with($path, 'category') || str_starts_with($path, 'shop')) {
            return 2;
        }

        return 1;
    }

    /**
     * Checks client budget and charges points.
     *
     * @param string $ip Client IP.
     * @param int $cost Points to deduct for current request.
     * @param int $budget Total budget in points per window.
     * @param int $window Window duration in seconds.
     * @return array{exceeded: bool, current: int, remaining: int, reset: int, retryAfter: int}
     */
    public static function check(string $ip, int $cost = 1, int $budget = self::DEFAULT_BUDGET, int $window = self::DEFAULT_WINDOW): array
    {
        $now = time();
        $fallback = [
            'exceeded' => false,
            'current' => $cost,
            'remaining' => max(0, $budget - $cost),
            'reset' => $now + $window,
            'retryAfter' => 0,
        ];

        if (Yii::$app === null || !Yii::$app->has('cache') || Yii::$app->cache === null) {
            return $fallback;
        }

        try {
            $cache = Yii::$app->cache;
            $hash = md5($ip);
            $currentBucket = (int) ($now / $window);
            $bucketOffset = $now % $window;

            $currentKey = sprintf('antibot_wrl_%s_%d', $hash, $currentBucket);
            $prevKey = sprintf('antibot_wrl_%s_%d', $hash, $currentBucket - 1);

            $currentPoints = $cache->get($currentKey);
            $currentPoints = is_int($currentPoints) ? $currentPoints : 0;

            $prevPoints = $cache->get($prevKey);
            $prevPoints = is_int($prevPoints) ? $prevPoints : 0;

            $weight = ($window - $bucketOffset) / (float) $window;
            $estimatedTotal = (int) round($prevPoints * $weight + $currentPoints + $cost);

            // Increment current bucket
            $cache->set($currentKey, $currentPoints + $cost, $window * 2);

            $exceeded = $estimatedTotal > $budget;
            $retryAfter = $exceeded ? max(1, $window - $bucketOffset) : 0;

            return [
                'exceeded' => $exceeded,
                'current' => $estimatedTotal,
                'remaining' => max(0, $budget - $estimatedTotal),
                'reset' => $now + max(1, $window - $bucketOffset),
                'retryAfter' => $retryAfter,
            ];
        } catch (\Throwable $e) {
            Yii::warning('WeightedRateLimiter error: ' . $e->getMessage(), __METHOD__);
            return $fallback;
        }
    }
}
