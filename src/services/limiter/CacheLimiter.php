<?php

declare(strict_types=1);

namespace larikmc\antibot\services\limiter;

use Yii;

/**
 * High-speed cache-backed rate limiter (Best-effort dual-bucket algorithm).
 *
 * Designed for standard environments without Redis.
 * Avoids heavy file locks to prevent PHP-FPM worker starvation under high load.
 * Gracefully degrades (fails open) if cache is unreachable or throws exceptions.
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class CacheLimiter implements RateLimiterInterface
{
    /**
     * {@inheritdoc}
     */
    public function check(string $key, int $maxRequests = 60, int $window = 60, int $banDuration = 300): array
    {
        $now = time();
        $fallback = [
            'exceeded' => false,
            'current' => 1,
            'remaining' => max(0, $maxRequests - 1),
            'reset' => $now + $window,
            'retryAfter' => 0,
        ];

        try {
            if (Yii::$app === null || !Yii::$app->has('cache') || Yii::$app->cache === null) {
                return $fallback;
            }

            $cache = Yii::$app->cache;
            $hash = md5($key);
            $banKey = 'antibot_ban_' . $hash;

            // 1. Check temporary ban
            $banUntil = $cache->get($banKey);
            if ($banUntil !== false && is_int($banUntil) && $now < $banUntil) {
                return [
                    'exceeded' => true,
                    'current' => $maxRequests + 1,
                    'remaining' => 0,
                    'reset' => $banUntil,
                    'retryAfter' => max(1, $banUntil - $now),
                ];
            }

            // 2. Sliding window approximation using current and previous bucket
            $currentBucket = (int) ($now / $window);
            $bucketOffset = $now % $window;
            $currentKey = sprintf('antibot_rl_%s_%d', $hash, $currentBucket);
            $previousKey = sprintf('antibot_rl_%s_%d', $hash, $currentBucket - 1);

            $currentCount = $cache->get($currentKey);
            $currentCount = is_int($currentCount) ? $currentCount : 0;

            $prevCount = $cache->get($previousKey);
            $prevCount = is_int($prevCount) ? $prevCount : 0;

            // Weight previous bucket by remaining portion of window
            $weight = ($window - $bucketOffset) / (float) $window;
            $estimatedCount = (int) round($prevCount * $weight + $currentCount + 1);

            // Increment current bucket
            $newCurrent = $currentCount + 1;
            $cache->set($currentKey, $newCurrent, $window * 2);

            $exceeded = $estimatedCount > $maxRequests;
            $retryAfter = 0;

            if ($exceeded) {
                $retryAfter = max(1, $window - $bucketOffset);
                if ($banDuration > 0 && $estimatedCount > ($maxRequests * 1.5)) {
                    // Impose ban if significantly over limit
                    $banUntil = $now + $banDuration;
                    $cache->set($banKey, $banUntil, $banDuration);
                    $retryAfter = $banDuration;
                }
            }

            return [
                'exceeded' => $exceeded,
                'current' => $estimatedCount,
                'remaining' => max(0, $maxRequests - $estimatedCount),
                'reset' => $now + max(1, $window - $bucketOffset),
                'retryAfter' => $retryAfter,
            ];
        } catch (\Throwable $e) {
            Yii::warning('CacheLimiter failed: ' . $e->getMessage(), __METHOD__);
            return $fallback;
        }
    }
}
