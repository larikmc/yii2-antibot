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
     * Used for fast-path validation.
     */
    public const GOOGLEBOT_CIDRS = [
        '66.249.64.0/19',
        '64.233.160.0/19',
        '72.14.192.0/18',
        '209.85.128.0/17',
        '216.239.32.0/19',
        '2001:4860:4801::/48',
    ];

    /**
     * Known official subnets for Bingbot (published in Microsoft's official bingbot JSON).
     */
    public const BINGBOT_CIDRS = [
        '157.55.39.0/24',
        '207.46.13.0/24',
        '40.77.167.0/24',
        '13.66.139.0/24',
        '13.66.144.0/24',
        '52.167.144.0/24',
        '20.36.108.0/24',
        '20.40.0.0/16',
        '20.190.0.0/16',
        '40.76.0.0/14',
        '157.55.0.0/16',
        '207.46.0.0/16',
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
     */
    public static int $maxConcurrentDnsLookups = 3;

    /**
     * Resolves the full path to the shared DNS budget file.
     */
    public static function resolveDnsBudgetFile(): string
    {
        if (Yii::$app !== null && Yii::getAlias('@common', false) !== false) {
            $sharedDir = Yii::getAlias('@common/runtime');
            if (!is_dir($sharedDir)) {
                @mkdir($sharedDir, 0775, true);
            }
            return $sharedDir . '/antibot_dns_budget.json';
        }

        if (Yii::$app !== null && Yii::getAlias('@runtime', false) !== false) {
            $dir = Yii::getAlias('@runtime');
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            return $dir . '/antibot_dns_budget.json';
        }

        return sys_get_temp_dir() . '/antibot_dns_budget.json';
    }

    /**
     * Verifies whether an IP belongs to a legitimate search engine.
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

        $cacheKey = 'antibot_rdns_v2_' . md5($ip . '_' . $engine);

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
            $inOfficialRange = IpMatcher::match($ip, $knownCidrs);
            // If outside known official subnets, DNS is required. If budget is constrained,
            // we will safely yield UNKNOWN rather than allowing workers to starve.
        }

        // 3. Atomically acquire DNS budget & concurrency slot
        $slotToken = self::acquireDnsBudgetSlot($ip);
        if ($slotToken === null) {
            // DNS budget exhausted, rate limit hit, or max concurrent lookups reached:
            // Safely return UNKNOWN without blocking PHP-FPM workers!
            return self::STATUS_UNKNOWN;
        }

        try {
            $status = self::performTwoWayDns($ip, self::VALID_DOMAINS[$engine]);
        } finally {
            self::releaseDnsBudgetSlot($slotToken);
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
     *
     * @param string $ip
     * @param array<int, string> $allowedSuffixes
     * @return string
     */
    protected static function performTwoWayDns(string $ip, array $allowedSuffixes): string
    {
        // 1. Reverse DNS (IP -> Hostname)
        $hostname = @gethostbyaddr($ip);

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
     * Atomically acquires permission to perform an uncached DNS check.
     *
     * Enforces two separate limits:
     * 1. Rate limit: Max lookups per 60-second window across all workers ($maxDnsLookupsPerMinute).
     * 2. Concurrency limit: Max simultaneous in-flight DNS lookups at any given moment ($maxConcurrentDnsLookups).
     * 3. In-flight single-flight deduplication: Prevents multiple concurrent checks for the exact same IP.
     *
     * Uses bounded non-blocking file locking (LOCK_EX | LOCK_NB) with brief retry to guarantee workers never hang.
     *
     * @param string $ip
     * @return string|null Slot token if acquired; null if budget/concurrency exceeded or locked.
     */
    public static function acquireDnsBudgetSlot(string $ip): ?string
    {
        $budgetFile = self::resolveDnsBudgetFile();
        $lockFile = $budgetFile . '.lock';
        $dir = dirname($budgetFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $fp = @fopen($lockFile, 'c+');
        if (!$fp) {
            return null;
        }

        // Bounded non-blocking lock with brief retry (max 3 retries, total wait < 6ms)
        $locked = false;
        for ($retry = 0; $retry < 3; $retry++) {
            if (@flock($fp, LOCK_EX | LOCK_NB)) {
                $locked = true;
                break;
            }
            usleep(2000); // 2ms
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
            $nowMicro = microtime(true);
            $currentWindow = (int) ($now / 60);

            // 1. Rate Limit Window Check
            $storedWindow = (int) ($data['window'] ?? 0);
            $windowCount = (int) ($data['count'] ?? 0);
            if ($storedWindow !== $currentWindow) {
                $storedWindow = $currentWindow;
                $windowCount = 0;
            }

            if ($windowCount >= self::$maxDnsLookupsPerMinute) {
                return null; // Rate budget exhausted
            }

            // 2. Concurrency Limit & In-flight Deduplication
            $activeLeases = is_array($data['active'] ?? null) ? $data['active'] : [];

            // Purge leases older than 5.0 seconds (dead worker / timeout protection)
            foreach ($activeLeases as $k => $lease) {
                $leaseTs = (float) ($lease['ts'] ?? 0);
                if ($nowMicro - $leaseTs > 5.0) {
                    unset($activeLeases[$k]);
                }
            }

            // In-flight single-flight deduplication: check if this exact IP is already resolving!
            foreach ($activeLeases as $lease) {
                if (($lease['ip'] ?? '') === $ip) {
                    return null; // Another worker is resolving this exact IP right now!
                }
            }

            // Check concurrency limit
            if (count($activeLeases) >= self::$maxConcurrentDnsLookups) {
                return null; // Max concurrent lookups reached!
            }

            // 3. Claim slot
            $token = bin2hex(random_bytes(8));
            $activeLeases[$token] = [
                'ip' => $ip,
                'ts' => $nowMicro,
                'pid' => getmypid(),
            ];
            $windowCount++;

            $data = [
                'window' => $storedWindow,
                'count' => $windowCount,
                'active' => $activeLeases,
            ];

            @file_put_contents($budgetFile, json_encode($data, JSON_UNESCAPED_SLASHES));
            return $token;
        } finally {
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }
    }

    /**
     * Atomically releases a claimed DNS concurrency slot.
     *
     * @param string $token
     */
    public static function releaseDnsBudgetSlot(string $token): void
    {
        if ($token === '') {
            return;
        }

        $budgetFile = self::resolveDnsBudgetFile();
        $lockFile = $budgetFile . '.lock';
        $fp = @fopen($lockFile, 'c+');
        if (!$fp) {
            return;
        }

        $locked = false;
        for ($retry = 0; $retry < 3; $retry++) {
            if (@flock($fp, LOCK_EX | LOCK_NB)) {
                $locked = true;
                break;
            }
            usleep(1500);
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
                    if (is_array($data) && isset($data['active'][$token])) {
                        unset($data['active'][$token]);
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
        $token = self::acquireDnsBudgetSlot($ip ?? '0.0.0.0');
        if ($token !== null) {
            self::releaseDnsBudgetSlot($token);
            return true;
        }
        return false;
    }

    /**
     * Resets DNS budget state (for testing and diagnostics).
     */
    public static function resetBudget(): void
    {
        $budgetFile = self::resolveDnsBudgetFile();
        $lockFile = $budgetFile . '.lock';
        if (file_exists($budgetFile)) {
            @unlink($budgetFile);
        }
        if (file_exists($lockFile)) {
            @unlink($lockFile);
        }
    }
}
