<?php

declare(strict_types=1);

namespace larikmc\antibot\services;

use Yii;

/**
 * Internationalization & localization service for AntiBot dashboard, logs, and analytics.
 *
 * Supports seamless bilingual operation (Russian & English):
 * - Automatic detection strictly from framework Yii::$app->language (Russian for ru/ru-RU, English for en/en-US, etc.).
 * - Human-friendly translations for all technical security reasons, actions, and dashboard UI strings.
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
     * Full bilingual translation dictionary for AntiBot interface.
     */
    public const MESSAGES = [
        'ru' => [
            'app_title' => 'Антибот — Мониторинг и визуальная аналитика',
            'diagnostics_title' => 'Антибот — Диагностика и Советник правил',
            'settings_title' => 'Антибот — Настройки правил',
            'ip_list_title' => 'Антибот — Списки IP адресов',
            'tab_monitoring' => '📊 Мониторинг и Журнал',
            'tab_diagnostics' => '🩺 Диагностика и Советник',
            'tab_settings' => '⚙️ Настройки правил',
            'tab_iplist' => '📋 Списки IP (White/Black)',
            'nav_back_to_monitoring' => '← В мониторинг',
            'header_title' => 'Антибот-защита',
            'header_desc' => 'Автономный модуль поведенческой защиты, фильтрации ботов, верификации поисковиков и аналитики',
            'header_desc_diagnostics' => 'Самодиагностика компонентов, рекомендации советника, симулятор правил и аудит изменений',
            'header_desc_iplist' => 'Белые списки, черные списки и диапазоны доверенных прокси (поддерживается нотация CIDR)',
            'header_desc_settings' => 'Управление сигнатурами, фильтрами поисковиков, AI-парсерами и лимитами',
            'clear_log_btn' => '🗑️ Очистить журнал',
            'clear_log_confirm' => 'Вы уверены, что хотите очистить журнал событий?',
            'settings_btn' => '⚙️ Настройки правил',
            'status_module' => 'Статус модуля:',
            'status_active' => '● Активен',
            'status_disabled' => '○ Отключен',
            'mode_label' => 'Режим:',
            'mode_monitoring' => 'Мониторинг',
            'mode_standard' => 'Стандартный',
            'mode_strict' => 'Строгий (Блокировка)',
            // KPIs
            'kpi_inspected' => 'Проинспектировано',
            'kpi_total_requests' => 'Всего запросов',
            'kpi_events' => 'Событий в журнале',
            'kpi_last_24h' => 'За 24 часа',
            'kpi_blocked' => 'Заблокировано (403)',
            'kpi_in_monitoring' => 'в монит.',
            'kpi_search_bots' => 'Поисковики (SEO)',
            'kpi_search_desc' => 'Яндекс, Google, Bing',
            // Charts
            'chart_dynamics_title' => '📈 Динамика подозрительной активности',
            'chart_dynamics_desc' => 'События журнала по времени (при simulated: true действия отображаются как мониторинг без блокировки)',
            'period_24h' => '24 часа',
            'period_7d' => '7 дней',
            'period_all' => 'Вся история',
            'btn_refresh' => 'Обновить',
            'badge_full_window' => 'Полное окно',
            'badge_events_processed' => 'Учтено событий:',
            'badge_generated_at' => 'Сформировано:',
            'chart_disclaimer' => 'ℹ️ События журнала, а не весь HTTP-трафик. Статистика Best-Effort.',
            'chart_dist_title' => '🍩 Распределение действий',
            'chart_dist_desc' => 'Доля категорий в учтённых событиях журнала',
            'chart_reasons_title' => '📊 Основные причины срабатываний',
            'chart_reasons_desc' => 'Топ категорий детекции (с понятными описаниями)',
            'chart_routes_title' => '🎯 Самые атакуемые маршруты (Top-10 URL)',
            'chart_routes_desc' => 'Маршруты с наибольшим количеством подозрительных запросов (query string и токены удалены)',
            // Table
            'table_title' => 'Журнал последних событий (Live Feed)',
            'table_desc' => 'Зафиксированные подозрительные обращения',
            'table_empty' => 'Журнал событий пуст. Активность ботов пока не фиксировалась.',
            'th_time' => 'Время',
            'th_ip' => 'IP адрес',
            'th_action' => 'Действие',
            'th_reason' => 'Причина',
            'th_ua' => 'User-Agent',
            'th_actions' => 'Действия',
            'btn_ban' => 'Бан',
            'btn_whitelist' => 'В белый',
            'ban_ip_title' => 'Заблокировать этот IP',
            'whitelist_ip_title' => 'Добавить в белый список',
            // Alerts
            'alert_sec_warning_title' => 'Предупреждение безопасности доступа:',
            'alert_sec_warning_text' => 'Модуль настроен с ролью [\'@\'] (доступен любому авторизованному клиенту). Рекомендуется настроить RBAC-разрешение antibot.manage или ограничить роли: \'accessRoles\' => [\'admin\'].',
            'alert_secret_warning_title' => 'Предупреждение ключа подписи:',
            'alert_secret_warning_text' => 'Не задан собственный $secretKey антибота. Используется автоматически сгенерированный локальный ключ.',
            'alert_disabled_title' => 'Модуль защиты выключен:',
            'alert_disabled_text' => 'Трафик не фильтруется. Перейдите в настройки правил для включения.',
            'btn_enable_protection' => 'Включить защиту →',
            'alert_monitoring_title' => 'Режим мониторинга (Monitoring):',
            'alert_monitoring_text' => 'Подозрительные боты и скраперы фиксируются в журнале, но запросы не блокируются (HTTP 200). Безопасное тестирование без риска для посетителей сайта.',
            'alert_strict_title' => 'Строгий режим (Strict):',
            'alert_strict_text' => 'Включена повышенная строгость фильтрации с обязательным превентивным отсечением подозрительных клиентов.',
            'btn_change_mode' => 'Изменить режим →',
            // Diagnostics
            'diag_selftest_title' => '🩺 Состояние системы и самодиагностика (Self-Test)',
            'diag_selftest_desc' => 'Автоматическая проверка прав записи, сетевых DNS-резолверов, целостности ключей и адаптеров',
            'diag_system_ok' => 'Система исправна',
            'diag_system_warning' => 'Предупреждение',
            'diag_system_fail' => 'Требует внимания',
            'diag_recommendation' => 'Рекомендация:',
            'diag_advisor_title' => '💡 Эвристический советник правил (Rule Advisor)',
            'diag_advisor_desc' => 'Интеллектуальный анализ трафика и рекомендации по безопасности',
            'diag_advisor_what' => 'Что это такое: Советник непрерывно анализирует реальные обращения в журнале событий. Если он замечает подозрительные сканеры, агрессивных ботов или незащищенные маршруты, он предлагает готовые решения для включения в один клик без риска сломать сайт.',
            'diag_advisor_optimal_title' => 'Текущие правила оптимальны!',
            'diag_advisor_optimal_desc' => 'Советник проверил журнал событий: аномалий и незаблокированных угроз не обнаружено. Все политики безопасности работают в штатном режиме.',
            'diag_apply_rec_btn' => 'Применить рекомендацию',
            'diag_apply_rec_confirm' => 'Применить это правило к конфигурации сайта?',
            'diag_simulator_title' => '🧪 Симулятор правил ("Что-Если" анализ)',
            'diag_simulator_desc' => 'Тестируйте гипотетические правила на реальной истории трафика без риска для посетителей',
            'diag_simulator_how' => 'Как работает симулятор: Вы можете задать экспериментальный лимит (например, максимум 15 запросов за минуту или блокировку скрипта curl) и проверить, сколько реальных посетителей или ботов попали бы под ограничение. Ваши текущие рабочие настройки при этом не меняются.',
            'diag_sim_check_type' => 'Тип проверки:',
            'diag_sim_opt_ratelimit' => 'Частота запросов (Rate Limit)',
            'diag_sim_opt_ua' => 'Маска User-Agent',
            'diag_sim_max_or_ua' => 'Макс. запросов / Шаблон UA:',
            'diag_sim_window' => 'Окно (сек):',
            'diag_sim_run_btn' => 'Запустить тест',
            'diag_sim_res_title' => 'Результат анализа:',
            'diag_sim_sample' => 'Выборка:',
            'diag_sim_would_block' => 'Было бы заблокировано:',
            'diag_sim_running' => 'Выполняется симуляция...',
            'diag_sim_step1' => 'Чтение и сортировка журнала событий...',
            'diag_sim_step2' => 'Симуляция правила и временных интервалов...',
            'diag_sim_step3' => 'Проверка исключений для поисковых ботов (Яндекс / Google)...',
            'diag_sim_affected_ips' => 'Затронутые IP:',
            'diag_sim_affected_routes' => 'Затронутые URL:',
            'diag_sim_search_protected' => '0 (Защищены)',
            'diag_sim_retest_btn' => 'Повторить тест',
            'diag_sim_safe_badge' => 'Безопасно для посетителей',
            'diag_sim_mild_badge' => 'Минимальное влияние',
            'diag_sim_mod_badge' => 'Умеренный риск',
            'diag_sim_risk_badge' => 'Высокий риск блокировок',
            'diag_perf_title' => '⏱️ Производительность проверок',
            'diag_perf_desc' => 'Накладные расходы антибота на каждый HTTP-запрос',
            'diag_perf_avg' => 'Среднее время',
            'diag_perf_p95' => 'p95 перцентиль',
            'diag_perf_ms' => 'мс',
            'diag_perf_total' => 'Всего проинспектировано:',
            'diag_perf_allowed' => 'Пропущено легитимных:',
            'diag_perf_adapter' => 'Адаптер Rate Limit:',
            'diag_perf_window' => 'Окно лимитирования:',
            'diag_perf_sec' => 'сек.',
            'diag_audit_title' => '📜 Журнал изменений правил (Audit Trail)',
            'diag_audit_desc' => 'История сохранения настроек и применения рекомендаций',
            'diag_audit_empty' => 'История изменений пока пуста. Настройки не менялись.',
            'diag_audit_th_time' => 'Время',
            'diag_audit_th_ip' => 'IP автора',
            'diag_audit_th_action' => 'Действие',
            // IP List
            'iplist_header_title' => 'Списки IP адресов и подсетей',
            'iplist_whitelist_title' => 'Белый список (Whitelist)',
            'iplist_whitelist_desc' => 'Запросы с этих IP пропускаются без проверок',
            'iplist_whitelist_active' => 'Активные доверенные адреса:',
            'iplist_whitelist_empty' => 'Список пуст.',
            'iplist_blacklist_title' => 'Черный список (Blacklist)',
            'iplist_blacklist_desc' => 'Запросы с этих IP немедленно блокируются (403)',
            'iplist_blacklist_active' => 'Заблокированные адреса:',
            'iplist_blacklist_empty' => 'Список пуст. Ни один IP пока не заблокирован вручную.',
            'iplist_proxies_title' => 'Доверенные прокси',
            'iplist_proxies_desc' => 'Разрешает чтение X-Forwarded-For и CF-Connecting-IP',
            'iplist_proxies_active' => 'Активные прокси-узлы:',
            'iplist_proxies_empty' => 'Список пуст. Заголовки прокси игнорируются для защиты от подмены IP.',
            'iplist_proxies_autotrust' => 'Авто-доверие локальному прокси активно:',
            'iplist_proxies_autotrust_desc' => 'Локальные адреса (127.0.0.1, ::1) распознаются автоматически.',
            'iplist_add_btn' => '+ Добавить',
            'iplist_del_btn' => 'Удалить',
            'iplist_del_confirm_white' => 'Удалить {entry} из белого списка?',
            'iplist_del_confirm_black' => 'Удалить {entry} из черного списка?',
            'iplist_del_confirm_proxy' => 'Удалить {entry} из списка прокси?',
            // Settings
            'settings_header_title' => 'Настройки правил защиты',
            'settings_save_btn' => '💾 Сохранить настройки',
            'settings_bar_title' => '⚙️ Конфигурация защиты',
            'settings_bar_desc' => '— изменения вступают в силу немедленно',
            'settings_sec_1' => '1. Основной режим работы',
            'settings_sec_1_badge' => 'Базовый профиль',
            'settings_enabled_label' => 'Включить фильтрацию трафика Антибот',
            'settings_enabled_desc' => 'Глобальный мастер-тумблер модуля антибот-защиты.',
            'settings_strictness_label' => 'Уровень строгости защиты:',
            'settings_mode_monitoring_desc' => 'Только аудит и лог. Никаких блокировок для проверки настроек.',
            'settings_mode_standard_desc' => 'Рекомендуется. Блокирует парсеры, AI-ботов и фейковые поисковики.',
            'settings_mode_strict_desc' => 'JS-челлендж для всех браузеров перед допуском к контенту.',
            'settings_sec_2' => '2. Верификация поисковиков (SEO-защита)',
            'settings_verify_search' => 'Верифицировать реальные поисковые роботы',
            'settings_verify_search_desc' => 'Двусторонняя Reverse DNS (PTR + Forward) проверка ботов Яндекс, Google, Bing, Mail.ru. Легитимные поисковики никогда не блокируются.',
            'settings_block_fake' => 'Блокировать поддельных поисковых роботов (Fake Search Bots)',
            'settings_block_fake_desc' => 'Если бот маскируется под Googlebot или YandexBot с постороннего IP-адреса, он блокируется с 403 Forbidden. Ошибки DNS трактуются безопасно как unknown.',
            'settings_sec_3' => '3. Политики для ИИ-краулеров (AI Bots)',
            'settings_block_ai' => 'Глобальная блокировка скраперов для обучения ИИ',
            'settings_block_ai_desc' => 'Базовое правило для всех ненастроенных индивидуально скраперов.',
            'settings_ai_table_bot' => 'Бот / Система',
            'settings_ai_table_type' => 'Тип',
            'settings_ai_table_policy' => 'Политика',
            'settings_ai_type_search' => 'Поисковый',
            'settings_ai_type_training' => 'Обучение',
            'settings_ai_allow' => 'Разрешить (Allow)',
            'settings_ai_monitor' => 'Мониторить (Monitor)',
            'settings_ai_throttle' => 'Ограничивать (Throttle)',
            'settings_ai_block' => 'Блокировать (Block)',
            'settings_sec_4' => '4. Коммерческие скраперы и CLI-утилиты',
            'settings_block_scrapers' => 'Блокировать коммерческие SEO-парсеры',
            'settings_block_scrapers_desc' => 'AhrefsBot, SemrushBot, DotBot, MJ12bot, MegaIndex и аналогичные тяжелые скраперы.',
            'settings_block_cli' => 'Блокировать CLI-утилиты и скрипты',
            'settings_block_cli_desc' => 'cURL, Wget, Python-requests, Scrapy, Guzzle, Go-http-client и клиенты без User-Agent.',
            'settings_sec_5' => '5. Поведенческий анализ (Behavior Intelligence)',
            'settings_behavior' => 'Включить поведенческий анализ активности',
            'settings_behavior_desc' => 'Оценивает последовательность запросов, интервалы между кликами, аномальное разнообразие URL, частый поиск и всплески ошибок. Включает эвристический RiskEngine.',
            'settings_sec_6' => '6. Ограничение частоты (Rate Limiter)',
            'settings_ratelimit_enable' => 'Включить Rate Limiter',
            'settings_adapter_label' => 'Адаптер хранилища:',
            'settings_adapter_cache' => 'CacheLimiter (Стандартный без Redis)',
            'settings_adapter_redis' => 'RedisLimiter (Атомарный Lua, при наличии Redis)',
            'settings_adapter_desc' => 'CacheLimiter не создает блокировок и предотвращает зависание PHP.',
            'settings_ratelimit_max' => 'Максимум запросов:',
            'settings_ratelimit_window' => 'Окно подсчета (секунды):',
            'settings_ratelimit_ban' => 'Время временного бана (секунды):',
            'settings_ratelimit_ban_desc' => 'Применяется при систематическом флуде (0 = без бана).',
            'settings_sec_7' => '7. Бюджет нагрузки (Weighted Limiter)',
            'settings_weighted_enable' => 'Учитывать вес операций (баллы)',
            'settings_weighted_budget' => 'Лимит баллов (бюджет на окно):',
            'settings_weighted_budget_desc' => 'Обычная страница: 1 балл, поиск: 5 баллов, тяжелый фильтр: 8-10 баллов.',
            'settings_weighted_window' => 'Окно бюджета (секунды):',
            'settings_sec_8' => '8. Доверенные прокси (Proxies)',
            'settings_proxy_autotrust' => 'Автоматически доверять локальным reverse proxy (127.0.0.1, SERVER_ADDR)',
            'settings_proxy_autotrust_desc' => 'Рекомендуется для Nginx + Apache / PHP-FPM. Распознает реальный IP посетителя из X-Forwarded-For / X-Real-IP.',
            'settings_proxy_custom' => 'Дополнительные IP или CIDR подсети доверенных прокси:',
            'settings_proxy_safety' => 'Безопасность: Указывайте внешние балансировщики или Cloudflare. Заголовки прокси принимаются только от доверенных узлов.',
            'settings_sec_9' => '9. Proof-of-Work Челлендж',
            'settings_pow_enable' => 'Включить локальный Proof-of-Work',
            'settings_pow_diff' => 'Сложность вычислений (SHA-256 target):',
            'settings_pow_light' => 'Light (~50-100 мс — базовый)',
            'settings_pow_medium' => 'Medium (~300-800 мс — рекомендуемый)',
            'settings_pow_strong' => 'Strong (~2-4 с — жесткий отсев)',
            'settings_sec_10' => '10. Ссылки-ловушки (Honey URLs)',
            'settings_traps_enable' => 'Активировать перехват ловушек',
            'settings_trap_route' => 'Маршрут ловушки:',
            'settings_sec_11' => '11. Экран блокировки',
            'settings_block_msg' => 'Текст на экране блокировки:',
        ],
        'en' => [
            'app_title' => 'AntiBot — Monitoring & Visual Analytics',
            'diagnostics_title' => 'AntiBot — Diagnostics & Rule Advisor',
            'settings_title' => 'AntiBot — Protection Rules & Settings',
            'ip_list_title' => 'AntiBot — IP Address Lists',
            'tab_monitoring' => '📊 Monitoring & Log',
            'tab_diagnostics' => '🩺 Diagnostics & Advisor',
            'tab_settings' => '⚙️ Rule Settings',
            'tab_iplist' => '📋 IP Lists (White/Black)',
            'nav_back_to_monitoring' => '← Back to Monitoring',
            'header_title' => 'AntiBot Protection',
            'header_desc' => 'Autonomous behavioral anti-bot protection, crawler filtering, search engine verification and visual analytics',
            'header_desc_diagnostics' => 'Component health self-test, advisor recommendations, rule simulator and configuration audit trail',
            'header_desc_iplist' => 'Whitelists, blacklists and trusted proxy IP ranges (CIDR notation supported)',
            'header_desc_settings' => 'Manage detection signatures, search bot filters, AI scrapers, and rate limits',
            'clear_log_btn' => '🗑️ Clear Log',
            'clear_log_confirm' => 'Are you sure you want to clear the event log?',
            'settings_btn' => '⚙️ Rule Settings',
            'status_module' => 'Module status:',
            'status_active' => '● Active',
            'status_disabled' => '○ Disabled',
            'mode_label' => 'Mode:',
            'mode_monitoring' => 'Monitoring',
            'mode_standard' => 'Standard',
            'mode_strict' => 'Strict (Blocking)',
            // KPIs
            'kpi_inspected' => 'Inspected',
            'kpi_total_requests' => 'Total requests',
            'kpi_events' => 'Journal Events',
            'kpi_last_24h' => 'Last 24 hours',
            'kpi_blocked' => 'Blocked (403)',
            'kpi_in_monitoring' => 'in monitor',
            'kpi_search_bots' => 'Search Engines (SEO)',
            'kpi_search_desc' => 'Yandex, Google, Bing',
            // Charts
            'chart_dynamics_title' => '📈 Suspicious Activity Dynamics',
            'chart_dynamics_desc' => 'Log events over time (when simulated: true actions are recorded as monitoring without blocking)',
            'period_24h' => '24 hours',
            'period_7d' => '7 days',
            'period_all' => 'All history',
            'btn_refresh' => 'Refresh',
            'badge_full_window' => 'Full window',
            'badge_events_processed' => 'Events processed:',
            'badge_generated_at' => 'Generated at:',
            'chart_disclaimer' => 'ℹ️ Journal events, not all HTTP traffic. Best-Effort statistics.',
            'chart_dist_title' => '🍩 Action Distribution',
            'chart_dist_desc' => 'Share of categories in recorded journal events',
            'chart_reasons_title' => '📊 Main Trigger Reasons',
            'chart_reasons_desc' => 'Top detection categories (with plain descriptions)',
            'chart_routes_title' => '🎯 Most Targeted Routes (Top-10 URL)',
            'chart_routes_desc' => 'Routes with highest number of suspicious requests (query string and tokens stripped)',
            // Table
            'table_title' => 'Recent Events Journal (Live Feed)',
            'table_desc' => 'Recorded suspicious client requests',
            'table_empty' => 'Event journal is empty. No suspicious bot activity has been recorded yet.',
            'th_time' => 'Time',
            'th_ip' => 'IP Address',
            'th_action' => 'Action',
            'th_reason' => 'Reason',
            'th_ua' => 'User-Agent',
            'th_actions' => 'Actions',
            'btn_ban' => 'Ban',
            'btn_whitelist' => 'Whitelist',
            'ban_ip_title' => 'Block this IP address',
            'whitelist_ip_title' => 'Add to trusted whitelist',
            // Alerts
            'alert_sec_warning_title' => 'Access Security Warning:',
            'alert_sec_warning_text' => 'The module is configured with [\'@\'] role (accessible to any authenticated user). It is recommended to configure antibot.manage permission or restrict roles: \'accessRoles\' => [\'admin\'].',
            'alert_secret_warning_title' => 'Signing Key Warning:',
            'alert_secret_warning_text' => 'No custom $secretKey is configured for Antibot. A temporary generated local key is in use.',
            'alert_disabled_title' => 'Protection Module Disabled:',
            'alert_disabled_text' => 'Traffic is not being filtered. Go to Rule Settings to enable protection.',
            'btn_enable_protection' => 'Enable Protection →',
            'alert_monitoring_title' => 'Monitoring Mode:',
            'alert_monitoring_text' => 'Suspicious bots and scrapers are recorded in the journal, but requests are not blocked (HTTP 200). Safe testing without impacting visitors.',
            'alert_strict_title' => 'Strict Mode:',
            'alert_strict_text' => 'Elevated protection strictness is enabled with mandatory preemptive verification for suspicious clients.',
            'btn_change_mode' => 'Change Mode →',
            // Diagnostics
            'diag_selftest_title' => '🩺 System Health & Self-Test',
            'diag_selftest_desc' => 'Automatic verification of write permissions, DNS resolvers, signing keys and adapters',
            'diag_system_ok' => 'System Healthy',
            'diag_system_warning' => 'Warning',
            'diag_system_fail' => 'Needs Attention',
            'diag_recommendation' => 'Recommendation:',
            'diag_advisor_title' => '💡 Heuristic Rule Advisor',
            'diag_advisor_desc' => 'Intelligent traffic analysis and security recommendations',
            'diag_advisor_what' => 'How it works: The advisor continuously analyzes real requests in the event log. If it detects suspicious scanners, aggressive bots or unprotected routes, it offers one-click solutions without risk of breaking normal site traffic.',
            'diag_advisor_optimal_title' => 'Current rules are optimal!',
            'diag_advisor_optimal_desc' => 'The advisor checked the event log: no unmitigated anomalies or threats were detected. All security policies are functioning normally.',
            'diag_apply_rec_btn' => 'Apply Recommendation',
            'diag_apply_rec_confirm' => 'Apply this rule to website configuration?',
            'diag_simulator_title' => '🧪 Rule Simulator ("What-If" Analysis)',
            'diag_simulator_desc' => 'Test hypothetical rules against actual traffic history without risk to visitors',
            'diag_simulator_how' => 'How the simulator works: You can set an experimental limit (e.g. max 15 requests per minute or block curl script) and check how many real visitors or bots would be affected. Your live settings are not modified.',
            'diag_sim_check_type' => 'Check Type:',
            'diag_sim_opt_ratelimit' => 'Rate Limit',
            'diag_sim_opt_ua' => 'User-Agent Pattern',
            'diag_sim_max_or_ua' => 'Max Requests / UA Pattern:',
            'diag_sim_window' => 'Window (seconds):',
            'diag_sim_run_btn' => 'Run Simulation',
            'diag_sim_res_title' => 'Analysis Result:',
            'diag_sim_sample' => 'Sample:',
            'diag_sim_would_block' => 'Would Block:',
            'diag_sim_running' => 'Running simulation...',
            'diag_sim_step1' => 'Reading and sorting event history...',
            'diag_sim_step2' => 'Simulating rule threshold and rolling window...',
            'diag_sim_step3' => 'Verifying search engine exemptions (Google / Yandex)...',
            'diag_sim_affected_ips' => 'Affected IPs:',
            'diag_sim_affected_routes' => 'Affected URLs:',
            'diag_sim_search_protected' => '0 (Protected)',
            'diag_sim_retest_btn' => 'Re-run Test',
            'diag_sim_safe_badge' => 'Safe for Visitors',
            'diag_sim_mild_badge' => 'Mild Impact',
            'diag_sim_mod_badge' => 'Moderate Risk',
            'diag_sim_risk_badge' => 'High Risk of Blocks',
            'diag_perf_title' => '⏱️ Inspection Performance',
            'diag_perf_desc' => 'Antibot inspection overhead per HTTP request',
            'diag_perf_avg' => 'Average Time',
            'diag_perf_p95' => 'p95 Percentile',
            'diag_perf_ms' => 'ms',
            'diag_perf_total' => 'Total Inspected:',
            'diag_perf_allowed' => 'Allowed Legitimate:',
            'diag_perf_adapter' => 'Rate Limit Adapter:',
            'diag_perf_window' => 'Limiting Window:',
            'diag_perf_sec' => 'sec.',
            'diag_audit_title' => '📜 Rule Audit Trail',
            'diag_audit_desc' => 'History of settings updates and applied recommendations',
            'diag_audit_empty' => 'Audit trail is currently empty. Settings have not been modified.',
            'diag_audit_th_time' => 'Time',
            'diag_audit_th_ip' => 'Author IP',
            'diag_audit_th_action' => 'Action',
            // IP List
            'iplist_header_title' => 'IP Address & Subnet Lists',
            'iplist_whitelist_title' => 'Whitelist',
            'iplist_whitelist_desc' => 'Requests from these IPs bypass all inspection checks',
            'iplist_whitelist_active' => 'Active Trusted Addresses:',
            'iplist_whitelist_empty' => 'List is empty.',
            'iplist_blacklist_title' => 'Blacklist',
            'iplist_blacklist_desc' => 'Requests from these IPs are immediately blocked (HTTP 403)',
            'iplist_blacklist_active' => 'Blocked Addresses:',
            'iplist_blacklist_empty' => 'List is empty. No IP has been manually blocked yet.',
            'iplist_proxies_title' => 'Trusted Proxies',
            'iplist_proxies_desc' => 'Allows reading X-Forwarded-For and CF-Connecting-IP headers',
            'iplist_proxies_active' => 'Active Proxy Nodes:',
            'iplist_proxies_empty' => 'List is empty. Proxy headers are ignored to prevent IP spoofing.',
            'iplist_proxies_autotrust' => 'Auto-trust for local proxies is active:',
            'iplist_proxies_autotrust_desc' => 'Local addresses (127.0.0.1, ::1) are recognized automatically.',
            'iplist_add_btn' => '+ Add Entry',
            'iplist_del_btn' => 'Delete',
            'iplist_del_confirm_white' => 'Remove {entry} from whitelist?',
            'iplist_del_confirm_black' => 'Remove {entry} from blacklist?',
            'iplist_del_confirm_proxy' => 'Remove {entry} from trusted proxies?',
            // Settings
            'settings_header_title' => 'Protection Rule Settings',
            'settings_save_btn' => '💾 Save Settings',
            'settings_bar_title' => '⚙️ Protection Configuration',
            'settings_bar_desc' => '— changes take effect immediately',
            'settings_sec_1' => '1. Primary Operating Mode',
            'settings_sec_1_badge' => 'Base Profile',
            'settings_enabled_label' => 'Enable AntiBot Traffic Filtering',
            'settings_enabled_desc' => 'Global master switch for the antibot security module.',
            'settings_strictness_label' => 'Protection Strictness Level:',
            'settings_mode_monitoring_desc' => 'Audit & log only. No blocks issued for safe configuration checks.',
            'settings_mode_standard_desc' => 'Recommended. Blocks scrapers, AI crawlers and fake search bots.',
            'settings_mode_strict_desc' => 'Issues JS challenge to all browsers before allowing access.',
            'settings_sec_2' => '2. Search Engine Verification (SEO Protection)',
            'settings_verify_search' => 'Verify Authentic Search Bots',
            'settings_verify_search_desc' => 'Two-way Reverse DNS (PTR + Forward) verification for Yandex, Google, Bing, Mail.ru. Legitimate search engines are never blocked.',
            'settings_block_fake' => 'Block Fake Search Bots',
            'settings_block_fake_desc' => 'If a bot spoofs Googlebot or YandexBot from an unauthorized IP, it is blocked with 403 Forbidden. DNS errors safely fail open as unknown.',
            'settings_sec_3' => '3. AI Crawler Policies (LLM Bots)',
            'settings_block_ai' => 'Global AI Training Scraper Block',
            'settings_block_ai_desc' => 'Default rule for all unconfigured AI crawlers.',
            'settings_ai_table_bot' => 'Bot / System',
            'settings_ai_table_type' => 'Type',
            'settings_ai_table_policy' => 'Policy',
            'settings_ai_type_search' => 'Search',
            'settings_ai_type_training' => 'Training',
            'settings_ai_allow' => 'Allow',
            'settings_ai_monitor' => 'Monitor',
            'settings_ai_throttle' => 'Throttle',
            'settings_ai_block' => 'Block',
            'settings_sec_4' => '4. Commercial Scrapers & CLI Utilities',
            'settings_block_scrapers' => 'Block Commercial SEO Scrapers',
            'settings_block_scrapers_desc' => 'AhrefsBot, SemrushBot, DotBot, MJ12bot, MegaIndex and similar aggressive scrapers.',
            'settings_block_cli' => 'Block CLI Utilities & Script Libraries',
            'settings_block_cli_desc' => 'cURL, Wget, Python-requests, Scrapy, Guzzle, Go-http-client and clients with empty User-Agent.',
            'settings_sec_5' => '5. Behavioral Analysis (Behavior Intelligence)',
            'settings_behavior' => 'Enable Behavioral Analysis',
            'settings_behavior_desc' => 'Evaluates request sequences, click intervals, anomalous URL diversity, high-frequency search and error spikes. Enables heuristic RiskEngine.',
            'settings_sec_6' => '6. Rate Limiting',
            'settings_ratelimit_enable' => 'Enable Rate Limiter',
            'settings_adapter_label' => 'Storage Adapter:',
            'settings_adapter_cache' => 'CacheLimiter (Standard without Redis)',
            'settings_adapter_redis' => 'RedisLimiter (Atomic Lua, when Redis is configured)',
            'settings_adapter_desc' => 'CacheLimiter does not create locks and prevents PHP worker hangs.',
            'settings_ratelimit_max' => 'Max Requests:',
            'settings_ratelimit_window' => 'Calculation Window (seconds):',
            'settings_ratelimit_ban' => 'Temporary Ban Duration (seconds):',
            'settings_ratelimit_ban_desc' => 'Applied to systematic flooding (0 = no ban).',
            'settings_sec_7' => '7. Weighted Load Budget',
            'settings_weighted_enable' => 'Consider Operation Weight (Points)',
            'settings_weighted_budget' => 'Points Budget (per window):',
            'settings_weighted_budget_desc' => 'Standard page: 1 point, search: 5 points, heavy catalog filter: 8-10 points.',
            'settings_weighted_window' => 'Budget Window (seconds):',
            'settings_sec_8' => '8. Trusted Proxies',
            'settings_proxy_autotrust' => 'Automatically trust local reverse proxies (127.0.0.1, SERVER_ADDR)',
            'settings_proxy_autotrust_desc' => 'Recommended for Nginx + Apache / PHP-FPM. Resolves client IP from X-Forwarded-For / X-Real-IP.',
            'settings_proxy_custom' => 'Additional Trusted Proxy IPs or CIDR Subnets:',
            'settings_proxy_safety' => 'Security: Specify external load balancers or Cloudflare. Proxy headers are only accepted from trusted nodes.',
            'settings_sec_9' => '9. Proof-of-Work Challenge',
            'settings_pow_enable' => 'Enable Local Proof-of-Work',
            'settings_pow_diff' => 'Calculation Difficulty (SHA-256 target):',
            'settings_pow_light' => 'Light (~50-100 ms — basic)',
            'settings_pow_medium' => 'Medium (~300-800 ms — recommended)',
            'settings_pow_strong' => 'Strong (~2-4 s — strict filtration)',
            'settings_sec_10' => '10. Honey URLs',
            'settings_traps_enable' => 'Activate Honey Trap Interception',
            'settings_trap_route' => 'Trap Route:',
            'settings_sec_11' => '11. Blocking Screen',
            'settings_block_msg' => 'Custom Block Screen Message:',
        ],
    ];

    /**
     * Resolves active language ('ru' or 'en') strictly from Yii::$app->language.
     */
    public static function getLanguage(): string
    {
        if (Yii::$app !== null && !empty(Yii::$app->language)) {
            $appLang = strtolower((string) Yii::$app->language);
            if (preg_match('/^(ru|be|uk|kk)/', $appLang)) {
                return self::LANG_RU;
            }
            return self::LANG_EN;
        }

        // Component fallback if configured
        if (Yii::$app !== null && Yii::$app->has('antibot', true)) {
            $antibot = Yii::$app->get('antibot');
            if (isset($antibot->language) && in_array($antibot->language, [self::LANG_RU, self::LANG_EN], true)) {
                return $antibot->language;
            }
        }

        return self::LANG_RU;
    }

    /**
     * Checks if current language is Russian.
     */
    public static function isRussian(?string $lang = null): bool
    {
        return ($lang ?? self::getLanguage()) === self::LANG_RU;
    }

    /**
     * Translates a dictionary key into current language with optional parameter substitution.
     *
     * @param string $key
     * @param string|null $lang
     * @param array<string, string|int|float> $params
     * @return string
     */
    public static function t(string $key, ?string $lang = null, array $params = []): string
    {
        $lang = $lang ?? self::getLanguage();
        $message = self::MESSAGES[$lang][$key] ?? self::MESSAGES[self::LANG_RU][$key] ?? $key;

        if (!empty($params)) {
            foreach ($params as $pKey => $val) {
                $message = str_replace('{' . $pKey . '}', (string) $val, $message);
            }
        }

        return $message;
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
                'badgeClass' => 'badge-soft-info',
                'icon' => '👁️',
                'description' => $isRu ? 'Событие зафиксировано в журнале без блокировки (режим мониторинга)' : 'Event recorded in journal without blocking (monitoring mode)',
            ],
            'blocked' => [
                'code' => 'blocked',
                'label' => $isRu ? 'Заблокирован' : 'Blocked',
                'badgeClass' => 'badge-soft-danger',
                'icon' => '⛔',
                'description' => $isRu ? 'Запрос отклонён с кодом 403 Forbidden' : 'Request blocked with 403 Forbidden',
            ],
            'throttled' => [
                'code' => 'throttled',
                'label' => $isRu ? 'Лимит превышен' : 'Throttled',
                'badgeClass' => 'badge-soft-warning',
                'icon' => '⏱️',
                'description' => $isRu ? 'Превышен лимит частоты запросов (Rate Limit 429)' : 'Rate limit exceeded (HTTP 429)',
            ],
            'allowed_search_bot' => [
                'code' => 'allowed_search_bot',
                'label' => $isRu ? 'Поисковик (OK)' : 'Search Engine',
                'badgeClass' => 'badge-soft-success',
                'icon' => '🤖',
                'description' => $isRu ? 'Подлинный проверенный поисковый робот (пропущен без ограничений)' : 'Verified legitimate search engine bot (whitelisted)',
            ],
            'challenge_passed' => [
                'code' => 'challenge_passed',
                'label' => $isRu ? 'Проверка пройдена' : 'Challenge Passed',
                'badgeClass' => 'badge-soft-primary',
                'icon' => '✅',
                'description' => $isRu ? 'Браузерная проверка успешно решена (JS Challenge)' : 'Browser challenge solved successfully (JS Challenge)',
            ],
            'challenged' => [
                'code' => 'challenged',
                'label' => $isRu ? 'Запрос проверки' : 'Challenge Issued',
                'badgeClass' => 'badge-soft-secondary',
                'icon' => '🛡️',
                'description' => $isRu ? 'Клиенту выдана страница проверки браузера' : 'Client presented with browser challenge verification',
            ],
            'pow_solved' => [
                'code' => 'pow_solved',
                'label' => $isRu ? 'PoW решён' : 'PoW Solved',
                'badgeClass' => 'badge-soft-primary',
                'icon' => '⚡',
                'description' => $isRu ? 'Криптографическое вычисление Proof-of-Work подтверждено' : 'Proof-of-Work cryptographic calculation verified',
            ],
            default => [
                'code' => $actionLower,
                'label' => strtoupper($actionLower),
                'badgeClass' => 'badge-soft-secondary',
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
