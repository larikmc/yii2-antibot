<?php

declare(strict_types=1);

namespace larikmc\antibot\services;

use Yii;

/**
 * Reverse DNS (PTR) + Forward DNS verification for legitimate search engine bots.
 *
 * Prevents bots from impersonating Googlebot, YandexBot, Bingbot etc.
 * Protects SEO rankings by ensuring real search crawlers are NEVER blocked,
 * while detecting and mitigating spoofed search bots.
 *
 * Implements three verification states:
 * - VERIFIED: Confirmed authentic search engine crawler.
 * - FAKE: Confirmed spoofed crawler (PTR forged or forward DNS mismatch).
 * - UNKNOWN: DNS lookup failed, timed out, or rate budget exceeded (NEVER blocked as fake).
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class ReverseDnsVerifier
{
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_FAKE = 'fake';
    public const STATUS_UNKNOWN = 'unknown';
    public const STATUS_NOT_CHECKED = 'not_checked';

    /**
     * Diagnostic reason codes for STATUS_UNKNOWN.
     */
    public const REASON_SLOTS_BUSY = 'dns_slots_busy';
    public const REASON_RATE_LIMIT = 'dns_rate_limit';
    public const REASON_LOCK_TIMEOUT = 'dns_lock_timeout';
    public const REASON_LOOKUP_IN_PROGRESS = 'dns_lookup_in_progress';
    public const REASON_PTR_TIMEOUT = 'dns_ptr_timeout';
    public const REASON_FORWARD_FAILED = 'dns_forward_failed';
    public const REASON_RESOLVER_ERROR = 'dns_resolver_error';
    public const REASON_VERIFICATION_DISABLED = 'verification_disabled';

    /**
     * Cache version and prefix for verified search bot lookups.
     * v3 ensures that any stale cached records from prior versions with broad cloud subnets
     * are completely invalidated without flushing the general application cache.
     */
    public const CACHE_VERSION = 'v3';
    public const CACHE_PREFIX = 'antibot_rdns_v3_';

    /**
     * Snapshot date and official sources of search engine crawler subnets.
     */
    public const CIDR_SNAPSHOT_DATE = '2026-10-30';
    public const GOOGLEBOT_CIDR_SOURCE = 'https://developers.google.com/static/crawling/ipranges/common-crawlers.json';
    public const BINGBOT_CIDR_SOURCE = 'https://www.bing.com/toolbox/bingbot.json';

    /**
     * Legitimate hostname domain suffixes for each search engine and crawler.
     */
    public const VALID_DOMAINS = [
        'yandex' => ['.yandex.ru', '.yandex.net', '.yandex.com'],
        'google' => ['.googlebot.com', '.google.com'],
        'google_special' => ['.google.com', '.googlebot.com'],
        'bing' => ['.search.msn.com'],
        'mailru' => ['.mail.ru'],
        'apple' => ['.applebot.apple.com'],
        'baidu' => ['.baidu.com', '.baidu.jp'],
        'duckduckgo' => ['.duckduckgo.com'],
        'petalbot' => ['.aspiegel.com', '.petalsearch.com'],
        'ahrefs' => ['.ahrefs.com', '.ahrefs.net'],
        'semrush' => ['.semrush.com'],
        'dotbot' => ['.opensiteexplorer.org', '.moz.com'],
        'mj12bot' => ['.majestic12.co.uk', '.majesticseo.com'],
        'dataforseo' => ['.dataforseo.com'],
    ];

    /**
     * Known official subnets for Googlebot (published in Google's official googlebot.json).
     * Used for fast-path validation without blocking DNS calls.
     */
    public const GOOGLEBOT_CIDRS = [
        '192.178.4.0/27',
        '192.178.4.128/27',
        '192.178.4.160/27',
        '192.178.4.192/27',
        '192.178.4.224/27',
        '192.178.4.32/27',
        '192.178.4.64/27',
        '192.178.4.96/27',
        '192.178.5.0/27',
        '192.178.6.0/27',
        '192.178.6.128/27',
        '192.178.6.160/27',
        '192.178.6.192/27',
        '192.178.6.224/27',
        '192.178.6.32/27',
        '192.178.6.64/27',
        '192.178.6.96/27',
        '192.178.7.0/27',
        '192.178.7.128/27',
        '192.178.7.160/27',
        '192.178.7.192/27',
        '192.178.7.224/27',
        '192.178.7.32/27',
        '192.178.7.64/27',
        '192.178.7.96/27',
        '2001:4860:4801:10::/64',
        '2001:4860:4801:11::/64',
        '2001:4860:4801:12::/64',
        '2001:4860:4801:13::/64',
        '2001:4860:4801:14::/64',
        '2001:4860:4801:15::/64',
        '2001:4860:4801:16::/64',
        '2001:4860:4801:17::/64',
        '2001:4860:4801:18::/64',
        '2001:4860:4801:19::/64',
        '2001:4860:4801:1a::/64',
        '2001:4860:4801:1b::/64',
        '2001:4860:4801:1c::/64',
        '2001:4860:4801:1d::/64',
        '2001:4860:4801:1e::/64',
        '2001:4860:4801:1f::/64',
        '2001:4860:4801:20::/64',
        '2001:4860:4801:21::/64',
        '2001:4860:4801:22::/64',
        '2001:4860:4801:23::/64',
        '2001:4860:4801:24::/64',
        '2001:4860:4801:25::/64',
        '2001:4860:4801:26::/64',
        '2001:4860:4801:27::/64',
        '2001:4860:4801:28::/64',
        '2001:4860:4801:29::/64',
        '2001:4860:4801:2::/64',
        '2001:4860:4801:2a::/64',
        '2001:4860:4801:2b::/64',
        '2001:4860:4801:2c::/64',
        '2001:4860:4801:2d::/64',
        '2001:4860:4801:2e::/64',
        '2001:4860:4801:2f::/64',
        '2001:4860:4801:30::/64',
        '2001:4860:4801:31::/64',
        '2001:4860:4801:32::/64',
        '2001:4860:4801:33::/64',
        '2001:4860:4801:34::/64',
        '2001:4860:4801:35::/64',
        '2001:4860:4801:36::/64',
        '2001:4860:4801:37::/64',
        '2001:4860:4801:38::/64',
        '2001:4860:4801:39::/64',
        '2001:4860:4801:3a::/64',
        '2001:4860:4801:3b::/64',
        '2001:4860:4801:3c::/64',
        '2001:4860:4801:3d::/64',
        '2001:4860:4801:3e::/64',
        '2001:4860:4801:3f::/64',
        '2001:4860:4801:40::/64',
        '2001:4860:4801:41::/64',
        '2001:4860:4801:42::/64',
        '2001:4860:4801:43::/64',
        '2001:4860:4801:44::/64',
        '2001:4860:4801:45::/64',
        '2001:4860:4801:46::/64',
        '2001:4860:4801:47::/64',
        '2001:4860:4801:48::/64',
        '2001:4860:4801:49::/64',
        '2001:4860:4801:4a::/64',
        '2001:4860:4801:4b::/64',
        '2001:4860:4801:4c::/64',
        '2001:4860:4801:4d::/64',
        '2001:4860:4801:4e::/64',
        '2001:4860:4801:50::/64',
        '2001:4860:4801:51::/64',
        '2001:4860:4801:52::/64',
        '2001:4860:4801:53::/64',
        '2001:4860:4801:54::/64',
        '2001:4860:4801:55::/64',
        '2001:4860:4801:56::/64',
        '2001:4860:4801:57::/64',
        '2001:4860:4801:58::/64',
        '2001:4860:4801:59::/64',
        '2001:4860:4801:60::/64',
        '2001:4860:4801:61::/64',
        '2001:4860:4801:62::/64',
        '2001:4860:4801:63::/64',
        '2001:4860:4801:64::/64',
        '2001:4860:4801:65::/64',
        '2001:4860:4801:66::/64',
        '2001:4860:4801:67::/64',
        '2001:4860:4801:68::/64',
        '2001:4860:4801:69::/64',
        '2001:4860:4801:6a::/64',
        '2001:4860:4801:6b::/64',
        '2001:4860:4801:6c::/64',
        '2001:4860:4801:6d::/64',
        '2001:4860:4801:6e::/64',
        '2001:4860:4801:6f::/64',
        '2001:4860:4801:70::/64',
        '2001:4860:4801:71::/64',
        '2001:4860:4801:72::/64',
        '2001:4860:4801:73::/64',
        '2001:4860:4801:74::/64',
        '2001:4860:4801:75::/64',
        '2001:4860:4801:76::/64',
        '2001:4860:4801:77::/64',
        '2001:4860:4801:78::/64',
        '2001:4860:4801:79::/64',
        '2001:4860:4801:7a::/64',
        '2001:4860:4801:7b::/64',
        '2001:4860:4801:7c::/64',
        '2001:4860:4801:7d::/64',
        '2001:4860:4801:7e::/64',
        '2001:4860:4801:7f::/64',
        '2001:4860:4801:80::/64',
        '2001:4860:4801:81::/64',
        '2001:4860:4801:82::/64',
        '2001:4860:4801:83::/64',
        '2001:4860:4801:84::/64',
        '2001:4860:4801:85::/64',
        '2001:4860:4801:86::/64',
        '2001:4860:4801:87::/64',
        '2001:4860:4801:88::/64',
        '2001:4860:4801:90::/64',
        '2001:4860:4801:91::/64',
        '2001:4860:4801:92::/64',
        '2001:4860:4801:93::/64',
        '2001:4860:4801:94::/64',
        '2001:4860:4801:95::/64',
        '2001:4860:4801:96::/64',
        '2001:4860:4801:97::/64',
        '2001:4860:4801:a0::/64',
        '2001:4860:4801:a1::/64',
        '2001:4860:4801:a2::/64',
        '2001:4860:4801:a3::/64',
        '2001:4860:4801:a4::/64',
        '2001:4860:4801:a5::/64',
        '2001:4860:4801:a6::/64',
        '2001:4860:4801:a7::/64',
        '2001:4860:4801:a8::/64',
        '2001:4860:4801:a9::/64',
        '2001:4860:4801:aa::/64',
        '2001:4860:4801:ab::/64',
        '2001:4860:4801:ac::/64',
        '2001:4860:4801:ad::/64',
        '2001:4860:4801:ae::/64',
        '2001:4860:4801:b0::/64',
        '2001:4860:4801:b1::/64',
        '2001:4860:4801:b2::/64',
        '2001:4860:4801:b3::/64',
        '2001:4860:4801:b4::/64',
        '2001:4860:4801:b5::/64',
        '2001:4860:4801:b6::/64',
        '2001:4860:4801:c::/64',
        '2001:4860:4801:f::/64',
        '34.100.182.96/28',
        '34.101.50.144/28',
        '34.118.254.0/28',
        '34.118.66.0/28',
        '34.126.178.96/28',
        '34.146.150.144/28',
        '34.147.110.144/28',
        '34.151.74.144/28',
        '34.152.50.64/28',
        '34.154.114.144/28',
        '34.155.98.32/28',
        '34.165.18.176/28',
        '34.175.160.64/28',
        '34.176.130.16/28',
        '34.22.85.0/27',
        '34.64.82.64/28',
        '34.65.242.112/28',
        '34.80.50.80/28',
        '34.88.194.0/28',
        '34.89.10.80/28',
        '34.89.198.80/28',
        '34.96.162.48/28',
        '35.247.243.240/28',
        '66.249.64.0/27',
        '66.249.64.128/27',
        '66.249.64.160/27',
        '66.249.64.192/27',
        '66.249.64.224/27',
        '66.249.64.32/27',
        '66.249.64.64/27',
        '66.249.64.96/27',
        '66.249.65.0/27',
        '66.249.65.128/27',
        '66.249.65.160/27',
        '66.249.65.192/27',
        '66.249.65.224/27',
        '66.249.65.32/27',
        '66.249.65.64/27',
        '66.249.65.96/27',
        '66.249.66.0/27',
        '66.249.66.128/27',
        '66.249.66.160/27',
        '66.249.66.192/27',
        '66.249.66.224/27',
        '66.249.66.32/27',
        '66.249.66.64/27',
        '66.249.66.96/27',
        '66.249.67.0/27',
        '66.249.67.32/27',
        '66.249.67.64/27',
        '66.249.68.0/27',
        '66.249.68.128/27',
        '66.249.68.160/27',
        '66.249.68.192/27',
        '66.249.68.224/27',
        '66.249.68.32/27',
        '66.249.68.64/27',
        '66.249.68.96/27',
        '66.249.69.0/27',
        '66.249.69.128/27',
        '66.249.69.160/27',
        '66.249.69.192/27',
        '66.249.69.224/27',
        '66.249.69.32/27',
        '66.249.69.64/27',
        '66.249.69.96/27',
        '66.249.70.0/27',
        '66.249.70.128/27',
        '66.249.70.160/27',
        '66.249.70.192/27',
        '66.249.70.224/27',
        '66.249.70.32/27',
        '66.249.70.64/27',
        '66.249.70.96/27',
        '66.249.71.0/27',
        '66.249.71.128/27',
        '66.249.71.160/27',
        '66.249.71.192/27',
        '66.249.71.224/27',
        '66.249.71.32/27',
        '66.249.71.64/27',
        '66.249.71.96/27',
        '66.249.72.0/27',
        '66.249.72.128/27',
        '66.249.72.160/27',
        '66.249.72.192/27',
        '66.249.72.224/27',
        '66.249.72.32/27',
        '66.249.72.64/27',
        '66.249.72.96/27',
        '66.249.73.0/27',
        '66.249.73.128/27',
        '66.249.73.160/27',
        '66.249.73.192/27',
        '66.249.73.224/27',
        '66.249.73.32/27',
        '66.249.73.64/27',
        '66.249.73.96/27',
        '66.249.74.0/27',
        '66.249.74.128/27',
        '66.249.74.160/27',
        '66.249.74.192/27',
        '66.249.74.224/27',
        '66.249.74.32/27',
        '66.249.74.64/27',
        '66.249.74.96/27',
        '66.249.75.0/27',
        '66.249.75.128/27',
        '66.249.75.160/27',
        '66.249.75.192/27',
        '66.249.75.224/27',
        '66.249.75.32/27',
        '66.249.75.64/27',
        '66.249.75.96/27',
        '66.249.76.0/27',
        '66.249.76.128/27',
        '66.249.76.160/27',
        '66.249.76.192/27',
        '66.249.76.224/27',
        '66.249.76.32/27',
        '66.249.76.64/27',
        '66.249.76.96/27',
        '66.249.77.0/27',
        '66.249.77.128/27',
        '66.249.77.160/27',
        '66.249.77.192/27',
        '66.249.77.224/27',
        '66.249.77.32/27',
        '66.249.77.64/27',
        '66.249.77.96/27',
        '66.249.78.0/27',
        '66.249.78.128/27',
        '66.249.78.160/27',
        '66.249.78.192/27',
        '66.249.78.224/27',
        '66.249.78.32/27',
        '66.249.78.64/27',
        '66.249.78.96/27',
        '66.249.79.0/27',
        '66.249.79.128/27',
        '66.249.79.160/27',
        '66.249.79.192/27',
        '66.249.79.224/27',
        '66.249.79.32/27',
        '66.249.79.64/27',
    ];

    /**
     * Official subnets for Bingbot (Source: https://www.bing.com/toolbox/bingbot.json).
     */
    public const BINGBOT_CIDRS = [
        '13.66.139.0/24',
        '13.66.144.0/24',
        '13.67.10.16/28',
        '13.69.66.240/28',
        '13.71.172.224/28',
        '139.217.52.0/28',
        '157.55.39.0/24',
        '191.233.204.224/28',
        '199.30.24.0/23',
        '20.125.163.80/28',
        '20.15.133.160/27',
        '20.36.108.32/28',
        '20.43.120.16/28',
        '20.74.197.0/28',
        '20.79.107.240/28',
        '207.46.13.0/24',
        '40.77.139.0/25',
        '40.77.167.0/24',
        '40.77.177.0/24',
        '40.77.178.0/23',
        '40.77.188.0/22',
        '40.77.202.0/24',
        '40.79.131.208/28',
        '40.79.186.176/28',
        '51.105.67.0/28',
        '52.167.144.0/24',
        '52.231.148.0/28',
        '65.55.210.0/24',
    ];

    /**
     * Cache TTL in seconds.
     * Verified bot caching default is 48 hours (172800 seconds).
     * Configurable at runtime.
     */
    public static int $cacheVerified = 172800; // 48 hours default
    public static int $cacheFake = 86400;     // 24 hours default for proven fakes
    public static int $cacheUnknown = 300;    // Legacy fallback, fine-grained getUnknownTtl() used

    /**
     * Diagnostic reason for the last UNKNOWN verification outcome.
     */
    public static ?string $lastUnknownReason = null;

    /**
     * Maximum capacity for persistent verified bot records store (antibot_verified_bots.json).
     */
    public static int $maxVerifiedBotRecords = 10000;

    /**
     * Maximum capacity for persistent fake bot records store (antibot_fake_bots.json).
     * Isolated from verified bots store to prevent eviction attacks.
     */
    public static int $maxFakeBotRecords = 10000;

    /**
     * Verification performance metrics and diagnostics.
     * @var array<string, mixed>
     */
    public static array $stats = [
        'cache_hits' => 0,
        'cache_hits_verified' => 0,
        'cache_hits_fake' => 0,
        'dns_performed' => 0,
        'prevented_duplicates' => 0,
        'skipped_by_policy' => 0,
        'unknown_counts' => [],
        'verified_first_time' => 0,
        'fcrdns_failed' => 0,
        'expired_records' => 0,
    ];

    /**
     * Returns the diagnostic code for the last UNKNOWN verification outcome.
     */
    public static function getLastUnknownReason(): ?string
    {
        return self::$lastUnknownReason;
    }

    /**
     * Returns the verification performance statistics.
     *
     * @return array<string, mixed>
     */
    public static function getStats(): array
    {
        return self::$stats;
    }

    /**
     * Resets verification performance statistics.
     */
    public static function resetStats(): void
    {
        self::$stats = [
            'cache_hits' => 0,
            'cache_hits_verified' => 0,
            'cache_hits_fake' => 0,
            'dns_performed' => 0,
            'prevented_duplicates' => 0,
            'skipped_by_policy' => 0,
            'unknown_counts' => [],
            'verified_first_time' => 0,
            'fcrdns_failed' => 0,
            'expired_records' => 0,
        ];
        self::$lastUnknownReason = null;
    }

    /**
     * Returns detailed verification and cache statistics for administrative dashboard and diagnostics.
     *
     * @return array<string, mixed>
     */
    public static function getDetailedStats(): array
    {
        $vFile = self::resolveVerifiedBotFile();
        $fFile = self::resolveFakeBotFile();
        $totalHits = (int) (self::$stats['cache_hits'] ?? 0);
        $dnsCount = (int) (self::$stats['dns_performed'] ?? 0);
        $totalLookups = $totalHits + $dnsCount;
        $hitRatio = $totalLookups > 0 ? round(($totalHits / $totalLookups) * 100, 1) : 0.0;

        return [
            'verified_store_count' => count(self::getVerifiedBotRecords()),
            'fake_store_count' => count(self::getFakeBotRecords()),
            'cache_hits' => $totalHits,
            'cache_hits_verified' => (int) (self::$stats['cache_hits_verified'] ?? 0),
            'cache_hits_fake' => (int) (self::$stats['cache_hits_fake'] ?? 0),
            'skipped_by_policy' => (int) (self::$stats['skipped_by_policy'] ?? 0),
            'unknown_count' => array_sum((array) (self::$stats['unknown_counts'] ?? [])),
            'dns_performed' => $dnsCount,
            'prevented_duplicates' => (int) (self::$stats['prevented_duplicates'] ?? 0),
            'fcrdns_failed' => (int) (self::$stats['fcrdns_failed'] ?? 0),
            'expired_records' => (int) (self::$stats['expired_records'] ?? 0),
            'hit_ratio_percent' => $hitRatio,
            'verified_file_size_bytes' => file_exists($vFile) ? (int) @filesize($vFile) : 0,
            'fake_file_size_bytes' => file_exists($fFile) ? (int) @filesize($fFile) : 0,
        ];
    }

    /**
     * Max uncached DNS lookups allowed per 60-second window across the app to prevent DNS DoS.
     */
    public static int $maxDnsLookupsPerMinute = 25;

    /**
     * Max concurrent in-flight DNS lookups executing simultaneously across all workers.
     * Backed by physical OS non-blocking file locks on slot files.
     */
    public static int $maxConcurrentDnsLookups = 3;

    /**
     * Optional test callback to simulate DNS responses without issuing network calls.
     * @var (\Closure(string, array<int, string>): string)|null
     */
    public static ?\Closure $dnsResolverCallback = null;

    /**
     * Explicit override for shared storage directory (useful for custom shared paths).
     */
    public static ?string $storageDir = null;

    /**
     * Resolves the full path to the shared storage directory.
     * In Yii2 Advanced, prioritizes @common/runtime so that frontend, backend,
     * and console share the exact same persistent files.
     */
    public static function getStorageDir(): string
    {
        if (self::$storageDir !== null) {
            if (!is_dir(self::$storageDir)) {
                @mkdir(self::$storageDir, 0775, true);
            }
            return self::$storageDir;
        }

        if (class_exists('Yii', false) && Yii::getAlias('@common', false) !== false) {
            $sharedDir = Yii::getAlias('@common/runtime');
            if (!is_dir($sharedDir)) {
                @mkdir($sharedDir, 0775, true);
            }
            if (is_dir($sharedDir) && is_writable($sharedDir)) {
                return $sharedDir;
            }
        }

        if (class_exists('Yii', false) && Yii::getAlias('@runtime', false) !== false) {
            $dir = Yii::getAlias('@runtime');
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            if (is_dir($dir) && is_writable($dir)) {
                return $dir;
            }
        }

        return sys_get_temp_dir();
    }

    /**
     * Resolves the full path to the shared DNS budget file.
     */
    public static function resolveDnsBudgetFile(): string
    {
        return self::getStorageDir() . '/antibot_dns_budget.json';
    }

    /**
     * Resolves the full path to the persistent verified bots store file.
     */
    public static function resolveVerifiedBotFile(): string
    {
        return self::getStorageDir() . '/antibot_verified_bots.json';
    }

    /**
     * Builds the versioned cache key for a search bot verification result.
     *
     * @param string $ip Client IP.
     * @param string $engine Search engine key.
     * @return string
     */
    public static function buildCacheKey(string $ip, string $engine): string
    {
        return self::CACHE_PREFIX . md5($ip . '_' . strtolower($engine));
    }

    /**
     * Retrieves cached verification details for an IP and engine.
     * Checks Tier 1 (Yii::$app->cache) and Tier 2 (persistent shared JSON file).
     * Strictly verifies that the cached record matches the requested operator/engine.
     *
     * @param string $ip Client IP.
     * @param string $engine Target engine key ('yandex', 'google', etc.).
     * @return array<string, mixed>|null Structured record if verified and valid; null otherwise.
     */
    public static function getCachedVerification(string $ip, string $engine): ?array
    {
        $ip = trim($ip);
        $engine = strtolower(trim($engine));
        if ($ip === '' || $engine === '') {
            return null;
        }

        $now = time();
        $cacheKey = self::buildCacheKey($ip, $engine);

        // Tier 1: Application Cache
        if (Yii::$app !== null && Yii::$app->has('cache') && Yii::$app->cache !== null) {
            try {
                $cached = Yii::$app->cache->get($cacheKey);
                if (is_array($cached)) {
                    if (($cached['status'] ?? '') === self::STATUS_VERIFIED &&
                        strtolower((string) ($cached['engine'] ?? '')) === $engine &&
                        (int) ($cached['expires_at'] ?? 0) > $now) {
                        return $cached;
                    }
                } elseif (is_string($cached) && $cached === self::STATUS_VERIFIED) {
                    return [
                        'ip' => $ip,
                        'engine' => $engine,
                        'operator' => ucfirst($engine),
                        'method' => 'fcrdns',
                        'status' => self::STATUS_VERIFIED,
                        'first_verified' => $now,
                        'last_verified' => $now,
                        'expires_at' => $now + self::$cacheVerified,
                        'source' => 'FCrDNS',
                    ];
                }
            } catch (\Throwable) {
                // Ignore cache exception, fallback to persistent store
            }
        }

        // Tier 2: Persistent Shared Verified Bots Store
        $storeFile = self::resolveVerifiedBotFile();
        if (!file_exists($storeFile)) {
            return null;
        }

        $storeKey = $engine . ':' . $ip;
        $lockFile = $storeFile . '.lock';
        $lockFp = @fopen($lockFile, 'c+');
        $record = null;

        if ($lockFp && @flock($lockFp, LOCK_SH)) {
            try {
                if (file_exists($storeFile)) {
                    $raw = @file_get_contents($storeFile);
                    if ($raw !== false && trim($raw) !== '') {
                        $data = json_decode($raw, true);
                        if (is_array($data) && isset($data[$storeKey])) {
                            $cand = $data[$storeKey];
                            if (is_array($cand) &&
                                ($cand['status'] ?? '') === self::STATUS_VERIFIED &&
                                strtolower((string) ($cand['engine'] ?? '')) === $engine &&
                                (int) ($cand['expires_at'] ?? 0) > $now) {
                                $record = $cand;
                            }
                        }
                    }
                }
            } catch (\Throwable) {
                $record = null;
            } finally {
                @flock($lockFp, LOCK_UN);
                @fclose($lockFp);
            }
        } elseif ($lockFp) {
            @fclose($lockFp);
        }

        if ($record !== null) {
            // Populate Tier 1 cache for fast subsequent hits
            if (Yii::$app !== null && Yii::$app->has('cache') && Yii::$app->cache !== null) {
                try {
                    $ttl = max(60, (int) ($record['expires_at'] - $now));
                    Yii::$app->cache->set($cacheKey, $record, $ttl);
                } catch (\Throwable) {
                    // Ignore
                }
            }
            return $record;
        }

        return null;
    }

    /**
     * Saves a verified bot confirmation to Tier 1 and Tier 2 persistent storage.
     *
     * @param string $ip
     * @param string $engine
     * @param array<string, mixed> $extra
     * @param int|null $ttl
     */
    public static function saveCachedVerification(string $ip, string $engine, array $extra = [], ?int $ttl = null): void
    {
        $ip = trim($ip);
        $engine = strtolower(trim($engine));
        if ($ip === '' || $engine === '') {
            return;
        }

        $ttl = $ttl ?? self::$cacheVerified;
        $now = time();
        $expiresAt = $now + $ttl;

        $record = [
            'ip' => $ip,
            'engine' => $engine,
            'operator' => (string) ($extra['operator'] ?? ucfirst($engine)),
            'method' => (string) ($extra['method'] ?? 'fcrdns'),
            'status' => self::STATUS_VERIFIED,
            'first_verified' => (int) ($extra['first_verified'] ?? $now),
            'last_verified' => $now,
            'expires_at' => $expiresAt,
            'source' => (string) ($extra['source'] ?? 'FCrDNS'),
        ];

        // Tier 1: Yii Cache
        $cacheKey = self::buildCacheKey($ip, $engine);
        if (Yii::$app !== null && Yii::$app->has('cache') && Yii::$app->cache !== null) {
            try {
                Yii::$app->cache->set($cacheKey, $record, $ttl);
            } catch (\Throwable) {
                // Ignore
            }
        }

        // Tier 2: Persistent Shared JSON File
        try {
            $storeFile = self::resolveVerifiedBotFile();
            $lockFile = $storeFile . '.lock';
            $lockFp = @fopen($lockFile, 'c+');
            if ($lockFp && @flock($lockFp, LOCK_EX)) {
                try {
                    $data = [];
                    if (file_exists($storeFile)) {
                        $raw = @file_get_contents($storeFile);
                        if ($raw !== false && trim($raw) !== '') {
                            $decoded = json_decode($raw, true);
                            if (is_array($decoded)) {
                                $data = $decoded;
                            }
                        }
                    }

                    $storeKey = $engine . ':' . $ip;
                    if (isset($data[$storeKey]['first_verified'])) {
                        $record['first_verified'] = (int) $data[$storeKey]['first_verified'];
                    }
                    $data[$storeKey] = $record;

                    // Prune expired or limit capacity (bounded LRU)
                    if (count($data) > self::$maxVerifiedBotRecords) {
                        foreach ($data as $k => $v) {
                            if (($v['expires_at'] ?? 0) <= $now) {
                                unset($data[$k]);
                            }
                        }
                        if (count($data) > self::$maxVerifiedBotRecords) {
                            uasort($data, static fn($a, $b) => ($a['last_verified'] ?? 0) <=> ($b['last_verified'] ?? 0));
                            $data = array_slice($data, -self::$maxVerifiedBotRecords, null, true);
                        }
                    }

                    $tmp = $storeFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
                    if (@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false) {
                        if (!@rename($tmp, $storeFile)) {
                            @copy($tmp, $storeFile);
                            @unlink($tmp);
                        }
                    }
                } finally {
                    @flock($lockFp, LOCK_UN);
                    @fclose($lockFp);
                }
            } elseif ($lockFp) {
                @fclose($lockFp);
            }
        } catch (\Throwable) {
            // Best effort, do not crash worker
        }
    }

    /**
     * Retrieves all valid verified bot records from persistent store.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getVerifiedBotRecords(): array
    {
        $storeFile = self::resolveVerifiedBotFile();
        if (!file_exists($storeFile)) {
            return [];
        }

        $now = time();
        $records = [];
        $lockFile = $storeFile . '.lock';
        $lockFp = @fopen($lockFile, 'c+');
        if (!$lockFp) {
            return [];
        }

        try {
            if (@flock($lockFp, LOCK_SH)) {
                if (file_exists($storeFile)) {
                    $raw = @file_get_contents($storeFile);
                    if ($raw !== false && trim($raw) !== '') {
                        $data = json_decode($raw, true);
                        if (is_array($data)) {
                            foreach ($data as $key => $rec) {
                                if (is_array($rec) && (int) ($rec['expires_at'] ?? 0) > $now) {
                                    $records[$key] = $rec;
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Throwable) {
            $records = [];
        } finally {
            @flock($lockFp, LOCK_UN);
            @fclose($lockFp);
        }

        return $records;
    }

    /**
     * Resets persistent verified bots storage (for testing and diagnostics).
     */
    public static function resetVerifiedBotsStore(): void
    {
        $file = self::resolveVerifiedBotFile();
        if (file_exists($file)) {
            @unlink($file);
        }
        $lock = $file . '.lock';
        if (file_exists($lock)) {
            @unlink($lock);
        }
        self::$verifiedIpRegistry = [];
        self::resetStats();
    }

    /**
     * Resolves the full path to the persistent fake bots store file.
     * Isolated from verified bots store to prevent eviction attacks.
     */
    public static function resolveFakeBotFile(): string
    {
        return self::getStorageDir() . '/antibot_fake_bots.json';
    }

    /**
     * Retrieves cached fake status for an IP and engine.
     * Checks Tier 1 (Yii::$app->cache) and Tier 2 (antibot_fake_bots.json).
     * Reading the cache does NOT extend expires_at.
     *
     * @param string $ip Client IP.
     * @param string $engine Engine key.
     * @return array<string, mixed>|null Record if confirmed fake and still within TTL; null otherwise.
     */
    public static function getCachedFake(string $ip, string $engine): ?array
    {
        $ip = trim($ip);
        $engine = strtolower(trim($engine));
        if ($ip === '' || $engine === '') {
            return null;
        }

        $now = time();
        $cacheKey = self::buildCacheKey($ip, $engine) . '_fake';

        // Tier 1: Application Cache
        if (Yii::$app !== null && Yii::$app->has('cache') && Yii::$app->cache !== null) {
            try {
                $cached = Yii::$app->cache->get($cacheKey);
                if (is_array($cached)) {
                    if (($cached['status'] ?? '') === self::STATUS_FAKE &&
                        strtolower((string) ($cached['engine'] ?? '')) === $engine &&
                        (int) ($cached['expires_at'] ?? 0) > $now) {
                        return $cached;
                    }
                } elseif (is_string($cached) && $cached === self::STATUS_FAKE) {
                    return [
                        'ip' => $ip,
                        'engine' => $engine,
                        'status' => self::STATUS_FAKE,
                        'detected_at' => $now,
                        'expires_at' => $now + self::$cacheFake,
                        'reason' => 'Proven fake crawler',
                    ];
                }
            } catch (\Throwable) {
                // Ignore, fallback to Tier 2
            }
        }

        // Tier 2: Persistent Shared Fake Bots Store
        $storeFile = self::resolveFakeBotFile();
        if (!file_exists($storeFile)) {
            return null;
        }

        $storeKey = $engine . ':' . $ip;
        $lockFile = $storeFile . '.lock';
        $lockFp = @fopen($lockFile, 'c+');
        $record = null;

        if ($lockFp && @flock($lockFp, LOCK_SH)) {
            try {
                if (file_exists($storeFile)) {
                    $raw = @file_get_contents($storeFile);
                    if ($raw !== false && trim($raw) !== '') {
                        $data = json_decode($raw, true);
                        if (is_array($data) && isset($data[$storeKey])) {
                            $cand = $data[$storeKey];
                            if (is_array($cand) &&
                                ($cand['status'] ?? '') === self::STATUS_FAKE &&
                                strtolower((string) ($cand['engine'] ?? '')) === $engine &&
                                (int) ($cand['expires_at'] ?? 0) > $now) {
                                $record = $cand;
                            }
                        }
                    }
                }
            } catch (\Throwable) {
                $record = null;
            } finally {
                @flock($lockFp, LOCK_UN);
                @fclose($lockFp);
            }
        } elseif ($lockFp) {
            @fclose($lockFp);
        }

        if ($record !== null) {
            // Populate Tier 1 cache for fast subsequent hits (reading does NOT extend expires_at!)
            if (Yii::$app !== null && Yii::$app->has('cache') && Yii::$app->cache !== null) {
                try {
                    $ttl = max(60, (int) ($record['expires_at'] - $now));
                    Yii::$app->cache->set($cacheKey, $record, $ttl);
                } catch (\Throwable) {
                    // Ignore
                }
            }
            return $record;
        }

        return null;
    }

    /**
     * Saves a confirmed fake bot record to Tier 1 and Tier 2 persistent storage.
     *
     * @param string $ip
     * @param string $engine
     * @param array<string, mixed> $extra
     * @param int|null $ttl
     */
    public static function saveCachedFake(string $ip, string $engine, array $extra = [], ?int $ttl = null): void
    {
        $ip = trim($ip);
        $engine = strtolower(trim($engine));
        if ($ip === '' || $engine === '') {
            return;
        }

        $ttl = $ttl ?? self::$cacheFake;
        $now = time();
        $expiresAt = $now + $ttl;

        $record = [
            'ip' => $ip,
            'engine' => $engine,
            'status' => self::STATUS_FAKE,
            'detected_at' => $now,
            'expires_at' => $expiresAt,
            'reason' => (string) ($extra['reason'] ?? 'fcrdns_failed'),
            'source' => (string) ($extra['source'] ?? 'FCrDNS'),
        ];

        // Tier 1: Yii Cache
        $cacheKey = self::buildCacheKey($ip, $engine) . '_fake';
        if (Yii::$app !== null && Yii::$app->has('cache') && Yii::$app->cache !== null) {
            try {
                Yii::$app->cache->set($cacheKey, $record, $ttl);
            } catch (\Throwable) {
                // Ignore
            }
        }

        // Tier 2: Persistent Shared Fake Bots File (antibot_fake_bots.json)
        try {
            $storeFile = self::resolveFakeBotFile();
            $lockFile = $storeFile . '.lock';
            $lockFp = @fopen($lockFile, 'c+');
            if ($lockFp && @flock($lockFp, LOCK_EX)) {
                try {
                    $data = [];
                    if (file_exists($storeFile)) {
                        $raw = @file_get_contents($storeFile);
                        if ($raw !== false && trim($raw) !== '') {
                            $decoded = json_decode($raw, true);
                            if (is_array($decoded)) {
                                $data = $decoded;
                            }
                        }
                    }

                    $storeKey = $engine . ':' . $ip;
                    $data[$storeKey] = $record;

                    // Prune expired records or limit capacity (bounded LRU)
                    if (count($data) > self::$maxFakeBotRecords) {
                        foreach ($data as $k => $v) {
                            if (($v['expires_at'] ?? 0) <= $now) {
                                unset($data[$k]);
                                self::$stats['expired_records']++;
                            }
                        }
                        if (count($data) > self::$maxFakeBotRecords) {
                            uasort($data, static fn($a, $b) => ($a['detected_at'] ?? 0) <=> ($b['detected_at'] ?? 0));
                            $data = array_slice($data, -self::$maxFakeBotRecords, null, true);
                        }
                    }

                    $tmp = $storeFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
                    if (@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false) {
                        if (!@rename($tmp, $storeFile)) {
                            @copy($tmp, $storeFile);
                            @unlink($tmp);
                        }
                    }
                } finally {
                    @flock($lockFp, LOCK_UN);
                    @fclose($lockFp);
                }
            } elseif ($lockFp) {
                @fclose($lockFp);
            }
        } catch (\Throwable) {
            // Best effort, do not crash worker
        }
    }

    /**
     * Retrieves all valid fake bot records from persistent store.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getFakeBotRecords(): array
    {
        $storeFile = self::resolveFakeBotFile();
        if (!file_exists($storeFile)) {
            return [];
        }

        $now = time();
        $records = [];
        $lockFile = $storeFile . '.lock';
        $lockFp = @fopen($lockFile, 'c+');
        if (!$lockFp) {
            return [];
        }

        try {
            if (@flock($lockFp, LOCK_SH)) {
                if (file_exists($storeFile)) {
                    $raw = @file_get_contents($storeFile);
                    if ($raw !== false && trim($raw) !== '') {
                        $data = json_decode($raw, true);
                        if (is_array($data)) {
                            foreach ($data as $key => $rec) {
                                if (is_array($rec) && (int) ($rec['expires_at'] ?? 0) > $now) {
                                    $records[$key] = $rec;
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Throwable) {
            $records = [];
        } finally {
            @flock($lockFp, LOCK_UN);
            @fclose($lockFp);
        }

        return $records;
    }

    /**
     * Resets persistent fake bots storage.
     */
    public static function resetFakeBotsStore(): void
    {
        $file = self::resolveFakeBotFile();
        if (file_exists($file)) {
            @unlink($file);
        }
        $lock = $file . '.lock';
        if (file_exists($lock)) {
            @unlink($lock);
        }
    }

    /**
     * Fast read-only check for whether a bot is already verified or known fake in cache.
     * Performs 0 network DNS lookups and consumes 0 DNS slots.
     *
     * @param string $ip
     * @param string $engine
     * @return string|null STATUS_VERIFIED, STATUS_FAKE, or null if not cached.
     */
    public static function getCachedStatus(string $ip, string $engine): ?string
    {
        if (self::getCachedVerification($ip, $engine) !== null) {
            return self::STATUS_VERIFIED;
        }
        if (self::getCachedFake($ip, $engine) !== null) {
            return self::STATUS_FAKE;
        }
        return null;
    }

    /**
     * Calculates the fine-grained temporary cache TTL for STATUS_UNKNOWN based on failure reason.
     *
     * @param string|null $reason
     * @return int TTL in seconds (0 = do not cache).
     */
    public static function getUnknownTtl(?string $reason): int
    {
        return match ($reason) {
            self::REASON_SLOTS_BUSY, self::REASON_LOOKUP_IN_PROGRESS => 0, // No caching: retry immediately when slot frees up
            self::REASON_LOCK_TIMEOUT => 5,                                 // 5 seconds
            self::REASON_RATE_LIMIT => max(5, 60 - (time() % 60)),          // Remaining window seconds
            self::REASON_PTR_TIMEOUT, self::REASON_FORWARD_FAILED => 45,    // 45 seconds
            self::REASON_RESOLVER_ERROR => 60,                              // 60 seconds with backoff
            default => 30,
        };
    }

    /**
     * Invalidation and administrative clearing of DNS verification caches.
     *
     * @param string $type 'all'|'verified'|'fake'|'unknown'|'single'
     * @param string $ip Specific IP (when type is 'single')
     * @param string $engine Specific engine (when type is 'single')
     */
    public static function clearCache(string $type = 'all', string $ip = '', string $engine = ''): void
    {
        $vFile = self::resolveVerifiedBotFile();
        $fFile = self::resolveFakeBotFile();

        if ($type === 'single' && $ip !== '' && $engine !== '') {
            $storeKey = strtolower($engine) . ':' . $ip;
            $cacheKey = self::buildCacheKey($ip, $engine);
            if (Yii::$app !== null && Yii::$app->has('cache') && Yii::$app->cache !== null) {
                try {
                    Yii::$app->cache->delete($cacheKey);
                    Yii::$app->cache->delete($cacheKey . '_fake');
                } catch (\Throwable) {
                    // Ignore
                }
            }
            self::deleteRecordFromFile($vFile, $storeKey);
            self::deleteRecordFromFile($fFile, $storeKey);
            return;
        }

        $hasAppCache = Yii::$app !== null && Yii::$app->has('cache') && Yii::$app->cache !== null;

        if ($type === 'all' || $type === 'verified') {
            if ($hasAppCache) {
                foreach (self::getVerifiedBotRecords() as $rec) {
                    $ipRec = $rec['ip'] ?? '';
                    $engRec = $rec['engine'] ?? '';
                    if ($ipRec !== '' && $engRec !== '') {
                        try {
                            Yii::$app->cache->delete(self::buildCacheKey($ipRec, $engRec));
                        } catch (\Throwable) {
                            // Ignore
                        }
                    }
                }
            }
            self::resetVerifiedBotsStore();
        }

        if ($type === 'all' || $type === 'fake') {
            if ($hasAppCache) {
                foreach (self::getFakeBotRecords() as $rec) {
                    $ipRec = $rec['ip'] ?? '';
                    $engRec = $rec['engine'] ?? '';
                    if ($ipRec !== '' && $engRec !== '') {
                        try {
                            Yii::$app->cache->delete(self::buildCacheKey($ipRec, $engRec));
                            Yii::$app->cache->delete(self::buildCacheKey($ipRec, $engRec) . '_fake');
                        } catch (\Throwable) {
                            // Ignore
                        }
                    }
                }
            }
            self::resetFakeBotsStore();
        }

        if ($type === 'all' || $type === 'unknown') {
            self::$lastUnknownReason = null;
        }

        if ($type === 'all' && $hasAppCache) {
            try {
                Yii::$app->cache->flush();
            } catch (\Throwable) {
                // Ignore
            }
        }

        if ($type === 'all') {
            self::resetStats();
        }
    }

    /**
     * Atomically deletes a specific key from a JSON store file.
     */
    protected static function deleteRecordFromFile(string $storeFile, string $storeKey): bool
    {
        if (!file_exists($storeFile)) {
            return false;
        }

        $lockFile = $storeFile . '.lock';
        $lockFp = @fopen($lockFile, 'c+');
        if (!$lockFp) {
            return false;
        }

        try {
            if (@flock($lockFp, LOCK_EX)) {
                $data = [];
                $raw = @file_get_contents($storeFile);
                if ($raw !== false && trim($raw) !== '') {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $data = $decoded;
                    }
                }
                if (isset($data[$storeKey])) {
                    unset($data[$storeKey]);
                    $tmp = $storeFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
                    if (@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false) {
                        if (!@rename($tmp, $storeFile)) {
                            @copy($tmp, $storeFile);
                            @unlink($tmp);
                        }
                    }
                    return true;
                }
            }
        } finally {
            @flock($lockFp, LOCK_UN);
            @fclose($lockFp);
        }

        return false;
    }

    /**
     * Runtime registry of confirmed verified bot IPs.
     * @var array<string, string> [$ip => $botName]
     */
    public static array $verifiedIpRegistry = [];

    /**
     * Registers a confirmed authentic bot IP into the runtime registry.
     */
    public static function registerVerifiedIp(string $ip, string $botName): void
    {
        $ip = trim($ip);
        if ($ip !== '') {
            self::$verifiedIpRegistry[$ip] = $botName;
        }
    }

    /**
     * Retrieves known confirmed verified bot IPs from runtime memory and recent event logs.
     *
     * @param int $limit Maximum event lines to analyze.
     * @return array<string, string> Map of IP => Crawler name
     */
    public static function getKnownVerifiedBotIps(int $limit = 500): array
    {
        $verified = self::$verifiedIpRegistry;

        try {
            $storage = Yii::$app !== null && Yii::$app->has('antibot')
                ? Yii::$app->get('antibot')->getStorage()
                : new StorageService();

            $events = $storage->getRecentEvents($limit);
            foreach ($events as $event) {
                $status = $event['verification_status'] ?? null;
                $action = (string) ($event['action'] ?? '');
                if ($status === self::STATUS_VERIFIED || str_starts_with($action, 'allowed_')) {
                    $ip = trim((string) ($event['ip'] ?? ''));
                    if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                        $botName = (string) ($event['reason'] ?? ($event['bot_key'] ?? 'Verified Crawler'));
                        if (!isset($verified[$ip])) {
                            $verified[$ip] = $botName;
                        }
                    }
                }
            }
        } catch (\Throwable) {
            // Fail-safe: return available in-memory registry
        }

        return $verified;
    }

    /**
     * Verifies whether an IP belongs to a legitimate search engine.
     *
     * Order of evaluation:
     * 1. Check cached verification result (Tier 1 memory/cache & Tier 2 persistent JSON).
     * 2. Official CIDR pre-filter (Googlebot & Bingbot): If in official snapshot, verify instantly without DNS.
     * 3. Acquire atomic rate budget and non-blocking concurrency slot (1..3).
     *    Implements Single Flight deduplication if concurrent workers are resolving the same IP.
     * 4. Perform PTR + A/AAAA lookup (with strict domain boundary matching).
     * 5. Cache result and release slot.
     *
     * @param string $ip Client IP address.
     * @param string $engine Search engine key ('yandex', 'google', 'bing', etc.).
     * @return string One of STATUS_VERIFIED, STATUS_FAKE, or STATUS_UNKNOWN.
     */
    public static function check(string $ip, string $engine): string
    {
        self::$lastUnknownReason = null;
        $engine = strtolower($engine);
        if (!isset(self::VALID_DOMAINS[$engine])) {
            return self::STATUS_UNKNOWN;
        }

        $cacheKey = self::buildCacheKey($ip, $engine);

        // 1. Check cached verification (Tier 1 & Tier 2 persistent storage)
        $cachedRecord = self::getCachedVerification($ip, $engine);
        if ($cachedRecord !== null) {
            self::$stats['cache_hits']++;
            self::$stats['cache_hits_verified']++;
            self::registerVerifiedIp($ip, ucfirst($engine) . ' Bot');
            return self::STATUS_VERIFIED;
        }

        // 1b. Fast check for cached negative FAKE (Tier 1 & Tier 2 persistent storage)
        $cachedFake = self::getCachedFake($ip, $engine);
        if ($cachedFake !== null) {
            self::$stats['cache_hits']++;
            self::$stats['cache_hits_fake']++;
            return self::STATUS_FAKE;
        }

        // 1c. Fast check for temporary UNKNOWN in Tier 1 cache
        if (Yii::$app !== null && Yii::$app->has('cache') && Yii::$app->cache !== null) {
            try {
                $cached = Yii::$app->cache->get($cacheKey);
                if (is_string($cached) && $cached === self::STATUS_UNKNOWN) {
                    return self::STATUS_UNKNOWN;
                }
            } catch (\Throwable) {
                // Ignore
            }
        }

        // 2. Pre-filter official CIDR ranges where published (Google, Google Special, Bing)
        if ($engine === 'google' || $engine === 'google_special' || $engine === 'bing') {
            $knownCidrs = match ($engine) {
                'google' => self::GOOGLEBOT_CIDRS,
                'google_special' => \larikmc\antibot\data\GoogleSpecialRanges::getCidrs(),
                'bing' => self::BINGBOT_CIDRS,
            };
            if (IpMatcher::match($ip, $knownCidrs)) {
                // Official CIDR match confirms authentic infrastructure without slow DNS!
                self::saveCachedVerification($ip, $engine, [
                    'operator' => ucfirst($engine),
                    'method' => 'cidr',
                    'source' => 'Official CIDR snapshot',
                ], self::$cacheVerified);
                self::$stats['verified_first_time']++;
                self::registerVerifiedIp($ip, ucfirst($engine) . ' Bot');
                return self::STATUS_VERIFIED;
            }
            // If outside known official snapshot, do NOT mark FAKE (snapshot may be incomplete or outdated).
            // Safely proceed to two-way DNS verification below.
        }

        // 3. Atomically acquire DNS budget & physical concurrency slot (with Single Flight deduplication)
        $slotLease = self::acquireDnsBudgetSlot($ip, $engine);
        if (is_array($slotLease) && isset($slotLease['cached'])) {
            // Single Flight win: concurrent worker completed resolution during bounded wait!
            if ($slotLease['cached'] === self::STATUS_VERIFIED) {
                self::registerVerifiedIp($ip, ucfirst($engine) . ' Bot');
            }
            return (string) $slotLease['cached'];
        }

        if ($slotLease === null) {
            // DNS budget exhausted, rate limit hit, or max concurrent lookups reached:
            // Safely return UNKNOWN without blocking PHP-FPM workers!
            return self::STATUS_UNKNOWN;
        }

        try {
            $status = self::performTwoWayDns($ip, self::VALID_DOMAINS[$engine]);
        } finally {
            self::releaseDnsBudgetSlot($slotLease, $ip);
        }

        // 4. Cache result based on status
        if ($status === self::STATUS_VERIFIED) {
            self::saveCachedVerification($ip, $engine, [
                'operator' => ucfirst($engine),
                'method' => 'fcrdns',
                'source' => 'FCrDNS PTR+Forward',
            ], self::$cacheVerified);
            self::$stats['verified_first_time']++;
            self::$stats['dns_performed']++;
            self::registerVerifiedIp($ip, ucfirst($engine) . ' Bot');
        } elseif ($status === self::STATUS_FAKE) {
            self::$stats['dns_performed']++;
            self::saveCachedFake($ip, $engine, [
                'reason' => self::$lastUnknownReason ?? 'fcrdns_failed',
                'source' => 'FCrDNS PTR+Forward',
            ], self::$cacheFake);
        } else {
            self::$stats['dns_performed']++;
            $unknownTtl = self::getUnknownTtl(self::$lastUnknownReason);
            if ($unknownTtl > 0 && Yii::$app !== null && Yii::$app->has('cache') && Yii::$app->cache !== null) {
                try {
                    Yii::$app->cache->set($cacheKey, self::STATUS_UNKNOWN, $unknownTtl);
                } catch (\Throwable) {
                    // Ignore
                }
            }
        }

        return $status;
    }

    /**
     * Verifies a bot using its catalog entry definition.
     *
     * Supports:
     * - 'rdns': Two-way DNS verification with authorized suffixes
     * - 'cidr_or_rdns': Pre-filtered CIDR check + fallback to two-way DNS
     * - 'cidr': Official published subnets/IPs check (Criteo, Quantcast, Meta)
     * - 'none': Returns STATUS_UNKNOWN (no verification mechanism available)
     *
     * @param string $ip Client IP.
     * @param array<string, mixed> $botData Bot metadata from BotCatalog.
     * @return string One of STATUS_VERIFIED, STATUS_FAKE, or STATUS_UNKNOWN.
     */
    public static function checkBot(string $ip, array $botData): string
    {
        $vMethod = $botData['verification'] ?? BotCatalog::VERIFY_NONE;

        if ($vMethod === BotCatalog::VERIFY_NONE) {
            return self::STATUS_UNKNOWN;
        }

        $botName = (string) ($botData['name'] ?? 'Verified Crawler');

        if ($vMethod === BotCatalog::VERIFY_CIDR) {
            $cidrKey = $botData['cidr_key'] ?? '';
            $matched = match ($cidrKey) {
                'criteo' => IpMatcher::match($ip, BotCatalog::CRITEO_CIDRS),
                'quantcast' => in_array($ip, BotCatalog::QUANTCAST_IPS, true),
                'meta' => IpMatcher::match($ip, BotCatalog::META_CIDRS),
                'ias' => \larikmc\antibot\data\IasRanges::match($ip),
                'openai' => IpMatcher::match($ip, BotCatalog::OPENAI_ADS_CIDRS),
                'telegram' => IpMatcher::match($ip, BotCatalog::TELEGRAM_CIDRS),
                'vk' => IpMatcher::match($ip, BotCatalog::VK_CIDRS),
                default => false,
            };
            if ($matched) {
                self::registerVerifiedIp($ip, $botName);
                return self::STATUS_VERIFIED;
            }
            return self::STATUS_FAKE;
        }

        $engineKey = $botData['engine_key'] ?? '';
        if ($engineKey !== '' && isset(self::VALID_DOMAINS[$engineKey])) {
            $status = self::check($ip, $engineKey);
            if ($status === self::STATUS_VERIFIED) {
                self::registerVerifiedIp($ip, $botName);
            }
            return $status;
        }

        if (!empty($botData['domains'])) {
            $botKey = strtolower((string) ($botData['engine_key'] ?? ($botData['name'] ?? 'crawler')));

            // 1. Check verified cache
            $cached = self::getCachedVerification($ip, $botKey);
            if ($cached !== null) {
                self::$stats['cache_hits']++;
                self::$stats['cache_hits_verified']++;
                self::registerVerifiedIp($ip, $botName);
                return self::STATUS_VERIFIED;
            }

            // 1b. Fast check for cached negative FAKE
            $cachedFake = self::getCachedFake($ip, $botKey);
            if ($cachedFake !== null) {
                self::$stats['cache_hits']++;
                self::$stats['cache_hits_fake']++;
                return self::STATUS_FAKE;
            }

            // 1c. Fast check for temporary UNKNOWN in Tier 1 cache
            $cacheKey = self::buildCacheKey($ip, $botKey);
            if (Yii::$app !== null && Yii::$app->has('cache') && Yii::$app->cache !== null) {
                try {
                    $c = Yii::$app->cache->get($cacheKey);
                    if (is_string($c) && $c === self::STATUS_UNKNOWN) {
                        return self::STATUS_UNKNOWN;
                    }
                } catch (\Throwable) {
                    // Ignore
                }
            }

            $slotLease = self::acquireDnsBudgetSlot($ip, $botKey);
            if (is_array($slotLease) && isset($slotLease['cached'])) {
                if ($slotLease['cached'] === self::STATUS_VERIFIED) {
                    self::registerVerifiedIp($ip, $botName);
                }
                return (string) $slotLease['cached'];
            }

            if ($slotLease === null) {
                return self::STATUS_UNKNOWN;
            }
            try {
                $status = self::performTwoWayDns($ip, (array) $botData['domains']);
                if ($status === self::STATUS_VERIFIED) {
                    self::saveCachedVerification($ip, $botKey, [
                        'operator' => $botName,
                        'method' => 'fcrdns',
                        'source' => 'FCrDNS PTR+Forward',
                    ], self::$cacheVerified);
                    self::$stats['verified_first_time']++;
                    self::$stats['dns_performed']++;
                    self::registerVerifiedIp($ip, $botName);
                } elseif ($status === self::STATUS_FAKE) {
                    self::$stats['dns_performed']++;
                    self::saveCachedFake($ip, $botKey, [
                        'reason' => self::$lastUnknownReason ?? 'fcrdns_failed',
                        'source' => 'FCrDNS PTR+Forward',
                    ], self::$cacheFake);
                } else {
                    self::$stats['dns_performed']++;
                    $unknownTtl = self::getUnknownTtl(self::$lastUnknownReason);
                    if ($unknownTtl > 0 && Yii::$app !== null && Yii::$app->has('cache') && Yii::$app->cache !== null) {
                        try {
                            Yii::$app->cache->set($cacheKey, self::STATUS_UNKNOWN, $unknownTtl);
                        } catch (\Throwable) {
                            // Ignore
                        }
                    }
                }
                return $status;
            } finally {
                self::releaseDnsBudgetSlot($slotLease, $ip);
            }
        }

        return self::STATUS_UNKNOWN;
    }

    /**
     * Backward-compatible boolean helper.
     */
    public static function verify(string $ip, string $engine): bool
    {
        return self::check($ip, $engine) === self::STATUS_VERIFIED;
    }

    /**
     * Performs PTR lookup followed by forward DNS verification.
     * Supports testing hook through $dnsResolverCallback.
     *
     * @param string $ip
     * @param array<int, string> $allowedSuffixes
     * @return string
     */
    protected static function performTwoWayDns(string $ip, array $allowedSuffixes): string
    {
        $mockRecords = null;
        if (self::$dnsResolverCallback !== null) {
            try {
                $cbResult = (self::$dnsResolverCallback)($ip, $allowedSuffixes);
                if (is_string($cbResult)) {
                    return $cbResult;
                }
                if (is_array($cbResult)) {
                    $mockRecords = $cbResult;
                }
            } catch (\Throwable) {
                self::$lastUnknownReason = self::REASON_RESOLVER_ERROR;
                self::$stats['unknown_counts'][self::REASON_RESOLVER_ERROR] = (self::$stats['unknown_counts'][self::REASON_RESOLVER_ERROR] ?? 0) + 1;
                return self::STATUS_UNKNOWN;
            }
        }

        // 1. Reverse DNS (IP -> Hostname)
        $hostname = $mockRecords !== null ? ($mockRecords['host'] ?? false) : @gethostbyaddr($ip);

        // If DNS timed out or failed to resolve, it returns false or original IP
        if ($hostname === false || $hostname === $ip) {
            // Check if IP is in private/reserved ranges
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                self::$stats['fcrdns_failed']++;
                return self::STATUS_FAKE;
            }
            self::$lastUnknownReason = self::REASON_PTR_TIMEOUT;
            self::$stats['unknown_counts'][self::REASON_PTR_TIMEOUT] = (self::$stats['unknown_counts'][self::REASON_PTR_TIMEOUT] ?? 0) + 1;
            return self::STATUS_UNKNOWN;
        }

        $hostnameLower = strtolower($hostname);

        // 2. Check if hostname ends with an authorized domain suffix (strict boundary matching)
        $suffixMatched = false;
        foreach ($allowedSuffixes as $suffix) {
            $cleanSuffix = ltrim($suffix, '.');
            if ($hostnameLower === $cleanSuffix || str_ends_with($hostnameLower, '.' . $cleanSuffix)) {
                $suffixMatched = true;
                break;
            }
        }

        if (!$suffixMatched) {
            // Hostname resolved to a domain that does NOT belong to the search engine (e.g. some-vps.com)
            // This is a definitive fake bot!
            self::$stats['fcrdns_failed']++;
            return self::STATUS_FAKE;
        }

        // 3. Forward DNS (Hostname -> IPs) to prevent PTR spoofing
        // Supports both IPv4 (A records) and IPv6 (AAAA records)
        $forwardIps = [];
        if ($mockRecords !== null && isset($mockRecords['ips'])) {
            $forwardIps = (array) $mockRecords['ips'];
        } else {
            if (function_exists('dns_get_record')) {
                $records = @dns_get_record($hostname, DNS_A + DNS_AAAA);
                if (is_array($records)) {
                    foreach ($records as $r) {
                        if (isset($r['ip'])) {
                            $forwardIps[] = (string) $r['ip'];
                        }
                        if (isset($r['ipv6'])) {
                            $forwardIps[] = (string) $r['ipv6'];
                        }
                    }
                }
            }

            if (empty($forwardIps)) {
                $v4 = @gethostbynamel($hostname);
                if (is_array($v4)) {
                    $forwardIps = $v4;
                }
            }
        }

        if (empty($forwardIps)) {
            // Forward DNS failed (temporary DNS lookup failure)
            self::$lastUnknownReason = self::REASON_FORWARD_FAILED;
            self::$stats['unknown_counts'][self::REASON_FORWARD_FAILED] = (self::$stats['unknown_counts'][self::REASON_FORWARD_FAILED] ?? 0) + 1;
            return self::STATUS_UNKNOWN;
        }

        // Normalize IPv4 & IPv6 for comparison
        $packedClientIp = @inet_pton($ip);
        foreach ($forwardIps as $resolved) {
            if ($resolved === $ip) {
                return self::STATUS_VERIFIED;
            }
            $packedResolved = @inet_pton($resolved);
            if ($packedClientIp !== false && $packedResolved !== false && $packedClientIp === $packedResolved) {
                return self::STATUS_VERIFIED;
            }
        }

        // Hostname resolved to other IPs, but not the caller IP -> Spoof detected!
        self::$stats['fcrdns_failed']++;
        return self::STATUS_FAKE;
    }

    /**
     * Atomically acquires permission and a physical concurrency slot to perform an uncached DNS check.
     *
     * Concurrency is enforced via OS-level non-blocking file locks on slot files (antibot_dns_slot_1..3.lock).
     * - The lock stays held for the ENTIRE duration of the DNS check, even if DNS hangs for 15+ seconds.
     * - No premature expiration while a real DNS call is executing.
     * - If a worker process crashes, the operating system kernel automatically frees the file lock.
     * - Implements Single Flight bounded polling when another worker is currently resolving the same IP.
     *
     * @param string $ip Client IP.
     * @param string $engine Engine or crawler key for Single Flight cache lookup.
     * @return array<string, mixed>|array{0: int, 1: resource}|null Array with lease or cached result; null if rate limited / busy.
     */
    public static function acquireDnsBudgetSlot(string $ip, string $engine = ''): ?array
    {
        $dir = self::getStorageDir();
        $budgetFile = self::resolveDnsBudgetFile();
        $lockFile = $budgetFile . '.lock';

        $fp = @fopen($lockFile, 'c+');
        if (!$fp) {
            self::$lastUnknownReason = self::REASON_LOCK_TIMEOUT;
            self::$stats['unknown_counts'][self::REASON_LOCK_TIMEOUT] = (self::$stats['unknown_counts'][self::REASON_LOCK_TIMEOUT] ?? 0) + 1;
            return null;
        }

        // Bounded non-blocking lock with retry: 15 retries with 2ms sleep (total max ~30ms)
        $locked = false;
        for ($retry = 0; $retry < 15; $retry++) {
            if (@flock($fp, LOCK_EX | LOCK_NB)) {
                $locked = true;
                break;
            }
            usleep(2000); // 2ms
        }

        if (!$locked) {
            @fclose($fp);
            self::$lastUnknownReason = self::REASON_LOCK_TIMEOUT;
            self::$stats['unknown_counts'][self::REASON_LOCK_TIMEOUT] = (self::$stats['unknown_counts'][self::REASON_LOCK_TIMEOUT] ?? 0) + 1;
            return null;
        }

        try {
            $data = [];
            if (file_exists($budgetFile)) {
                $raw = @file_get_contents($budgetFile);
                if ($raw !== false && trim($raw) !== '') {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $data = $decoded;
                    }
                }
            }

            $now = time();
            $currentWindow = (int) ($now / 60);

            // 1. Rate Limit Window Check (max 25 / minute)
            $storedWindow = (int) ($data['window'] ?? 0);
            $windowCount = (int) ($data['count'] ?? 0);
            if ($storedWindow !== $currentWindow) {
                $storedWindow = $currentWindow;
                $windowCount = 0;
            }

            if ($windowCount >= self::$maxDnsLookupsPerMinute) {
                self::$lastUnknownReason = self::REASON_RATE_LIMIT;
                self::$stats['unknown_counts'][self::REASON_RATE_LIMIT] = (self::$stats['unknown_counts'][self::REASON_RATE_LIMIT] ?? 0) + 1;
                return null;
            }

            // 2. In-flight single-flight deduplication & stale entry reconciliation:
            // Check each in-flight entry against its corresponding physical OS slot lock.
            // If the slot lock is NOT currently held by an active process, the entry is stale (worker died or finished).
            $inFlight = is_array($data['in_flight'] ?? null) ? $data['in_flight'] : [];
            if (!empty($inFlight)) {
                $cleanedInFlight = [];
                foreach ($inFlight as $flightIp => $slotInfo) {
                    $slotNum = (int) ($slotInfo['slot'] ?? 0);
                    if ($slotNum < 1 || $slotNum > self::$maxConcurrentDnsLookups) {
                        continue;
                    }
                    $testLockFile = $dir . "/antibot_dns_slot_{$slotNum}.lock";
                    $testFp = @fopen($testLockFile, 'c+');
                    $isSlotActivelyHeld = false;
                    if ($testFp) {
                        if (!@flock($testFp, LOCK_EX | LOCK_NB)) {
                            // Slot lock is actively held by a running process!
                            $isSlotActivelyHeld = true;
                        } else {
                            // Lock acquired -> NO active process is using this slot!
                            // Release immediate test lock and discard stale in-flight record.
                            @flock($testFp, LOCK_UN);
                        }
                        @fclose($testFp);
                    }
                    if ($isSlotActivelyHeld) {
                        $cleanedInFlight[$flightIp] = $slotInfo;
                    }
                }
                $inFlight = $cleanedInFlight;
            }

            // Single Flight handling: another worker is actively resolving this exact IP right now!
            if (isset($inFlight[$ip])) {
                // Immediately release budget lock so the resolving worker can update in-flight state without contention
                @flock($fp, LOCK_UN);
                @fclose($fp);
                $fp = null;

                // Bounded wait: poll cache up to 6 times with 25ms delay (max 150ms)
                for ($wait = 0; $wait < 6; $wait++) {
                    usleep(25000); // 25ms
                    if ($engine !== '') {
                        $cached = self::getCachedVerification($ip, $engine);
                        if ($cached !== null) {
                            self::$stats['prevented_duplicates']++;
                            self::$stats['cache_hits']++;
                            self::$stats['cache_hits_verified']++;
                            return ['cached' => self::STATUS_VERIFIED, 'data' => $cached];
                        }
                        $cachedFake = self::getCachedFake($ip, $engine);
                        if ($cachedFake !== null) {
                            self::$stats['prevented_duplicates']++;
                            self::$stats['cache_hits']++;
                            self::$stats['cache_hits_fake']++;
                            return ['cached' => self::STATUS_FAKE, 'data' => $cachedFake];
                        }
                    }
                    // Also check general cache key if engine given
                    if (Yii::$app !== null && Yii::$app->has('cache') && Yii::$app->cache !== null && $engine !== '') {
                        $c = Yii::$app->cache->get(self::buildCacheKey($ip, $engine));
                        if (is_string($c) && in_array($c, [self::STATUS_VERIFIED, self::STATUS_FAKE], true)) {
                            self::$stats['prevented_duplicates']++;
                            self::$stats['cache_hits']++;
                            if ($c === self::STATUS_VERIFIED) {
                                self::$stats['cache_hits_verified']++;
                            } else {
                                self::$stats['cache_hits_fake']++;
                            }
                            return ['cached' => $c];
                        }
                    }
                }

                // If wait timed out without cached result, safely return UNKNOWN with diagnostic reason
                self::$lastUnknownReason = self::REASON_LOOKUP_IN_PROGRESS;
                self::$stats['unknown_counts'][self::REASON_LOOKUP_IN_PROGRESS] = (self::$stats['unknown_counts'][self::REASON_LOOKUP_IN_PROGRESS] ?? 0) + 1;
                return null;
            }

            // 3. Try to acquire one of the physical OS concurrency slots (1..$maxConcurrentDnsLookups)
            $acquiredSlot = null;
            $acquiredFp = null;

            for ($slot = 1; $slot <= self::$maxConcurrentDnsLookups; $slot++) {
                $slotLockFile = $dir . "/antibot_dns_slot_{$slot}.lock";
                $slotFp = @fopen($slotLockFile, 'c+');
                if ($slotFp && @flock($slotFp, LOCK_EX | LOCK_NB)) {
                    $acquiredSlot = $slot;
                    $acquiredFp = $slotFp;
                    break;
                }
                if ($slotFp) {
                    @fclose($slotFp);
                }
            }

            if ($acquiredSlot === null || $acquiredFp === null) {
                self::$lastUnknownReason = self::REASON_SLOTS_BUSY;
                self::$stats['unknown_counts'][self::REASON_SLOTS_BUSY] = (self::$stats['unknown_counts'][self::REASON_SLOTS_BUSY] ?? 0) + 1;
                return null; // All concurrency slots are currently busy!
            }

            // Successfully claimed slot: register IP in in-flight list and increment budget
            $inFlight[$ip] = [
                'slot' => $acquiredSlot,
                'ts' => microtime(true),
                'pid' => getmypid(),
            ];
            $windowCount++;

            $data = [
                'window' => $storedWindow,
                'count' => $windowCount,
                'in_flight' => $inFlight,
            ];

            @file_put_contents($budgetFile, json_encode($data, JSON_UNESCAPED_SLASHES));

            return [
                'slot' => $acquiredSlot,
                'fp' => $acquiredFp,
                0 => $acquiredSlot,
                1 => $acquiredFp,
            ];
        } finally {
            if ($fp !== null) {
                @flock($fp, LOCK_UN);
                @fclose($fp);
            }
        }
    }

    /**
     * Releases physical concurrency slot and clears in-flight IP deduplication.
     *
     * @param array{slot: int, fp: resource}|array{0: int, 1: resource}|string $slotLease
     * @param string|null $ip
     */
    public static function releaseDnsBudgetSlot($slotLease, ?string $ip = null): void
    {
        $slotNumber = null;
        $slotFp = null;

        if (is_array($slotLease)) {
            $slotNumber = isset($slotLease['slot']) ? (int) $slotLease['slot'] : (int) ($slotLease[0] ?? 0);
            $slotFp = $slotLease['fp'] ?? ($slotLease[1] ?? null);
        }

        // Release physical OS file lock on the concurrency slot
        if (is_resource($slotFp)) {
            @flock($slotFp, LOCK_UN);
            @fclose($slotFp);
        }

        if ($ip === null || $ip === '') {
            return;
        }

        $budgetFile = self::resolveDnsBudgetFile();
        $lockFile = $budgetFile . '.lock';
        $fp = @fopen($lockFile, 'c+');
        if (!$fp) {
            return;
        }

        $locked = false;
        for ($retry = 0; $retry < 15; $retry++) {
            if (@flock($fp, LOCK_EX | LOCK_NB)) {
                $locked = true;
                break;
            }
            usleep(2000);
        }

        if (!$locked) {
            @fclose($fp);
            return;
        }

        try {
            if (file_exists($budgetFile)) {
                $raw = @file_get_contents($budgetFile);
                if ($raw !== false && trim($raw) !== '') {
                    $data = json_decode($raw, true);
                    if (is_array($data) && isset($data['in_flight'][$ip])) {
                        unset($data['in_flight'][$ip]);
                        @file_put_contents($budgetFile, json_encode($data, JSON_UNESCAPED_SLASHES));
                    }
                }
            }
        } finally {
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }
    }

    /**
     * Backward-compatible budget check helper.
     */
    public static function acquireDnsBudget(?string $ip = null): bool
    {
        $slotLease = self::acquireDnsBudgetSlot($ip ?? '0.0.0.0');
        if ($slotLease !== null) {
            self::releaseDnsBudgetSlot($slotLease, $ip ?? '0.0.0.0');
            return true;
        }
        return false;
    }

    /**
     * Resets DNS budget state, slot locks, and verified bot persistent store (for testing and diagnostics).
     */
    public static function resetBudget(): void
    {
        $dir = self::getStorageDir();
        $budgetFile = self::resolveDnsBudgetFile();
        $lockFile = $budgetFile . '.lock';

        if (file_exists($budgetFile)) {
            @unlink($budgetFile);
        }
        if (file_exists($lockFile)) {
            @unlink($lockFile);
        }

        for ($slot = 1; $slot <= 10; $slot++) {
            $sf = $dir . "/antibot_dns_slot_{$slot}.lock";
            if (file_exists($sf)) {
                @unlink($sf);
            }
        }

        self::resetVerifiedBotsStore();
    }
}
