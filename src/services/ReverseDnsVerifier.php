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
     * Legitimate hostname domain suffixes for each search engine.
     */
    public const VALID_DOMAINS = [
        'yandex' => ['.yandex.ru', '.yandex.net', '.yandex.com'],
        'google' => ['.googlebot.com', '.google.com'],
        'bing' => ['.search.msn.com'],
        'mailru' => ['.mail.ru'],
        'apple' => ['.applebot.apple.com'],
        'baidu' => ['.baidu.com', '.baidu.jp'],
        'duckduckgo' => ['.duckduckgo.com'],
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
     */
    public static int $cacheVerified = 86400; // 24 hours
    public static int $cacheFake = 900;       // 15 minutes
    public static int $cacheUnknown = 300;    // 5 minutes

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
     * Resolves the full path to the shared storage directory.
     */
    public static function getStorageDir(): string
    {
        if (Yii::$app !== null && Yii::getAlias('@common', false) !== false) {
            $sharedDir = Yii::getAlias('@common/runtime');
            if (!is_dir($sharedDir)) {
                @mkdir($sharedDir, 0775, true);
            }
            return $sharedDir;
        }

        if (Yii::$app !== null && Yii::getAlias('@runtime', false) !== false) {
            $dir = Yii::getAlias('@runtime');
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            return $dir;
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
     * Verifies whether an IP belongs to a legitimate search engine.
     *
     * Order of evaluation:
     * 1. Check cached verification result.
     * 2. Official CIDR pre-filter (Googlebot & Bingbot): If in official snapshot, verify instantly without DNS.
     * 3. Acquire atomic rate budget and non-blocking concurrency slot (1..3).
     * 4. Perform PTR + A/AAAA lookup (with strict domain boundary matching).
     * 5. Cache result and release slot.
     *
     * @param string $ip Client IP address.
     * @param string $engine Search engine key ('yandex', 'google', 'bing', etc.).
     * @return string One of STATUS_VERIFIED, STATUS_FAKE, or STATUS_UNKNOWN.
     */
    public static function check(string $ip, string $engine): string
    {
        $engine = strtolower($engine);
        if (!isset(self::VALID_DOMAINS[$engine])) {
            return self::STATUS_UNKNOWN;
        }

        $cacheKey = self::buildCacheKey($ip, $engine);

        // 1. Check cached verification
        if (Yii::$app !== null && Yii::$app->has('cache') && Yii::$app->cache !== null) {
            $cached = Yii::$app->cache->get($cacheKey);
            if (is_string($cached) && in_array($cached, [self::STATUS_VERIFIED, self::STATUS_FAKE, self::STATUS_UNKNOWN], true)) {
                return $cached;
            }
        }

        // 2. Pre-filter official CIDR ranges where published (Google & Bing)
        if ($engine === 'google' || $engine === 'bing') {
            $knownCidrs = $engine === 'google' ? self::GOOGLEBOT_CIDRS : self::BINGBOT_CIDRS;
            if (IpMatcher::match($ip, $knownCidrs)) {
                // Official CIDR match confirms authentic search engine infrastructure without slow DNS!
                if (Yii::$app !== null && Yii::$app->has('cache') && Yii::$app->cache !== null) {
                    Yii::$app->cache->set($cacheKey, self::STATUS_VERIFIED, self::$cacheVerified);
                }
                return self::STATUS_VERIFIED;
            }
            // If outside known official snapshot, do NOT mark FAKE (snapshot may be incomplete or outdated).
            // Safely proceed to two-way DNS verification below.
        }

        // 3. Atomically acquire DNS budget & physical concurrency slot
        $slotLease = self::acquireDnsBudgetSlot($ip);
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
        if (Yii::$app !== null && Yii::$app->has('cache') && Yii::$app->cache !== null) {
            $ttl = match ($status) {
                self::STATUS_VERIFIED => self::$cacheVerified,
                self::STATUS_FAKE => self::$cacheFake,
                default => self::$cacheUnknown,
            };
            Yii::$app->cache->set($cacheKey, $status, $ttl);
        }

        return $status;
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
                return self::STATUS_UNKNOWN;
            }
        }

        // 1. Reverse DNS (IP -> Hostname)
        $hostname = $mockRecords !== null ? ($mockRecords['host'] ?? false) : @gethostbyaddr($ip);

        // If DNS timed out or failed to resolve, it returns false or original IP
        if ($hostname === false || $hostname === $ip) {
            // Check if IP is in private/reserved ranges
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return self::STATUS_FAKE;
            }
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
        return self::STATUS_FAKE;
    }

    /**
     * Atomically acquires permission and a physical concurrency slot to perform an uncached DNS check.
     *
     * Concurrency is enforced via OS-level non-blocking file locks on slot files (antibot_dns_slot_1..3.lock).
     * - The lock stays held for the ENTIRE duration of the DNS check, even if DNS hangs for 15+ seconds.
     * - No premature expiration while a real DNS call is executing.
     * - If a worker process crashes, the operating system kernel automatically frees the file lock.
     *
     * @param string $ip
     * @return array{0: int, 1: resource}|null Array with [slotNumber, fileHandle] if acquired; null otherwise.
     */
    public static function acquireDnsBudgetSlot(string $ip): ?array
    {
        $dir = self::getStorageDir();
        $budgetFile = self::resolveDnsBudgetFile();
        $lockFile = $budgetFile . '.lock';

        $fp = @fopen($lockFile, 'c+');
        if (!$fp) {
            return null;
        }

        // Bounded non-blocking lock with brief retry (max 4 retries, total wait < 5ms)
        $locked = false;
        for ($retry = 0; $retry < 4; $retry++) {
            if (@flock($fp, LOCK_EX | LOCK_NB)) {
                $locked = true;
                break;
            }
            usleep(1000); // 1ms
        }

        if (!$locked) {
            @fclose($fp);
            return null; // Cannot acquire lock quickly -> return UNKNOWN, never hang worker!
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
                return null; // Rate budget exhausted
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

            if (isset($inFlight[$ip])) {
                return null; // Another worker is actively resolving this exact IP right now!
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
            @flock($fp, LOCK_UN);
            @fclose($fp);
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
        for ($retry = 0; $retry < 4; $retry++) {
            if (@flock($fp, LOCK_EX | LOCK_NB)) {
                $locked = true;
                break;
            }
            usleep(1000);
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
     * Resets DNS budget state and cleans slot locks (for testing and diagnostics).
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
    }
}
