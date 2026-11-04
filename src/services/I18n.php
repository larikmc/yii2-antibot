<?php

declare(strict_types=1);

namespace larikmc\antibot\services;

use Yii;

/**
 * Internationalization & localization service for AntiBot dashboard, logs, and analytics.
 *
 * Supports seamless bilingual operation (Russian & English):
 * - Automatic detection from Yii::$app->language (defaults to Russian for ru/ru-RU).
 * - Human-friendly Russian translations for all technical security reasons and actions.
 * - Interactive language switching on admin dashboard.
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class I18n
{
    public const LANG_RU = 'ru';
    public const LANG_EN = 'en';

    /**
     * Engine names localized in Russian.
     */
    public const ENGINES_RU = [
        'yandex' => 'Яндекс',
        'google' => 'Google',
        'bing' => 'Bing',
        'mailru' => 'Mail.ru',
        'baidu' => 'Baidu',
        'apple' => 'Apple',
        'yahoo' => 'Yahoo',
        'duckduckgo' => 'DuckDuckGo',
    ];

    /**
     * Resolves active language ('ru' or 'en').
     */
    public static function getLanguage(): string
    {
        // 1. Session override
        if (Yii::$app !== null && Yii::$app->has('session', true)) {
            $sessLang = Yii::$app->session->get('antibot_language');
            if (in_array($sessLang, [self::LANG_RU, self::LANG_EN], true)) {
                return $sessLang;
            }
        }

        // 2. Component configuration override
        if (Yii::$app !== null && Yii::$app->has('antibot', true)) {
            $antibot = Yii::$app->get('antibot');
            if (isset($antibot->language) && in_array($antibot->language, [self::LANG_RU, self::LANG_EN], true)) {
                return $antibot->language;
            }
        }

        // 3. Application language
        if (Yii::$app !== null && !empty(Yii::$app->language)) {
            $appLang = strtolower((string) Yii::$app->language);
            if (preg_match('/^(ru|be|uk|kk)/', $appLang)) {
                return self::LANG_RU;
            }
        }

        return self::LANG_RU; // Default to Russian
    }

    /**
     * Checks if current language is Russian.
     */
    public static function isRussian(?string $lang = null): bool
    {
        return ($lang ?? self::getLanguage()) === self::LANG_RU;
    }

    /**
     * Formats an action code into a localized label, badge class, icon, and description.
     *
     * @param string $action
     * @param string|null $lang
     * @return array{code: string, label: string, badgeClass: string, icon: string, description: string}
     */
    public static function formatAction(string $action, ?string $lang = null): array
    {
        $actionLower = strtolower(trim($action));
        $isRu = self::isRussian($lang);

        return match ($actionLower) {
            'monitored' => [
                'code' => 'monitored',
                'label' => $isRu ? 'Мониторинг' : 'Monitored',
                'badgeClass' => 'bg-info bg-opacity-10 text-info border border-info border-opacity-25',
                'icon' => '👁️',
                'description' => $isRu ? 'Событие зафиксировано в журнале без блокировки (режим мониторинга)' : 'Event recorded in journal without blocking (monitoring mode)',
            ],
            'blocked' => [
                'code' => 'blocked',
                'label' => $isRu ? 'Заблокирован' : 'Blocked',
                'badgeClass' => 'bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25',
                'icon' => '⛔',
                'description' => $isRu ? 'Запрос отклонён с кодом 403 Forbidden' : 'Request blocked with 403 Forbidden',
            ],
            'throttled' => [
                'code' => 'throttled',
                'label' => $isRu ? 'Лимит превышен' : 'Throttled',
                'badgeClass' => 'bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25',
                'icon' => '⏱️',
                'description' => $isRu ? 'Превышен лимит частоты запросов (Rate Limit 429)' : 'Rate limit exceeded (HTTP 429)',
            ],
            'allowed_search_bot' => [
                'code' => 'allowed_search_bot',
                'label' => $isRu ? 'Поисковик (OK)' : 'Search Engine',
                'badgeClass' => 'bg-success bg-opacity-10 text-success border border-success border-opacity-25',
                'icon' => '🤖',
                'description' => $isRu ? 'Подлинный проверенный поисковый робот (пропущен без ограничений)' : 'Verified legitimate search engine bot (whitelisted)',
            ],
            'challenge_passed' => [
                'code' => 'challenge_passed',
                'label' => $isRu ? 'Проверка пройдена' : 'Challenge Passed',
                'badgeClass' => 'bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25',
                'icon' => '✅',
                'description' => $isRu ? 'Браузерная проверка успешно решена (JS Challenge)' : 'Browser challenge solved successfully (JS Challenge)',
            ],
            'challenged' => [
                'code' => 'challenged',
                'label' => $isRu ? 'Запрос проверки' : 'Challenge Issued',
                'badgeClass' => 'bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25',
                'icon' => '🛡️',
                'description' => $isRu ? 'Клиенту выдана страница проверки браузера' : 'Client presented with browser challenge verification',
            ],
            'pow_solved' => [
                'code' => 'pow_solved',
                'label' => $isRu ? 'PoW решён' : 'PoW Solved',
                'badgeClass' => 'bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25',
                'icon' => '⚡',
                'description' => $isRu ? 'Криптографическое вычисление Proof-of-Work подтверждено' : 'Proof-of-Work cryptographic calculation verified',
            ],
            default => [
                'code' => $actionLower,
                'label' => strtoupper($actionLower),
                'badgeClass' => 'bg-light text-muted border',
                'icon' => '•',
                'description' => $action,
            ],
        };
    }

    /**
     * Translates and formats a raw reason string into clean, human-friendly text.
     *
     * @param string $reason
     * @param string|null $lang
     * @return array{title: string, badge: string, raw: string}
     */
    public static function formatReason(string $reason, ?string $lang = null): array
    {
        $raw = trim($reason);
        if ($raw === '') {
            return [
                'title' => self::isRussian($lang) ? 'Причина не указана' : 'Unknown reason',
                'badge' => self::isRussian($lang) ? 'Неизвестно' : 'Unknown',
                'raw' => $raw,
            ];
        }

        $isRu = self::isRussian($lang);

        // 1. Fake search bot spoofing Engine
        if (preg_match('/^Fake search bot spoofing\s+(.+)$/i', $raw, $m)) {
            $engineName = trim($m[1]);
            $engineLocal = $isRu ? (self::ENGINES_RU[strtolower($engineName)] ?? $engineName) : $engineName;
            return [
                'title' => $isRu ? "Поддельный бот (выдаёт себя за {$engineLocal})" : "Fake search bot spoofing {$engineName}",
                'badge' => $isRu ? 'Фейк-бот' : 'Fake Bot',
                'raw' => $raw,
            ];
        }

        // 2. Authentic Search Engine: Engine
        if (preg_match('/^Authentic Search Engine:\s*(.+)$/i', $raw, $m)) {
            $engineName = trim($m[1]);
            $engineLocal = $isRu ? (self::ENGINES_RU[strtolower($engineName)] ?? $engineName) : $engineName;
            return [
                'title' => $isRu ? "Подлинный поисковый робот: {$engineLocal}" : "Authentic Search Engine: {$engineName}",
                'badge' => $isRu ? 'Поисковик' : 'Search Engine',
                'raw' => $raw,
            ];
        }

        // 3. Commercial scraper (blocked / monitored)
        if (preg_match('/^Commercial scraper\s+(?:blocked|monitored):\s*(.+)$/i', $raw, $m)) {
            $bot = trim($m[1]);
            return [
                'title' => $isRu ? "Коммерческий парсер / скрапер: {$bot}" : "Commercial scraper: {$bot}",
                'badge' => $isRu ? 'Скрапер' : 'Scraper',
                'raw' => $raw,
            ];
        }

        // 4. Automated CLI or HTTP library
        if (preg_match('/^Automated CLI or HTTP library:\s*(.+)$/i', $raw, $m)) {
            $lib = trim($m[1]);
            return [
                'title' => $isRu ? "Автоматическая утилита / скрипт: {$lib}" : "Automated CLI / HTTP library: {$lib}",
                'badge' => $isRu ? 'CLI / Скрипт' : 'CLI / Script',
                'raw' => $raw,
            ];
        }

        // 5. AI crawler (blocked / monitored / throttled)
        if (preg_match('/^AI crawler\s+(?:blocked|monitored|throttled):\s*(.+)$/i', $raw, $m)) {
            $ai = trim($m[1]);
            return [
                'title' => $isRu ? "AI-краулер (нейросеть): {$ai}" : "AI crawler / LLM: {$ai}",
                'badge' => $isRu ? 'AI-бот' : 'AI Crawler',
                'raw' => $raw,
            ];
        }

        // 6. Empty or missing User-Agent header
        if (preg_match('/^Empty or missing User-Agent/i', $raw)) {
            return [
                'title' => $isRu ? 'Пустой или отсутствующий заголовок User-Agent' : 'Empty or missing User-Agent header',
                'badge' => $isRu ? 'Аномалия' : 'Anomaly',
                'raw' => $raw,
            ];
        }

        // 7. JS Challenge Solved
        if (preg_match('/^JS Challenge Solved/i', $raw)) {
            return [
                'title' => $isRu ? 'Браузерная проверка успешно решена (JS Challenge)' : 'Browser challenge solved (JS Challenge)',
                'badge' => $isRu ? 'Проверка OK' : 'Challenge OK',
                'raw' => $raw,
            ];
        }

        // 8. JS Challenge verification failed or replayed
        if (preg_match('/^JS Challenge verification failed/i', $raw)) {
            return [
                'title' => $isRu ? 'Ошибка JS-проверки или повторно использованный токен' : 'JS challenge verification failed or replayed',
                'badge' => $isRu ? 'Сбой проверки' : 'Challenge Fail',
                'raw' => $raw,
            ];
        }

        // 9. Rate limit exceeded
        if (preg_match('/^Rate limit exceeded(?:\s*\((.+)\))?/i', $raw, $m)) {
            $detail = !empty($m[1]) ? " ({$m[1]})" : '';
            return [
                'title' => $isRu ? "Превышен лимит частоты запросов{$detail}" : "Rate limit exceeded{$detail}",
                'badge' => $isRu ? 'Лимит' : 'Rate Limit',
                'raw' => $raw,
            ];
        }

        // 10. Weighted budget exceeded
        if (preg_match('/^Weighted budget exceeded(?:\s*\((.+)\))?/i', $raw, $m)) {
            $detail = !empty($m[1]) ? " ({$m[1]})" : '';
            return [
                'title' => $isRu ? "Превышен взвешенный бюджет нагрузки{$detail}" : "Weighted budget exceeded{$detail}",
                'badge' => $isRu ? 'Бюджет' : 'Budget',
                'raw' => $raw,
            ];
        }

        // 11. Honey trap URL visited
        if (preg_match('/^Honey trap URL visited/i', $raw)) {
            return [
                'title' => $isRu ? 'Переход по скрытой ссылке-ловушке (HoneyTrap)' : 'Hidden trap URL visited (HoneyTrap)',
                'badge' => $isRu ? 'Ловушка' : 'Trap',
                'raw' => $raw,
            ];
        }

        // 12. Honeypot form submission
        if (preg_match('/^Honeypot form (?:submission|field filled)/i', $raw)) {
            return [
                'title' => $isRu ? 'Заполнение скрытого спам-поля формы (Honeypot)' : 'Hidden form honeypot field filled',
                'badge' => $isRu ? 'Спам-форма' : 'Honeypot',
                'raw' => $raw,
            ];
        }

        // 13. Behavioral anomaly
        if (preg_match('/^Behavioral anomaly:\s*(.+)$/i', $raw, $m)) {
            $bType = strtolower(trim($m[1]));
            $bDesc = match (true) {
                str_contains($bType, 'high frequency') || str_contains($bType, 'burst') => $isRu ? 'серия быстрых запросов (флуд)' : 'high frequency burst',
                str_contains($bType, 'rapid navigation') => $isRu ? 'неестественно быстрые переходы по сайту' : 'rapid page navigation',
                str_contains($bType, 'scanning') || str_contains($bType, 'url') => $isRu ? 'перебор несуществующих страниц (сканирование)' : 'URL scanning',
                default => $m[1],
            };

            return [
                'title' => $isRu ? "Поведенческая аномалия: {$bDesc}" : "Behavioral anomaly: {$bDesc}",
                'badge' => $isRu ? 'Поведение' : 'Behavior',
                'raw' => $raw,
            ];
        }

        // 14. Blacklisted IP address
        if (preg_match('/^Blacklisted IP|Manual IP blacklist/i', $raw)) {
            return [
                'title' => $isRu ? 'IP-адрес находится в чёрном списке' : 'IP address is in blacklist',
                'badge' => $isRu ? 'Чёрный список' : 'Blacklist',
                'raw' => $raw,
            ];
        }

        // 15. Whitelisted IP address
        if (preg_match('/^Whitelisted IP/i', $raw)) {
            return [
                'title' => $isRu ? 'IP-адрес в белом списке' : 'IP address in whitelist',
                'badge' => $isRu ? 'Белый список' : 'Whitelist',
                'raw' => $raw,
            ];
        }

        // 16. PoW challenge solved
        if (preg_match('/^PoW challenge solved/i', $raw)) {
            return [
                'title' => $isRu ? 'Криптографическая проверка Proof-of-Work решена' : 'Proof-of-Work challenge solved',
                'badge' => $isRu ? 'PoW OK' : 'PoW OK',
                'raw' => $raw,
            ];
        }

        // 17. Access Denied: Automated bot activity detected.
        if (preg_match('/^Access Denied/i', $raw)) {
            return [
                'title' => $isRu ? 'Доступ запрещён: обнаружена активность бота' : 'Access Denied: Automated bot activity detected',
                'badge' => $isRu ? 'Блокировка' : 'Blocked',
                'raw' => $raw,
            ];
        }

        // Fallback: return raw string with generic badge
        return [
            'title' => $raw,
            'badge' => $isRu ? 'Событие' : 'Event',
            'raw' => $raw,
        ];
    }
}
