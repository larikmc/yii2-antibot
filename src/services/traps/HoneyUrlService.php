<?php

declare(strict_types=1);

namespace larikmc\antibot\services\traps;

use larikmc\antibot\Antibot;
use larikmc\antibot\services\BotDetector;
use larikmc\antibot\services\ReverseDnsVerifier;
use Yii;
use yii\helpers\Html;

/**
 * Invisible Honey URL trap service.
 *
 * Traps automated scraping bots and crawlers that blindly follow invisible links
 * embedded within page HTML, while strictly protecting legitimate search engines (SEO safe).
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class HoneyUrlService
{
    public const DEFAULT_ROUTE = 'antibot/trap';
    public const TOKEN_PARAM = 'h';
    public const MAX_AGE_SECONDS = 86400; // 24 hours

    /**
     * Generates a signed cryptographic token for trap URLs.
     */
    public static function generateToken(string $secretKey, ?int $timestamp = null): string
    {
        $time = $timestamp ?? time();
        $salt = bin2hex(random_bytes(4));
        $payload = sprintf('%d|%s', $time, $salt);
        $signature = substr(hash_hmac('sha256', $payload, $secretKey), 0, 16);

        return base64_encode($payload . '|' . $signature);
    }

    /**
     * Validates whether a submitted trap token has a valid cryptographic signature and is within maxAge.
     */
    public static function validateToken(string $token, string $secretKey, int $maxAge = self::MAX_AGE_SECONDS): bool
    {
        if (trim($token) === '') {
            return false;
        }

        $decoded = base64_decode($token, true);
        if ($decoded === false) {
            return false;
        }

        $parts = explode('|', $decoded);
        if (count($parts) !== 3) {
            return false;
        }

        [$timeStr, $salt, $signature] = $parts;
        $timestamp = (int) $timeStr;

        if (time() - $timestamp > $maxAge || $timestamp > time() + 10) {
            return false;
        }

        $payload = sprintf('%d|%s', $timestamp, $salt);
        $expectedSignature = substr(hash_hmac('sha256', $payload, $secretKey), 0, 16);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Generates an invisible HTML link tag targeting the honey trap route.
     *
     * @param string $baseUrl Base application URL (e.g., '/' or 'https://example.loc/').
     * @param string $secretKey Secret signing key.
     * @param string $route Route for the trap.
     * @param string $label Visible link anchor (ignored by humans since styled invisible).
     * @return string HTML anchor tag.
     */
    public static function generateTrapHtml(
        string $baseUrl,
        string $secretKey,
        string $route = self::DEFAULT_ROUTE,
        string $label = 'Site Directory'
    ): string {
        $token = self::generateToken($secretKey);
        $cleanBase = rtrim($baseUrl, '/');
        $trapUrl = sprintf('%s/%s?%s=%s', $cleanBase, ltrim($route, '/'), self::TOKEN_PARAM, urlencode($token));

        return Html::a(Html::encode($label), $trapUrl, [
            'style' => 'position:absolute!important;left:-9999px!important;top:-9999px!important;opacity:0!important;height:0!important;width:0!important;z-index:-1!important;pointer-events:none!important;',
            'rel' => 'nofollow',
            'aria-hidden' => 'true',
            'tabindex' => '-1',
        ]);
    }

    /**
     * Handles an incoming visit to the Honey Trap URL.
     *
     * @param string $ip Visitor IP address.
     * @param string $ua Visitor User-Agent.
     * @param Antibot $antibot Antibot component instance.
     * @return array{status: string, penalized: bool, reason: string}
     */
    public static function handleTrapHit(string $ip, string $ua, Antibot $antibot): array
    {
        // 1. Strict SEO Safety: Verify whether this is an authentic search engine crawler
        $searchEngine = BotDetector::detectSearchBot($ua);
        if ($searchEngine !== null) {
            $status = ReverseDnsVerifier::check($ip, $searchEngine);
            if ($status === ReverseDnsVerifier::STATUS_VERIFIED) {
                // Legitimate search engines index all discovered links. Never penalize!
                return [
                    'status' => 'ignored_verified_search_bot',
                    'penalized' => false,
                    'reason' => 'Authentic Search Engine: ' . ucfirst($searchEngine),
                ];
            }
        }

        $url = '';
        if (Yii::$app !== null) {
            try {
                $url = Yii::$app->request->getAbsoluteUrl();
            } catch (\Throwable) {
                $url = (string) ($_SERVER['REQUEST_URI'] ?? '/antibot/trap');
            }
        }

        // 2. Automated scraper detected by following invisible trap link
        $storage = $antibot->getStorage();
        $storage->logEvent([
            'ip' => $ip,
            'userAgent' => $ua,
            'action' => 'honey_trap_hit',
            'reason' => 'Crawler followed invisible honey URL trap',
            'url' => $url,
        ]);

        // Increment telemetry counter
        if (Yii::$app !== null && Yii::$app->has('cache')) {
            $cache = Yii::$app->get('cache');
            if ($cache instanceof \yii\caching\CacheInterface) {
                $count = (int) $cache->get('antibot_stat_trap_hits');
                $cache->set('antibot_stat_trap_hits', $count + 1, 86400 * 30);
            }
        }

        return [
            'status' => 'trapped',
            'penalized' => true,
            'reason' => 'Invisible trap link triggered by scraper',
        ];
    }
}
