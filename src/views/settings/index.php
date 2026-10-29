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
            <!-- Left Column: Core Rules -->
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
                                        <input class="form-check-input" type="radio" name="AntibotSetting[mode]" value="standard" <?= ($settings['mode'] ?? '') === 'standard' ? 'checked' : '' ?>>
                                        <span class="fw-bold text-primary">Стандартный (Реком.)</span>
                                    </div>
                                    <div class="small text-muted">Блокирует явных ботов, парсеры и спам. Живые люди не замечают проверку.</div>
                                </label>
                            </div>
                            <div class="col-md-4">
                                <label class="card p-3 h-100 border cursor-pointer">
                                    <div class="form-check mb-1">
                                        <input class="form-check-input" type="radio" name="AntibotSetting[mode]" value="strict" <?= ($settings['mode'] ?? '') === 'strict' ? 'checked' : '' ?>>
                                        <span class="fw-bold">Строгий</span>
                                    </div>
                                    <div class="small text-muted">Принудительный JS-челлендж для всех новых посетителей сайта.</div>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 2. Сигнатурные фильтры -->
                <div class="card border-0 shadow-sm rounded-3 mb-4">
                    <div class="card-header bg-transparent border-0 pt-3">
                        <h5 class="fw-bold mb-0">2. Сигнатурные правила и краулеры</h5>
                    </div>
                    <div class="card-body">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="AntibotSetting[blockAiCrawlers]" value="1" id="blockAiSwitch" <?= !empty($settings['blockAiCrawlers']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="blockAiSwitch">Блокировать AI-краулеры (ChatGPT, Claude, Bytespider, CCBot)</label>
                            <div class="form-text">Защищает сайт от выкачивания контента нейросетями для обучения моделей.</div>
                        </div>

                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="AntibotSetting[blockCommercialScrapers]" value="1" id="blockScrapersSwitch" <?= !empty($settings['blockCommercialScrapers']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="blockScrapersSwitch">Блокировать коммерческие парсеры (Ahrefs, Semrush, MJ12bot, DotBot)</label>
                            <div class="form-text">Снижает паразитную нагрузку на сервер от SEO-агрегаторов.</div>
                        </div>

                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="AntibotSetting[blockCliAndLibraries]" value="1" id="blockCliSwitch" <?= !empty($settings['blockCliAndLibraries']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="blockCliSwitch">Блокировать скрипты и CLI-утилиты (cURL, Python, Wget, Scrapy, Guzzle, Go)</label>
                            <div class="form-text">Отсекает прямые автоматические запросы к страницам без браузера.</div>
                        </div>
                    </div>
                </div>

                <!-- 3. Поисковые системы (SEO) -->
                <div class="card border-0 shadow-sm rounded-3 mb-4">
                    <div class="card-header bg-transparent border-0 pt-3">
                        <h5 class="fw-bold mb-0">3. Поисковые системы и SEO</h5>
                    </div>
                    <div class="card-body">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="AntibotSetting[verifySearchBots]" value="1" id="verifyBotsSwitch" <?= !empty($settings['verifySearchBots']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="verifyBotsSwitch">Проверять подлинность поисковиков (Reverse DNS PTR)</label>
                            <div class="form-text">Проверяет, действительно ли IP принадлежит серверам Яндекса, Google или Bing.</div>
                        </div>

                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" name="AntibotSetting[blockFakeSearchBots]" value="1" id="blockFakeBotsSwitch" <?= !empty($settings['blockFakeSearchBots']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold text-danger" for="blockFakeBotsSwitch">Мгновенно банить фейковых поисковиков</label>
                            <div class="form-text">Если бот притворился Googlebot или YandexBot, но PTR-запись не подтвердилась — немедленный бан.</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Rate Limiting & Messages -->
            <div class="col-lg-4">
                <!-- Ограничение частоты -->
                <div class="card border-0 shadow-sm rounded-3 mb-4">
                    <div class="card-header bg-transparent border-0 pt-3">
                        <h5 class="fw-bold mb-0">⏱️ Ограничение частоты (Rate Limiter)</h5>
                    </div>
                    <div class="card-body">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="AntibotSetting[rateLimitEnabled]" value="1" id="rateLimitSwitch" <?= !empty($settings['rateLimitEnabled']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="rateLimitSwitch">Включить Rate Limiter</label>
                            <div class="form-text">Защита от быстрого кликанья и флуда.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold">Макс. запросов:</label>
                            <input type="number" class="form-control" name="AntibotSetting[rateLimitMax]" value="<?= (int) ($settings['rateLimitMax'] ?? 60) ?>" min="5" max="1000">
                            <div class="form-text">С одного IP-адреса.</div>
                        </div>

                        <div class="mb-0">
                            <label class="form-label small fw-bold">Интервал окна (сек):</label>
                            <input type="number" class="form-control" name="AntibotSetting[rateLimitWindow]" value="<?= (int) ($settings['rateLimitWindow'] ?? 60) ?>" min="5" max="600">
                        </div>
                    </div>
                </div>

                <!-- Сообщение о блокировке -->
                <div class="card border-0 shadow-sm rounded-3 mb-4">
                    <div class="card-header bg-transparent border-0 pt-3">
                        <h5 class="fw-bold mb-0">💬 Сообщение при блокировке</h5>
                    </div>
                    <div class="card-body">
                        <textarea class="form-control mb-2" rows="3" name="AntibotSetting[customBlockMessage]"><?= Html::encode($settings['customBlockMessage'] ?? 'Access Denied: Automated bot activity detected.') ?></textarea>
                        <div class="small text-muted">Текст, отображаемый заблокированным ботам на 403 экране.</div>
                    </div>
                </div>

                <!-- Сохранение -->
                <div class="card border-0 shadow-sm rounded-3 p-3 bg-white">
                    <button type="submit" class="btn btn-primary btn-lg w-100 fw-bold">
                        💾 Сохранить настройки
                    </button>
                    <div class="small text-muted text-center mt-2">
                        Настройки сохраняются в <code>common/config/antibot.json</code>.
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
