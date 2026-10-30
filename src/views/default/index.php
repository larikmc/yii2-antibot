<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var larikmc\antibot\Antibot $antibot */
/** @var larikmc\antibot\Module $module */
/** @var array<string, mixed> $stats */
/** @var array<int, array<string, mixed>> $events */

use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'Антибот — Мониторинг и защита';
$this->params['breadcrumbs'] = [
    ['label' => 'Антибот', 'url' => ['/antibot/default/index']],
    ['label' => 'Мониторинг'],
];
?>

<div class="antibot-dashboard">
    <!-- Header -->
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 fw-bold mb-1">🛡️ Антибот-защита</h1>
            <p class="text-muted mb-0">Мониторинг трафика, отсечение ботов и верификация поисковиков</p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= Url::to(['/antibot/settings/index']) ?>" class="btn btn-outline-primary">
                ⚙️ Настройки правил
            </a>
            <a href="<?= Url::to(['/antibot/ip-list/index']) ?>" class="btn btn-outline-secondary">
                📋 Списки IP (White/Black)
            </a>
            <?php if (!empty($events)): ?>
                <?= Html::beginForm(['/antibot/default/clear-events'], 'post', ['class' => 'd-inline']) ?>
                    <?= Html::submitButton('🗑️ Очистить журнал', [
                        'class' => 'btn btn-outline-danger',
                        'data-confirm' => 'Вы уверены, что хотите очистить журнал событий?',
                    ]) ?>
                <?= Html::endForm() ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Performance & Telemetry Header -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div class="d-flex gap-2 flex-wrap">
            <span class="badge bg-light text-dark border px-3 py-2">
                ⚡ Время проверки: <strong><?= number_format((float) ($perf['avg_ms'] ?? 0.15), 3) ?> мс</strong>
                (p50: <?= number_format((float) ($perf['p50_ms'] ?? 0.12), 3) ?> мс, p95: <?= number_format((float) ($perf['p95_ms'] ?? 0.28), 3) ?> мс)
            </span>
            <span class="badge bg-light text-secondary border px-3 py-2">
                Всего проверено: <strong><?= (int) ($perf['total_checks'] ?? 0) ?></strong>
                <span class="text-success ms-1" title="Разрешено">✓ <?= (int) ($perf['allowed'] ?? 0) ?></span>,
                <span class="text-danger ms-1" title="Заблокировано">✗ <?= (int) ($perf['blocked'] ?? 0) ?></span>,
                <span class="text-warning ms-1" title="Ограничено (Rate Limit)">⏱ <?= (int) ($perf['throttled'] ?? 0) ?></span>,
                <span class="text-info ms-1" title="Капча/PoW Challenge">🧩 <?= (int) ($perf['challenge'] ?? 0) ?></span>
                <?php if (($perf['monitored'] ?? 0) > 0): ?>
                    , <span class="text-primary ms-1" title="Мониторинг">👁 <?= (int) $perf['monitored'] ?></span>
                <?php endif; ?>
                <?php if (($perf['exceptions'] ?? 0) > 0): ?>
                    , <span class="text-danger fw-bold ms-1" title="Исключения">💥 <?= (int) $perf['exceptions'] ?></span>
                <?php endif; ?>
            </span>
            <span class="badge bg-light text-muted border px-3 py-2" title="Режим учета статистики">
                📊 Режим: <strong>Best-Effort</strong> (выборка: <?= (int) ($perf['sample_size'] ?? 0) ?>/<?= (int) ($perf['sample_capacity'] ?? 100) ?>)
                <?php if (($perf['dropped_records'] ?? 0) > 0): ?>
                    <span class="text-warning ms-1" title="Пропущено записей при конкурентной блокировке диска">⚠️ Пропущено: <?= (int) $perf['dropped_records'] ?></span>
                <?php endif; ?>
            </span>
        </div>
    </div>
    <?php if ($module->hasInsecureAccessConfig()): ?>
        <div class="alert alert-danger d-flex align-items-center mb-4" role="alert">
            <span class="fs-4 me-3">⚠️</span>
            <div>
                <strong>Предупреждение безопасности доступа:</strong>
                Модуль настроен с ролью <code>['@']</code> (доступен любому авторизованному пользователю сайта).
                Рекомендуется настроить RBAC-разрешение <code>antibot.manage</code> или ограничить роли <code>accessRoles =&gt; ['admin']</code>.
            </div>
        </div>
    <?php endif; ?>

    <?php if ($antibot->hasSecretKeyWarning()): ?>
        <div class="alert alert-warning d-flex align-items-center mb-4" role="alert">
            <span class="fs-4 me-3">🔑</span>
            <div>
                <strong>Предупреждение ключа подписи:</strong>
                Не задан <code>$secretKey</code> антибота и отсутствует <code>cookieValidationKey</code> в запросе.
                Используется сгенерированный машинный ключ. Рекомендуется задать собственный ключ в настройках.
            </div>
        </div>
    <?php endif; ?>

    <!-- Alert status -->
    <?php if (!$antibot->enabled): ?>
        <div class="alert alert-warning d-flex align-items-center mb-4" role="alert">
            <span class="fs-4 me-2">⚠️</span>
            <div><strong>Защита выключена!</strong> Модуль находится в неактивном состоянии. Перейдите в настройки, чтобы включить защиту.</div>
        </div>
    <?php elseif ($antibot->mode === 'monitoring'): ?>
        <div class="alert alert-info d-flex align-items-center mb-4" role="alert">
            <span class="fs-4 me-2">ℹ️</span>
            <div><strong>Режим мониторинга (Тест):</strong> Обнаруженные боты логируются, но запросы не блокируются.</div>
        </div>
    <?php endif; ?>

    <!-- KPI Cards Grid -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm rounded-3 h-100 p-3 bg-white">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small text-uppercase fw-bold">Всего событий (24ч)</div>
                        <h2 class="display-6 fw-bold mb-0 text-dark"><?= (int) ($stats['last24h']['total'] ?? $stats['total'] ?? 0) ?></h2>
                    </div>
                    <div class="fs-1 text-primary opacity-50">🌐</div>
                </div>
                <div class="small text-muted mt-2">Всего в журнале: <?= (int) ($stats['total'] ?? 0) ?></div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm rounded-3 h-100 p-3 bg-white">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small text-uppercase fw-bold">Заблокировано (24ч)</div>
                        <h2 class="display-6 fw-bold mb-0 text-danger"><?= (int) ($stats['last24h']['blocked'] ?? $stats['blocked'] ?? 0) ?></h2>
                    </div>
                    <div class="fs-1 text-danger opacity-50">🛑</div>
                </div>
                <div class="small text-muted mt-2">Всего блокировок: <?= (int) ($stats['blocked'] ?? 0) ?></div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm rounded-3 h-100 p-3 bg-white">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small text-uppercase fw-bold">Поисковики (SEO)</div>
                        <h2 class="display-6 fw-bold mb-0 text-success"><?= (int) ($stats['searchBots'] ?? 0) ?></h2>
                    </div>
                    <div class="fs-1 text-success opacity-50">🤖</div>
                </div>
                <div class="small text-muted mt-2">Яндекс, Google, Bing (Reverse DNS)</div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm rounded-3 h-100 p-3 bg-white">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small text-uppercase fw-bold">Rate Limiter / Флуд</div>
                        <h2 class="display-6 fw-bold mb-0 text-warning"><?= (int) ($stats['throttled'] ?? 0) ?></h2>
                    </div>
                    <div class="fs-1 text-warning opacity-50">⚡</div>
                </div>
                <div class="small text-muted mt-2">Адаптер: <code><?= Html::encode($antibot->rateLimitAdapter) ?></code></div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm rounded-3 h-100 p-3 bg-white">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small text-uppercase fw-bold">PoW решено</div>
                        <h2 class="display-6 fw-bold mb-0 text-info"><?= (int) ($stats['powSolved'] ?? 0) ?></h2>
                    </div>
                    <div class="fs-1 text-info opacity-50">🛡️</div>
                </div>
                <div class="small text-muted mt-2">Proof-of-Work: <?= Html::encode($antibot->powDifficulty) ?></div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm rounded-3 h-100 p-3 bg-white">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small text-uppercase fw-bold">Срабатываний ловушек</div>
                        <h2 class="display-6 fw-bold mb-0 text-danger"><?= (int) ($stats['trapHits'] ?? 0) ?></h2>
                    </div>
                    <div class="fs-1 text-danger opacity-50">🍯</div>
                </div>
                <div class="small text-muted mt-2">Honey URLs & Формы</div>
            </div>
        </div>
    </div>

    <!-- Top Blocked & Reasons Section -->
    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm rounded-3 h-100">
                <div class="card-header bg-transparent border-0 pt-3 pb-0">
                    <h5 class="card-title fw-bold mb-0">Топ заблокированных IP</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($stats['topIps'])): ?>
                        <div class="text-muted small py-3">Пока нет заблокированных IP.</div>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($stats['topIps'] as $ip => $count): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                    <code><?= Html::encode($ip) ?></code>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge bg-danger rounded-pill"><?= $count ?> событий</span>
                                        <?= Html::beginForm(['/antibot/default/quick-whitelist'], 'post', ['class' => 'd-inline']) ?>
                                            <?= Html::hiddenInput('ip', $ip) ?>
                                            <?= Html::submitButton('В белый', ['class' => 'btn btn-sm btn-outline-success py-0 px-2', 'title' => 'Добавить в белый список']) ?>
                                        <?= Html::endForm() ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm rounded-3 h-100">
                <div class="card-header bg-transparent border-0 pt-3 pb-0">
                    <h5 class="card-title fw-bold mb-0">Причины блокировок</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($stats['topReasons'])): ?>
                        <div class="text-muted small py-3">Пока нет зафиксированных причин.</div>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($stats['topReasons'] as $reason => $count): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                    <span class="small fw-semibold"><?= Html::encode($reason) ?></span>
                                    <span class="badge bg-secondary rounded-pill"><?= $count ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Heuristic Rule Advisor Section -->
    <?php if (!empty($recommendations)): ?>
        <div class="card border-0 shadow-sm rounded-3 mb-4 border-start border-4 border-primary">
            <div class="card-header bg-transparent border-0 pt-3 pb-0 d-flex justify-content-between align-items-center">
                <h5 class="fw-bold mb-0">💡 Эвристический советник правил (Rule Advisor)</h5>
                <span class="badge bg-primary rounded-pill"><?= count($recommendations) ?></span>
            </div>
            <div class="card-body">
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
                            <div class="alert <?= $alertClass ?> mb-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
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
                                            'class' => 'btn btn-sm btn-dark shadow-sm px-3',
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
    <div class="card border-0 shadow-sm rounded-3 mb-4">
        <div class="card-header bg-transparent border-0 pt-3 pb-0">
            <h5 class="card-title fw-bold mb-0">🧪 Симулятор правил ("Что-Если" анализ)</h5>
            <p class="text-muted small mb-0">Проверьте влияние гипотетических лимитов на реальный поток запросов без изменения настроек</p>
        </div>
        <div class="card-body">
            <form method="get" action="<?= Url::to(['/antibot/default/index']) ?>" class="row g-3 align-items-end mb-3">
                <div class="col-md-3">
                    <label class="form-label small fw-bold">Тип проверки:</label>
                    <select name="sim_type" class="form-select form-select-sm">
                        <option value="ratelimit" <?= ($simType ?? '') === 'ratelimit' ? 'selected' : '' ?>>Частота запросов (Rate Limit)</option>
                        <option value="ua" <?= ($simType ?? '') === 'ua' ? 'selected' : '' ?>>Маска User-Agent</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold">Макс. запросов / Шаблон UA:</label>
                    <?php if (($simType ?? '') === 'ua'): ?>
                        <input type="text" name="sim_ua" class="form-control form-control-sm" value="<?= Html::encode($simUa ?? 'Python') ?>">
                    <?php else: ?>
                        <input type="number" name="sim_max" class="form-control form-control-sm" value="<?= (int) ($simMax ?? 20) ?>" min="1" max="1000">
                    <?php endif; ?>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Окно (сек):</label>
                    <input type="number" name="sim_window" class="form-control form-control-sm" value="<?= (int) ($simWindow ?? 60) ?>" min="5" max="3600">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-sm btn-primary w-100">Запустить симуляцию</button>
                </div>
            </form>

            <?php if (!empty($simResult)): ?>
                <div class="p-3 rounded-3 bg-light border">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <div>
                            <strong>Результат симуляции:</strong> <?= Html::encode($simResult['notice']) ?>
                        </div>
                        <div>
                            <span class="badge bg-secondary">Выборка: <?= (int) $simResult['sampleSize'] ?> событий</span>
                            <span class="badge bg-danger">Было бы заблокировано: <?= (int) $simResult['wouldBlock'] ?> (<?= $simResult['impactPercent'] ?>%)</span>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Self-Test Diagnostics Section -->
    <?php if (!empty($selfTest)): ?>
        <div class="card border-0 shadow-sm rounded-3 mb-4">
            <div class="card-header bg-transparent border-0 pt-3 pb-2 d-flex justify-content-between align-items-center">
                <h5 class="card-title fw-bold mb-0">🩺 Состояние системы и самодиагностика (Self-Test)</h5>
                <?php
                    $overallBadge = match ($selfTest['overall'] ?? 'ok') {
                        'fail' => 'bg-danger',
                        'warning' => 'bg-warning text-dark',
                        default => 'bg-success',
                    };
                ?>
                <span class="badge <?= $overallBadge ?> text-uppercase"><?= Html::encode($selfTest['overall'] ?? 'OK') ?></span>
            </div>
            <div class="card-body">
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
    <div class="card border-0 shadow-sm rounded-3">
        <div class="card-header bg-transparent border-0 pt-3 pb-2 d-flex justify-content-between align-items-center">
            <h5 class="card-title fw-bold mb-0">Журнал последних событий (Live Feed)</h5>
            <span class="badge bg-light text-dark border">JSON Lines (.jsonl)</span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($events)): ?>
                <div class="p-4 text-center text-muted">
                    Журнал событий пуст. Активность ботов пока не фиксировалась.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small text-uppercase text-muted">
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
                                        <code class="fw-bold"><?= Html::encode($ip) ?></code>
                                    </td>
                                    <td>
                                        <span class="badge <?= $badgeClass ?>"><?= Html::encode(strtoupper($action)) ?></span>
                                    </td>
                                    <td class="small fw-semibold"><?= Html::encode($event['reason'] ?? '') ?></td>
                                    <td class="small text-muted text-truncate" style="max-width: 320px;" title="<?= Html::encode($event['userAgent'] ?? '') ?>">
                                        <?= Html::encode($event['userAgent'] ?? '—') ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($action !== 'blocked'): ?>
                                            <?= Html::beginForm(['/antibot/default/quick-block'], 'post', ['class' => 'd-inline']) ?>
                                                <?= Html::hiddenInput('ip', $ip) ?>
                                                <?= Html::submitButton('Бан', ['class' => 'btn btn-sm btn-outline-danger py-0 px-2', 'title' => 'Заблокировать этот IP']) ?>
                                            <?= Html::endForm() ?>
                                        <?php else: ?>
                                            <?= Html::beginForm(['/antibot/default/quick-whitelist'], 'post', ['class' => 'd-inline']) ?>
                                                <?= Html::hiddenInput('ip', $ip) ?>
                                                <?= Html::submitButton('В белый', ['class' => 'btn btn-sm btn-outline-success py-0 px-2', 'title' => 'Добавить в белый список']) ?>
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
