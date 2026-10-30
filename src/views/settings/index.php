<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var array<string, mixed> $settings */
/** @var larikmc\antibot\Antibot $antibot */

use yii\helpers\Html;
use yii\helpers\Url;

\larikmc\antibot\assets\AntibotDashboardAsset::register($this);

$this->title = 'Антибот — Настройки правил';
$this->params['breadcrumbs'] = [
    ['label' => 'Антибот', 'url' => ['/antibot/default/index']],
    ['label' => 'Настройки правил'],
];

$trustedProxiesStr = implode("\n", (array) ($settings['trustedProxies'] ?? []));
$aiPolicies = (array) ($settings['aiCrawlerPolicies'] ?? []);
$currentMode = (string) ($settings['mode'] ?? 'standard');
?>

<div class="antibot-settings">
    <!-- Modern Header -->
    <div class="antibot-header d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div>
            <h1 class="antibot-title">
                <span>⚙️</span> Настройки правил защиты
            </h1>
            <p class="antibot-subtitle">Управление сигнатурами, фильтрами поисковиков, AI-парсерами и лимитами</p>
        </div>
        <div>
            <a href="<?= Url::to(['/antibot/default/index']) ?>" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                ← В дашборд
            </a>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="antibot-nav-pills">
        <a href="<?= Url::to(['/antibot/default/index']) ?>" class="antibot-nav-link">
            📊 Мониторинг и Аналитика
        </a>
        <a href="<?= Url::to(['/antibot/settings/index']) ?>" class="antibot-nav-link active">
            ⚙️ Настройки правил
        </a>
        <a href="<?= Url::to(['/antibot/ip-list/index']) ?>" class="antibot-nav-link">
            📋 Списки IP (White/Black)
        </a>
    </div>

    <form method="post" action="<?= Url::to(['/antibot/settings/index']) ?>">
        <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->csrfToken) ?>

        <div class="row g-4">
            <!-- Left Column: Core Rules, Modes & Crawlers -->
            <div class="col-lg-8">
                <!-- 1. Основные режимы -->
                <div class="antibot-card mb-4">
                    <div class="antibot-card-header">
                        <h2 class="antibot-card-title">
                            <span>🛡️</span> 1. Основной режим работы
                        </h2>
                        <span class="antibot-card-title-badge">Базовый профиль</span>
                    </div>
                    <div class="antibot-card-body">
                        <div class="form-check form-switch mb-4 p-3 bg-light rounded-3 d-flex align-items-center justify-content-between">
                            <div>
                                <label class="form-check-label fw-bold text-dark fs-6" for="enabledSwitch">Включить фильтрацию трафика Антибот</label>
                                <div class="small text-muted">Глобальный мастер-тумблер модуля антибот-защиты.</div>
                            </div>
                            <input class="form-check-input ms-3 me-0" type="checkbox" name="AntibotSetting[enabled]" value="1" id="enabledSwitch" <?= !empty($settings['enabled']) ? 'checked' : '' ?>>
                        </div>

                        <label class="form-label fw-bold text-dark mb-2">Уровень строгости защиты:</label>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="antibot-mode-card <?= $currentMode === 'monitoring' ? 'is-active is-active-monitoring' : '' ?>">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="radio" name="AntibotSetting[mode]" value="monitoring" <?= $currentMode === 'monitoring' ? 'checked' : '' ?>>
                                        <span class="fw-bold text-info">Мониторинг</span>
                                    </div>
                                    <div class="small text-muted">Только аудит и лог. Никаких блокировок для проверки настроек.</div>
                                </label>
                            </div>
                            <div class="col-md-4">
                                <label class="antibot-mode-card <?= $currentMode === 'standard' ? 'is-active' : '' ?>">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="radio" name="AntibotSetting[mode]" value="standard" <?= $currentMode === 'standard' ? 'checked' : '' ?>>
                                        <span class="fw-bold text-primary">Стандартный</span>
                                    </div>
                                    <div class="small text-muted">Рекомендуется. Блокирует парсеры, AI-ботов и фейковые поисковики.</div>
                                </label>
                            </div>
                            <div class="col-md-4">
                                <label class="antibot-mode-card <?= $currentMode === 'strict' ? 'is-active is-active-strict' : '' ?>">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="radio" name="AntibotSetting[mode]" value="strict" <?= $currentMode === 'strict' ? 'checked' : '' ?>>
                                        <span class="fw-bold text-danger">Строгий (Strict)</span>
                                    </div>
                                    <div class="small text-muted">JS-челлендж для всех браузеров перед допуском к контенту.</div>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 2. Поисковики и Reverse DNS -->
                <div class="antibot-card mb-4">
                    <div class="antibot-card-header">
                        <h2 class="antibot-card-title">
                            <span>🤖</span> 2. Верификация поисковиков (SEO-защита)
                        </h2>
                        <span class="antibot-card-title-badge">Reverse DNS</span>
                    </div>
                    <div class="antibot-card-body">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="AntibotSetting[verifySearchBots]" value="1" id="verifySearchSwitch" <?= !empty($settings['verifySearchBots']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="verifySearchSwitch">Верифицировать реальные поисковые роботы</label>
                            <div class="form-text">Двусторонняя Reverse DNS (PTR + Forward) проверка ботов Яндекс, Google, Bing, Mail.ru. Легитимные поисковики никогда не блокируются.</div>
                        </div>

                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" name="AntibotSetting[blockFakeSearchBots]" value="1" id="fakeSearchSwitch" <?= !empty($settings['blockFakeSearchBots']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold text-danger" for="fakeSearchSwitch">Блокировать поддельных поисковых роботов (Fake Search Bots)</label>
                            <div class="form-text">Если бот маскируется под Googlebot или YandexBot с постороннего IP-адреса, он блокируется с 403 Forbidden. Ошибки DNS трактуются безопасно как <code>unknown</code>.</div>
                        </div>
                    </div>
                </div>

                <!-- 3. Гранулярное управление ИИ-краулерами -->
                <div class="antibot-card mb-4">
                    <div class="antibot-card-header">
                        <h2 class="antibot-card-title">
                            <span>🧠</span> 3. Политики для ИИ-краулеров (AI Bots)
                        </h2>
                        <span class="antibot-card-title-badge">LLM & Скраперы</span>
                    </div>
                    <div class="antibot-card-body">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="AntibotSetting[blockAiCrawlers]" value="1" id="aiSwitch" <?= !empty($settings['blockAiCrawlers']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="aiSwitch">Глобальная блокировка скраперов для обучения ИИ</label>
                            <div class="form-text">Базовое правило для всех ненастроенных индивидуально скраперов.</div>
                        </div>

                        <h6 class="fw-bold small text-muted text-uppercase mt-4 mb-2">Индивидуальные правила для роботов:</h6>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle antibot-table mb-0">
                                <thead>
                                    <tr>
                                        <th>Бот / Система</th>
                                        <th>Тип</th>
                                        <th>Политика</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $botsList = [
                                        'OAI-SearchBot' => ['Поиск ChatGPT Search', 'search'],
                                        'ChatGPT-User' => ['Прямой переход пользователя из ChatGPT', 'search'],
                                        'PerplexityBot' => ['Поисковик Perplexity AI', 'search'],
                                        'GPTBot' => ['Сбор данных для обучения моделей OpenAI', 'training'],
                                        'ClaudeBot' => ['Сбор данных Anthropic Claude', 'training'],
                                        'Bytespider' => ['Скрапер ByteDance (TikTok)', 'training'],
                                        'CCBot' => ['Common Crawl датасет', 'training'],
                                        'Google-Extended' => ['Обучение Gemini / Bard', 'training'],
                                    ];
                                    foreach ($botsList as $bKey => [$bDesc, $bType]):
                                        $currentPolicy = $aiPolicies[$bKey] ?? ($bType === 'search' ? 'allow' : 'block');
                                    ?>
                                        <tr>
                                            <td>
                                                <strong class="text-dark"><?= Html::encode($bKey) ?></strong>
                                                <div class="small text-muted"><?= Html::encode($bDesc) ?></div>
                                            </td>
                                            <td>
                                                <span class="badge <?= $bType === 'search' ? 'bg-success' : 'bg-secondary' ?> rounded-pill px-2 py-1">
                                                    <?= $bType === 'search' ? 'Поисковый' : 'Обучение' ?>
                                                </span>
                                            </td>
                                            <td>
                                                <select name="AntibotSetting[aiCrawlerPolicies][<?= Html::encode($bKey) ?>]" class="form-select form-select-sm rounded-3">
                                                    <option value="allow" <?= $currentPolicy === 'allow' ? 'selected' : '' ?>>Разрешить (Allow)</option>
                                                    <option value="monitor" <?= $currentPolicy === 'monitor' ? 'selected' : '' ?>>Мониторить (Monitor)</option>
                                                    <option value="throttle" <?= $currentPolicy === 'throttle' ? 'selected' : '' ?>>Ограничивать (Throttle)</option>
                                                    <option value="block" <?= $currentPolicy === 'block' ? 'selected' : '' ?>>Блокировать (Block)</option>
                                                </select>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- 4. Коммерческие парсеры и CLI -->
                <div class="antibot-card mb-4">
                    <div class="antibot-card-header">
                        <h2 class="antibot-card-title">
                            <span>🚫</span> 4. Коммерческие скраперы и CLI-утилиты
                        </h2>
                    </div>
                    <div class="antibot-card-body">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="AntibotSetting[blockCommercialScrapers]" value="1" id="commercialSwitch" <?= !empty($settings['blockCommercialScrapers']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="commercialSwitch">Блокировать коммерческие SEO-парсеры</label>
                            <div class="form-text">AhrefsBot, SemrushBot, DotBot, MJ12bot, MegaIndex и аналогичные тяжелые скраперы.</div>
                        </div>

                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" name="AntibotSetting[blockCliAndLibraries]" value="1" id="cliSwitch" <?= !empty($settings['blockCliAndLibraries']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="cliSwitch">Блокировать CLI-утилиты и скрипты</label>
                            <div class="form-text">cURL, Wget, Python-requests, Scrapy, Guzzle, Go-http-client и клиенты без User-Agent.</div>
                        </div>
                    </div>
                </div>

                <!-- 5. Поведенческий анализ (Behavior Intelligence) -->
                <div class="antibot-card mb-4">
                    <div class="antibot-card-header">
                        <h2 class="antibot-card-title">
                            <span>🕵️</span> 5. Поведенческий анализ (Behavior Intelligence)
                        </h2>
                    </div>
                    <div class="antibot-card-body">
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" name="AntibotSetting[behaviorAnalysisEnabled]" value="1" id="bhSwitch" <?= !empty($settings['behaviorAnalysisEnabled']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="bhSwitch">Включить поведенческий анализ активности</label>
                            <div class="form-text">
                                Оценивает последовательность запросов, интервалы между кликами, аномальное разнообразие URL (парсинг всех ссылок), частый поиск и всплески ошибок. Включает эвристический RiskEngine.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Rate Limiter, PoW & Proxies -->
            <div class="col-lg-4">
                <!-- 6. Rate Limiter -->
                <div class="antibot-card mb-4">
                    <div class="antibot-card-header">
                        <h2 class="antibot-card-title">
                            <span>⚡</span> 6. Ограничение частоты (Rate Limiter)
                        </h2>
                    </div>
                    <div class="antibot-card-body">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="AntibotSetting[rateLimitEnabled]" value="1" id="rateLimitSwitch" <?= !empty($settings['rateLimitEnabled']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="rateLimitSwitch">Включить Rate Limiter</label>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold">Адаптер хранилища:</label>
                            <select name="AntibotSetting[rateLimitAdapter]" class="form-select form-select-sm rounded-3">
                                <option value="cache" <?= ($settings['rateLimitAdapter'] ?? 'cache') === 'cache' ? 'selected' : '' ?>>CacheLimiter (Стандартный без Redis)</option>
                                <option value="redis" <?= ($settings['rateLimitAdapter'] ?? '') === 'redis' ? 'selected' : '' ?>>RedisLimiter (Атомарный Lua, при наличии Redis)</option>
                            </select>
                            <div class="form-text">CacheLimiter не создает блокировок и предотвращает зависание PHP.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold">Максимум запросов:</label>
                            <input type="number" name="AntibotSetting[rateLimitMax]" class="form-control rounded-3" value="<?= (int) ($settings['rateLimitMax'] ?? 60) ?>" min="1" max="1000">
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold">Окно подсчета (секунды):</label>
                            <input type="number" name="AntibotSetting[rateLimitWindow]" class="form-control rounded-3" value="<?= (int) ($settings['rateLimitWindow'] ?? 60) ?>" min="1" max="3600">
                        </div>

                        <div class="mb-0">
                            <label class="form-label small fw-bold">Время временного бана (секунды):</label>
                            <input type="number" name="AntibotSetting[rateLimitBanDuration]" class="form-control rounded-3" value="<?= (int) ($settings['rateLimitBanDuration'] ?? 300) ?>" min="0" max="86400">
                            <div class="form-text">Применяется при систематическом флуде (0 = без бана).</div>
                        </div>
                    </div>
                </div>

                <!-- 7. Бюджет нагрузки (Weighted Limiter) -->
                <div class="antibot-card mb-4">
                    <div class="antibot-card-header">
                        <h2 class="antibot-card-title">
                            <span>⚖️</span> 7. Бюджет нагрузки (Weighted Limiter)
                        </h2>
                    </div>
                    <div class="antibot-card-body">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="AntibotSetting[weightedRateLimitEnabled]" value="1" id="wrlSwitch" <?= !empty($settings['weightedRateLimitEnabled']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="wrlSwitch">Учитывать вес операций (баллы)</label>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Лимит баллов (бюджет на окно):</label>
                            <input type="number" name="AntibotSetting[weightedRateLimitBudget]" class="form-control rounded-3" value="<?= (int) ($settings['weightedRateLimitBudget'] ?? 150) ?>" min="10" max="10000">
                            <div class="form-text">Обычная страница: 1 балл, поиск: 5 баллов, тяжелый фильтр: 8-10 баллов.</div>
                        </div>
                        <div class="mb-0">
                            <label class="form-label small fw-bold">Окно бюджета (секунды):</label>
                            <input type="number" name="AntibotSetting[weightedRateLimitWindow]" class="form-control rounded-3" value="<?= (int) ($settings['weightedRateLimitWindow'] ?? 60) ?>" min="5" max="3600">
                        </div>
                    </div>
                </div>

                <!-- 8. Доверенные прокси (Trusted Proxies) -->
                <div class="antibot-card mb-4">
                    <div class="antibot-card-header">
                        <h2 class="antibot-card-title">
                            <span>🛡️</span> 8. Доверенные прокси (Proxies)
                        </h2>
                    </div>
                    <div class="antibot-card-body">
                        <div class="mb-0">
                            <label class="form-label small fw-bold">IP или CIDR подсети доверенных прокси:</label>
                            <textarea name="AntibotSetting[trustedProxies]" class="form-control font-monospace small rounded-3" rows="3" placeholder="127.0.0.1&#10;10.0.0.0/8"><?= Html::encode($trustedProxiesStr) ?></textarea>
                            <div class="form-text text-danger mt-2">
                                <strong>Безопасность:</strong> Если список пуст, заголовки <code>X-Forwarded-For</code> строго игнорируются для защиты от подмены IP.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 9. Proof-of-Work -->
                <div class="antibot-card mb-4">
                    <div class="antibot-card-header">
                        <h2 class="antibot-card-title">
                            <span>🧩</span> 9. Proof-of-Work Челлендж
                        </h2>
                    </div>
                    <div class="antibot-card-body">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="AntibotSetting[powEnabled]" value="1" id="powSwitch" <?= !empty($settings['powEnabled']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="powSwitch">Включить локальный Proof-of-Work</label>
                        </div>
                        <div class="mb-0">
                            <label class="form-label small fw-bold">Сложность вычислений (SHA-256 target):</label>
                            <select name="AntibotSetting[powDifficulty]" class="form-select form-select-sm rounded-3">
                                <option value="light" <?= ($settings['powDifficulty'] ?? 'medium') === 'light' ? 'selected' : '' ?>>Light (~50-100 мс — базовый)</option>
                                <option value="medium" <?= ($settings['powDifficulty'] ?? 'medium') === 'medium' ? 'selected' : '' ?>>Medium (~300-800 мс — рекомендуемый)</option>
                                <option value="strong" <?= ($settings['powDifficulty'] ?? 'medium') === 'strong' ? 'selected' : '' ?>>Strong (~2-4 с — жесткий отсев)</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- 10. Ловушки (Honey URLs) -->
                <div class="antibot-card mb-4">
                    <div class="antibot-card-header">
                        <h2 class="antibot-card-title">
                            <span>🍯</span> 10. Ссылки-ловушки (Honey URLs)
                        </h2>
                    </div>
                    <div class="antibot-card-body">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="AntibotSetting[honeyTrapsEnabled]" value="1" id="honeySwitch" <?= !empty($settings['honeyTrapsEnabled']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="honeySwitch">Активировать перехват ловушек</label>
                        </div>
                        <div class="mb-0">
                            <label class="form-label small fw-bold">Маршрут ловушки:</label>
                            <input type="text" name="AntibotSetting[trapRoute]" class="form-control rounded-3" value="<?= Html::encode($settings['trapRoute'] ?? 'antibot/trap') ?>">
                        </div>
                    </div>
                </div>

                <!-- 11. Текст блокировки -->
                <div class="antibot-card mb-4">
                    <div class="antibot-card-header">
                        <h2 class="antibot-card-title">
                            <span>💬</span> 11. Экран блокировки
                        </h2>
                    </div>
                    <div class="antibot-card-body">
                        <div class="mb-0">
                            <label class="form-label small fw-bold">Текст на экране блокировки:</label>
                            <textarea name="AntibotSetting[customBlockMessage]" class="form-control rounded-3" rows="2"><?= Html::encode($settings['customBlockMessage'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sticky Bottom Save Bar -->
        <div class="antibot-sticky-bar">
            <div>
                <span class="fw-semibold text-dark">⚙️ Конфигурация защиты</span>
                <span class="text-muted small ms-2 d-none d-md-inline">— изменения вступают в силу немедленно</span>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary rounded-pill px-4 shadow-sm fw-bold">
                    💾 Сохранить настройки
                </button>
            </div>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Interactive radio mode cards
    var modeCards = document.querySelectorAll('.antibot-mode-card');
    modeCards.forEach(function (card) {
        var radio = card.querySelector('input[type="radio"]');
        if (radio) {
            radio.addEventListener('change', function () {
                modeCards.forEach(function (c) {
                    c.classList.remove('is-active', 'is-active-monitoring', 'is-active-strict');
                });
                if (radio.value === 'monitoring') {
                    card.classList.add('is-active', 'is-active-monitoring');
                } else if (radio.value === 'strict') {
                    card.classList.add('is-active', 'is-active-strict');
                } else {
                    card.classList.add('is-active');
                }
            });
        }
    });
});
</script>
