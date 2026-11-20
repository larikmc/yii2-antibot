<?php

declare(strict_types=1);

namespace larikmc\antibot\services;

/**
 * High-performance IP and CIDR subnet matcher.
 *
 * Supports single IPv4/IPv6 addresses and CIDR notation (e.g. 192.168.1.0/24).
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class IpMatcher
{
    /**
     * Checks if a given IP matches any of the entries in an IP list.
     *
     * @param string $ip Client IP address.
     * @param array<int, string> $ipList List of IPs or CIDR subnets.
     * @return bool True if matched, false otherwise.
     */
    public static function match(string $ip, array $ipList): bool
    {
        $ip = trim($ip);
        if ($ip === '') {
            return false;
        }

        foreach ($ipList as $entry) {
            $entry = trim($entry);
            if ($entry === '' || str_starts_with($entry, '#')) {
                continue;
            }

            if (str_contains($entry, '/')) {
                if (self::matchCidr($ip, $entry)) {
                    return true;
                }
            } elseif ($ip === $entry) {
                return true;
            }
        }

        return false;
    }

    /**
     * Matches an IP address against a CIDR block (IPv4 or IPv6).
     *
     * @param string $ip
     * @param string $cidr e.g. "192.168.0.0/16" or "2001:db8::/32"
     * @return bool
     */
    public static function matchCidr(string $ip, string $cidr): bool
    {
        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2) {
            return $ip === $cidr;
        }

        [$subnet, $mask] = $parts;
        $mask = (int) $mask;

        // IPv4 check
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if (!filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return false;
            }
            if ($mask < 0 || $mask > 32) {
                return false;
            }

            $ipLong = ip2long($ip);
            $subnetLong = ip2long($subnet);

            if ($mask === 0) {
                return true;
            }

            $netmask = ~((1 << (32 - $mask)) - 1);

            return ($ipLong & $netmask) === ($subnetLong & $netmask);
        }

        // IPv6 check
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            if (!filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                return false;
            }
            if ($mask < 0 || $mask > 128) {
                return false;
            }

            $ipPacked = inet_pton($ip);
            $subnetPacked = inet_pton($subnet);

            if ($ipPacked === false || $subnetPacked === false) {
                return false;
            }

            $bytes = (int) ($mask / 8);
            $bits = $mask % 8;

            if ($bytes > 0 && substr($ipPacked, 0, $bytes) !== substr($subnetPacked, 0, $bytes)) {
                return false;
            }

            if ($bits > 0) {
                $bitmask = chr(0xFF << (8 - $bits));
                if ((ord($ipPacked[$bytes]) & ord($bitmask)) !== (ord($subnetPacked[$bytes]) & ord($bitmask))) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Calculates the /24 subnet for a given IPv4 address.
     */
    public static function getSubnet24(string $ip): ?string
    {
        $ip = trim($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            if ($long !== false) {
                return long2ip($long & ~255) . '/24';
            }
        }
        return null;
    }

    /**
     * Validates whether a string is a valid IPv4, IPv6, or CIDR notation.
     */
    public static function isValidIpOrCidr(string $entry): bool
    {
        $entry = trim($entry);
        if ($entry === '') {
            return false;
        }

        if (str_contains($entry, '/')) {
            $parts = explode('/', $entry, 2);
            if (count($parts) !== 2) {
                return false;
            }
            [$ip, $mask] = $parts;
            if (!is_numeric($mask)) {
                return false;
            }
            $mask = (int) $mask;
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $mask >= 0 && $mask <= 32;
            }
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                return $mask >= 0 && $mask <= 128;
            }
            return false;
        }

        return (bool) filter_var($entry, FILTER_VALIDATE_IP);
    }

    /**
     * Checks if two CIDR blocks or IP addresses intersect (overlap).
     *
     * @param string $cidrA IP or CIDR block (e.g. "192.168.1.0/24" or "10.0.0.1")
     * @param string $cidrB IP or CIDR block (e.g. "192.168.0.0/16" or "10.0.0.1")
     * @return bool True if they overlap or share any IP addresses.
     */
    public static function cidrsIntersect(string $cidrA, string $cidrB): bool
    {
        $cidrA = trim($cidrA);
        $cidrB = trim($cidrB);

        if ($cidrA === '' || $cidrB === '') {
            return false;
        }

        $baseA = str_contains($cidrA, '/') ? explode('/', $cidrA, 2)[0] : $cidrA;
        $baseB = str_contains($cidrB, '/') ? explode('/', $cidrB, 2)[0] : $cidrB;

        // Two dyadic CIDR blocks intersect if and only if one's network address is inside the other
        if (self::matchCidr($baseA, $cidrB)) {
            return true;
        }
        if (self::matchCidr($baseB, $cidrA)) {
            return true;
        }

        return false;
    }

    /**
     * Checks if a candidate IP or CIDR subnet intersects with any protected search or advertising infrastructure.
     *
     * @param string $entry Candidate IP or CIDR subnet to be tested for blocking.
     * @return array{intersects: bool, system: string|null, range: string|null}
     */
    public static function checkProtectedIntersection(string $entry): array
    {
        $entry = trim($entry);
        if ($entry === '') {
            return ['intersects' => false, 'system' => null, 'range' => null];
        }

        // Single IP FCrDNS check for Yandex
        if (!str_contains($entry, '/') && filter_var($entry, FILTER_VALIDATE_IP)) {
            if (\larikmc\antibot\services\ReverseDnsVerifier::check($entry, 'yandex') === \larikmc\antibot\services\ReverseDnsVerifier::STATUS_VERIFIED) {
                return [
                    'intersects' => true,
                    'system' => 'Yandex Search / Direct (FCrDNS)',
                    'range' => $entry,
                ];
            }
        }

        // Check against known confirmed verified crawler IPs (from FCrDNS logs & runtime cache)
        $knownVerified = \larikmc\antibot\services\ReverseDnsVerifier::getKnownVerifiedBotIps();
        foreach ($knownVerified as $verifiedIp => $botName) {
            if (self::cidrsIntersect($entry, $verifiedIp)) {
                return [
                    'intersects' => true,
                    'system' => "Verified Crawler ({$botName})",
                    'range' => $verifiedIp,
                ];
            }
        }

        // Registry of protected CIDR ranges and systems
        $systems = [
            'Googlebot (SEO Search)' => \larikmc\antibot\services\ReverseDnsVerifier::GOOGLEBOT_CIDRS,
            'Google Ads / Special Crawlers' => \larikmc\antibot\data\GoogleSpecialRanges::getCidrs(),
            'Microsoft Bingbot' => \larikmc\antibot\services\ReverseDnsVerifier::BINGBOT_CIDRS,
            'Criteo Advertising' => \larikmc\antibot\services\BotCatalog::CRITEO_CIDRS,
            'Quantcast Measurement' => \larikmc\antibot\services\BotCatalog::QUANTCAST_IPS,
            'Meta / Facebook Preview' => \larikmc\antibot\services\BotCatalog::META_CIDRS,
            'Integral Ad Science (IAS)' => \larikmc\antibot\data\IasRanges::getIps(),
            'OpenAI AdsBot' => \larikmc\antibot\services\BotCatalog::OPENAI_ADS_CIDRS,
            'Telegram Link Preview' => \larikmc\antibot\services\BotCatalog::TELEGRAM_CIDRS,
            'VKontakte Link Preview' => \larikmc\antibot\services\BotCatalog::VK_CIDRS,
        ];

        foreach ($systems as $systemName => $ranges) {
            foreach ($ranges as $protectedRange) {
                if (self::cidrsIntersect($entry, $protectedRange)) {
                    return [
                        'intersects' => true,
                        'system' => $systemName,
                        'range' => $protectedRange,
                    ];
                }
            }
        }

        return ['intersects' => false, 'system' => null, 'range' => null];
    }
}
