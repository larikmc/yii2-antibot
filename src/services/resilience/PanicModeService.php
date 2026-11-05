<?php

declare(strict_types=1);

namespace larikmc\antibot\services\resilience;

use Yii;
use yii\helpers\Json;

/**
 * Emergency "Under Attack" / Panic Defense Mode Service.
 *
 * Provides a 1-click elevated defense mechanism during active DDoS floods or brute-force attacks:
 * - Halves rate limiting thresholds for aggressive throttling.
 * - Forces strict cryptographic Proof-of-Work (PoW) or JS verification for unverified clients.
 * - Immediate zero-tolerance detention into Auto-Jail (threshold = 1).
 * - Timed auto-expiration (default 15 minutes, 30 minutes, or 1 hour) so site automatically returns to normal.
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class PanicModeService
{
    public const CACHE_KEY = 'antibot:panic_mode_until';
    public const DEFAULT_DURATION = 900; // 15 minutes

    /**
     * Checks whether Emergency Panic Mode is currently active.
     */
    public static function isActive(): bool
    {
        return self::getRemainingSeconds() > 0;
    }

    /**
     * Returns remaining seconds of active Panic Mode (0 if inactive).
     */
    public static function getRemainingSeconds(): int
    {
        $now = time();

        // Check Cache first
        if (Yii::$app !== null && Yii::$app->has('cache') && Yii::$app->cache !== null) {
            try {
                $until = Yii::$app->cache->get(self::CACHE_KEY);
                if (is_int($until)) {
                    return max(0, $until - $now);
                }
            } catch (\Throwable) {
                // Ignore
            }
        }

        // Fallback to runtime state file
        $file = self::getStorageFilePath();
        if (file_exists($file)) {
            try {
                $content = file_get_contents($file);
                if ($content !== false && trim($content) !== '') {
                    $data = Json::decode($content);
                    $until = (int) ($data['until'] ?? 0);
                    return max(0, $until - $now);
                }
            } catch (\Throwable) {
                // Ignore
            }
        }

        return 0;
    }

    /**
     * Activates Panic Mode for specified duration.
     *
     * @param int $duration Seconds to maintain elevated defense (default 900 = 15m).
     * @return int Expiration timestamp.
     */
    public static function activate(int $duration = self::DEFAULT_DURATION): int
    {
        $duration = max(60, min(86400, $duration));
        $until = time() + $duration;

        // Write to Cache
        if (Yii::$app !== null && Yii::$app->has('cache') && Yii::$app->cache !== null) {
            try {
                Yii::$app->cache->set(self::CACHE_KEY, $until, $duration);
            } catch (\Throwable) {
                // Ignore
            }
        }

        // Persist to file
        $file = self::getStorageFilePath();
        try {
            $json = Json::encode(['until' => $until, 'activated_at' => time(), 'duration' => $duration]);
            file_put_contents($file, $json, LOCK_EX);
        } catch (\Throwable) {
            // Best effort
        }

        return $until;
    }

    /**
     * Deactivates Panic Mode immediately.
     */
    public static function deactivate(): void
    {
        if (Yii::$app !== null && Yii::$app->has('cache') && Yii::$app->cache !== null) {
            try {
                Yii::$app->cache->delete(self::CACHE_KEY);
            } catch (\Throwable) {
                // Ignore
            }
        }

        $file = self::getStorageFilePath();
        if (file_exists($file)) {
            @unlink($file);
        }
    }

    private static function getStorageFilePath(): string
    {
        $dir = Yii::getAlias('@runtime/antibot', false);
        if ($dir === false || $dir === '') {
            $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'antibot';
        }

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir . DIRECTORY_SEPARATOR . 'panic_mode.json';
    }
}
