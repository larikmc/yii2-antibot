<?php

declare(strict_types=1);

namespace larikmc\antibot\services\behavior;

use larikmc\antibot\services\BotDetector;

/**
 * Client Hints & HTTP Header Consistency Anomaly Detector.
 *
 * Inspects modern browser client hints and standard request headers:
 * - Sec-Ch-Ua, Sec-Ch-Ua-Platform, Sec-Ch-Ua-Mobile vs User-Agent
 * - Accept-Language presence and formatting for purported desktop browsers
 * - Accept-Encoding (gzip, deflate, br) presence
 *
 * Identifies headless scrapers, cURL/python spoofing Chrome User-Agents
 * without matching browser headers.
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class HeaderAnalyzer
{
    /**
     * Analyzes HTTP headers against User-Agent for inconsistencies.
     *
     * @param string $userAgent
     * @param array<string, mixed> $headers Array of headers (case-insensitive keys).
     * @return array{
     *     isAnomaly: bool,
     *     reason: ?string,
     *     score: int,
     *     details: array<string, mixed>
     * }
     */
    public static function analyze(string $userAgent, array $headers): array
    {
        $defaultResult = [
            'isAnomaly' => false,
            'reason' => null,
            'score' => 0,
            'details' => [],
        ];

        $ua = trim($userAgent);

        // 1. Missing or empty UA
        if ($ua === '') {
            return [
                'isAnomaly' => true,
                'reason' => 'Empty or missing User-Agent header',
                'score' => 60,
                'details' => ['issue' => 'empty_ua'],
            ];
        }

        // 2. Suspiciously short UA
        if (strlen($ua) < 8) {
            return [
                'isAnomaly' => true,
                'reason' => 'Suspiciously short User-Agent string',
                'score' => 50,
                'details' => ['issue' => 'short_ua', 'length' => strlen($ua)],
            ];
        }

        // Search engines (Google, Yandex, etc.) have distinct header patterns; do not flag them
        if (BotDetector::detectSearchBot($ua) !== null) {
            return $defaultResult;
        }

        // Normalize header keys to lowercase for robust lookup
        $normalizedHeaders = [];
        foreach ($headers as $key => $val) {
            $lowerKey = strtolower((string) $key);
            $strVal = is_array($val) ? (string) reset($val) : (string) $val;
            $normalizedHeaders[$lowerKey] = trim($strVal);
        }

        // Also check $_SERVER superglobal for fast-CGI headers if not present in request array
        $headerGet = static function (string $name) use ($normalizedHeaders): ?string {
            $lower = strtolower($name);
            if (isset($normalizedHeaders[$lower]) && $normalizedHeaders[$lower] !== '') {
                return $normalizedHeaders[$lower];
            }
            $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
            if (!empty($_SERVER[$serverKey])) {
                return trim((string) $_SERVER[$serverKey]);
            }
            return null;
        };

        $secChUaPlatform = $headerGet('sec-ch-ua-platform');
        $secChUaMobile = $headerGet('sec-ch-ua-mobile');
        $acceptLang = $headerGet('accept-language');
        $acceptEncoding = $headerGet('accept-encoding');

        // Clean quotes from sec-ch-ua-platform (e.g. '"Windows"' -> 'Windows')
        if ($secChUaPlatform !== null) {
            $secChUaPlatform = trim($secChUaPlatform, '"\'');
        }

        // 3. Platform Mismatch Check (Sec-Ch-Ua-Platform vs UA)
        if ($secChUaPlatform !== null && $secChUaPlatform !== '') {
            $cleanSecPlatform = strtolower($secChUaPlatform);

            $uaIsWindows = (bool) preg_match('/windows\s+nt/i', $ua);
            $uaIsAndroid = (bool) preg_match('/android/i', $ua);
            $uaIsMac = (bool) preg_match('/macintosh|mac\s+os\s+x/i', $ua) && !$uaIsAndroid;
            $uaIsLinux = (bool) preg_match('/linux/i', $ua) && !$uaIsAndroid;
            $uaIsIos = (bool) preg_match('/iphone|ipad|ipod/i', $ua);

            if ($uaIsWindows && !str_contains($cleanSecPlatform, 'window')) {
                return [
                    'isAnomaly' => true,
                    'reason' => sprintf('Platform inconsistency: UA specifies Windows, but Sec-Ch-Ua-Platform is "%s"', $secChUaPlatform),
                    'score' => 70,
                    'details' => ['ua_platform' => 'Windows', 'sec_platform' => $secChUaPlatform],
                ];
            }

            if ($uaIsMac && !str_contains($cleanSecPlatform, 'mac') && !str_contains($cleanSecPlatform, 'apple')) {
                return [
                    'isAnomaly' => true,
                    'reason' => sprintf('Platform inconsistency: UA specifies macOS, but Sec-Ch-Ua-Platform is "%s"', $secChUaPlatform),
                    'score' => 70,
                    'details' => ['ua_platform' => 'macOS', 'sec_platform' => $secChUaPlatform],
                ];
            }

            if ($uaIsAndroid && !str_contains($cleanSecPlatform, 'android')) {
                return [
                    'isAnomaly' => true,
                    'reason' => sprintf('Platform inconsistency: UA specifies Android, but Sec-Ch-Ua-Platform is "%s"', $secChUaPlatform),
                    'score' => 70,
                    'details' => ['ua_platform' => 'Android', 'sec_platform' => $secChUaPlatform],
                ];
            }

            if ($uaIsLinux && !str_contains($cleanSecPlatform, 'linux')) {
                return [
                    'isAnomaly' => true,
                    'reason' => sprintf('Platform inconsistency: UA specifies Linux, but Sec-Ch-Ua-Platform is "%s"', $secChUaPlatform),
                    'score' => 70,
                    'details' => ['ua_platform' => 'Linux', 'sec_platform' => $secChUaPlatform],
                ];
            }
        }

        // 4. Mobile Indicator Check (Sec-Ch-Ua-Mobile vs UA)
        if ($secChUaMobile !== null && $secChUaMobile !== '') {
            $isMobileSec = str_contains($secChUaMobile, '?1') || $secChUaMobile === '1';
            $isDesktopSec = str_contains($secChUaMobile, '?0') || $secChUaMobile === '0';

            $uaIsMobile = (bool) preg_match('/mobile|android|iphone|ipod/i', $ua);
            $uaIsStrictDesktop = (bool) preg_match('/windows\s+nt.*win64|x86_64.*macintosh/i', $ua) && !$uaIsMobile;

            if ($isMobileSec && $uaIsStrictDesktop) {
                return [
                    'isAnomaly' => true,
                    'reason' => 'Mobile indicator mismatch: Sec-Ch-Ua-Mobile is ?1 but User-Agent indicates desktop 64-bit OS',
                    'score' => 60,
                    'details' => ['sec_mobile' => $secChUaMobile, 'ua_mobile' => false],
                ];
            }

            if ($isDesktopSec && preg_match('/(Android.*Mobile|iPhone)/i', $ua)) {
                return [
                    'isAnomaly' => true,
                    'reason' => 'Mobile indicator mismatch: Sec-Ch-Ua-Mobile is ?0 but User-Agent indicates mobile phone',
                    'score' => 60,
                    'details' => ['sec_mobile' => $secChUaMobile, 'ua_mobile' => true],
                ];
            }
        }

        // 5. Desktop Browser Header Completeness
        // Real desktop browsers (Chrome, Firefox, Safari, Edge) always send Accept-Language and Accept-Encoding: gzip
        $isDesktopBrowser = (bool) preg_match('/^Mozilla\/5\.0\s+\((Windows\s+NT|Macintosh|X11;\s+Linux\s+x86_64).*AppleWebKit\/[0-9.]+\s+\(KHTML,\s+like\s+Gecko\)\s+(Chrome\/[0-9.]+|Version\/[0-9.]+\s+Safari\/[0-9.]+|Firefox\/[0-9.]+)/i', $ua);

        if ($isDesktopBrowser) {
            // Missing Accept-Language
            if ($acceptLang === null || $acceptLang === '') {
                return [
                    'isAnomaly' => true,
                    'reason' => 'Purported desktop browser missing Accept-Language header (scraper heuristic)',
                    'score' => 45,
                    'details' => ['issue' => 'missing_accept_language'],
                ];
            }

            // Missing Accept-Encoding or missing gzip
            if ($acceptEncoding === null || !str_contains(strtolower($acceptEncoding), 'gzip')) {
                return [
                    'isAnomaly' => true,
                    'reason' => 'Purported desktop browser missing standard gzip compression in Accept-Encoding',
                    'score' => 40,
                    'details' => ['issue' => 'missing_gzip_encoding'],
                ];
            }
        }

        return $defaultResult;
    }
}
