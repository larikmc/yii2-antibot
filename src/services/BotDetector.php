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
     */
    public const SEARCH_BOTS = [
        'yandex' => 'YandexBot|YandexMobileBot|YandexDirect|YandexMetrika|YandexImages|YandexNews|YandexVideo|YandexMedia|YandexFavicons|YandexWebmaster|YandexSpravBot',
        'google' => 'Googlebot|Googlebot-Image|Googlebot-News|Googlebot-Video|Mediapartners-Google|AdsBot-Google|Feedfetcher-Google|Google-Read-Aloud',
        'bing' => 'bingbot|msnbot|BingPreview|adidxbot|MicrosoftPreview',
        'mailru' => 'Mail\.RU_Bot|Mail\.Ru',
        'duckduckgo' => 'DuckDuckBot|DuckDuckGo-Favicons-Bot',
        'yahoo' => 'Slurp',
        'apple' => 'Applebot',
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
     */
    public const AI_TRAINING_CRAWLERS = [
        'GPTBot' => 'GPTBot',
        'ClaudeBot' => 'ClaudeBot|anthropic-ai|Claude-Web',
        'Bytespider' => 'Bytespider',
        'CCBot' => 'CCBot',
        'Google-Extended' => 'Google-Extended',
        'Meta-ExternalAgent' => 'FacebookBot|Meta-ExternalAgent',
        'Amazonbot' => 'Amazonbot',
        'cohere-ai' => 'cohere-ai',
        'Diffbot' => 'Diffbot',
        'Timpibot' => 'Timpibot',
    ];

    /**
     * Aggressive SEO scrapers and commercial parsers.
     */
    public const COMMERCIAL_SCRAPERS = [
        'Ahrefs' => 'AhrefsBot',
        'Semrush' => 'SemrushBot',
        'DotBot' => 'DotBot',
        'MJ12bot' => 'MJ12bot',
        'PetalBot' => 'PetalBot',
        'Seekport' => 'SeekportBot',
        'MegaIndex' => 'MegaIndex',
        'BLEXBot' => 'BLEXBot',
        'DataForSeo' => 'DataForSeoBot',
        'Serpstat' => 'SerpstatBot',
        'Criteo' => 'CriteoBot',
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
        if ($ua === '') {
            return 'Empty or missing User-Agent header';
        }

        if (strlen($ua) < 8) {
            return 'Suspiciously short User-Agent string';
        }

        return null;
    }
}
