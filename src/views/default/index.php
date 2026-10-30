<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var larikmc\antibot\Antibot $antibot */
/** @var larikmc\antibot\Module $module */
/** @var array<string, mixed> $stats */
/** @var array<int, array<string, mixed>> $events */
/** @var array<string, mixed> $perf */
/** @var array<string, mixed> $selfTest */
/** @var array<string, mixed> $analyticsData */
/** @var array<int, array<string, mixed>> $recommendations */
/** @var array<string, mixed>|null $simResult */
/** @var string $simType */
/** @var int $simMax */
/** @var int $simWindow */
/** @var string $simUa */
/** @var array<int, array<string, mixed>> $auditTrail */

use yii\helpers\Html;
use yii\helpers\Json;
use yii\helpers\Url;

\larikmc\antibot\assets\AntibotDashboardAsset::register($this);

$this->title = 'Антибот — Мониторинг и визуальная аналитика';
$this->params['breadcrumbs'] = [
    ['label' => 'Антибот', 'url' => ['/antibot/default/index']],
    ['label' => 'Мониторинг и аналитика'],
];

$perfTotal = (int) ($perf['total_checks'] ?? 0);
$perfAvg = ($perf['avg_ms'] ?? null) !== null ? number_format((float) $perf['avg_ms'], 3) : null;
$perfP95 = ($perf['p95_ms'] ?? null) !== null ? number_format((float) $perf['p95_ms'], 3) : null;
?>

