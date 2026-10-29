<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var array<string, mixed> $settings */
/** @var larikmc\antibot\Antibot $antibot */

use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'Антибот — Настройки правил';
$this->params['breadcrumbs'] = [
    ['label' => 'Антибот', 'url' => ['/antibot/default/index']],
    ['label' => 'Настройки'],
];

$trustedProxiesStr = implode("\n", (array) ($settings['trustedProxies'] ?? []));
$aiPolicies = (array) ($settings['aiCrawlerPolicies'] ?? []);
?>

<div class="antibot-settings">
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 fw-bold mb-1">⚙️ Настройки правил защиты</h1>
            <p class="text-muted mb-0">Управление сигнатурами, фильтрами поисковиков, AI-парсерами и лимитами</p>
        </div>
        <div>
            <a href="<?= Url::to(['/antibot/default/index']) ?>" class="btn btn-outline-secondary">
                ← Вернуться в дашборд
            </a>
        </div>
    </div>

    <form method="post" action="<?= Url::to(['/antibot/settings/index']) ?>">
        <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->csrfToken) ?>

        <div class="row g-4">
            <!-- Left Column: Core Rules & Crawlers -->
            <div class="col-lg-8">
                <!-- 1. Основные режимы -->
                <div class="card border-0 shadow-sm rounded-3 mb-4">
                    <div class="card-header bg-transparent border-0 pt-3">
                        <h5 class="fw-bold mb-0">1. Основной режим работы</h5>
                    </div>
                    <div class="card-body">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="AntibotSetting[enabled]" value="1" id="enabledSwitch" <?= !empty($settings['enabled']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="enabledSwitch">Включить модуль Антибот</label>
                            <div class="form-text">Глобальный тумблер активности фильтрации трафика.</div>
                        </div>

                        <hr class="my-3">

                        <label class="form-label fw-bold">Уровень строгости защиты:</label>
                        <div class="row g-2">
                            <div class="col-md-4">
                                <label class="card p-3 h-100 border cursor-pointer">
                                    <div class="form-check mb-1">
                                        <input class="form-check-input" type="radio" name="AntibotSetting[mode]" value="monitoring" <?= ($settings['mode'] ?? '') === 'monitoring' ? 'checked' : '' ?>>
                                        <span class="fw-bold">Мониторинг</span>
                                    </div>
                                    <div class="small text-muted">Только аудит и лог. Запросы ботов не блокируются.</div>
                                </label>
                            </div>
                            <div class="col-md-4">
                                <label class="card p-3 h-100 border border-primary bg-primary bg-opacity-10 cursor-pointer">
                                    <div class="form-check mb-1">
                                        <input class="form-check-input" type="radio" name="AntibotSetting[mode]" value="standard" <?= ($settings['mode'] ?? 'standard') === 'standard' ? 'checked' : '' ?>>
                                        <span class="fw-bold text-primary">Стандартный (Рекомендуется)</span>
                                    </div>
                                    <div class="small text-muted">Блокирует известные парсеры, ИИ-скраперы и фейковые поисковики.</div>
                                </label>
                            </div>
                            <div class="col-md-4">
                                <label class="card p-3 h-100 border cursor-pointer">
                                    <div class="form-check mb-1">
                                        <input class="form-check-input" type="radio" name="AntibotSetting[mode]" value="strict" <?= ($settings['mode'] ?? '') === 'strict' ? 'checked' : '' ?>>
                                        <span class="fw-bold text-danger">Строгий (Strict)</span>
                                    </div>
                                    <div class="small text-muted">JS-челлендж для всех браузеров перед допуском к сайту.</div>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 2. Поисковики и Reverse DNS -->
                <div class="card border-0 shadow-sm rounded-3 mb-4">
                    <div class="card-header bg-transparent border-0 pt-3">
                        <h5 class="fw-bold mb-0">2. Верификация поисковиков (SEO-защита)</h5>
                    </div>
                    <div class="card-body">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="AntibotSetting[verifySearchBots]" value="1" id="verifySearchSwitch" <?= !empty($settings['verifySearchBots']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="verifySearchSwitch">Верифицировать реальные поисковые роботы</label>
                            <div class="form-text">Двусторонняя Reverse DNS (PTR + Forward) проверка ботов Яндекс, Google, Bing, Mail.ru. Легитимные поисковики никогда не блокируются.</div>
                        </div>

                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="AntibotSetting[blockFakeSearchBots]" value="1" id="fakeSearchSwitch" <?= !empty($settings['blockFakeSearchBots']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold text-danger" for="fakeSearchSwitch">Блокировать поддельных поисковых роботов (Fake Search Bots)</label>
                            <div class="form-text">Если бот маскируется под Googlebot или YandexBot с постороннего IP-адреса, он блокируется с 403 Forbidden. Ошибки DNS трактуются безопасно как <code>unknown</code>.</div>
                        </div>
                    </div>
                </div>

                <!-- 3. Гранулярное управление ИИ-краулерами -->
                <div class="card border-0 shadow-sm rounded-3 mb-4">
                    <div class="card-header bg-transparent border-0 pt-3">
                        <h5 class="fw-bold mb-0">3. Политики для ИИ-краулеров (AI Bots)</h5>
                    </div>
                    <div class="card-body">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="AntibotSetting[blockAiCrawlers]" value="1" id="aiSwitch" <?= !empty($settings['blockAiCrawlers']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="aiSwitch">Глобальная блокировка скраперов для обучения ИИ</label>
                            <div class="form-text">Базовое правило для всех ненастроенных индивидуально скраперов.</div>
                        </div>

                        <h6 class="fw-bold small text-muted text-uppercase mt-4 mb-2">Индивидуальные правила для роботов:</h6>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead>
                                    <tr class="small text-muted">
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
                                                <strong><?= Html::encode($bKey) ?></strong>
                                                <div class="small text-muted"><?= Html::encode($bDesc) ?></div>
                                            </td>
                                            <td>
                                                <span class="badge <?= $bType === 'search' ? 'bg-success' : 'bg-secondary' ?>">
                                                    <?= $bType === 'search' ? 'Поисковый' : 'Обучение' ?>
                                                </span>
                                            </td>
                                            <td>
                                                <select name="AntibotSetting[aiCrawlerPolicies][<?= Html::encode($bKey) ?>]" class="form-select form-select-sm">
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
                <div class="card border-0 shadow-sm rounded-3 mb-4">
                    <div class="card-header bg-transparent border-0 pt-3">
                        <h5 class="fw-bold mb-0">4. Прочие нежелательные клиенты</h5>
                    </div>
                    <div class="card-body">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="AntibotSetting[blockCommercialScrapers]" value="1" id="commercialSwitch" <?= !empty($settings['blockCommercialScrapers']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="commercialSwitch">Блокировать коммерческие SEO-парсеры</label>
                            <div class="form-text">AhrefsBot, SemrushBot, DotBot, MJ12bot, MegaIndex и аналогичные тяжелые скраперы.</div>
                        </div>

                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="AntibotSetting[blockCliAndLibraries]" value="1" id="cliSwitch" <?= !empty($settings['blockCliAndLibraries']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="cliSwitch">Блокировать CLI-утилиты и скрипты</label>
                            <div class="form-text">cURL, Wget, Python-requests, Scrapy, Guzzle, Go-http-client и клиенты без User-Agent.</div>
                        </div>
                    </div>
                </div>

                <!-- 5. Поведенческий анализ (Behavior Intelligence) -->
                <div class="card border-0 shadow-sm rounded-3 mb-4">
                    <div class="card-header bg-transparent border-0 pt-3">
                        <h5 class="fw-bold mb-0">5. Поведенческий анализ (Behavior Intelligence)</h5>
                    </div>
                    <div class="card-body">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="AntibotSetting[behaviorAnalysisEnabled]" value="1" id="bhSwitch" <?= !empty($settings['behaviorAnalysisEnabled']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="bhSwitch">Включить поведенческий анализ активности</label>
                            <div class="form-text">
                                Оценивает последовательность запросов, интервалы между кликами, аномальное разнообразие URL (парсинг всех ссылок), частый поиск и всплески ошибок. Включает эвристический RiskEngine.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Rate Limiter & Proxies -->
            <div class="col-lg-4">
                <!-- 5. Rate Limiter -->
                <div class="card border-0 shadow-sm rounded-3 mb-4">
                    <div class="card-header bg-transparent border-0 pt-3">
                        <h5 class="fw-bold mb-0">5. Ограничение частоты (Rate Limiter)</h5>
                    </div>
                    <div class="card-body">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="AntibotSetting[rateLimitEnabled]" value="1" id="rateLimitSwitch" <?= !empty($settings['rateLimitEnabled']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="rateLimitSwitch">Включить Rate Limiter</label>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold">Адаптер хранилища:</label>
                            <select name="AntibotSetting[rateLimitAdapter]" class="form-select form-select-sm">
                                <option value="cache" <?= ($settings['rateLimitAdapter'] ?? 'cache') === 'cache' ? 'selected' : '' ?>>CacheLimiter (Стандартный без Redis)</option>
                                <option value="redis" <?= ($settings['rateLimitAdapter'] ?? '') === 'redis' ? 'selected' : '' ?>>RedisLimiter (Атомарный Lua, при наличии Redis)</option>
                            </select>
                            <div class="form-text">CacheLimiter не создает блокировок и предотвращает зависание PHP.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold">Максимум запросов:</label>
                            <input type="number" name="AntibotSetting[rateLimitMax]" class="form-control" value="<?= (int) ($settings['rateLimitMax'] ?? 60) ?>" min="1" max="1000">
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold">Окно подсчета (секунды):</label>
                            <input type="number" name="AntibotSetting[rateLimitWindow]" class="form-control" value="<?= (int) ($settings['rateLimitWindow'] ?? 60) ?>" min="1" max="3600">
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold">Время временного бана (секунды):</label>
                            <input type="number" name="AntibotSetting[rateLimitBanDuration]" class="form-control" value="<?= (int) ($settings['rateLimitBanDuration'] ?? 300) ?>" min="0" max="86400">
                            <div class="form-text">Применяется при систематическом превышении лимита (0 = без бана).</div>
                        </div>
                    </div>
                </div>

                <!-- 6. Бюджет нагрузки (Weighted Rate Limiter) -->
                <div class="card border-0 shadow-sm rounded-3 mb-4">
                    <div class="card-header bg-transparent border-0 pt-3">
                        <h5 class="fw-bold mb-0">6. Бюджет нагрузки (Weighted Limiter)</h5>
                    </div>
                    <div class="card-body">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="AntibotSetting[weightedRateLimitEnabled]" value="1" id="wrlSwitch" <?= !empty($settings['weightedRateLimitEnabled']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="wrlSwitch">Учитывать вес операций (баллы)</label>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Лимит баллов (бюджет на окно):</label>
                            <input type="number" name="AntibotSetting[weightedRateLimitBudget]" class="form-control" value="<?= (int) ($settings['weightedRateLimitBudget'] ?? 150) ?>" min="10" max="10000">
                            <div class="form-text">Обычная страница: 1 балл, каталог: 2 балла, поиск: 5 баллов, тяжелый фильтр/API: 8-10 баллов.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Окно бюджета (секунды):</label>
                            <input type="number" name="AntibotSetting[weightedRateLimitWindow]" class="form-control" value="<?= (int) ($settings['weightedRateLimitWindow'] ?? 60) ?>" min="5" max="3600">
                        </div>
                    </div>
                </div>

                <!-- 7. Доверенные прокси (Trusted Proxies) -->
                <div class="card border-0 shadow-sm rounded-3 mb-4">
                    <div class="card-header bg-transparent border-0 pt-3">
                        <h5 class="fw-bold mb-0">7. Доверенные прокси (Trusted Proxies)</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label small fw-bold">IP или CIDR подсети доверенных прокси:</label>
                            <textarea name="AntibotSetting[trustedProxies]" class="form-control font-monospace small" rows="4" placeholder="127.0.0.1&#10;10.0.0.0/8&#10;173.245.48.0/20"><?= Html::encode($trustedProxiesStr) ?></textarea>
                            <div class="form-text text-danger">
                                <strong>Безопасность:</strong> Если список пуст, заголовки <code>X-Forwarded-For</code> и <code>CF-Connecting-IP</code> игнорируются для защиты от подмены IP.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 7. Сообщение блокировки -->
                <div class="card border-0 shadow-sm rounded-3 mb-4">
                    <div class="card-header bg-transparent border-0 pt-3">
                        <h5 class="fw-bold mb-0">8. Кастомизация</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Текст на экране блокировки:</label>
                            <textarea name="AntibotSetting[customBlockMessage]" class="form-control" rows="2"><?= Html::encode($settings['customBlockMessage'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- 9. Локальный Proof-of-Work (Active Challenge) -->
                <div class="card border-0 shadow-sm rounded-3 mb-4">
                    <div class="card-header bg-transparent border-0 pt-3">
                        <h5 class="fw-bold mb-0">9. Proof-of-Work (Вычислительный челлендж)</h5>
                    </div>
                    <div class="card-body">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="AntibotSetting[powEnabled]" value="1" id="powSwitch" <?= !empty($settings['powEnabled']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="powSwitch">Включить локальный Proof-of-Work</label>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Сложность вычислений (SHA-256 target):</label>
                            <select name="AntibotSetting[powDifficulty]" class="form-select form-select-sm">
                                <option value="light" <?= ($settings['powDifficulty'] ?? 'medium') === 'light' ? 'selected' : '' ?>>Light (3 нуля, ~50-100 мс — базовая защита)</option>
                                <option value="medium" <?= ($settings['powDifficulty'] ?? 'medium') === 'medium' ? 'selected' : '' ?>>Medium (4 нуля, ~300-800 мс — рекомендуется)</option>
                                <option value="strong" <?= ($settings['powDifficulty'] ?? 'medium') === 'strong' ? 'selected' : '' ?>>Strong (5 нулей, ~2-4 с — против упорных ботов)</option>
                            </select>
                            <div class="form-text">Работает локально через Web Crypto API в браузере. Без сторонних облаков и капч.</div>
                        </div>
                    </div>
                </div>

                <!-- 10. Ловушки (Honey URLs) -->
                <div class="card border-0 shadow-sm rounded-3 mb-4">
                    <div class="card-header bg-transparent border-0 pt-3">
                        <h5 class="fw-bold mb-0">10. Невидимые ссылки-ловушки (Honey URLs)</h5>
                    </div>
                    <div class="card-body">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="AntibotSetting[honeyTrapsEnabled]" value="1" id="honeySwitch" <?= !empty($settings['honeyTrapsEnabled']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="honeySwitch">Активировать перехват ловушек</label>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Маршрут ловушки:</label>
                            <input type="text" name="AntibotSetting[trapRoute]" class="form-control" value="<?= Html::encode($settings['trapRoute'] ?? 'antibot/trap') ?>">
                            <div class="form-text">Краулеры, перешедшие по скрытой ссылке, фиксируются и пенализируются. Верифицированные поисковики защищены от блокировки.</div>
                        </div>
                    </div>
                </div>

                <!-- Сохранить -->
                <div class="d-grid mb-4">
                    <button type="submit" class="btn btn-primary btn-lg shadow-sm">
                        💾 Сохранить все настройки
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>
