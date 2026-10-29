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

    <!-- Security Warnings -->
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
