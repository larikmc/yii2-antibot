<?php

declare(strict_types=1);

namespace larikmc\antibot\services;

use Yii;
use yii\helpers\Json;

/**
 * Storage and configuration service for Antibot settings, IP lists, and event statistics.
 *
 * Uses:
 * - Persistent JSON for settings (@common/config/antibot.json).
 * - High-speed JSON Lines (JSONL) append-only log for events to prevent disk I/O bottlenecks.
 * - Automatic rotation and migration from legacy JSON log.
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
     * @var int Maximum events log file size in bytes before rotation (5 MB).
     */
    public int $maxLogSizeBytes = 5242880;

    /**
     * In-memory cache for loaded settings within the current request.
     */
    private ?array $_cachedSettings = null;

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
     * Resolves the full path to the events JSONL log file.
     */
    public function resolveEventsFile(): string
    {
        if ($this->eventsFile !== null) {
            return Yii::getAlias($this->eventsFile);
        }

        if (Yii::$app !== null && Yii::getAlias('@common', false) !== false) {
            $sharedDir = Yii::getAlias('@common/runtime/logs');
            if (!is_dir($sharedDir)) {
                @mkdir($sharedDir, 0775, true);
            }
            return $sharedDir . '/antibot_events.jsonl';
        }

        return Yii::getAlias('@runtime/logs/antibot_events.jsonl');
    }

    /**
     * Checks whether an explicit settings file exists on disk.
     */
    public function hasSavedSettings(): bool
    {
        $file = $this->resolveStorageFile();
        return file_exists($file) && is_file($file);
    }

    /**
     * Loads raw explicitly saved settings from disk without merging component defaults.
     *
     * @return array<string, mixed> Empty array if no settings file exists or on decode error.
     */
    public function getSavedSettings(): array
    {
        $file = $this->resolveStorageFile();
        if (!file_exists($file) || !is_file($file)) {
            return [];
        }

        try {
            $content = file_get_contents($file);
            if ($content !== false && trim($content) !== '') {
                $saved = Json::decode($content);
                if (is_array($saved)) {
                    return $saved;
                }
            }
        } catch (\Throwable $e) {
            Yii::warning('Failed to load raw Antibot settings: ' . $e->getMessage(), __METHOD__);
        }

        return [];
    }

    /**
     * Loads settings from file with defaults.
     *
     * @return array<string, mixed>
     */
    public function loadSettings(): array
    {
        if ($this->_cachedSettings !== null) {
            return $this->_cachedSettings;
        }

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
            'rateLimitBanDuration' => 300,
            'rateLimitAdapter' => RateLimiter::ADAPTER_CACHE,
            'jsChallengeEnabled' => true,
            'secretKey' => null,
            'whitelist' => ['127.0.0.1', '::1'],
            'blacklist' => [],
            'trustedProxies' => [],
            'ipHeaders' => ['CF-Connecting-IP', 'X-Forwarded-For', 'X-Real-IP'],
            'aiCrawlerPolicies' => [],
            'routeRules' => [],
            'behaviorAnalysisEnabled' => true,
            'weightedRateLimitEnabled' => false,
            'weightedRateLimitBudget' => 150,
            'weightedRateLimitWindow' => 60,
            'powEnabled' => false,
            'powDifficulty' => 'medium',
            'honeyTrapsEnabled' => false,
            'trapRoute' => 'antibot/trap',
            'customBlockMessage' => 'Access Denied: Automated bot activity detected.',
        ];

        $file = $this->resolveStorageFile();
        if (!file_exists($file)) {
            $this->_cachedSettings = $defaults;
            return $defaults;
        }

        try {
            $content = file_get_contents($file);
            if ($content !== false && trim($content) !== '') {
                $saved = Json::decode($content);
                if (is_array($saved)) {
                    $this->_cachedSettings = array_merge($defaults, $saved);
                    return $this->_cachedSettings;
                }
            }
        } catch (\Throwable $e) {
            Yii::warning('Failed to load Antibot settings: ' . $e->getMessage(), __METHOD__);
        }

        $this->_cachedSettings = $defaults;
        return $defaults;
    }

    /**
     * Retrieves or generates a cryptographically secure random persistent secret key.
     */
    public function getOrCreateAutoSecret(): string
    {
        $settings = $this->loadSettings();
        if (!empty($settings['autoSecretKey']) && is_string($settings['autoSecretKey']) && strlen($settings['autoSecretKey']) >= 32) {
            return $settings['autoSecretKey'];
        }

        try {
            $newSecret = bin2hex(random_bytes(32));
        } catch (\Throwable) {
            $newSecret = hash('sha256', uniqid((string) mt_rand(), true) . microtime(true));
        }

        // Try to persist it so it remains identical across requests
        try {
            $settings['autoSecretKey'] = $newSecret;
            $this->saveSettings($settings, 'system');
        } catch (\Throwable) {
            // Read-only storage fallback
        }

        return $newSecret;
    }

    /**
     * Saves settings to JSON file and records an audit log entry.
     *
     * @param array<string, mixed> $settings
     * @param string|null $adminIp
     * @return bool
     */
    public function saveSettings(array $settings, ?string $adminIp = null): bool
    {
        $this->_cachedSettings = null;
        $file = $this->resolveStorageFile();
        $dir = dirname($file);

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Directory "%s" could not be created.', $dir));
        }

        $current = $this->loadSettings();
        $changedKeys = [];
        foreach ($settings as $k => $v) {
            if (!array_key_exists($k, $current) || $current[$k] !== $v) {
                $changedKeys[] = $k;
            }
        }

        $merged = array_merge($current, $settings);
        $merged['updatedAt'] = date('c');

        $json = Json::encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $saved = file_put_contents($file, $json, LOCK_EX) !== false;

        if ($saved && !empty($changedKeys)) {
            $this->recordAudit($changedKeys, $adminIp);
        }

        return $saved;
    }

    /**
     * Resolves the full path to the settings audit log file.
     */
    public function resolveAuditFile(): string
    {
        if (Yii::$app !== null && Yii::getAlias('@common', false) !== false) {
            $sharedDir = Yii::getAlias('@common/runtime/logs');
            if (!is_dir($sharedDir)) {
                @mkdir($sharedDir, 0775, true);
            }
            return $sharedDir . '/antibot_audit.jsonl';
        }

        return Yii::getAlias('@runtime/logs/antibot_audit.jsonl');
    }

    /**
     * Records an audit log entry for modified configuration settings.
     *
     * @param array<int, string> $changedKeys
     * @param string|null $adminIp
     * @return bool
     */
    public function recordAudit(array $changedKeys, ?string $adminIp = null): bool
    {
        if (empty($changedKeys)) {
            return true;
        }

        $file = $this->resolveAuditFile();
        $entry = [
            'timestamp' => time(),
            'datetime' => date('Y-m-d H:i:s'),
            'adminIp' => $adminIp ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'),
            'changedKeys' => $changedKeys,
        ];

        return (bool) @file_put_contents($file, Json::encode($entry) . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Retrieves recent settings audit log history.
     *
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function getAuditTrail(int $limit = 20): array
    {
        $file = $this->resolveAuditFile();
        if (!file_exists($file)) {
            return [];
        }

        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false || empty($lines)) {
            return [];
        }

        $recent = array_slice($lines, -$limit);
        $entries = [];
        foreach ($recent as $line) {
            try {
                $decoded = Json::decode($line);
                if (is_array($decoded)) {
                    $entries[] = $decoded;
                }
            } catch (\Throwable) {
                // Ignore corrupted lines
            }
        }

        return array_reverse($entries);
    }

    /**
     * High-speed append-only JSONL event logging.
     *
     * Never throws exceptions or hangs the request on disk failure.
     *
     * @param array{
     *     ip: string,
     *     userAgent: string,
     *     action: string,
     *     reason: string,
     *     url?: string,
     *     route?: string,
     *     timestamp?: int
     * } $event
     */
    public function logEvent(array $event): void
    {
        // Debounce repetitive duplicate monitored events from the same IP within a 3-second window
        // to prevent disk lock contention during automated bursts in monitoring mode.
        if (($event['action'] ?? '') === 'monitored') {
            $debounceKey = 'antibot_mon_log_' . md5(($event['ip'] ?? '') . '_' . ($event['reason'] ?? ''));
            if (Yii::$app !== null && Yii::$app->has('cache') && Yii::$app->cache !== null) {
                if (Yii::$app->cache->get($debounceKey) !== false) {
                    return; // Already logged this exact monitored event recently
                }
                Yii::$app->cache->set($debounceKey, 1, 3);
            }
        }

        \larikmc\antibot\services\resilience\CircuitBreaker::execute(
            function () use ($event): void {
                $file = $this->resolveEventsFile();
                $dir = dirname($file);

                if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                    throw new \RuntimeException("Directory {$dir} not writable");
                }

                // Check migration from legacy .json if needed
                $this->ensureMigration($file);

                // Sampled log rotation check (1 in 20 writes) to reduce filesystem metadata query overhead
                if (mt_rand(1, 20) === 1 && file_exists($file) && filesize($file) > $this->maxLogSizeBytes) {
                    @rename($file, $file . '.1');
                }

                $event['timestamp'] = $event['timestamp'] ?? time();
                $event['date'] = date('Y-m-d H:i:s', $event['timestamp']);

                // Sanitize URL to strip sensitive GET params like token, auth, password
                if (isset($event['url']) && is_string($event['url'])) {
                    $event['url'] = $this->sanitizeUrl($event['url']);
                }

                $line = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
                $written = @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
                if ($written === false) {
                    throw new \RuntimeException("Failed to write to {$file}");
                }
            },
            function (?Throwable $e): void {
                // Fail-safe graceful degradation: no impact to client
            },
            'storage'
        );
    }

    /**
     * Efficiently reads the most recent N events from JSONL.
     *
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function getRecentEvents(int $limit = 50): array
    {
        $file = $this->resolveEventsFile();
        $this->ensureMigration($file);

        if (!file_exists($file)) {
            return [];
        }

        $lines = $this->tailFile($file, $limit);
        $events = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            try {
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    $events[] = $decoded;
                }
            } catch (\Throwable) {
                // Ignore corrupted lines
            }
        }

        // Return most recent first
        return array_reverse($events);
    }

    /**
     * Clears all events log.
     */
    public function clearEvents(): bool
    {
        $file = $this->resolveEventsFile();
        if (file_exists($file)) {
            @unlink($file);
        }
        if (file_exists($file . '.1')) {
            @unlink($file . '.1');
        }
        return true;
    }

    /**
     * Computes statistics from event log with time intervals (24h, 7d, all-time).
     *
     * @param int $scanLimit Maximum lines to analyze.
     * @return array{
     *     total: int,
     *     blocked: int,
     *     monitored: int,
     *     challenged: int,
     *     throttled: int,
     *     searchBots: int,
     *     last24h: array{blocked: int, total: int},
     *     last7d: array{blocked: int, total: int},
     *     topIps: array<string, int>,
     *     topReasons: array<string, int>,
     *     topRoutes: array<string, int>
     * }
     */
    public function getStatistics(int $scanLimit = 1000): array
    {
        $events = $this->getRecentEvents($scanLimit);
        $now = time();
        $dayAgo = $now - 86400;
        $weekAgo = $now - (7 * 86400);

        $stats = [
            'total' => count($events),
            'blocked' => 0,
            'monitored' => 0,
            'challenged' => 0,
            'throttled' => 0,
            'searchBots' => 0,
            'powSolved' => 0,
            'trapHits' => 0,
            'last24h' => ['blocked' => 0, 'total' => 0],
            'last7d' => ['blocked' => 0, 'total' => 0],
            'topIps' => [],
            'topReasons' => [],
            'topRoutes' => [],
        ];

        foreach ($events as $e) {
            $action = $e['action'] ?? '';
            $ip = $e['ip'] ?? '';
            $reason = $e['reason'] ?? 'Unknown';
            $ts = $e['timestamp'] ?? $now;
            $route = $e['route'] ?? ($e['url'] ?? '/');

            // Categorize action
            $isBlocked = false;
            if ($action === 'blocked') {
                $stats['blocked']++;
                $isBlocked = true;
            } elseif ($action === 'monitored') {
                $stats['monitored']++;
            } elseif ($action === 'challenged') {
                $stats['challenged']++;
            } elseif ($action === 'throttled') {
                $stats['throttled']++;
                $isBlocked = true;
            } elseif ($action === 'allowed_search_bot') {
                $stats['searchBots']++;
            } elseif ($action === 'pow_solved') {
                $stats['powSolved']++;
            } elseif ($action === 'honey_trap_hit' || $action === 'honeypot_triggered') {
                $stats['trapHits']++;
                $isBlocked = true;
            }

            // Time windows
            if ($ts >= $dayAgo) {
                $stats['last24h']['total']++;
                if ($isBlocked) {
                    $stats['last24h']['blocked']++;
                }
            }
            if ($ts >= $weekAgo) {
                $stats['last7d']['total']++;
                if ($isBlocked) {
                    $stats['last7d']['blocked']++;
                }
            }

            // Top aggregations for blocked/suspicious events
            if ($isBlocked || $action === 'challenged' || $action === 'monitored') {
                if ($ip !== '') {
                    $stats['topIps'][$ip] = ($stats['topIps'][$ip] ?? 0) + 1;
                }
                $stats['topReasons'][$reason] = ($stats['topReasons'][$reason] ?? 0) + 1;
                if ($route !== '') {
                    $parsedPath = parse_url($route, PHP_URL_PATH) ?? $route;
                    $stats['topRoutes'][$parsedPath] = ($stats['topRoutes'][$parsedPath] ?? 0) + 1;
                }
            }
        }

        arsort($stats['topIps']);
        arsort($stats['topReasons']);
        arsort($stats['topRoutes']);

        $stats['topIps'] = array_slice($stats['topIps'], 0, 8, true);
        $stats['topReasons'] = array_slice($stats['topReasons'], 0, 8, true);
        $stats['topRoutes'] = array_slice($stats['topRoutes'], 0, 8, true);

        return $stats;
    }

    /**
     * Strips sensitive parameters from URLs before logging.
     */
    protected function sanitizeUrl(string $url): string
    {
        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['query'])) {
            return $url;
        }

        parse_str($parsed['query'], $query);
        $sensitive = ['token', 'key', 'password', 'secret', 'auth', 'hash', 'code'];
        foreach ($sensitive as $s) {
            if (isset($query[$s])) {
                $query[$s] = '***';
            }
        }

        $cleanQuery = urldecode(http_build_query($query));
        $scheme = isset($parsed['scheme']) ? $parsed['scheme'] . '://' : '';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $path = $parsed['path'] ?? '';

        return $scheme . $host . $port . $path . ($cleanQuery !== '' ? '?' . $cleanQuery : '');
    }

    /**
     * Efficiently reads the last $lines of a file.
     *
     * @param string $filepath
     * @param int $lines
     * @return array<int, string>
     */
    protected function tailFile(string $filepath, int $lines = 50): array
    {
        $handle = @fopen($filepath, 'rb');
        if (!$handle) {
            return [];
        }

        $lineList = [];
        $bufferSize = 4096;
        fseek($handle, 0, SEEK_END);
        $fileSize = ftell($handle);
        $currentPos = $fileSize;
        $remainingText = '';

        while ($currentPos > 0 && count($lineList) < $lines) {
            $readSize = (int) min($bufferSize, $currentPos);
            $currentPos -= $readSize;
            fseek($handle, $currentPos);
            $chunk = fread($handle, $readSize);
            if ($chunk === false) {
                break;
            }

            $text = $chunk . $remainingText;
            $splitLines = explode("\n", $text);

            if ($currentPos > 0) {
                $remainingText = array_shift($splitLines) ?? '';
            } else {
                $remainingText = '';
            }

            for ($i = count($splitLines) - 1; $i >= 0; $i--) {
                $trimmed = trim($splitLines[$i]);
                if ($trimmed !== '') {
                    $lineList[] = $trimmed;
                    if (count($lineList) >= $lines) {
                        break 2;
                    }
                }
            }
        }

        fclose($handle);

        return array_reverse($lineList);
    }

    /**
     * Automatically migrates legacy .json array log to .jsonl format.
     */
    protected function ensureMigration(string $jsonlFile): void
    {
        if (file_exists($jsonlFile)) {
            return;
        }

        $legacyJson = str_replace('.jsonl', '.json', $jsonlFile);
        if (!file_exists($legacyJson)) {
            return;
        }

        try {
            $content = file_get_contents($legacyJson);
            if ($content !== false && trim($content) !== '') {
                $events = json_decode($content, true);
                if (is_array($events)) {
                    $lines = '';
                    // Legacy stored most recent first, so reverse to append chronologically
                    foreach (array_reverse($events) as $e) {
                        if (is_array($e)) {
                            $lines .= json_encode($e, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
                        }
                    }
                    if ($lines !== '') {
                        @file_put_contents($jsonlFile, $lines, LOCK_EX);
                    }
                }
            }
        } catch (\Throwable) {
            // Migration is best-effort
        }
    }
}
