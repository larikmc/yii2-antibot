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
     * Snapshot date of included official search engine CIDR subnets.
     */
    public const CIDR_SNAPSHOT_DATE = '2026-10-30';

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
        '66.249.64.0/19',
        '64.233.160.0/19',
        '72.14.192.0/18',
        '209.85.128.0/17',
        '216.239.32.0/19',
        '34.64.0.0/10',
        '34.128.0.0/10',
        '35.184.0.0/13',
        '35.192.0.0/12',
        '35.208.0.0/12',
        '35.224.0.0/12',
        '35.240.0.0/13',
        '2001:4860:4801::/48',
        '2001:4860:4802::/48',
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
        '2603:1030::/32',
        '2620:1ec:40::/42',
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

            // 2. In-flight single-flight deduplication:
            // Check if this exact IP is already resolving in an active slot
            $inFlight = is_array($data['in_flight'] ?? null) ? $data['in_flight'] : [];
            if (isset($inFlight[$ip])) {
                return null; // Another worker is resolving this exact IP right now!
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
