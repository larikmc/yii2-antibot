<?php

declare(strict_types=1);

namespace larikmc\antibot\services;

use Yii;

/**
 * High-speed cache-backed rate limiter for flood and DDoS scraper protection.
 *
 * Uses sliding window counter in Yii::$app->cache.
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class RateLimiter
{
    /**
     * Checks whether an IP has exceeded the allowed rate limit.
     *
     * @param string $ip Client IP.
     * @param int $maxRequests Max requests allowed within the window.
     * @param int $window Window duration in seconds.
     * @return array{exceeded: bool, current: int, remaining: int, reset: int}
     */
    public static function check(string $ip, int $maxRequests = 60, int $window = 60): array
    {
        if (Yii::$app === null || !Yii::$app->has('cache') || Yii::$app->cache === null) {
            return [
                'exceeded' => false,
                'current' => 1,
                'remaining' => $maxRequests - 1,
                'reset' => time() + $window,
            ];
        }

        $now = time();
        $bucketKey = 'antibot_rl_' . md5($ip);
        $banKey = 'antibot_ban_' . md5($ip);

        // Check if currently temporarily banned
        $banUntil = Yii::$app->cache->get($banKey);
        if ($banUntil !== false && is_int($banUntil) && $now < $banUntil) {
            return [
                'exceeded' => true,
                'current' => $maxRequests + 1,
                'remaining' => 0,
                'reset' => $banUntil,
            ];
        }

        $data = Yii::$app->cache->get($bucketKey);

        if (!is_array($data) || !isset($data['reset'], $data['count']) || $now > $data['reset']) {
            $data = [
                'count' => 1,
                'reset' => $now + $window,
            ];
            Yii::$app->cache->set($bucketKey, $data, $window);

            return [
                'exceeded' => false,
                'current' => 1,
                'remaining' => max(0, $maxRequests - 1),
                'reset' => $data['reset'],
            ];
        }

        $data['count']++;
        Yii::$app->cache->set($bucketKey, $data, max(1, $data['reset'] - $now));

        $exceeded = $data['count'] > $maxRequests;
        if ($exceeded) {
            // Apply a 5-minute temporary ban on repeated violations
            Yii::$app->cache->set($banKey, $now + 300, 300);
        }

        return [
            'exceeded' => $exceeded,
            'current' => $data['count'],
            'remaining' => max(0, $maxRequests - $data['count']),
            'reset' => $data['reset'],
        ];
    }
}
