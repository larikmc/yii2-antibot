<?php

declare(strict_types=1);

namespace larikmc\antibot\services;

use Yii;
use yii\helpers\Json;

/**
 * Storage and configuration service for Antibot settings, IP lists, and event statistics.
 *
 * Saves persistent settings to JSON file (defaults to @common/config/antibot.json).
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class StorageService
{
    public const MODE_MONITORING = 'monitoring';
    public const MODE_STANDARD = 'standard';
    public const MODE_STRICT = 'strict';

    /**
     * @var string|null Custom settings file path or alias.
     */
    public ?string $storageFile = null;

    /**
     * @var string|null Custom events log file path or alias.
     */
    public ?string $eventsFile = null;

    /**
     * Resolves the full path to the settings file.
     */
    public function resolveStorageFile(): string
    {
        if ($this->storageFile !== null) {
            return Yii::getAlias($this->storageFile);
        }

        if (Yii::$app !== null && Yii::getAlias('@common/config', false) !== false) {
            return Yii::getAlias('@common/config/antibot.json');
        }

        return Yii::getAlias('@runtime/antibot.json');
    }

    /**
     * Resolves the full path to the events log file.
     */
    public function resolveEventsFile(): string
    {
        if ($this->eventsFile !== null) {
            return Yii::getAlias($this->eventsFile);
        }

        if (Yii::$app !== null && Yii::getAlias('@common', false) !== false) {
            $sharedDir = Yii::getAlias('@common/runtime/logs');
            if (!is_dir($sharedDir)) {
                @mkdir($sharedDir, 0777, true);
            }
            return $sharedDir . '/antibot_events.json';
        }

        return Yii::getAlias('@runtime/logs/antibot_events.json');
    }

    /**
     * Loads settings from file with defaults.
     *
     * @return array<string, mixed>
     */
    public function loadSettings(): array
    {
        $defaults = [
            'enabled' => true,
            'mode' => self::MODE_STANDARD,
            'blockAiCrawlers' => true,
            'blockCommercialScrapers' => true,
            'blockCliAndLibraries' => true,
            'verifySearchBots' => true,
            'blockFakeSearchBots' => true,
            'rateLimitEnabled' => true,
            'rateLimitMax' => 60,
            'rateLimitWindow' => 60,
            'jsChallengeEnabled' => true,
            'secretKey' => null,
            'whitelist' => ['127.0.0.1', '::1'],
            'blacklist' => [],
            'customBlockMessage' => 'Access Denied: Automated bot activity detected.',
        ];

        $file = $this->resolveStorageFile();
        if (!file_exists($file)) {
            return $defaults;
        }

        try {
            $content = file_get_contents($file);
            if ($content !== false && trim($content) !== '') {
                $saved = Json::decode($content);
                if (is_array($saved)) {
                    return array_merge($defaults, $saved);
                }
            }
        } catch (\Throwable $e) {
            Yii::warning('Failed to load Antibot settings: ' . $e->getMessage(), __METHOD__);
        }

        return $defaults;
    }

    /**
     * Saves settings to JSON file.
     *
     * @param array<string, mixed> $settings
     * @return bool
     */
    public function saveSettings(array $settings): bool
    {
        $file = $this->resolveStorageFile();
        $dir = dirname($file);

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Directory "%s" could not be created.', $dir));
        }

        $current = $this->loadSettings();
        $merged = array_merge($current, $settings);
        $merged['updatedAt'] = date('c');

        $json = Json::encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return file_put_contents($file, $json, LOCK_EX) !== false;
    }

    /**
     * Appends an event to the circular events log (keeps last 500 events).
     *
     * @param array{
     *     ip: string,
     *     userAgent: string,
     *     action: string,
     *     reason: string,
     *     url: string,
     *     timestamp?: int
     * } $event
     */
    public function logEvent(array $event): void
    {
        $file = $this->resolveEventsFile();
        $dir = dirname($file);

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        $event['timestamp'] = $event['timestamp'] ?? time();
        $event['date'] = date('Y-m-d H:i:s', $event['timestamp']);

        $events = [];
        if (file_exists($file)) {
            try {
                $content = file_get_contents($file);
                if ($content !== false && trim($content) !== '') {
                    $decoded = Json::decode($content);
                    if (is_array($decoded)) {
                        $events = $decoded;
                    }
                }
            } catch (\Throwable) {
                $events = [];
            }
        }

        // Add to front and limit to 500
        array_unshift($events, $event);
        if (count($events) > 500) {
            $events = array_slice($events, 0, 500);
        }

        @file_put_contents($file, Json::encode($events, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    /**
     * Reads recent events for dashboard.
     *
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function getRecentEvents(int $limit = 50): array
    {
        $file = $this->resolveEventsFile();
        if (!file_exists($file)) {
            return [];
        }

        try {
            $content = file_get_contents($file);
            if ($content !== false && trim($content) !== '') {
                $events = Json::decode($content);
                if (is_array($events)) {
                    return array_slice($events, 0, $limit);
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return [];
    }

    /**
     * Computes statistics from event log.
     *
     * @return array{
     *     total: int,
     *     blocked: int,
     *     searchBots: int,
     *     humans: int,
     *     topIps: array<string, int>,
     *     topReasons: array<string, int>
     * }
     */
    public function getStatistics(): array
    {
        $events = $this->getRecentEvents(500);

        $stats = [
            'total' => count($events),
            'blocked' => 0,
            'searchBots' => 0,
            'humans' => 0,
            'topIps' => [],
            'topReasons' => [],
        ];

        foreach ($events as $e) {
            $action = $e['action'] ?? '';
            $ip = $e['ip'] ?? '';
            $reason = $e['reason'] ?? 'Unknown';

            if ($action === 'blocked' || $action === 'challenged') {
                $stats['blocked']++;
                if ($ip !== '') {
                    $stats['topIps'][$ip] = ($stats['topIps'][$ip] ?? 0) + 1;
                }
                $stats['topReasons'][$reason] = ($stats['topReasons'][$reason] ?? 0) + 1;
            } elseif ($action === 'allowed_search_bot') {
                $stats['searchBots']++;
            } else {
                $stats['humans']++;
            }
        }

        arsort($stats['topIps']);
        arsort($stats['topReasons']);

        $stats['topIps'] = array_slice($stats['topIps'], 0, 5, true);
        $stats['topReasons'] = array_slice($stats['topReasons'], 0, 5, true);

        return $stats;
    }
}
