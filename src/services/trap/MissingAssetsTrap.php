<?php

declare(strict_types=1);

namespace larikmc\antibot\services\trap;

use Yii;

/**
 * Missing Assets Trap for detecting headless/console scrapers.
 *
 * Console scrapers (Python requests, Scrapy, curl, aiohttp) load purely HTML
 * and deliberately skip static assets (CSS, JS, images, fonts, favicon).
 *
 * Real browsers always request accompanying assets. When an IP performs consecutive
 * HTML requests without any asset or beacon hits, it is flagged as an automated scraper.
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class MissingAssetsTrap
{
    public const DEFAULT_BEACON_ROUTE = '/antibot-beacon.png';
    public const CACHE_PREFIX = 'antibot_asset_trap_';

    /**
     * In-memory fallback tracking for CLI or when cache component is unavailable.
     *
     * @var array<string, array{count: int, expire: int}>
     */
    protected static array $inMemoryState = [];

    /**
     * Records an HTML page request from the client and returns the updated consecutive HTML count.
     *
     * @param string $ip Client IP
     * @param int $window Sliding window in seconds (default: 60)
     * @return int Consecutive HTML page requests without asset hit
     */
    public static function recordHtmlRequest(string $ip, int $window = 60): int
    {
        $cache = self::resolveCache();
        $key = self::CACHE_PREFIX . md5($ip);

        if ($cache !== null) {
            $current = (int) $cache->get($key);
            $newCount = $current + 1;
            $cache->set($key, $newCount, $window);
            return $newCount;
        }

        // Memory fallback
        $now = time();
        if (isset(self::$inMemoryState[$ip]) && self::$inMemoryState[$ip]['expire'] > $now) {
            self::$inMemoryState[$ip]['count']++;
        } else {
            self::$inMemoryState[$ip] = [
                'count' => 1,
                'expire' => $now + $window,
            ];
        }

        return self::$inMemoryState[$ip]['count'];
    }

    /**
     * Records an asset hit (beacon or static asset) for the client IP, resetting the counter.
     *
     * @param string $ip Client IP
     * @return void
     */
    public static function recordAssetHit(string $ip): void
    {
        $cache = self::resolveCache();
        $key = self::CACHE_PREFIX . md5($ip);

        if ($cache !== null) {
            $cache->delete($key);
        }

        unset(self::$inMemoryState[$ip]);
    }

    /**
     * Checks if client has exceeded the threshold of consecutive HTML requests without asset hits.
     *
     * @param string $ip Client IP
     * @param int $threshold Threshold of consecutive HTML requests
     * @param int $window Sliding window in seconds
     * @return bool
     */
    public static function isAnomaly(string $ip, int $threshold = 6, int $window = 60): bool
    {
        $cache = self::resolveCache();
        $key = self::CACHE_PREFIX . md5($ip);

        if ($cache !== null) {
            $count = (int) $cache->get($key);
            return $count >= $threshold;
        }

        $now = time();
        if (isset(self::$inMemoryState[$ip]) && self::$inMemoryState[$ip]['expire'] > $now) {
            return self::$inMemoryState[$ip]['count'] >= $threshold;
        }

        return false;
    }

    /**
     * Resets state for testing or release.
     *
     * @param string|null $ip If null, resets all
     */
    public static function reset(?string $ip = null): void
    {
        if ($ip !== null) {
            self::recordAssetHit($ip);
        } else {
            self::$inMemoryState = [];
        }
    }

    /**
     * Renders a 1x1 transparent beacon image tag for embedding into HTML responses.
     *
     * @param string $beaconRoute
     * @return string
     */
    public static function renderBeaconTag(string $beaconRoute = self::DEFAULT_BEACON_ROUTE): string
    {
        $cleanRoute = '/' . ltrim($beaconRoute, '/');
        return '<img src="' . htmlspecialchars($cleanRoute, ENT_QUOTES, 'UTF-8') . '" width="1" height="1" style="position:absolute;left:-9999px;opacity:0;pointer-events:none" alt="" aria-hidden="true" />';
    }

    /**
     * Returns 1x1 transparent PNG raw binary bytes for instantaneous delivery.
     *
     * @return string
     */
    public static function getTransparentPngBytes(): string
    {
        // 1x1 transparent 8-bit PNG
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
    }

    /**
     * Resolves the application cache component if available.
     *
     * @return \yii\caching\CacheInterface|null
     */
    protected static function resolveCache(): ?\yii\caching\CacheInterface
    {
        if (Yii::$app !== null && Yii::$app->has('cache')) {
            $cache = Yii::$app->get('cache');
            if ($cache instanceof \yii\caching\CacheInterface) {
                return $cache;
            }
        }
        return null;
    }
}
