<?php

declare(strict_types=1);

namespace larikmc\antibot\services;

/**
 * High-speed Bot and Crawler detection service using pre-compiled regex signatures.
 *
 * Clearly separates:
 * 1. Traditional search engines (Google, Yandex, Bing, etc.)
 * 2. AI Search & Referral bots (OAI-SearchBot, ChatGPT-User, Perplexity)
 * 3. AI Training & Scraper bots (GPTBot, ClaudeBot, Bytespider, CCBot)
 * 4. Commercial SEO scrapers (Ahrefs, Semrush, DotBot, etc.)
 * 5. Automated CLI tools & HTTP libraries (cURL, Python, Wget, Scrapy)
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class BotDetector
{
    /**
     * Search engines map: engine key => regex pattern.
     * Note: PetalBot is now properly classified under search engines.
     */
    public const SEARCH_BOTS = [
        'yandex' => 'YandexBot|YandexMobileBot|YandexDirect|YandexDirectDyn|YandexAdNet|YandexPartner|YaDirectFetcher|YandexMetrika|YandexImages|YandexNews|YandexVideo|YandexMedia|YandexFavicons|YandexWebmaster|YandexSpravBot|YandexRenderResourcesBot',
        'google' => 'Googlebot|Googlebot-Image|Googlebot-News|Googlebot-Video|Google-InspectionTool|Mediapartners-Google|AdsBot-Google|AdsBot-Google-Mobile|Google-Display-Ads-Bot|Feedfetcher-Google|Google-Read-Aloud',
        'bing' => 'bingbot|msnbot|BingPreview|adidxbot|MicrosoftPreview',
        'mailru' => 'Mail\.RU_Bot|Mail\.Ru',
        'duckduckgo' => 'DuckDuckBot|DuckDuckGo-Favicons-Bot',
        'yahoo' => 'Slurp',
        'apple' => 'Applebot',
        'petalbot' => 'PetalBot',
        'baidu' => 'Baiduspider',
    ];

    /**
     * AI Search & Conversational bots that bring direct referral visitors.
     */
    public const AI_SEARCH_BOTS = [
        'OAI-SearchBot' => 'OAI-SearchBot',
        'ChatGPT-User' => 'ChatGPT-User',
        'PerplexityBot' => 'PerplexityBot',
    ];

    /**
     * AI Training crawlers and offline LLM scrapers.
     * Note: Google-Extended is an instruction token for robots.txt only, not an HTTP User-Agent crawler.
     * It is intentionally omitted from HTTP classification to avoid false-positive blocks of Googlebot.
     */
    public const AI_TRAINING_CRAWLERS = [
        'GPTBot' => 'GPTBot',
        'ClaudeBot' => 'ClaudeBot|anthropic-ai|Claude-Web',
        'Bytespider' => 'Bytespider',
        'CCBot' => 'CCBot',
        'Meta-ExternalAgent' => 'FacebookBot|Meta-ExternalAgent',
        'Amazonbot' => 'Amazonbot',
        'cohere-ai' => 'cohere-ai',
        'Diffbot' => 'Diffbot',
        'Timpibot' => 'Timpibot',
    ];

    /**
     * Commercial SEO crawlers and parsers (Ahrefs, Semrush, DotBot, etc.).
     * Note: CriteoBot and PetalBot moved to their respective specialized categories (Ad and Search).
     */
    public const COMMERCIAL_SCRAPERS = [
        'Ahrefs' => 'AhrefsBot|AhrefsSiteAudit',
        'Semrush' => 'SemrushBot|SiteAuditBot|SemrushBot-BA|SemrushBot-SI|SemrushBot-SWA|SemrushBot-OCOB|SemrushBot-FT|SemrushBot-ESI|SplitSignalBot',
        'DotBot' => 'DotBot',
        'MJ12bot' => 'MJ12bot',
        'MegaIndex' => 'MegaIndex',
        'BLEXBot' => 'BLEXBot',
        'DataForSeo' => 'DataForSeoBot',
        'Serpstat' => 'SerpstatBot',
        'Ryte' => 'RyteBot',
        'Seekport' => 'SeekportBot',
    ];

    /**
     * Advertising & verification bots.
     */
    public const ADVERTISING_BOTS = [
        'YandexDirect' => 'YandexDirect|YandexDirectDyn',
        'YandexAdNet' => 'YandexAdNet|YandexPartner',
        'YaDirectFetcher' => 'YaDirectFetcher',
        'YandexMetrika' => 'YandexMetrika',
        'GoogleAds' => 'AdsBot-Google|AdsBot-Google-Mobile|Mediapartners-Google|Google-Display-Ads-Bot',
        'Criteo' => 'CriteoBot',
        'Quantcast' => 'Quantcastbot',
        'IAS' => 'IAS[ _-]Crawler|ias_crawler|ias_wombles',
        'OpenAIAds' => 'OAI-AdsBot',
        'DoubleVerify' => 'DoubleVerify',
    ];

    /**
     * Social networks & messenger preview bots (link unfurling).
     */
    public const SOCIAL_PREVIEW_BOTS = [
        'Telegram' => 'TelegramBot',
        'WhatsApp' => 'WhatsApp',
        'Facebook' => 'facebookexternalhit|facebookcatalog|meta-externalfetcher',
        'Twitter' => 'Twitterbot',
        'LinkedIn' => 'LinkedInBot',
        'Discord' => 'Discordbot',
        'Slack' => 'Slackbot|Slackbot-LinkExpanding',
        'VK' => 'vkShare',
    ];

    /**
     * CLI tools, programming HTTP libraries and automated scrapers.
     */
    public const CLI_AND_LIBRARIES = [
        'cURL' => '^curl\/',
        'Wget' => '^Wget\/',
        'Python' => 'python-requests|aiohttp|Python-urllib|httpx|requests\/',
        'Go' => 'Go-http-client',
        'PHP' => 'GuzzleHttp|Zend_Http_Client|PHP-SOAP|Symfony HttpClient',
        'NodeJS' => 'node-fetch|axios|Got\/|undici',
        'Java' => 'Java\/|okhttp|Apache-HttpClient|Jersey\/',
        'Postman' => 'PostmanRuntime',
        'Scrapy' => 'Scrapy',
        'Headless' => 'HeadlessChrome|PhantomJS',
    ];

    /**
     * Determines whether the User-Agent belongs to a known legitimate search engine.
     */
    public static function detectSearchBot(string $userAgent): ?string
    {
        if ($userAgent === '') {
            return null;
        }

        foreach (self::SEARCH_BOTS as $key => $pattern) {
            if (preg_match('/' . $pattern . '/i', $userAgent)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Detects AI crawlers with category separation: 'search' vs 'training'.
     *
     * @param string $userAgent
     * @return array{name: string, type: 'search'|'training'}|null
     */
    public static function detectAiCrawler(string $userAgent): ?array
    {
        if ($userAgent === '') {
            return null;
        }

        // Check AI Search bots first
        foreach (self::AI_SEARCH_BOTS as $name => $pattern) {
            if (preg_match('/' . $pattern . '/i', $userAgent)) {
                return ['name' => $name, 'type' => 'search'];
            }
        }

        // Check AI Training bots
        foreach (self::AI_TRAINING_CRAWLERS as $name => $pattern) {
            if (preg_match('/' . $pattern . '/i', $userAgent)) {
                return ['name' => $name, 'type' => 'training'];
            }
        }

        return null;
    }

    /**
     * Detects commercial SEO crawlers.
     */
    public static function detectCommercialScraper(string $userAgent): ?string
    {
        if ($userAgent === '') {
            return null;
        }

        foreach (self::COMMERCIAL_SCRAPERS as $name => $pattern) {
            if (preg_match('/' . $pattern . '/i', $userAgent)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Detects advertising & verification bots.
     */
    public static function detectAdvertisingBot(string $userAgent): ?string
    {
        if ($userAgent === '') {
            return null;
        }

        foreach (self::ADVERTISING_BOTS as $name => $pattern) {
            if (preg_match('/' . $pattern . '/i', $userAgent)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Detects social media and messenger link preview bots.
     */
    public static function detectSocialPreviewBot(string $userAgent): ?string
    {
        if ($userAgent === '') {
            return null;
        }

        foreach (self::SOCIAL_PREVIEW_BOTS as $name => $pattern) {
            if (preg_match('/' . $pattern . '/i', $userAgent)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Fast identification via BotCatalog.
     *
     * @param string $userAgent
     * @return array{key: string, data: array<string, mixed>}|null
     */
    public static function identifyBot(string $userAgent): ?array
    {
        return BotCatalog::identify($userAgent);
    }

    /**
     * Detects CLI tools and programming libraries.
     */
    public static function detectCliAndLibraries(string $userAgent): ?string
    {
        if ($userAgent === '') {
            return null;
        }

        foreach (self::CLI_AND_LIBRARIES as $name => $pattern) {
            if (preg_match('/' . $pattern . '/i', $userAgent)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Detects anomalous or suspicious HTTP headers typical of crude scrapers.
     * Integrates Client Hints consistency and missing standard browser header heuristics.
     *
     * @param array<string, mixed> $headers
     * @return string|null Reason or null.
     */
    public static function detectAnomalousHeaders(array $headers): ?string
    {
        $uaVal = $headers['user-agent'] ?? $headers['User-Agent'] ?? null;
        if (is_array($uaVal)) {
            $uaVal = reset($uaVal);
        }

        $ua = trim((string) $uaVal);
        $result = \larikmc\antibot\services\behavior\HeaderAnalyzer::analyze($ua, $headers);

        return $result['isAnomaly'] ? $result['reason'] : null;
    }
}
