<?php

declare(strict_types=1);

namespace larikmc\antibot\services;

use Yii;

/**
 * Reverse DNS (PTR) + Forward DNS verification for legitimate search engine bots.
 *
 * Prevents bots from impersonating Googlebot, YandexBot, Bingbot etc.
 * Protects SEO rankings by ensuring real search crawlers are NEVER blocked,
 * while instantly detecting and eliminating spoofed search bots.
 *
 * Verification results are cached for 24 hours to ensure 0 ms latency on subsequent requests.
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class ReverseDnsVerifier
{
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
     * Cache duration in seconds (24 hours).
     */
    public static int $cacheDuration = 86400;

    /**
     * Verifies whether an IP belongs to a legitimate search engine.
     *
     * @param string $ip Client IP address.
     * @param string $engine Search engine key ('yandex', 'google', 'bing', etc.).
     * @return bool True if authentic search engine, false if fake/spoofed.
     */
    public static function verify(string $ip, string $engine): bool
    {
        $engine = strtolower($engine);
        if (!isset(self::VALID_DOMAINS[$engine])) {
            return false;
        }

        $cacheKey = 'antibot_rdns_' . md5($ip . '_' . $engine);

        if (Yii::$app !== null && Yii::$app->has('cache') && Yii::$app->cache !== null) {
            $cached = Yii::$app->cache->get($cacheKey);
            if ($cached !== false && is_bool($cached)) {
                return $cached;
            }
        }

        $isAuthentic = self::performDnsCheck($ip, self::VALID_DOMAINS[$engine]);

        if (Yii::$app !== null && Yii::$app->has('cache') && Yii::$app->cache !== null) {
            Yii::$app->cache->set($cacheKey, $isAuthentic, self::$cacheDuration);
        }

        return $isAuthentic;
    }

    /**
     * Performs PTR lookup followed by forward DNS verification.
     *
     * @param string $ip
     * @param array<int, string> $allowedSuffixes
     * @return bool
     */
    protected static function performDnsCheck(string $ip, array $allowedSuffixes): bool
    {
        // 1. Reverse DNS (IP -> Hostname)
        $hostname = @gethostbyaddr($ip);
        if ($hostname === false || $hostname === $ip) {
            return false;
        }

        $hostnameLower = strtolower($hostname);

        // 2. Check if hostname ends with an authorized domain suffix
        $suffixMatched = false;
        foreach ($allowedSuffixes as $suffix) {
            if (str_ends_with($hostnameLower, $suffix)) {
                $suffixMatched = true;
                break;
            }
        }

        if (!$suffixMatched) {
            return false;
        }

        // 3. Forward DNS (Hostname -> IPs) to prevent DNS spoofing
        $resolvedIps = @gethostbynamel($hostname);
        if (!is_array($resolvedIps) || empty($resolvedIps)) {
            return false;
        }

        return in_array($ip, $resolvedIps, true);
    }
}