<div class="antibot-dashboard">
    <!-- Modern Header & Top Navigation -->
    <div class="antibot-header d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div>
            <h1 class="antibot-title">
                <span>🛡️</span> Антибот-защита
                <span class="antibot-badge-mode antibot-badge-mode-<?= Html::encode($antibot->mode) ?> ms-2">
                    <?= Html::encode(ucfirst($antibot->mode)) ?>
                </span>
            </h1>
            <p class="antibot-subtitle">Мониторинг трафика, отсечение ботов, верификация поисковиков и аналитика</p>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <?php if (!empty($events)): ?>
                <?= Html::beginForm(['/antibot/default/clear-events'], 'post', ['class' => 'd-inline']) ?>
                    <?= Html::submitButton('🗑️ Очистить журнал', [
                        'class' => 'btn btn-outline-danger btn-sm rounded-pill px-3',
                        'data-confirm' => 'Вы уверены, что хотите очистить журнал событий?',
                    ]) ?>
                <?= Html::endForm() ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="antibot-nav-pills">
        <a href="<?= Url::to(['/antibot/default/index']) ?>" class="antibot-nav-link active">
            📊 Мониторинг и Аналитика
        </a>
        <a href="<?= Url::to(['/antibot/settings/index']) ?>" class="antibot-nav-link">
            ⚙️ Настройки правил
        </a>
        <a href="<?= Url::to(['/antibot/ip-list/index']) ?>" class="antibot-nav-link">
            📋 Списки IP (White/Black)
        </a>
    </div>

    <!-- Active Mode & System Status Alerts -->
    <?php if ($module->hasInsecureAccessConfig()): ?>
        <div class="alert alert-danger d-flex align-items-center mb-4 rounded-3 border-0 shadow-sm" role="alert">
            <span class="fs-4 me-3">⚠️</span>
            <div>
                <strong>Предупреждение безопасности доступа:</strong>
                Модуль настроен с ролью <code>['@']</code> (панель доступна любому авторизованному клиенту).
                Рекомендуется настроить RBAC-разрешение <code>antibot.manage</code> или ограничить роли: <code>'accessRoles' => ['admin']</code>.
            </div>
        </div>
    <?php endif; ?>

    <?php if ($antibot->hasSecretKeyWarning()): ?>
        <div class="alert alert-warning d-flex align-items-center mb-4 rounded-3 border-0 shadow-sm" role="alert">
            <span class="fs-4 me-3">🔑</span>
            <div>
                <strong>Предупреждение ключа подписи:</strong>
                Не задан собственный <code>$secretKey</code> антибота. Используется автоматически сгенерированный локальный ключ.
            </div>
        </div>
    <?php endif; ?>

    <?php if (!$antibot->enabled): ?>
        <div class="alert alert-warning d-flex align-items-center mb-4 rounded-3 border-0 shadow-sm" role="alert">
            <span class="fs-4 me-2">⚠️</span>
            <div><strong>Защита выключена!</strong> Модуль находится в неактивном состоянии. Перейдите в настройки, чтобы включить защиту.</div>
        </div>
    <?php elseif ($antibot->mode === 'monitoring'): ?>
        <div class="alert alert-info d-flex align-items-center mb-4 rounded-3 border-0 shadow-sm" role="alert">
            <span class="fs-4 me-2">ℹ️</span>
            <div><strong>Режим мониторинга (Monitoring):</strong> Обнаруженные боты фиксируются в журнале, но запросы не блокируются (HTTP 200). Безопасное тестирование без риска для посетителей.</div>
        </div>
    <?php elseif ($antibot->mode === 'strict'): ?>
        <div class="alert alert-danger d-flex align-items-center mb-4 rounded-3 border-0 shadow-sm" role="alert">
            <span class="fs-4 me-2">🚨</span>
            <div><strong>Строгий режим (Strict):</strong> Включена повышенная строгость фильтрации с превентивным отсечением подозрительных клиентов.</div>
        </div>
    <?php endif; ?>

    <!-- LEVEL 1: Modern SaaS KPI Cards Grid -->
    <div class="row g-3 mb-4">
        <!-- 1. Total Checks -->
        <div class="col-sm-6 col-lg-3">
            <div class="antibot-kpi-card">
                <div>
                    <div class="antibot-kpi-top">
                        <span class="antibot-kpi-label">Проинспектировано</span>
                        <div class="antibot-kpi-icon-wrap antibot-kpi-icon-primary">🌐</div>
                    </div>
                    <div class="antibot-kpi-value"><?= number_format($perfTotal) ?></div>
                </div>
                <div class="antibot-kpi-footer">
                    <span>Всего запросов</span>
                    <span class="antibot-kpi-tag antibot-kpi-tag-success">✓ <?= (int) ($perf['allowed'] ?? 0) ?> OK</span>
                </div>
            </div>
        </div>

        <!-- 2. Suspicious Events Recorded -->
        <div class="col-sm-6 col-lg-3">
            <div class="antibot-kpi-card">
                <div>
                    <div class="antibot-kpi-top">
                        <span class="antibot-kpi-label">Событий в журнале</span>
                        <div class="antibot-kpi-icon-wrap antibot-kpi-icon-warning">📝</div>
                    </div>
                    <div class="antibot-kpi-value"><?= number_format((int) ($stats['total'] ?? 0)) ?></div>
                </div>
                <div class="antibot-kpi-footer">
                    <span>За 24 часа</span>
                    <strong class="text-dark"><?= (int) ($stats['last24h']['total'] ?? 0) ?></strong>
                </div>
            </div>
        </div>

        <!-- 3. Blocked (403) -->
        <div class="col-sm-6 col-lg-3">
            <div class="antibot-kpi-card">
                <div>
                    <div class="antibot-kpi-top">
                        <span class="antibot-kpi-label">Заблокировано (403)</span>
                        <div class="antibot-kpi-icon-wrap antibot-kpi-icon-danger">🛑</div>
                    </div>
                    <div class="antibot-kpi-value text-danger"><?= number_format((int) ($stats['blocked'] ?? 0)) ?></div>
                </div>
                <div class="antibot-kpi-footer">
                    <span>За 24 часа</span>
                    <?php if (($stats['monitored'] ?? 0) > 0 || ($perf['monitored'] ?? 0) > 0): ?>
                        <span class="antibot-kpi-tag antibot-kpi-tag-info">👁 <?= (int) max($stats['monitored'] ?? 0, $perf['monitored'] ?? 0) ?> в монит.</span>
                    <?php else: ?>
                        <strong class="text-danger"><?= (int) ($stats['last24h']['blocked'] ?? 0) ?></strong>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- 4. Rate Limited (429) -->
        <div class="col-sm-6 col-lg-3">
            <div class="antibot-kpi-card">
                <div>
                    <div class="antibot-kpi-top">
                        <span class="antibot-kpi-label">Rate Limit (429)</span>
                        <div class="antibot-kpi-icon-wrap antibot-kpi-icon-warning">⚡</div>
                    </div>
                    <div class="antibot-kpi-value text-warning"><?= number_format((int) ($stats['throttled'] ?? 0)) ?></div>
                </div>
                <div class="antibot-kpi-footer">
                    <span>Адаптер <code><?= Html::encode($antibot->rateLimitAdapter) ?></code></span>
                    <span class="text-muted"><?= (int) $antibot->rateLimitWindow ?>с окн.</span>
                </div>
            </div>
        </div>

        <!-- 5. Search Bots (SEO) -->
        <div class="col-sm-6 col-lg-3">
            <div class="antibot-kpi-card">
                <div>
                    <div class="antibot-kpi-top">
                        <span class="antibot-kpi-label">Поисковики (SEO)</span>
                        <div class="antibot-kpi-icon-wrap antibot-kpi-icon-success">🤖</div>
                    </div>
                    <div class="antibot-kpi-value text-success"><?= number_format((int) ($stats['searchBots'] ?? 0)) ?></div>
                </div>
                <div class="antibot-kpi-footer">
                    <span>Яндекс, Google, Bing</span>
                    <span class="antibot-kpi-tag antibot-kpi-tag-success">Reverse DNS</span>
                </div>
            </div>
        </div>

        <!-- 6. Latency (Avg & p95) -->
        <div class="col-sm-6 col-lg-3">
            <div class="antibot-kpi-card">
                <div>
                    <div class="antibot-kpi-top">
                        <span class="antibot-kpi-label">Время проверки</span>
                        <div class="antibot-kpi-icon-wrap antibot-kpi-icon-info">⏱️</div>
                    </div>
                    <div class="antibot-kpi-value">
                        <?= $perfAvg !== null ? $perfAvg . ' <small style="font-size:1rem;font-weight:normal;color:#64748b;">мс</small>' : '<small class="text-muted fs-6">Нет данных</small>' ?>
                    </div>
                </div>
                <div class="antibot-kpi-footer">
                    <span>p95 перцентиль</span>
                    <strong class="text-dark"><?= $perfP95 !== null ? $perfP95 . ' мс' : '—' ?></strong>
                </div>
            </div>
        </div>

        <!-- 7. Traps & Challenges -->
        <div class="col-sm-6 col-lg-3">
            <div class="antibot-kpi-card">
                <div>
                    <div class="antibot-kpi-top">
                        <span class="antibot-kpi-label">Ловушки / PoW</span>
                        <div class="antibot-kpi-icon-wrap antibot-kpi-icon-primary">🍯</div>
                    </div>
                    <div class="antibot-kpi-value">
                        <?= (int) ($stats['trapHits'] ?? 0) ?>
                        <small class="fs-6 text-muted fw-normal">/ <?= (int) ($stats['powSolved'] ?? 0) ?></small>
                    </div>
                </div>
                <div class="antibot-kpi-footer">
                    <span>Honey URLs & Формы</span>
                    <span class="text-muted">PoW: <?= Html::encode($antibot->powDifficulty) ?></span>
                </div>
            </div>
        </div>

        <!-- 8. Mode & Engine Status -->
        <div class="col-sm-6 col-lg-3">
            <div class="antibot-kpi-card">
                <div>
                    <div class="antibot-kpi-top">
                        <span class="antibot-kpi-label">Защитный профиль</span>
                        <div class="antibot-kpi-icon-wrap antibot-kpi-icon-primary">🛡️</div>
                    </div>
                    <div class="antibot-kpi-value">
                        <span class="antibot-badge-mode antibot-badge-mode-<?= Html::encode($antibot->mode) ?>">
                            <?= Html::encode(ucfirst($antibot->mode)) ?>
                        </span>
                    </div>
                </div>
                <div class="antibot-kpi-footer">
                    <span>Статус системы</span>
                    <span class="text-success fw-bold"><?= $antibot->enabled ? '● Активен' : '○ Отключен' ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- LEVEL 2: Chart #1 — Wide Timeline Dynamics Chart (Line / Area) -->
    <div class="antibot-card mb-4">
        <div class="antibot-card-header">
            <div>
                <h2 class="antibot-card-title">
                    <span>📈 Динамика подозрительной активности</span>
                </h2>
                <div class="text-muted small mt-1">
                    События журнала по времени (при <code>simulated: true</code> действия отображаются как мониторинг без блокировки)
                </div>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <!-- Segmented Control Period Switcher -->
                <div class="antibot-segmented-control" role="group" aria-label="Период выборки">
                    <button type="button" class="antibot-btn-period active" data-period="24h">24 часа</button>
                    <button type="button" class="antibot-btn-period" data-period="7d">7 дней</button>
                    <button type="button" class="antibot-btn-period" data-period="all">Вся история</button>
                </div>
                <!-- Refresh Button with Cooldown -->
                <button type="button" class="antibot-btn-refresh" id="antibot-btn-refresh" title="Обновить аналитику (кэшируется на 120 сек)">
                    <span class="antibot-refresh-icon">🔄</span> Обновить
                </button>
            </div>
        </div>
        <div class="antibot-card-body">
            <!-- Metadata & Honesty Badges -->
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2 small">
                <div class="d-flex gap-2 flex-wrap align-items-center">
                    <span id="antibot-badge-coverage" class="antibot-badge-soft text-success border">
                        ⏱ <strong>Полное окно</strong> (24 часа)
                    </span>
                    <span class="antibot-badge-soft">
                        Учтено событий: <strong id="antibot-badge-processed" class="ms-1"><?= (int) ($analyticsData['events_processed'] ?? 0) ?></strong>
                    </span>
                    <span class="antibot-badge-soft text-muted d-none d-md-inline">
                        Сформировано: <span id="antibot-badge-generated"><?= Html::encode($analyticsData['generated_at'] ?? date('Y-m-d H:i:s')) ?></span>
                    </span>
                </div>
                <div class="text-muted fst-italic small">
                    ℹ️ События журнала, а не весь HTTP-трафик. Статистика Best-Effort.
                </div>
            </div>

            <!-- ECharts Container for Chart #1 -->
            <div id="antibot-chart-dynamics" class="antibot-chart-container antibot-chart-wide"></div>

            <!-- Accessible Fallback Table for No-JS / Screen Readers -->
            <div class="antibot-chart-fallback table-responsive">
                <table class="table table-sm table-bordered">
                    <thead>
                        <tr><th>Интервал</th><th>Мониторинг</th><th>Заблокировано</th><th>Rate Limit</th><th>Challenge</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach (($analyticsData['timeline']['labels'] ?? []) as $idx => $label): ?>
                            <tr>
                                <td><?= Html::encode($label) ?></td>
                                <td><?= (int) ($analyticsData['timeline']['series']['monitoring'][$idx] ?? 0) ?></td>
                                <td><?= (int) ($analyticsData['timeline']['series']['blocked'][$idx] ?? 0) ?></td>
                                <td><?= (int) ($analyticsData['timeline']['series']['throttled'][$idx] ?? 0) ?></td>
                                <td><?= (int) ($analyticsData['timeline']['series']['challenge'][$idx] ?? 0) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- LEVEL 3: Two Cards Side-by-Side (Chart #2 Donut & Chart #3 Reasons Bar) -->
    <div class="row g-4 mb-4">
        <!-- Chart #2: Event Distribution (Donut Chart) -->
        <div class="col-lg-5">
            <div class="antibot-card h-100">
                <div class="antibot-card-header">
                    <div>
                        <h2 class="antibot-card-title">
                            <span>🍩 Распределение действий</span>
                        </h2>
                        <div class="text-muted small">Доля категорий в учтённых событиях журнала</div>
                    </div>
                </div>
                <div class="antibot-card-body">
                    <div id="antibot-chart-distribution" class="antibot-chart-container"></div>

                    <!-- Accessible Fallback -->
                    <div class="antibot-chart-fallback table-responsive">
                        <table class="table table-sm">
                            <?php foreach (($analyticsData['actions']['items'] ?? []) as $act): ?>
                                <tr>
                                    <td><?= Html::encode($act['name']) ?></td>
                                    <td><strong><?= (int) $act['value'] ?></strong> (<?= (float) $act['percent'] ?>%)</td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Chart #3: Main Trigger Reasons (Horizontal Bar Chart) -->
        <div class="col-lg-7">
            <div class="antibot-card h-100">
                <div class="antibot-card-header">
                    <div>
                        <h2 class="antibot-card-title">
                            <span>📊 Основные причины срабатываний</span>
                        </h2>
                        <div class="text-muted small">Топ категорий детекции (с понятными описаниями)</div>
                    </div>
                </div>
                <div class="antibot-card-body">
                    <div id="antibot-chart-reasons" class="antibot-chart-container"></div>

                    <!-- Accessible Fallback -->
                    <div class="antibot-chart-fallback table-responsive">
                        <table class="table table-sm">
                            <?php foreach (($analyticsData['reasons']['items'] ?? []) as $r): ?>
                                <tr>
                                    <td><?= Html::encode($r['label']) ?></td>
                                    <td><strong><?= (int) $r['count'] ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- LEVEL 4: Chart #4 — Most Targeted URLs (Horizontal Bar Chart) -->
    <div class="antibot-card mb-4">
        <div class="antibot-card-header">
            <div>
                <h2 class="antibot-card-title">
                    <span>🎯 Самые атакуемые маршруты (Top-10 URL)</span>
                </h2>
                <div class="text-muted small">
                    Маршруты с наибольшим количеством подозрительных запросов (query string и токены удалены в целях безопасности)
                </div>
            </div>
        </div>
        <div class="antibot-card-body">
            <div id="antibot-chart-routes" class="antibot-chart-container"></div>

            <!-- Accessible Fallback -->
            <div class="antibot-chart-fallback table-responsive">
                <table class="table table-sm">
                    <?php foreach (($analyticsData['routes']['items'] ?? []) as $rt): ?>
                        <tr>
                            <td><code><?= Html::encode($rt['route']) ?></code></td>
                            <td><strong><?= (int) $rt['count'] ?></strong> событий</td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
    </div>

    <!-- Heuristic Rule Advisor Section -->
    <?php if (!empty($recommendations)): ?>
        <div class="antibot-card mb-4 border-start border-4 border-primary">
            <div class="antibot-card-header">
                <div class="d-flex align-items-center gap-2">
                    <h5 class="antibot-card-title">💡 Эвристический советник правил (Rule Advisor)</h5>
                    <span class="badge bg-primary rounded-pill"><?= count($recommendations) ?></span>
                </div>
            </div>
            <div class="antibot-card-body">
                <div class="row g-3">
                    <?php foreach ($recommendations as $rec): ?>
                        <?php
                            $alertClass = match ($rec['severity'] ?? 'info') {
                                'danger' => 'alert-danger',
                                'warning' => 'alert-warning',
                                default => 'alert-info',
                            };
                        ?>
                        <div class="col-12">
                            <div class="alert <?= $alertClass ?> mb-0 d-flex justify-content-between align-items-center flex-wrap gap-2 rounded-3 border-0 shadow-sm">
                                <div>
                                    <div class="fw-bold fs-6 mb-1"><?= Html::encode($rec['title']) ?></div>
                                    <div class="small mb-1"><?= Html::encode($rec['description']) ?></div>
                                    <div class="small text-muted font-monospace">Основание: <?= Html::encode($rec['reason']) ?></div>
                                </div>
                                <div>
                                    <?= Html::beginForm(['/antibot/default/apply-recommendation'], 'post', ['class' => 'd-inline']) ?>
                                        <?= Html::hiddenInput('rec_id', $rec['id']) ?>
                                        <?php if (!empty($rec['suggestedConfig']['blacklist_add'])): ?>
                                            <?= Html::hiddenInput('ip', $rec['suggestedConfig']['blacklist_add']) ?>
                                        <?php endif; ?>
                                        <?= Html::submitButton('Применить рекомендацию', [
                                            'class' => 'btn btn-sm btn-dark rounded-pill px-3 shadow-sm',
                                            'data-confirm' => 'Применить это правило к конфигурации сайта?',
                                        ]) ?>
                                    <?= Html::endForm() ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Rule Simulator Section -->
    <div class="antibot-card mb-4">
        <div class="antibot-card-header">
            <div>
                <h5 class="antibot-card-title">🧪 Симулятор правил ("Что-Если" анализ)</h5>
                <p class="text-muted small mb-0">Проверьте влияние гипотетических лимитов на реальный поток запросов без изменения настроек</p>
            </div>
        </div>
        <div class="antibot-card-body">
            <form method="get" action="<?= Url::to(['/antibot/default/index']) ?>" class="row g-3 align-items-end mb-3">
                <div class="col-md-3">
                    <label class="form-label small fw-bold">Тип проверки:</label>
                    <select name="sim_type" class="form-select form-select-sm rounded-3">
                        <option value="ratelimit" <?= ($simType ?? '') === 'ratelimit' ? 'selected' : '' ?>>Частота запросов (Rate Limit)</option>
                        <option value="ua" <?= ($simType ?? '') === 'ua' ? 'selected' : '' ?>>Маска User-Agent</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold">Макс. запросов / Шаблон UA:</label>
                    <?php if (($simType ?? '') === 'ua'): ?>
                        <input type="text" name="sim_ua" class="form-control form-control-sm rounded-3" value="<?= Html::encode($simUa ?? 'Python') ?>">
                    <?php else: ?>
                        <input type="number" name="sim_max" class="form-control form-control-sm rounded-3" value="<?= (int) ($simMax ?? 20) ?>" min="1" max="1000">
                    <?php endif; ?>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Окно (сек):</label>
                    <input type="number" name="sim_window" class="form-control form-control-sm rounded-3" value="<?= (int) ($simWindow ?? 60) ?>" min="5" max="3600">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-sm btn-primary rounded-3 w-100">Запустить симуляцию</button>
                </div>
            </form>

            <?php if (!empty($simResult)): ?>
                <div class="p-3 rounded-3 bg-light border">
                    <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
                        <div>
                            <strong>Результат симуляции:</strong> <?= Html::encode($simResult['notice']) ?>
                        </div>
                        <div class="d-flex gap-2">
                            <span class="badge bg-secondary rounded-pill">Выборка: <?= (int) $simResult['sampleSize'] ?> событий</span>
                            <span class="badge bg-danger rounded-pill">Было бы заблокировано: <?= (int) $simResult['wouldBlock'] ?> (<?= $simResult['impactPercent'] ?>%)</span>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Self-Test Diagnostics Section -->
    <?php if (!empty($selfTest)): ?>
        <div class="antibot-card mb-4">
            <div class="antibot-card-header">
                <div class="d-flex align-items-center gap-2">
                    <h5 class="antibot-card-title">🩺 Состояние системы и самодиагностика (Self-Test)</h5>
                </div>
                <?php
                    $overallBadge = match ($selfTest['overall'] ?? 'ok') {
                        'fail' => 'bg-danger',
                        'warning' => 'bg-warning text-dark',
                        default => 'bg-success',
                    };
                ?>
                <span class="badge <?= $overallBadge ?> text-uppercase rounded-pill px-3 py-1"><?= Html::encode($selfTest['overall'] ?? 'OK') ?></span>
            </div>
            <div class="antibot-card-body">
                <div class="row g-3">
                    <?php foreach ($selfTest['checks'] ?? [] as $chk): ?>
                        <?php
                            $chkBadge = match ($chk['status'] ?? 'ok') {
                                'fail' => 'text-danger',
                                'warning' => 'text-warning',
                                default => 'text-success',
                            };
                            $chkIcon = match ($chk['status'] ?? 'ok') {
                                'fail' => '❌',
                                'warning' => '⚠️',
                                default => '✅',
                            };
                        ?>
                        <div class="col-md-6">
                            <div class="p-3 rounded-3 bg-light border h-100">
                                <div class="d-flex align-items-center mb-1">
                                    <span class="me-2"><?= $chkIcon ?></span>
                                    <strong class="<?= $chkBadge ?>"><?= Html::encode($chk['name']) ?></strong>
                                </div>
                                <div class="small text-muted mb-1"><?= Html::encode($chk['message']) ?></div>
                                <?php if (!empty($chk['recommendation'])): ?>
                                    <div class="small text-danger">Совет: <?= Html::encode($chk['recommendation']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Live Event Feed Table -->
    <div class="antibot-card mb-4">
        <div class="antibot-card-header">
            <div>
                <h5 class="antibot-card-title">Журнал последних событий (Live Feed)</h5>
                <span class="text-muted small">Зафиксированные подозрительные обращения</span>
            </div>
            <span class="badge bg-light text-dark border rounded-pill px-3 py-1">JSON Lines (.jsonl)</span>
        </div>
        <div class="antibot-card-body p-0">
            <?php if (empty($events)): ?>
                <div class="p-4 text-center text-muted">
                    Журнал событий пуст. Активность ботов пока не фиксировалась.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle antibot-table mb-0">
                        <thead>
                            <tr>
                                <th style="width: 170px;">Время</th>
                                <th style="width: 150px;">IP адрес</th>
                                <th style="width: 140px;">Действие</th>
                                <th>Причина</th>
                                <th>User-Agent</th>
                                <th class="text-end" style="width: 120px;">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($events as $event): ?>
                                <?php
                                    $action = (string) ($event['action'] ?? '');
                                    $badgeClass = match ($action) {
                                        'blocked' => 'bg-danger',
                                        'throttled' => 'bg-warning text-dark',
                                        'allowed_search_bot' => 'bg-success',
                                        'monitored' => 'bg-info text-dark',
                                        'challenged' => 'bg-secondary',
                                        'challenge_passed' => 'bg-primary',
                                        default => 'bg-secondary',
                                    };
                                    $ip = (string) ($event['ip'] ?? '');
                                ?>
                                <tr>
                                    <td class="small text-muted font-monospace">
                                        <?= Html::encode($event['date'] ?? date('Y-m-d H:i:s', (int) ($event['timestamp'] ?? time()))) ?>
                                    </td>
                                    <td>
                                        <code class="fw-bold px-2 py-1 bg-light rounded-2 border"><?= Html::encode($ip) ?></code>
                                    </td>
                                    <td>
                                        <span class="badge <?= $badgeClass ?> rounded-pill px-2 py-1"><?= Html::encode(strtoupper($action)) ?></span>
                                    </td>
                                    <td class="small fw-semibold"><?= Html::encode($event['reason'] ?? '') ?></td>
                                    <td class="small text-muted text-truncate" style="max-width: 300px;" title="<?= Html::encode($event['userAgent'] ?? '') ?>">
                                        <?= Html::encode($event['userAgent'] ?? '—') ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($action !== 'blocked'): ?>
                                            <?= Html::beginForm(['/antibot/default/quick-block'], 'post', ['class' => 'd-inline']) ?>
                                                <?= Html::hiddenInput('ip', $ip) ?>
                                                <?= Html::submitButton('Бан', ['class' => 'btn btn-sm btn-outline-danger rounded-pill py-0 px-2', 'title' => 'Заблокировать этот IP']) ?>
                                            <?= Html::endForm() ?>
                                        <?php else: ?>
                                            <?= Html::beginForm(['/antibot/default/quick-whitelist'], 'post', ['class' => 'd-inline']) ?>
                                                <?= Html::hiddenInput('ip', $ip) ?>
                                                <?= Html::submitButton('В белый', ['class' => 'btn btn-sm btn-outline-success rounded-pill py-0 px-2', 'title' => 'Добавить в белый список']) ?>
                                            <?= Html::endForm() ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
// Initialize ECharts with pre-rendered data and dynamic API endpoint
$initialDataJson = Json::encode($analyticsData);
$analyticsEndpointUrl = Url::to(['/antibot/default/analytics-data']);
$this->registerJs("
    if (window.AntibotCharts) {
        window.AntibotCharts.init({$initialDataJson}, '{$analyticsEndpointUrl}');
    }
");
?>
