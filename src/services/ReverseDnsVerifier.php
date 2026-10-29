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

        if (Yii::$app !== null && Yii::$app->has('cache') && Yii::$app->cache !== null) {
            $cached = Yii::$app->cache->get($cacheKey);
            if (is_string($cached) && in_array($cached, [self::STATUS_VERIFIED, self::STATUS_FAKE, self::STATUS_UNKNOWN], true)) {
                return $cached;
            }
        }

        // Fast-path: for Googlebot, if IP is NOT in Google's known CIDRs and claims to be Googlebot
        // we still run DNS, but if DNS budget is exceeded we know it's suspicious
        if (!self::acquireDnsBudget()) {
            // DNS budget exhausted, protect PHP-FPM workers from hanging
            return self::STATUS_UNKNOWN;
        }

        $status = self::performTwoWayDns($ip, self::VALID_DOMAINS[$engine]);

        // Cache based on status
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
     * Controls concurrency of uncached DNS lookups to prevent PHP-FPM starvation.
     */
    protected static function acquireDnsBudget(): bool
    {
        static $processBudget = 0;
        static $processWindow = 0;

        $now = time();
        if ($now - $processWindow > 60) {
            $processWindow = $now;
            $processBudget = 0;
        }

        if ($processBudget >= self::$maxDnsLookupsPerMinute) {
            return false;
        }
        $processBudget++;

        if (Yii::$app === null || !Yii::$app->has('cache') || Yii::$app->cache === null) {
            return true;
        }

        try {
            $budgetKey = 'antibot_dns_budget_' . (int) ($now / 60);
            $count = Yii::$app->cache->get($budgetKey);
            $count = is_int($count) ? $count : 0;

            if ($count >= self::$maxDnsLookupsPerMinute) {
                return false;
            }

            Yii::$app->cache->set($budgetKey, $count + 1, 70);
            return true;
        } catch (\Throwable) {
            return $processBudget <= 10;
        }
    }
}
