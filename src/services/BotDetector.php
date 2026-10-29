<?php

declare(strict_types=1);

namespace larikmc\antibot\services;

/**
 * High-speed Bot and Crawler detection service using pre-compiled regex signatures.
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class BotDetector
{
    /**
     * Search engines map: bot name => signature pattern.
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
     * AI training crawlers and LLM scrapers.
     */
    public const AI_CRAWLERS = [
        'OpenAI' => 'GPTBot|ChatGPT-User|OAI-SearchBot',
        'Anthropic' => 'ClaudeBot|anthropic-ai|Claude-Web',
        'ByteDance' => 'Bytespider',
        'CommonCrawl' => 'CCBot',
        'Perplexity' => 'PerplexityBot',
        'GoogleAI' => 'Google-Extended',
        'MetaAI' => 'FacebookBot|Meta-ExternalAgent',
        'Amazon' => 'Amazonbot',
        'Cohere' => 'cohere-ai',
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
     * Determines whether the given User-Agent belongs to a known legitimate search engine.
     *
     * @param string $userAgent
     * @return string|null Returns the search engine key (e.g. 'yandex', 'google') or null.
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
     * Determines whether the User-Agent belongs to an AI crawler/scraper.
     *
     * @param string $userAgent
     * @return string|null Returns crawler name or null.
     */
    public static function detectAiCrawler(string $userAgent): ?string
    {
        if ($userAgent === '') {
            return null;
        }

        foreach (self::AI_CRAWLERS as $name => $pattern) {
            if (preg_match('/' . $pattern . '/i', $userAgent)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Determines whether the User-Agent is an aggressive commercial scraper (Ahrefs, Semrush, etc.).
     *
     * @param string $userAgent
     * @return string|null
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
     * Determines whether the User-Agent is a CLI utility, HTTP library, or headless browser.
     *
     * @param string $userAgent
     * @return string|null
     */
    public static function detectCliOrLibrary(string $userAgent): ?string
    {
        if ($userAgent === '') {
            return 'Empty User-Agent';
        }

        foreach (self::CLI_AND_LIBRARIES as $name => $pattern) {
            if (preg_match('/' . $pattern . '/i', $userAgent)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Checks for obvious header anomalies indicating a fake or dumb bot.
     *
     * For example: User-Agent claims to be a modern desktop Chrome/Safari/Firefox,
     * but completely lacks standard browser headers (Accept, Accept-Language, Accept-Encoding).
     *
     * @param string $userAgent
     * @param array<string, string> $headers Lowercase header name => value.
     * @return string|null Returns reason if anomalous, or null if normal.
     */
    public static function detectHeaderAnomalies(string $userAgent, array $headers): ?string
    {
        $uaLower = strtolower($userAgent);

        if (trim($userAgent) === '') {
            return 'Empty User-Agent';
        }

        if (strlen($userAgent) < 15) {
            return 'Abnormally short User-Agent';
        }

        // If pretending to be standard desktop Chrome, Firefox or Safari
        $isBrowser = str_contains($uaLower, 'mozilla/') &&
            (str_contains($uaLower, 'chrome/') || str_contains($uaLower, 'firefox/') || str_contains($uaLower, 'safari/'));

        if ($isBrowser) {
            // Real desktop browsers always send Accept-Language or Accept-Encoding
            $hasAccept = !empty($headers['accept']);
            $hasEncoding = !empty($headers['accept-encoding']);
            $hasLanguage = !empty($headers['accept-language']);

            if (!$hasAccept && !$hasEncoding && !$hasLanguage) {
                return 'Missing standard browser headers (Accept / Encoding / Language)';
            }
        }

        return null;
    }
}
