<?php

declare(strict_types=1);

namespace larikmc\antibot\services;

use larikmc\antibot\services\limiter\CacheLimiter;
use larikmc\antibot\services\limiter\RateLimiterInterface;
use larikmc\antibot\services\limiter\RedisLimiter;
use Yii;

/**
 * Universal Rate Limiter service with pluggable storage adapters.
 *
 * Provides:
 * - CacheLimiter: Fast, best-effort dual-bucket algorithm for standard setups without Redis.
 * - RedisLimiter: 100% atomic sliding window algorithm powered by Redis and Lua scripts.
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class RateLimiter
{
    public const ADAPTER_CACHE = 'cache';
    public const ADAPTER_REDIS = 'redis';

    /**
     * @var string Current active adapter ('cache' or 'redis').
     */
    public static string $adapter = self::ADAPTER_CACHE;

    private static ?RateLimiterInterface $_instance = null;

    /**
     * Resolves the active RateLimiterInterface instance.
     */
    public static function getAdapter(): RateLimiterInterface
    {
        if (self::$_instance !== null) {
            return self::$_instance;
        }

        if (self::$adapter === self::ADAPTER_REDIS) {
            self::$_instance = new RedisLimiter();
        } else {
            self::$_instance = new CacheLimiter();
        }

        return self::$_instance;
    }

    /**
     * Sets a custom adapter instance or type.
     */
    public static function setAdapter(RateLimiterInterface|string $adapter): void
    {
        if ($adapter instanceof RateLimiterInterface) {
            self::$_instance = $adapter;
        } else {
            self::$adapter = (string) $adapter;
            self::$_instance = null;
        }
    }

    /**
     * Checks whether an IP or key has exceeded the allowed rate limit.
     *
     * @param string $key Client IP or combined key.
     * @param int $maxRequests Max requests allowed within the window.
     * @param int $window Window duration in seconds.
     * @param int $banDuration Ban duration in seconds if threshold is repeatedly exceeded.
     * @return array{
     *     exceeded: bool,
     *     current: int,
     *     remaining: int,
     *     reset: int,
     *     retryAfter: int
     * }
     */
    public static function check(string $key, int $maxRequests = 60, int $window = 60, int $banDuration = 300): array
    {
        return self::getAdapter()->check($key, $maxRequests, $window, $banDuration);
    }
}
