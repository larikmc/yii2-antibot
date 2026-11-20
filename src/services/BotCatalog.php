<?php

declare(strict_types=1);

namespace larikmc\antibot\services;

/**
 * Centralized catalog of known legitimate, commercial, SEO, advertising, social, and AI bots.
 *
 * Provides metadata, verification requirements, categories, operator info, and default policies.
 *
 * Categories:
 * - search: Legitimate search engines (Google, Yandex, Bing, etc.)
 * - ad: Advertising & marketing bots (Yandex Direct, Google AdsBot, Criteo, etc.)
 * - seo: SEO & analytics crawlers (Ahrefs, Semrush, DotBot, etc.)
 * - social: Social media & messaging link preview bots (Telegram, WhatsApp, Meta, etc.)
 * - ai_search: AI search engines bringing direct referral traffic (OAI-SearchBot, Perplexity)
 * - ai_training: AI model training scrapers and dataset harvesters (GPTBot, ClaudeBot, CCBot)
 *
 * Verification Methods:
 * - rdns: Two-way Forward-Confirmed Reverse DNS (FCrDNS)
 * - cidr_or_rdns: Official CIDR snapshot pre-filter + fallback FCrDNS
 * - cidr: Official published IP ranges / ASN only (no branded PTR available)
 * - none: Operator publishes neither PTR nor static IP ranges (cautious handling)
 *
 * Policies:
 * - allow: Allowed when verified (or allowed directly if verification is none)
 * - monitor: Monitored in event log without blocking
 * - throttle: Rate-limited with custom/standard thresholds
 * - block: Blocked (HTTP 403)
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class BotCatalog
{
    public const CATEGORY_SEARCH = 'search';
    public const CATEGORY_AD = 'ad';
    public const CATEGORY_SEO = 'seo';
    public const CATEGORY_SOCIAL = 'social';
    public const CATEGORY_AI_SEARCH = 'ai_search';
    public const CATEGORY_AI_TRAINING = 'ai_training';

    public const POLICY_ALLOW = 'allow';
    public const POLICY_MONITOR = 'monitor';
    public const POLICY_THROTTLE = 'throttle';
    public const POLICY_BLOCK = 'block';

    public const VERIFY_RDNS = 'rdns';
    public const VERIFY_CIDR_OR_RDNS = 'cidr_or_rdns';
    public const VERIFY_CIDR = 'cidr';
    public const VERIFY_NONE = 'none';

    /**
     * Complete registry of known bots with full metadata.
     *
     * @var array<string, array{
     *     name: string,
     *     category: string,
     *     operator: string,
     *     description_ru: string,
     *     description_en: string,
     *     pattern: string,
     *     default_policy: string,
     *     verification: string,
     *     engine_key?: string,
     *     cidr_key?: string,
     *     domains?: list<string>,
     *     documentation_url: string
     * }>
     */
    protected static array $catalog = [
        // ==========================================
        // 1. Search Engines (Category A)
        // ==========================================
        'googlebot' => [
            'name' => 'Googlebot',
            'category' => self::CATEGORY_SEARCH,
            'operator' => 'Google LLC',
            'description_ru' => 'Основной поисковый робот Google для веб-индексации',
            'description_en' => 'Primary Google search indexing crawler',
            'pattern' => 'Googlebot|Googlebot-Image|Googlebot-News|Googlebot-Video|Google-Read-Aloud|Feedfetcher-Google',
            'default_policy' => self::POLICY_ALLOW,
            'verification' => self::VERIFY_CIDR_OR_RDNS,
            'engine_key' => 'google',
            'domains' => ['.googlebot.com', '.google.com'],
            'documentation_url' => 'https://developers.google.com/search/docs/crawling-indexing/googlebot',
        ],
        'google_inspection_tool' => [
            'name' => 'Google-InspectionTool',
            'category' => self::CATEGORY_SEARCH,
            'operator' => 'Google LLC',
            'description_ru' => 'Инструмент проверки URL в Search Console и тестов расширенных результатов Google',
            'description_en' => 'Google Search Console URL inspection and rich results testing crawler',
            'pattern' => 'Google-InspectionTool',
            'default_policy' => self::POLICY_ALLOW,
            'verification' => self::VERIFY_CIDR_OR_RDNS,
            'engine_key' => 'google',
            'domains' => ['.googlebot.com', '.google.com'],
            'documentation_url' => 'https://developers.google.com/search/docs/crawling-indexing/overview-google-crawlers',
        ],
        'yandexbot' => [
            'name' => 'YandexBot',
            'category' => self::CATEGORY_SEARCH,
            'operator' => 'Yandex LLC',
            'description_ru' => 'Основной поисковый робот Яндекса (индексация страниц, медиа, новостей)',
            'description_en' => 'Primary Yandex search indexing crawler',
            'pattern' => 'YandexBot|YandexMobileBot|YandexImages|YandexNews|YandexVideo|YandexMedia|YandexFavicons|YandexWebmaster|YandexSpravBot',
            'default_policy' => self::POLICY_ALLOW,
            'verification' => self::VERIFY_RDNS,
            'engine_key' => 'yandex',
            'domains' => ['.yandex.ru', '.yandex.net', '.yandex.com'],
            'documentation_url' => 'https://yandex.ru/support/webmaster/robot-workings/check-yandex-crawlers.html',
        ],
        'yandex_render_resources' => [
            'name' => 'YandexRenderResourcesBot',
            'category' => self::CATEGORY_SEARCH,
            'operator' => 'Yandex LLC',
            'description_ru' => 'Робот рендеринга Яндекса для загрузки стилей, скриптов и изображений при индексировании',
            'description_en' => 'Yandex rendering crawler loading CSS, JavaScript, and images for indexing evaluation',
            'pattern' => 'YandexRenderResourcesBot',
            'default_policy' => self::POLICY_ALLOW,
            'verification' => self::VERIFY_RDNS,
            'engine_key' => 'yandex',
            'domains' => ['.yandex.ru', '.yandex.net', '.yandex.com'],
            'documentation_url' => 'https://yandex.ru/support/webmaster/robot-workings/check-yandex-robots.html',
        ],
        'bingbot' => [
            'name' => 'Bingbot',
            'category' => self::CATEGORY_SEARCH,
            'operator' => 'Microsoft Corporation',
            'description_ru' => 'Поисковый робот поисковой системы Microsoft Bing',
            'description_en' => 'Microsoft Bing search crawler',
            'pattern' => 'bingbot|msnbot|BingPreview|adidxbot|MicrosoftPreview',
            'default_policy' => self::POLICY_ALLOW,
            'verification' => self::VERIFY_CIDR_OR_RDNS,
            'engine_key' => 'bing',
            'domains' => ['.search.msn.com'],
            'documentation_url' => 'https://www.bing.com/webmasters/help/which-crawlers-does-bing-use-8c184ec0',
        ],
        'mailru' => [
            'name' => 'Mail.Ru Bot',
            'category' => self::CATEGORY_SEARCH,
            'operator' => 'VK (Mail.Ru)',
            'description_ru' => 'Поисковый робот медиа- и поисковых сервисов Mail.Ru / VK',
            'description_en' => 'Mail.Ru / VK search crawler',
            'pattern' => 'Mail\.RU_Bot|Mail\.Ru',
            'default_policy' => self::POLICY_ALLOW,
            'verification' => self::VERIFY_RDNS,
            'engine_key' => 'mailru',
            'domains' => ['.mail.ru'],
            'documentation_url' => 'https://help.mail.ru/webmaster/indexing/robots',
        ],
        'duckduckgo' => [
            'name' => 'DuckDuckBot',
            'category' => self::CATEGORY_SEARCH,
            'operator' => 'Duck Duck Go, Inc.',
            'description_ru' => 'Краулер поисковой системы с фокусом на приватность DuckDuckGo',
            'description_en' => 'DuckDuckGo privacy-focused search crawler',
            'pattern' => 'DuckDuckBot|DuckDuckGo-Favicons-Bot',
            'default_policy' => self::POLICY_ALLOW,
            'verification' => self::VERIFY_RDNS,
            'engine_key' => 'duckduckgo',
            'domains' => ['.duckduckgo.com'],
            'documentation_url' => 'https://duckduckgo.com/duckduckbot',
        ],
        'applebot' => [
            'name' => 'Applebot',
            'category' => self::CATEGORY_SEARCH,
            'operator' => 'Apple Inc.',
            'description_ru' => 'Краулер Apple для Siri, Spotlight и поиска Safari',
            'description_en' => 'Apple crawler for Siri, Spotlight, and Safari search',
            'pattern' => 'Applebot',
            'default_policy' => self::POLICY_ALLOW,
            'verification' => self::VERIFY_RDNS,
            'engine_key' => 'apple',
            'domains' => ['.applebot.apple.com'],
            'documentation_url' => 'https://support.apple.com/HT204683',
        ],
        'petalbot' => [
            'name' => 'PetalBot',
            'category' => self::CATEGORY_SEARCH,
            'operator' => 'Huawei Device Co. / Aspiegel',
            'description_ru' => 'Поисковый робот мобильной экосистемы Petal Search (Huawei)',
            'description_en' => 'Huawei Petal Search and AI assistant crawler',
            'pattern' => 'PetalBot',
            'default_policy' => self::POLICY_ALLOW,
            'verification' => self::VERIFY_RDNS,
            'engine_key' => 'petalbot',
            'domains' => ['.aspiegel.com', '.petalsearch.com'],
            'documentation_url' => 'https://webmaster.petalsearch.com/site/petalbot',
        ],
        'baidu' => [
            'name' => 'Baiduspider',
            'category' => self::CATEGORY_SEARCH,
            'operator' => 'Baidu, Inc.',
            'description_ru' => 'Основной поисковый робот китайской системы Baidu',
            'description_en' => 'Baidu Chinese search engine crawler',
            'pattern' => 'Baiduspider',
            'default_policy' => self::POLICY_ALLOW,
            'verification' => self::VERIFY_RDNS,
            'engine_key' => 'baidu',
            'domains' => ['.baidu.com', '.baidu.jp'],
            'documentation_url' => 'https://help.baidu.com/question?prod_id=99&class=0',
        ],
        'yahoo' => [
            'name' => 'Yahoo! Slurp',
            'category' => self::CATEGORY_SEARCH,
            'operator' => 'Yahoo! / Yahoo Inc.',
            'description_ru' => 'Краулер каталога и контента Yahoo!',
            'description_en' => 'Yahoo! content and search crawler',
            'pattern' => 'Slurp',
            'default_policy' => self::POLICY_ALLOW,
            'verification' => self::VERIFY_RDNS,
            'engine_key' => 'yahoo',
            'domains' => ['.crawl.yahoo.net'],
            'documentation_url' => 'https://help.yahoo.com/kb/slurp-crawling-sln22600.html',
        ],

        // ==========================================
        // 2. Advertising & Marketing Bots (Category B) - TOP PRIORITY
        // ==========================================
        // Note on Ad Ecosystems (Digital Caramel, Affinity):
        // Neither platform currently publishes dedicated crawler User-Agents or official IP ranges.
        // If server access logs show traffic from verified partner endpoints, administrators can
        // whitelist them via IP List or add custom overrides in BotCatalog without breaking security.
        'yandex_direct' => [
            'name' => 'YandexDirect',
            'category' => self::CATEGORY_AD,
            'operator' => 'Yandex LLC',
            'description_ru' => 'Краулер Рекламной сети Яндекса (РСЯ) для анализа контекста и подбора рекламы',
            'description_en' => 'Yandex Advertising Network (YAN) contextual crawler',
            'pattern' => 'YandexDirect|YandexDirectDyn',
            'default_policy' => self::POLICY_ALLOW,
            'verification' => self::VERIFY_RDNS,
            'engine_key' => 'yandex',
            'domains' => ['.yandex.ru', '.yandex.net', '.yandex.com'],
            'documentation_url' => 'https://yandex.com/support/webmaster/robot-workings/check-yandex-crawlers.html',
        ],
        'yandex_adnet' => [
            'name' => 'YandexAdNet',
            'category' => self::CATEGORY_AD,
            'operator' => 'Yandex LLC',
            'description_ru' => 'Робот проверки сайтов-партнеров РСЯ и категорий контента',
            'description_en' => 'Yandex partner network quality and categorization crawler',
            'pattern' => 'YandexAdNet|YandexPartner',
            'default_policy' => self::POLICY_ALLOW,
            'verification' => self::VERIFY_RDNS,
            'engine_key' => 'yandex',
            'domains' => ['.yandex.ru', '.yandex.net', '.yandex.com'],
            'documentation_url' => 'https://yandex.com/support/webmaster/robot-workings/check-yandex-crawlers.html',
        ],
        'yadirect_fetcher' => [
            'name' => 'YaDirectFetcher',
            'category' => self::CATEGORY_AD,
            'operator' => 'Yandex LLC',
            'description_ru' => 'Проверка доступности целевых посадочных страниц объявлений Яндекс Директа',
            'description_en' => 'Yandex Direct landing page availability and quality checker',
            'pattern' => 'YaDirectFetcher',
            'default_policy' => self::POLICY_ALLOW,
            'verification' => self::VERIFY_RDNS,
            'engine_key' => 'yandex',
            'domains' => ['.yandex.ru', '.yandex.net', '.yandex.com'],
            'documentation_url' => 'https://yandex.com/support/webmaster/robot-workings/check-yandex-crawlers.html',
        ],
        'yandex_metrika' => [
            'name' => 'YandexMetrika',
            'category' => self::CATEGORY_AD,
            'operator' => 'Yandex LLC',
            'description_ru' => 'Проверка доступности сайтов и счетчиков сервиса Яндекс Метрика',
            'description_en' => 'Yandex Metrika website availability monitoring and tag verification crawler',
            'pattern' => 'YandexMetrika',
            'default_policy' => self::POLICY_ALLOW,
            'verification' => self::VERIFY_RDNS,
            'engine_key' => 'yandex',
            'domains' => ['.yandex.ru', '.yandex.net', '.yandex.com'],
            'documentation_url' => 'https://yandex.ru/support/metrica/general/counter-check.html',
        ],
        'google_ads' => [
            'name' => 'Google AdsBot',
            'category' => self::CATEGORY_AD,
            'operator' => 'Google LLC',
            'description_ru' => 'Проверка целевых страниц Google Ads, качества объявлений и релевантности',
            'description_en' => 'Google Ads landing page quality and compliance evaluator',
            'pattern' => 'AdsBot-Google|AdsBot-Google-Mobile|Mediapartners-Google|Google-Display-Ads-Bot',
            'default_policy' => self::POLICY_ALLOW,
            'verification' => self::VERIFY_CIDR_OR_RDNS,
            'engine_key' => 'google_special',
            'domains' => ['.google.com', '.googlebot.com'],
            'documentation_url' => 'https://developers.google.com/search/docs/crawling-indexing/google-special-case-crawlers',
        ],
        'criteo' => [
            'name' => 'CriteoBot',
            'category' => self::CATEGORY_AD,
            'operator' => 'Criteo SA (AS44788 / AS19750)',
            'description_ru' => 'Краулер товарных каталогов и контекстной классификации рекламы Criteo',
            'description_en' => 'Criteo contextual advertising and product catalog crawler',
            'pattern' => 'CriteoBot',
            'default_policy' => self::POLICY_ALLOW,
            'verification' => self::VERIFY_CIDR,
            'cidr_key' => 'criteo',
            'documentation_url' => 'https://www.criteo.com/criteo-crawler/',
        ],
        'quantcast' => [
            'name' => 'Quantcastbot',
            'category' => self::CATEGORY_AD,
            'operator' => 'Quantcast Corp.',
            'description_ru' => 'Краулер измерения рекламной аудитории и контроля качества Quantcast',
            'description_en' => 'Quantcast audience measurement and ad QA crawler',
            'pattern' => 'Quantcastbot',
            'default_policy' => self::POLICY_ALLOW,
            'verification' => self::VERIFY_CIDR,
            'cidr_key' => 'quantcast',
            'documentation_url' => 'https://www.quantcast.com/bot',
        ],
        'ias' => [
            'name' => 'IAS Crawler',
            'category' => self::CATEGORY_AD,
            'operator' => 'Integral Ad Science, Inc.',
            'description_ru' => 'Верификация Brand Safety, проверка контекста и защита от мошенничества IAS',
            'description_en' => 'Integral Ad Science brand safety and ad fraud verification crawler',
            'pattern' => 'IAS[ _-]Crawler|ias_crawler|ias_wombles',
            'default_policy' => self::POLICY_ALLOW,
            'verification' => self::VERIFY_CIDR,
            'cidr_key' => 'ias',
            'documentation_url' => 'https://integralads.com/policy-docs/iasbot.json',
        ],
        'oai_adsbot' => [
            'name' => 'OAI-AdsBot',
            'category' => self::CATEGORY_AD,
            'operator' => 'OpenAI',
            'description_ru' => 'Проверка безопасности и соответствия посадочных страниц рекламы в ChatGPT',
            'description_en' => 'OpenAI crawler verifying safety and relevance of ChatGPT ad landing pages',
            'pattern' => 'OAI-AdsBot',
            'default_policy' => self::POLICY_ALLOW,
            'verification' => self::VERIFY_CIDR,
            'cidr_key' => 'openai',
            'documentation_url' => 'https://openai.com/adsbot',
        ],
        'doubleverify' => [
            'name' => 'DoubleVerify',
            'category' => self::CATEGORY_AD,
            'operator' => 'DoubleVerify Inc.',
            'description_ru' => 'Проверка видимости рекламы, контекста и безопасности бренда DoubleVerify',
            'description_en' => 'DoubleVerify media quality, viewability, and brand safety crawler',
            'pattern' => 'DoubleVerify',
            'default_policy' => self::POLICY_ALLOW,
            'verification' => self::VERIFY_NONE,
            'documentation_url' => 'https://doubleverify.com/',
        ],

        // ==========================================
        // 3. SEO & Analytics Crawlers (Category C)
        // ==========================================
        'ahrefs' => [
            'name' => 'AhrefsBot',
            'category' => self::CATEGORY_SEO,
            'operator' => 'Ahrefs Pte. Ltd.',
            'description_ru' => 'Краулер анализа ссылочной массы и аудита сайта Ahrefs',
            'description_en' => 'Ahrefs backlink indexer and site audit crawler',
            'pattern' => 'AhrefsBot|AhrefsSiteAudit',
            'default_policy' => self::POLICY_THROTTLE,
            'verification' => self::VERIFY_RDNS,
            'engine_key' => 'ahrefs',
            'domains' => ['.ahrefs.com', '.ahrefs.net'],
            'documentation_url' => 'https://ahrefs.com/robot',
        ],
        'semrush' => [
            'name' => 'SemrushBot',
            'category' => self::CATEGORY_SEO,
            'operator' => 'Semrush Inc.',
            'description_ru' => 'Платформа SEO-аналитики Semrush (SiteAudit, Backlinks, Writing Assistant)',
            'description_en' => 'Semrush SEO, backlink, and site audit crawlers suite',
            'pattern' => 'SemrushBot|SiteAuditBot|SemrushBot-BA|SemrushBot-SI|SemrushBot-SWA|SemrushBot-OCOB|SemrushBot-FT|SemrushBot-ESI|SplitSignalBot',
            'default_policy' => self::POLICY_THROTTLE,
            'verification' => self::VERIFY_RDNS,
            'engine_key' => 'semrush',
            'domains' => ['.semrush.com'],
            'documentation_url' => 'https://www.semrush.com/bot/',
        ],
        'dotbot' => [
            'name' => 'DotBot',
            'category' => self::CATEGORY_SEO,
            'operator' => 'Moz, Inc.',
            'description_ru' => 'Поисковый робот для анализа авторитетности ссылок Moz Link Explorer',
            'description_en' => 'Moz DotBot crawler for Moz Link Explorer',
            'pattern' => 'DotBot',
            'default_policy' => self::POLICY_THROTTLE,
            'verification' => self::VERIFY_RDNS,
            'engine_key' => 'dotbot',
            'domains' => ['.opensiteexplorer.org', '.moz.com'],
            'documentation_url' => 'https://moz.com/help/moz-procedures/crawlers/dotbot',
        ],
        'mj12bot' => [
            'name' => 'MJ12bot',
            'category' => self::CATEGORY_SEO,
            'operator' => 'Majestic-12 Ltd.',
            'description_ru' => 'Распределенный краулер базы обратных ссылок Majestic SEO',
            'description_en' => 'Majestic SEO link intelligence map crawler',
            'pattern' => 'MJ12bot',
            'default_policy' => self::POLICY_THROTTLE,
            'verification' => self::VERIFY_RDNS,
            'engine_key' => 'mj12bot',
            'domains' => ['.majestic12.co.uk', '.majesticseo.com'],
            'documentation_url' => 'https://majestic.com/support/mj12bot',
        ],
        'megaindex' => [
            'name' => 'MegaIndex',
            'category' => self::CATEGORY_SEO,
            'operator' => 'MegaIndex',
            'description_ru' => 'Краулер видимости и позиций в поисковой выдаче MegaIndex',
            'description_en' => 'MegaIndex SEO audit and visibility crawler',
            'pattern' => 'MegaIndex|MegaIndex\.ru',
            'default_policy' => self::POLICY_THROTTLE,
            'verification' => self::VERIFY_NONE,
            'documentation_url' => 'https://megaindex.com/',
        ],
        'blexbot' => [
            'name' => 'BLEXBot',
            'category' => self::CATEGORY_SEO,
            'operator' => 'WebMeUp-seoconsultants',
            'description_ru' => 'Краулер обратных ссылок SEO-сервиса BLEXBot',
            'description_en' => 'BLEXBot backlink discovery crawler',
            'pattern' => 'BLEXBot',
            'default_policy' => self::POLICY_THROTTLE,
            'verification' => self::VERIFY_NONE,
            'documentation_url' => 'https://webmeup-crawler.com/',
        ],
        'dataforseo' => [
            'name' => 'DataForSeoBot',
            'category' => self::CATEGORY_SEO,
            'operator' => 'DataForSEO',
            'description_ru' => 'API-сервис сбора SEO-данных и аудита сайтов DataForSEO',
            'description_en' => 'DataForSEO crawler for SERP and On-Page SEO APIs',
            'pattern' => 'DataForSeoBot',
            'default_policy' => self::POLICY_THROTTLE,
            'verification' => self::VERIFY_RDNS,
            'engine_key' => 'dataforseo',
            'domains' => ['.dataforseo.com'],
            'documentation_url' => 'https://dataforseo.com/dataforseobot',
        ],
        'serpstat' => [
            'name' => 'SerpstatBot',
            'category' => self::CATEGORY_SEO,
            'operator' => 'Serpstat',
            'description_ru' => 'Парсер ключевых слов и ссылочного профиля Serpstat',
            'description_en' => 'Serpstat SEO and competitor analysis crawler',
            'pattern' => 'SerpstatBot',
            'default_policy' => self::POLICY_THROTTLE,
            'verification' => self::VERIFY_NONE,
            'documentation_url' => 'https://serpstat.com/',
        ],
        'ryte' => [
            'name' => 'RyteBot',
            'category' => self::CATEGORY_SEO,
            'operator' => 'Ryte GmbH',
            'description_ru' => 'Платформа мониторинга технического здоровья сайта Ryte',
            'description_en' => 'Ryte website quality and compliance audit crawler',
            'pattern' => 'RyteBot',
            'default_policy' => self::POLICY_THROTTLE,
            'verification' => self::VERIFY_NONE,
            'documentation_url' => 'https://en.ryte.com/',
        ],
        'seekport' => [
            'name' => 'Seekport',
            'category' => self::CATEGORY_SEO,
            'operator' => 'Seekport Internet Technologies',
            'description_ru' => 'Европейский коммерческий поисковый парсер Seekport',
            'description_en' => 'Seekport search indexer',
            'pattern' => 'SeekportBot',
            'default_policy' => self::POLICY_THROTTLE,
            'verification' => self::VERIFY_NONE,
            'documentation_url' => 'https://bot.seekport.com/',
        ],

        // ==========================================
        // 4. Social & Preview Bots (Category D)
        // ==========================================
        'telegram' => [
            'name' => 'TelegramBot',
            'category' => self::CATEGORY_SOCIAL,
            'operator' => 'Telegram FZ-LLC',
            'description_ru' => 'Генерация предпросмотра ссылок (Open Graph) в чатах Telegram',
            'description_en' => 'Telegram link preview unfurling bot',
            'pattern' => 'TelegramBot',
            'default_policy' => self::POLICY_ALLOW,
            'verification' => self::VERIFY_CIDR,
            'cidr_key' => 'telegram',
            'documentation_url' => 'https://core.telegram.org/bots/webhooks',
        ],
        'whatsapp' => [
            'name' => 'WhatsApp',
            'category' => self::CATEGORY_SOCIAL,
            'operator' => 'Meta Platforms (WhatsApp)',
            'description_ru' => 'Формирование превью ссылок в диалогах WhatsApp',
            'description_en' => 'WhatsApp link preview unfurler',
            'pattern' => 'WhatsApp',
            'default_policy' => self::POLICY_ALLOW,
            'verification' => self::VERIFY_NONE,
            'documentation_url' => 'https://www.whatsapp.com/',
        ],
        'meta_facebook' => [
            'name' => 'Facebook / Meta Preview',
            'category' => self::CATEGORY_SOCIAL,
            'operator' => 'Meta Platforms, Inc. (AS32934)',
            'description_ru' => 'Извлечение Open Graph метаданных для постов в Facebook и Instagram',
            'description_en' => 'Meta link preview fetcher for Facebook and Instagram posts',
            'pattern' => 'facebookexternalhit|facebookcatalog|meta-externalfetcher',
            'default_policy' => self::POLICY_ALLOW,
            'verification' => self::VERIFY_CIDR,
            'cidr_key' => 'meta',
            'documentation_url' => 'https://developers.facebook.com/docs/sharing/webmasters/crawler',
        ],
        'twitter' => [
            'name' => 'Twitterbot / X',
            'category' => self::CATEGORY_SOCIAL,
            'operator' => 'X Corp. (formerly Twitter)',
            'description_ru' => 'Парсер Twitter Cards для отображения сниппетов в ленте X (Twitter)',
            'description_en' => 'X (Twitter) Card preview parser',
            'pattern' => 'Twitterbot',
            'default_policy' => self::POLICY_ALLOW,
            'verification' => self::VERIFY_NONE,
            'documentation_url' => 'https://developer.twitter.com/en/docs/twitter-for-websites/cards/guides/getting-started',
        ],
        'linkedin' => [
            'name' => 'LinkedInBot',
            'category' => self::CATEGORY_SOCIAL,
            'operator' => 'LinkedIn Corp. / Microsoft',
            'description_ru' => 'Генерация карточек превью материалов в профессиональной сети LinkedIn',
            'description_en' => 'LinkedIn content card and metadata unfurler',
            'pattern' => 'LinkedInBot',
            'default_policy' => self::POLICY_ALLOW,
            'verification' => self::VERIFY_NONE,
            'documentation_url' => 'https://www.linkedin.com/help/linkedin/answer/a521928',
        ],
        'discord' => [
            'name' => 'Discordbot',
            'category' => self::CATEGORY_SOCIAL,
            'operator' => 'Discord Inc.',
            'description_ru' => 'Сбор Open Graph сниппетов и изображений для сообщений в Discord',
            'description_en' => 'Discord rich link embed generator',
            'pattern' => 'Discordbot',
            'default_policy' => self::POLICY_ALLOW,
            'verification' => self::VERIFY_NONE,
            'documentation_url' => 'https://discord.com/',
        ],
        'slack' => [
            'name' => 'Slackbot',
            'category' => self::CATEGORY_SOCIAL,
            'operator' => 'Slack Technologies / Salesforce',
            'description_ru' => 'Разворачивание ссылок (link unfurling) в каналах Slack',
            'description_en' => 'Slack link preview and metadata expansion bot',
            'pattern' => 'Slackbot|Slackbot-LinkExpanding',
            'default_policy' => self::POLICY_ALLOW,
            'verification' => self::VERIFY_NONE,
            'documentation_url' => 'https://api.slack.com/robots',
        ],
        'vk_share' => [
            'name' => 'VK Share Bot',
            'category' => self::CATEGORY_SOCIAL,
            'operator' => 'VK (VKontakte)',
            'description_ru' => 'Генератор превью для записей и личных сообщений ВКонтакте',
            'description_en' => 'VKontakte social share link preview bot',
            'pattern' => 'vkShare',
            'default_policy' => self::POLICY_ALLOW,
            'verification' => self::VERIFY_CIDR,
            'cidr_key' => 'vk',
            'documentation_url' => 'https://vk.com/dev/share_details',
        ],

        // ==========================================
        // 5. AI Crawlers (Search vs Training - Category E)
        // ==========================================
        'oai_search' => [
            'name' => 'OAI-SearchBot',
            'category' => self::CATEGORY_AI_SEARCH,
            'operator' => 'OpenAI, LLC',
            'description_ru' => 'Поисковый робот поисковых ответов OpenAI с прямыми переходами',
            'description_en' => 'OpenAI search crawler for direct referral search results',
            'pattern' => 'OAI-SearchBot',
            'default_policy' => self::POLICY_ALLOW,
            'verification' => self::VERIFY_NONE,
            'documentation_url' => 'https://platform.openai.com/docs/bots',
        ],
        'chatgpt_user' => [
            'name' => 'ChatGPT-User',
            'category' => self::CATEGORY_AI_SEARCH,
            'operator' => 'OpenAI, LLC',
            'description_ru' => 'Краулер веб-поиска по прямому запросу пользователя в ChatGPT',
            'description_en' => 'Live browsing agent triggered by user queries in ChatGPT',
            'pattern' => 'ChatGPT-User',
            'default_policy' => self::POLICY_ALLOW,
            'verification' => self::VERIFY_NONE,
            'documentation_url' => 'https://platform.openai.com/docs/bots',
        ],
        'perplexity' => [
            'name' => 'PerplexityBot',
            'category' => self::CATEGORY_AI_SEARCH,
            'operator' => 'Perplexity AI, Inc.',
            'description_ru' => 'Поисковый краулер поисковой AI-системы Perplexity с цитированием источников',
            'description_en' => 'Perplexity AI conversational search engine crawler',
            'pattern' => 'PerplexityBot',
            'default_policy' => self::POLICY_ALLOW,
            'verification' => self::VERIFY_NONE,
            'documentation_url' => 'https://docs.perplexity.ai/',
        ],
        'gptbot' => [
            'name' => 'GPTBot',
            'category' => self::CATEGORY_AI_TRAINING,
            'operator' => 'OpenAI, LLC',
            'description_ru' => 'Сборщик текстовых обучающих данных для моделей GPT от OpenAI',
            'description_en' => 'OpenAI AI model pre-training data harvester',
            'pattern' => 'GPTBot',
            'default_policy' => self::POLICY_BLOCK,
            'verification' => self::VERIFY_NONE,
            'documentation_url' => 'https://platform.openai.com/docs/gptbot',
        ],
        'claudebot' => [
            'name' => 'ClaudeBot',
            'category' => self::CATEGORY_AI_TRAINING,
            'operator' => 'Anthropic PBC',
            'description_ru' => 'Парсер веб-страниц компании Anthropic для обучения языковых моделей Claude',
            'description_en' => 'Anthropic Claude LLM training crawler',
            'pattern' => 'ClaudeBot|anthropic-ai|Claude-Web',
            'default_policy' => self::POLICY_BLOCK,
            'verification' => self::VERIFY_NONE,
            'documentation_url' => 'https://support.anthropic.com/en/articles/8896518-does-anthropic-crawl-data-from-the-web-and-how-can-i-block-it',
        ],
        'bytespider' => [
            'name' => 'Bytespider',
            'category' => self::CATEGORY_AI_TRAINING,
            'operator' => 'ByteDance Ltd. (TikTok)',
            'description_ru' => 'Агрессивный краулер ByteDance для обучения китайских языковых моделей',
            'description_en' => 'ByteDance high-volume scraper for LLM training',
            'pattern' => 'Bytespider',
            'default_policy' => self::POLICY_BLOCK,
            'verification' => self::VERIFY_NONE,
            'documentation_url' => 'https://bytedance.com/',
        ],
        'ccbot' => [
            'name' => 'CCBot',
            'category' => self::CATEGORY_AI_TRAINING,
            'operator' => 'Common Crawl Foundation',
            'description_ru' => 'Глобальный сборщик общедоступных веб-архивов Common Crawl',
            'description_en' => 'Common Crawl open web archive dataset scraper',
            'pattern' => 'CCBot',
            'default_policy' => self::POLICY_BLOCK,
            'verification' => self::VERIFY_NONE,
            'documentation_url' => 'https://commoncrawl.org/faq',
        ],
        // Note: Google-Extended is an instruction token for robots.txt only, not an independent HTTP User-Agent crawler.
        // It is intentionally omitted from HTTP classification to guarantee that legitimate Googlebot traffic is never affected.
        'meta_external_agent' => [
            'name' => 'Meta-ExternalAgent',
            'category' => self::CATEGORY_AI_TRAINING,
            'operator' => 'Meta Platforms, Inc.',
            'description_ru' => 'Обучающий парсер Meta для моделей Llama и генеративного интеллекта',
            'description_en' => 'Meta Llama model training scraper',
            'pattern' => 'FacebookBot|Meta-ExternalAgent',
            'default_policy' => self::POLICY_BLOCK,
            'verification' => self::VERIFY_NONE,
            'documentation_url' => 'https://developers.facebook.com/docs/sharing/bot',
        ],
        'amazonbot' => [
            'name' => 'Amazonbot',
            'category' => self::CATEGORY_AI_TRAINING,
            'operator' => 'Amazon.com, Inc.',
            'description_ru' => 'Краулер Amazon для обучения моделей Alexa и сервисов AWS AI',
            'description_en' => 'Amazon Alexa and AWS AI services crawler',
            'pattern' => 'Amazonbot',
            'default_policy' => self::POLICY_BLOCK,
            'verification' => self::VERIFY_NONE,
            'documentation_url' => 'https://developer.amazon.com/amazonbot',
        ],
        'cohere' => [
            'name' => 'cohere-ai',
            'category' => self::CATEGORY_AI_TRAINING,
            'operator' => 'Cohere Inc.',
            'description_ru' => 'Парсер корпоративных языковых моделей Cohere',
            'description_en' => 'Cohere AI enterprise LLM training crawler',
            'pattern' => 'cohere-ai',
            'default_policy' => self::POLICY_BLOCK,
            'verification' => self::VERIFY_NONE,
            'documentation_url' => 'https://cohere.com/',
        ],
        'diffbot' => [
            'name' => 'Diffbot',
            'category' => self::CATEGORY_AI_TRAINING,
            'operator' => 'Diffbot Technologies',
            'description_ru' => 'Коммерческий парсер структуры графа знаний Diffbot Knowledge Graph',
            'description_en' => 'Diffbot AI knowledge graph and structural extractor',
            'pattern' => 'Diffbot',
            'default_policy' => self::POLICY_BLOCK,
            'verification' => self::VERIFY_NONE,
            'documentation_url' => 'https://www.diffbot.com/',
        ],
        'timpibot' => [
            'name' => 'Timpibot',
            'category' => self::CATEGORY_AI_TRAINING,
            'operator' => 'Timpi Ltd.',
            'description_ru' => 'Децентрализованный сборщик веб-индекса Timpi',
            'description_en' => 'Timpi decentralized web index collector',
            'pattern' => 'Timpibot',
            'default_policy' => self::POLICY_BLOCK,
            'verification' => self::VERIFY_NONE,
            'documentation_url' => 'https://timpi.io/',
        ],
    ];

    /**
     * Known official subnets for advertising platforms that publish fixed CIDRs.
     */
    public const CRITEO_CIDRS = [
        '178.250.0.0/21',
        '185.235.84.0/22',
        '91.212.98.0/24',
        '91.199.242.0/24',
        '74.119.116.0/22',
        '199.204.168.0/22',
        '116.213.20.0/22',
        '182.161.72.0/22',
        '2a02:2638::/32',
        '2620:100:a000::/44',
        '2406:2600::/32',
    ];

    public const QUANTCAST_IPS = [
        '52.34.88.131',
        '54.71.154.151',
        '192.184.74.253',
        '192.184.75.253',
        '192.184.76.253',
        '192.184.77.253',
        '103.229.11.253',
    ];

    /**
     * Meta / Facebook crawler IPv4/IPv6 subnets (AS32934).
     */
    public const META_CIDRS = [
        '31.13.24.0/21',
        '31.13.64.0/18',
        '31.13.64.0/19',
        '31.13.65.0/24',
        '31.13.66.0/24',
        '31.13.67.0/24',
        '31.13.68.0/24',
        '31.13.69.0/24',
        '31.13.70.0/24',
        '31.13.71.0/24',
        '31.13.72.0/24',
        '31.13.73.0/24',
        '31.13.74.0/24',
        '31.13.75.0/24',
        '31.13.76.0/24',
        '31.13.77.0/24',
        '31.13.78.0/24',
        '31.13.79.0/24',
        '31.13.80.0/24',
        '31.13.81.0/24',
        '31.13.82.0/24',
        '31.13.83.0/24',
        '31.13.84.0/24',
        '31.13.85.0/24',
        '31.13.86.0/24',
        '31.13.87.0/24',
        '31.13.88.0/24',
        '31.13.89.0/24',
        '31.13.90.0/24',
        '31.13.91.0/24',
        '31.13.92.0/24',
        '31.13.93.0/24',
        '31.13.94.0/24',
        '31.13.95.0/24',
        '31.13.96.0/19',
        '45.64.40.0/22',
        '66.220.144.0/20',
        '69.63.176.0/20',
        '69.171.224.0/19',
        '74.119.76.0/22',
        '103.4.96.0/22',
        '129.134.0.0/16',
        '147.75.208.0/20',
        '157.240.0.0/16',
        '173.252.64.0/18',
        '179.60.192.0/22',
        '185.60.216.0/22',
        '185.89.216.0/22',
        '199.201.64.0/22',
        '204.15.20.0/22',
        '2a03:2880::/32',
    ];

    /**
     * Official OpenAI AdsBot subnets for ChatGPT Ads validation (published via https://openai.com/adsbot.json).
     */
    public const OPENAI_ADS_CIDRS = [
        '130.131.229.128/25',
        '20.168.90.128/25',
    ];

    /**
     * Official Telegram webhook & crawler subnets (AS62041 / AS44907).
     */
    public const TELEGRAM_CIDRS = [
        '91.108.4.0/22',
        '91.108.8.0/22',
        '91.108.12.0/22',
        '91.108.16.0/22',
        '91.108.20.0/22',
        '91.108.56.0/22',
        '149.154.160.0/20',
        '2001:b88::/32',
    ];

    /**
     * Official VKontakte (VK) crawler subnets (AS47541 / AS47542).
     */
    public const VK_CIDRS = [
        '87.240.128.0/18',
        '93.186.224.0/20',
        '95.142.192.0/20',
        '185.32.187.0/24',
        '2a00:bdc0::/32',
    ];

    /**
     * Returns full catalog of registered bots.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getAll(): array
    {
        return self::$catalog;
    }

    /**
     * Returns metadata for a specific bot key.
     *
     * @param string $key
     * @return array<string, mixed>|null
     */
    public static function get(string $key): ?array
    {
        return self::$catalog[$key] ?? null;
    }

    /**
     * Returns all bots in a specific category.
     *
     * @param string $category
     * @return array<string, array<string, mixed>>
     */
    public static function getByCategory(string $category): array
    {
        $result = [];
        foreach (self::$catalog as $key => $data) {
            if ($data['category'] === $category) {
                $result[$key] = $data;
            }
        }
        return $result;
    }

    /**
     * Identifies a bot from the given User-Agent string.
     *
     * @param string $userAgent
     * @return array{key: string, data: array<string, mixed>}|null
     */
    public static function identify(string $userAgent): ?array
    {
        $ua = trim($userAgent);
        if ($ua === '') {
            return null;
        }

        foreach (self::$catalog as $key => $data) {
            if (preg_match('/' . $data['pattern'] . '/i', $ua)) {
                return [
                    'key' => $key,
                    'data' => $data,
                ];
            }
        }

        return null;
    }

    /**
     * Returns the list of categories with localized labels.
     *
     * @param string $lang
     * @return array<string, string>
     */
    public static function getCategoryLabels(string $lang = 'ru'): array
    {
        $isRu = str_starts_with(strtolower($lang), 'ru');

        return [
            self::CATEGORY_SEARCH => $isRu ? 'Поисковые системы (Search)' : 'Search Engines',
            self::CATEGORY_AD => $isRu ? 'Рекламные и партнерские боты (Advertising)' : 'Advertising & Marketing Bots',
            self::CATEGORY_SEO => $isRu ? 'SEO и аналитические краулеры (SEO & Analytics)' : 'SEO & Analytics Crawlers',
            self::CATEGORY_SOCIAL => $isRu ? 'Социальные сети и мессенджеры (Social Preview)' : 'Social & Messaging Previews',
            self::CATEGORY_AI_SEARCH => $isRu ? 'Поисковые AI-боты (AI Search)' : 'AI Search & Referral Bots',
            self::CATEGORY_AI_TRAINING => $isRu ? 'Обучающие AI-краулеры (AI Training)' : 'AI Training Scrapers',
        ];
    }

    /**
     * Returns the recommended default policy for each category.
     *
     * @return array<string, string>
     */
    public static function getDefaultCategoryPolicies(): array
    {
        return [
            self::CATEGORY_SEARCH => self::POLICY_ALLOW,
            self::CATEGORY_AD => self::POLICY_ALLOW,
            self::CATEGORY_SEO => self::POLICY_THROTTLE,
            self::CATEGORY_SOCIAL => self::POLICY_ALLOW,
            self::CATEGORY_AI_SEARCH => self::POLICY_ALLOW,
            self::CATEGORY_AI_TRAINING => self::POLICY_BLOCK,
        ];
    }
}
