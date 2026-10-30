<?php

declare(strict_types=1);

namespace larikmc\antibot\services\analytics;

use larikmc\antibot\services\StorageService;
use Yii;

/**
 * High-performance backend analytics service for the Antibot Dashboard.
 *
 * Provides single-pass aggregation of JSONL event logs without placing any
 * additional overhead on public HTTP requests. Results are cached for 120 seconds.
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class DashboardAnalyticsService
{
    public const PERIOD_24H = '24h';
    public const PERIOD_7D = '7d';
    public const PERIOD_ALL = 'all';

    public const CACHE_TTL = 120; // 120 seconds as required
    public const CACHE_VERSION = 'v157';
    public const SCAN_LIMIT = 1500; // Efficient tail scanning
    public const REFRESH_COOLDOWN = 5; // Prevent hammer on force-refresh

    /**
     * Computes or retrieves cached analytics dataset for the specified period.
     *
     * @param StorageService $storage
     * @param string $period '24h', '7d', or 'all'
     * @param string $mode Current active protection mode (monitoring, standard, strict)
     * @param bool $forceRefresh
     * @return array<string, mixed>
     */
    public static function getAnalytics(
        StorageService $storage,
        string $period = self::PERIOD_24H,
        string $mode = StorageService::MODE_STANDARD,
        bool $forceRefresh = false
    ): array {
        $period = in_array($period, [self::PERIOD_24H, self::PERIOD_7D, self::PERIOD_ALL], true)
            ? $period
            : self::PERIOD_24H;

        $cache = (Yii::$app !== null && Yii::$app->has('cache')) ? Yii::$app->cache : null;
        $cacheKey = 'antibot_analytics_' . self::CACHE_VERSION . '_' . $period . '_' . $mode;
        $cooldownKey = 'antibot_analytics_cooldown_' . $period;

        if ($cache !== null && !$forceRefresh) {
            $cached = $cache->get($cacheKey);
            if (is_array($cached)) {
                $cached['from_cache'] = true;
                return $cached;
            }
        }

        // If force refresh requested, apply rate limit cooldown
        if ($forceRefresh && $cache !== null) {
            if ($cache->get($cooldownKey) !== false) {
                // Too frequent force refresh, reuse existing cache if present
                $cached = $cache->get($cacheKey);
                if (is_array($cached)) {
                    $cached['from_cache'] = true;
                    $cached['cooldown_active'] = true;
                    return $cached;
                }
            } else {
                $cache->set($cooldownKey, 1, self::REFRESH_COOLDOWN);
            }
        }

        // Fetch events efficiently from tail of JSONL
        $events = $storage->getRecentEvents(self::SCAN_LIMIT);

        $data = self::processEvents($events, $period, $mode);

        if ($cache !== null) {
            $cache->set($cacheKey, $data, self::CACHE_TTL);
        }

        return $data;
    }

    /**
     * Single-pass processor aggregating timeline, actions, reasons, and routes.
     *
     * @param array<int, array<string, mixed>> $events
     * @param string $period
     * @param string $mode
     * @return array<string, mixed>
     */
    public static function processEvents(array $events, string $period, string $mode): array
    {
        $now = time();
        $startTime = match ($period) {
            self::PERIOD_7D => $now - (7 * 86400),
            self::PERIOD_ALL => 0,
            default => $now - 86400,
        };

        // Determine coverage and truncation
        $totalInSample = count($events);
        $oldestTimestamp = null;
        $newestTimestamp = null;

        foreach ($events as $ev) {
            $ts = (int) ($ev['timestamp'] ?? 0);
            if ($ts <= 0 && !empty($ev['date'])) {
                $parsed = strtotime((string) $ev['date']);
                $ts = $parsed !== false ? $parsed : 0;
            }
            if ($ts > 0) {
                $oldestTimestamp = ($oldestTimestamp === null) ? $ts : min($oldestTimestamp, $ts);
                $newestTimestamp = ($newestTimestamp === null) ? $ts : max($newestTimestamp, $ts);
            }
        }

        $isTruncated = false;
        $coverageStatus = 'empty';

        if ($totalInSample > 0) {
            if ($period === self::PERIOD_ALL) {
                $coverageStatus = 'sample_available';
                $isTruncated = ($totalInSample >= self::SCAN_LIMIT);
            } elseif ($oldestTimestamp !== null && $oldestTimestamp > $startTime && $totalInSample >= self::SCAN_LIMIT) {
                $coverageStatus = 'partial_window';
                $isTruncated = true;
            } else {
                $coverageStatus = 'full_window';
            }
        }

        // Initialize timeline buckets
        [$buckets, $bucketKeys, $slotFormat] = self::initializeBuckets($period, $now, $startTime, $oldestTimestamp);

        // Aggregation counters
        $eventsProcessed = 0;
        $actionsCount = [
            'monitoring' => 0,
            'blocked' => 0,
            'throttled' => 0,
            'challenge' => 0,
            'other' => 0,
        ];
        $reasonsCount = [];
        $routesCount = [];

        // Single pass over events
        foreach ($events as $event) {
            $ts = (int) ($event['timestamp'] ?? 0);
            if ($ts <= 0 && !empty($event['date'])) {
                $parsed = strtotime((string) $event['date']);
                $ts = $parsed !== false ? $parsed : 0;
            }
            if ($ts <= 0) {
                $ts = $now; // Safe fallback
            }

            // Skip events outside the selected time window
            if ($ts < $startTime) {
                continue;
            }

            $eventsProcessed++;

            $actionRaw = (string) ($event['action'] ?? '');
            $simulated = !empty($event['simulated']);
            $reasonRaw = (string) ($event['reason'] ?? 'Неизвестная причина');
            $urlRaw = (string) ($event['url'] ?? ($event['route'] ?? '/'));

            // Categorize action
            // CRITICAL: simulated=true MUST strictly count as monitoring, never as actual block!
            if ($simulated || $actionRaw === 'monitored' || $mode === StorageService::MODE_MONITORING) {
                $actionCategory = 'monitoring';
            } elseif ($actionRaw === 'blocked' || $actionRaw === 'honey_trap_hit' || $actionRaw === 'honeypot_triggered') {
                $actionCategory = 'blocked';
            } elseif ($actionRaw === 'throttled') {
                $actionCategory = 'throttled';
            } elseif (in_array($actionRaw, ['challenged', 'pow_required', 'challenge_failed'], true)) {
                $actionCategory = 'challenge';
            } else {
                $actionCategory = 'other';
            }

            $actionsCount[$actionCategory]++;

            // Match timeline bucket
            $bucketKey = self::matchBucketKey($ts, $period, $slotFormat);
            if (isset($buckets[$bucketKey])) {
                $buckets[$bucketKey][$actionCategory]++;
                $buckets[$bucketKey]['total']++;
            }

            // Reasons aggregation (for suspicious / monitored / blocked actions)
            if ($actionCategory !== 'other' || in_array($actionRaw, ['allowed_search_bot', 'pow_solved'], true)) {
                $normalizedReason = self::normalizeReason($reasonRaw);
                $label = $normalizedReason['label'];
                $reasonsCount[$label] = ($reasonsCount[$label] ?? 0) + 1;
            }

            // Routes aggregation (sanitized & safe)
            $cleanRoute = self::normalizeUrl($urlRaw);
            $routesCount[$cleanRoute] = ($routesCount[$cleanRoute] ?? 0) + 1;
        }

        // Format timeline arrays for Chart 1 (Line / Area)
        $timelineLabels = [];
        $seriesMonitoring = [];
        $seriesBlocked = [];
        $seriesThrottled = [];
        $seriesChallenge = [];
        $seriesTotal = [];

        foreach ($bucketKeys as $bKey) {
            $b = $buckets[$bKey];
            $timelineLabels[] = $b['label'];
            $seriesMonitoring[] = $b['monitoring'];
            $seriesBlocked[] = $b['blocked'];
            $seriesThrottled[] = $b['throttled'];
            $seriesChallenge[] = $b['challenge'];
            $seriesTotal[] = $b['total'];
        }

        // Format Donut Chart 2 (Event distribution)
        $donutTotal = array_sum($actionsCount);
        $donutData = [
            [
                'name' => 'Мониторинг',
                'value' => $actionsCount['monitoring'],
                'color' => '#0d6efd', // Blue
                'percent' => $donutTotal > 0 ? round(($actionsCount['monitoring'] / $donutTotal) * 100, 1) : 0,
            ],
            [
                'name' => 'Заблокировано',
                'value' => $actionsCount['blocked'],
                'color' => '#dc3545', // Red
                'percent' => $donutTotal > 0 ? round(($actionsCount['blocked'] / $donutTotal) * 100, 1) : 0,
            ],
            [
                'name' => 'Rate Limit',
                'value' => $actionsCount['throttled'],
                'color' => '#fd7e14', // Orange
                'percent' => $donutTotal > 0 ? round(($actionsCount['throttled'] / $donutTotal) * 100, 1) : 0,
            ],
            [
                'name' => 'Проверки (Challenge)',
                'value' => $actionsCount['challenge'],
                'color' => '#6f42c1', // Purple
                'percent' => $donutTotal > 0 ? round(($actionsCount['challenge'] / $donutTotal) * 100, 1) : 0,
            ],
            [
                'name' => 'Другие события',
                'value' => $actionsCount['other'],
                'color' => '#6c757d', // Grey
                'percent' => $donutTotal > 0 ? round(($actionsCount['other'] / $donutTotal) * 100, 1) : 0,
            ],
        ];

        // Format Bar Chart 3 (Reasons - top 8)
        arsort($reasonsCount);
        $topReasons = [];
        $otherReasonsCount = 0;
        $i = 0;
        foreach ($reasonsCount as $rLabel => $count) {
            if ($i < 8) {
                $topReasons[] = ['label' => $rLabel, 'count' => $count];
                $i++;
            } else {
                $otherReasonsCount += $count;
            }
        }
        if ($otherReasonsCount > 0) {
            $topReasons[] = ['label' => 'Другие причины', 'count' => $otherReasonsCount];
        }

        // Format Bar Chart 4 (Routes - top 10)
        arsort($routesCount);
        $topRoutes = [];
        $j = 0;
        foreach ($routesCount as $routePath => $count) {
            if ($j < 10) {
                $topRoutes[] = ['route' => $routePath, 'count' => $count];
                $j++;
            } else {
                break;
            }
        }

        return [
            'period' => $period,
            'generated_at' => date('Y-m-d H:i:s', $now),
            'first_event_at' => $oldestTimestamp !== null ? date('Y-m-d H:i:s', $oldestTimestamp) : null,
            'last_event_at' => $newestTimestamp !== null ? date('Y-m-d H:i:s', $newestTimestamp) : null,
            'events_processed' => $eventsProcessed,
            'events_in_sample' => $totalInSample,
            'coverage_status' => $coverageStatus,
            'is_truncated' => $isTruncated,
            'recording_mode' => $mode,
            'disclaimer' => 'Статистика Best-Effort по событиям журнала. События с simulated:true отображены как мониторинг и не влияли на реальный трафик.',
            'timeline' => [
                'labels' => $timelineLabels,
                'series' => [
                    'monitoring' => $seriesMonitoring,
                    'blocked' => $seriesBlocked,
                    'throttled' => $seriesThrottled,
                    'challenge' => $seriesChallenge,
                    'total' => $seriesTotal,
                ],
            ],
            'actions' => [
                'total' => $donutTotal,
                'items' => $donutData,
                'isEmpty' => ($donutTotal === 0),
            ],
            'reasons' => [
                'items' => $topReasons,
                'isEmpty' => empty($topReasons),
            ],
            'routes' => [
                'items' => $topRoutes,
                'isEmpty' => empty($topRoutes),
            ],
            'from_cache' => false,
        ];
    }

    /**
     * Normalizes raw reason string into clean Russian descriptive label and category code.
     *
     * @param string $reason
     * @return array{code: string, label: string}
     */
    public static function normalizeReason(string $reason): array
    {
        $reasonTrimmed = trim($reason);
        if ($reasonTrimmed === '') {
            return ['code' => 'unknown', 'label' => 'Неизвестная причина'];
        }

        $rLower = mb_strtolower($reasonTrimmed, 'UTF-8');

        if (str_contains($rLower, 'fake') || str_contains($rLower, 'поддельн')) {
            return ['code' => 'fake_search_bot', 'label' => 'Поддельный поисковик (Fake Bot)'];
        }
        if (str_contains($rLower, 'cli') || str_contains($rLower, 'curl') || str_contains($rLower, 'python') ||
            str_contains($rLower, 'wget') || str_contains($rLower, 'guzzle') || str_contains($rLower, 'postman') ||
            str_contains($rLower, 'library') || str_contains($rLower, 'anomalous header')) {
            return ['code' => 'cli_library', 'label' => 'CLI и HTTP-библиотеки'];
        }
        if (str_contains($rLower, 'ai') || str_contains($rLower, 'gpt') || str_contains($rLower, 'claude') ||
            str_contains($rLower, 'bytespider') || str_contains($rLower, 'crawler')) {
            return ['code' => 'ai_crawler', 'label' => 'AI-краулеры и LLM'];
        }
        if (str_contains($rLower, 'scraper') || str_contains($rLower, 'ahrefs') || str_contains($rLower, 'semrush') ||
            str_contains($rLower, 'dotbot') || str_contains($rLower, 'commercial')) {
            return ['code' => 'commercial_scraper', 'label' => 'Коммерческие скраперы (SEO)'];
        }
        if (str_contains($rLower, 'rate limit') || str_contains($rLower, 'лимит') || str_contains($rLower, 'throttled') ||
            str_contains($rLower, 'флуд')) {
            return ['code' => 'rate_limit', 'label' => 'Превышение Rate Limit'];
        }
        if (str_contains($rLower, 'behavior') || str_contains($rLower, 'поведен') || str_contains($rLower, 'anomaly') ||
            str_contains($rLower, 'risk')) {
            return ['code' => 'behavior_anomaly', 'label' => 'Подозрительное поведение'];
        }
        if (str_contains($rLower, 'honey_trap') || str_contains($rLower, 'honey trap') || str_contains($rLower, 'honey url')) {
            return ['code' => 'honey_trap', 'label' => 'Скрытая ссылка (Honey URL)'];
        }
        if (str_contains($rLower, 'honeypot') || str_contains($rLower, 'ловушк')) {
            return ['code' => 'honeypot', 'label' => 'Ловушка формы (Honeypot)'];
        }
        if (str_contains($rLower, 'search engine') || str_contains($rLower, 'authentic search') || str_contains($rLower, 'поисков')) {
            return ['code' => 'search_bot', 'label' => 'Поисковые роботы (SEO)'];
        }
        if (str_contains($rLower, 'challenge') || str_contains($rLower, 'pow') || str_contains($rLower, 'капч')) {
            return ['code' => 'challenge', 'label' => 'JS/PoW проверки'];
        }

        // Fallback: clean truncated original reason
        $clean = trim(mb_substr($reasonTrimmed, 0, 36, 'UTF-8'));
        return ['code' => 'other', 'label' => $clean !== '' ? $clean : 'Другие причины'];
    }

    /**
     * Sanitizes and normalizes URL path to prevent XSS, sensitive token leaks, and path disclosure.
     *
     * @param string $url
     * @return string
     */
    public static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '/';
        }

        // Extract path component (strips query string, scheme, host)
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = $url;
            if (($pos = strpos($path, '?')) !== false) {
                $path = substr($path, 0, $pos);
            }
        }

        // Clean null bytes and malicious characters
        $path = str_replace(["\0", "\r", "\n", '<', '>', '"', "'", '\\'], '', $path);

        // Normalize directory traversal sequences
        $path = preg_replace('#/\.{1,2}(/|$)#', '/', $path) ?? $path;

        // Ensure leading slash
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        // Collapse multiple slashes
        $path = preg_replace('#/{2,}#', '/', $path) ?? $path;

        // Strip trailing slash except for root
        if (strlen($path) > 1) {
            $path = rtrim($path, '/');
        }

        // Truncate to safe length
        if (mb_strlen($path, 'UTF-8') > 50) {
            $path = mb_substr($path, 0, 47, 'UTF-8') . '...';
        }

        return $path;
    }

    /**
     * Initializes timeline buckets based on period.
     *
     * @param string $period
     * @param int $now
     * @param int $startTime
     * @param int|null $oldestTimestamp
     * @return array{0: array<string, array<string, mixed>>, 1: array<int, string>, 2: string}
     */
    protected static function initializeBuckets(string $period, int $now, int $startTime, ?int $oldestTimestamp): array
    {
        $buckets = [];
        $bucketKeys = [];
        $slotFormat = 'Y-m-d H:00';

        if ($period === self::PERIOD_24H) {
            // 24 hourly buckets from 23 hours ago to current hour
            $slotFormat = 'Y-m-d H:00';
            $currentHour = (int) floor($now / 3600) * 3600;
            for ($i = 23; $i >= 0; $i--) {
                $bTs = $currentHour - ($i * 3600);
                $key = date($slotFormat, $bTs);
                $bucketKeys[] = $key;
                $buckets[$key] = [
                    'label' => date('H:00', $bTs),
                    'timestamp' => $bTs,
                    'monitoring' => 0,
                    'blocked' => 0,
                    'throttled' => 0,
                    'challenge' => 0,
                    'other' => 0,
                    'total' => 0,
                ];
            }
        } elseif ($period === self::PERIOD_7D) {
            // 7 daily buckets from 6 days ago to today
            $slotFormat = 'Y-m-d';
            $today = strtotime('today', $now);
            for ($i = 6; $i >= 0; $i--) {
                $bTs = $today - ($i * 86400);
                $key = date($slotFormat, $bTs);
                $bucketKeys[] = $key;
                $buckets[$key] = [
                    'label' => date('d.m', $bTs),
                    'timestamp' => $bTs,
                    'monitoring' => 0,
                    'blocked' => 0,
                    'throttled' => 0,
                    'challenge' => 0,
                    'other' => 0,
                    'total' => 0,
                ];
            }
        } else {
            // PERIOD_ALL: Available history in sample
            $first = ($oldestTimestamp !== null && $oldestTimestamp > 0) ? $oldestTimestamp : ($now - 86400);
            $span = max(3600, $now - $first);

            if ($span <= 86400) {
                // Within 24h: hourly
                $slotFormat = 'Y-m-d H:00';
                $startHour = (int) floor($first / 3600) * 3600;
                $endHour = (int) floor($now / 3600) * 3600;
                for ($ts = $startHour; $ts <= $endHour; $ts += 3600) {
                    $key = date($slotFormat, $ts);
                    $bucketKeys[] = $key;
                    $buckets[$key] = [
                        'label' => date('H:00', $ts),
                        'timestamp' => $ts,
                        'monitoring' => 0,
                        'blocked' => 0,
                        'throttled' => 0,
                        'challenge' => 0,
                        'other' => 0,
                        'total' => 0,
                    ];
                }
            } else {
                // Multi-day: daily
                $slotFormat = 'Y-m-d';
                $startDay = strtotime('today', $first);
                $endDay = strtotime('today', $now);
                for ($ts = $startDay; $ts <= $endDay; $ts += 86400) {
                    $key = date($slotFormat, $ts);
                    $bucketKeys[] = $key;
                    $buckets[$key] = [
                        'label' => date('d.m', $ts),
                        'timestamp' => $ts,
                        'monitoring' => 0,
                        'blocked' => 0,
                        'throttled' => 0,
                        'challenge' => 0,
                        'other' => 0,
                        'total' => 0,
                    ];
                }
            }
        }

        return [$buckets, $bucketKeys, $slotFormat];
    }

    /**
     * Matches event timestamp to its bucket key.
     *
     * @param int $ts
     * @param string $period
     * @param string $slotFormat
     * @return string
     */
    protected static function matchBucketKey(int $ts, string $period, string $slotFormat): string
    {
        return date($slotFormat, $ts);
    }
}
