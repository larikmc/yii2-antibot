<?php

declare(strict_types=1);

namespace larikmc\antibot\services\resilience;

use Yii;
use yii\helpers\Json;

/**
 * Autonomous Auto-Jail (Quarantine) Service for Chronic Attackers.
 *
 * Temporarily isolates malicious IPs that trigger critical security violations:
 * - Invisible honey URL trap hits
 * - Spoofed search engine bots (failing reverse DNS)
 * - Repeated rate limit breaches within short intervals
 * - High behavioral anomaly scores
 *
 * Architecture:
 * - Sub-millisecond cache check (Yii::$app->cache) with fallback to file-based registry.
 * - Auto-expiring leases (TTL seconds) without requiring periodic cron jobs.
 * - Zero external API or database dependencies.
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class AutoJailService
{
    public const CACHE_PREFIX_JAIL = 'antibot:jail:';
    public const CACHE_PREFIX_VIOLS = 'antibot:viols:';
    public const VIOLATION_WINDOW = 600; // 10 minutes sliding window for tracking offenses
    public const DEFAULT_THRESHOLD = 2;
    public const DEFAULT_DURATION = 1800; // 30 minutes

    private static array $_memoryViols = [];

    /**
     * Checks whether an IP is currently detained in quarantine.
     */
    public static function isJailed(string $ip): bool
    {
        $ip = trim($ip);
        if ($ip === '' || $ip === '127.0.0.1' || $ip === '::1') {
            return false;
        }

        // Fast path 1: Yii Application Cache
        if (Yii::$app !== null && Yii::$app->has('cache') && Yii::$app->cache !== null) {
            try {
                $val = Yii::$app->cache->get(self::CACHE_PREFIX_JAIL . md5($ip));
                if ($val !== false && is_array($val) && ($val['until'] ?? 0) > time()) {
                    return true;
                }
            } catch (\Throwable) {
                // Ignore cache exceptions, fallback to storage
            }
        }

        // Fast path 2: File-based persistence
        $registry = self::loadRegistry();
        if (isset($registry[$ip])) {
            if ($registry[$ip]['until'] > time()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Records a security violation for an IP. Detains the IP in Auto-Jail if threshold is reached.
     *
     * @param string $ip Offending client IP.
     * @param string $reason Reason for violation.
     * @param int $threshold Max violations allowed before detention.
     * @param int $duration Detention duration in seconds.
     * @return int|null Remaining detention duration in seconds if jailed; null if not yet jailed.
     */
    public static function recordViolation(
        string $ip,
        string $reason,
        int $threshold = self::DEFAULT_THRESHOLD,
        int $duration = self::DEFAULT_DURATION
    ): ?int {
        $ip = trim($ip);
        if ($ip === '' || $ip === '127.0.0.1' || $ip === '::1') {
            return null;
        }

        $now = time();
        $violsKey = self::CACHE_PREFIX_VIOLS . md5($ip);
        $jailKey = self::CACHE_PREFIX_JAIL . md5($ip);
        $threshold = max(1, $threshold);
        $duration = max(60, $duration);

        $violCount = 1;

        if (Yii::$app !== null && Yii::$app->has('cache') && Yii::$app->cache !== null) {
            try {
                $cache = Yii::$app->cache;
                $current = $cache->get($violsKey);
                $violCount = is_int($current) ? $current + 1 : 1;
                $cache->set($violsKey, $violCount, self::VIOLATION_WINDOW);
            } catch (\Throwable) {
                self::$_memoryViols[$ip] = (self::$_memoryViols[$ip] ?? 0) + 1;
                $violCount = self::$_memoryViols[$ip];
            }
        } else {
            self::$_memoryViols[$ip] = (self::$_memoryViols[$ip] ?? 0) + 1;
            $violCount = self::$_memoryViols[$ip];
        }

        if ($violCount >= $threshold) {
            $until = $now + $duration;
            $data = [
                'ip' => $ip,
                'reason' => $reason,
                'jailed_at' => $now,
                'until' => $until,
            ];

            // 1. Write to Cache
            if (Yii::$app !== null && Yii::$app->has('cache') && Yii::$app->cache !== null) {
                try {
                    Yii::$app->cache->set($jailKey, $data, $duration);
                } catch (\Throwable) {
                    // Ignore
                }
            }

            // 2. Persist to file
            $registry = self::loadRegistry();
            $registry[$ip] = $data;
            self::saveRegistry($registry);

            return $duration;
        }

        return null;
    }

    /**
     * Retrieves all currently detained IPs and auto-prunes expired leases.
     *
     * @return array<int, array{ip: string, reason: string, jailed_at: int, until: int, remaining_sec: int}>
     */
    public static function getJailedIps(): array
    {
        $registry = self::loadRegistry();
        $now = time();
        $active = [];
        $dirty = false;

        foreach ($registry as $ip => $data) {
            $until = (int) ($data['until'] ?? 0);
            if ($until > $now) {
                $active[] = [
                    'ip' => $ip,
                    'reason' => (string) ($data['reason'] ?? 'Security violation'),
                    'jailed_at' => (int) ($data['jailed_at'] ?? $now),
                    'until' => $until,
                    'remaining_sec' => max(0, $until - $now),
                ];
            } else {
                $dirty = true;
                unset($registry[$ip]);
            }
        }

        if ($dirty) {
            self::saveRegistry($registry);
        }

        // Sort: newest detentions first
        usort($active, static fn(array $a, array $b) => $b['jailed_at'] <=> $a['jailed_at']);

        return $active;
    }

    /**
     * Manually releases an IP from Auto-Jail quarantine immediately.
     */
    public static function releaseIp(string $ip): bool
    {
        $ip = trim($ip);
        if ($ip === '') {
            return false;
        }

        $jailKey = self::CACHE_PREFIX_JAIL . md5($ip);
        $violsKey = self::CACHE_PREFIX_VIOLS . md5($ip);
        unset(self::$_memoryViols[$ip]);

        if (Yii::$app !== null && Yii::$app->has('cache') && Yii::$app->cache !== null) {
            try {
                Yii::$app->cache->delete($jailKey);
                Yii::$app->cache->delete($violsKey);
            } catch (\Throwable) {
                // Ignore
            }
        }

        $registry = self::loadRegistry();
        if (isset($registry[$ip])) {
            unset($registry[$ip]);
            self::saveRegistry($registry);
            return true;
        }

        return true;
    }

    /**
     * Releases all detained IPs immediately.
     */
    public static function clearAll(): void
    {
        $registry = self::loadRegistry();
        foreach (array_keys($registry) as $ip) {
            self::releaseIp($ip);
        }
        self::saveRegistry([]);
    }

    /**
     * Resolves storage file path for persistent registry.
     */
    private static function getStorageFilePath(): string
    {
        $dir = Yii::getAlias('@runtime/antibot', false);
        if ($dir === false || $dir === '') {
            $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'antibot';
        }

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir . DIRECTORY_SEPARATOR . 'autojail.json';
    }

    /**
     * Safely loads the persistent registry.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function loadRegistry(): array
    {
        $file = self::getStorageFilePath();
        if (!file_exists($file)) {
            return [];
        }

        try {
            $content = file_get_contents($file);
            if ($content !== false && trim($content) !== '') {
                $decoded = Json::decode($content);
                return is_array($decoded) ? $decoded : [];
            }
        } catch (\Throwable) {
            // Ignore corrupted file
        }

        return [];
    }

    /**
     * Atomically saves the persistent registry.
     *
     * @param array<string, array<string, mixed>> $data
     */
    private static function saveRegistry(array $data): void
    {
        $file = self::getStorageFilePath();
        try {
            $json = Json::encode($data);
            $tmpFile = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
            if (file_put_contents($tmpFile, $json, LOCK_EX) !== false) {
                @rename($tmpFile, $file);
            }
        } catch (\Throwable) {
            // Best effort
        }
    }
}
