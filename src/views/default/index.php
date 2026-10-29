<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var larikmc\antibot\Antibot $antibot */
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
        </div>
    </div>

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
                        <div class="text-muted small text-uppercase fw-bold">Всего запросов</div>
                        <h2 class="display-6 fw-bold mb-0 text-dark"><?= (int) ($stats['total'] ?? 0) ?></h2>
                    </div>
                    <div class="fs-1 text-primary opacity-50">🌐</div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm rounded-3 h-100 p-3 bg-white">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small text-uppercase fw-bold">Заблокировано ботов</div>
                        <h2 class="display-6 fw-bold mb-0 text-danger"><?= (int) ($stats['blocked'] ?? 0) ?></h2>
                    </div>
                    <div class="fs-1 text-danger opacity-50">🛑</div>
                </div>
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
                <div class="small text-muted mt-2">Яндекс, Google, Bing (PTR подтвержден)</div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm rounded-3 h-100 p-3 bg-white">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted small text-uppercase fw-bold">Режим защиты</div>
                        <h3 class="h4 fw-bold mb-0 text-capitalize text-info"><?= Html::encode($antibot->mode) ?></h3>
                    </div>
                    <div class="fs-1 text-info opacity-50">⚡</div>
                </div>
                <div class="small text-muted mt-2">
                    <?php if ($antibot->mode === 'strict'): ?>
                        Строгий (JS-челлендж для всех)
                    <?php elseif ($antibot->mode === 'monitoring'): ?>
                        Только аудит логов
                    <?php else: ?>
                        Стандартный (авто-отсечение)
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Secondary Row: Top IPs and Top Reasons -->
    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm rounded-3 h-100">
                <div class="card-header bg-transparent border-0 pt-3 pb-0">
                    <h5 class="card-title fw-bold mb-0">Топ заблокированных IP-адресов</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($stats['topIps'])): ?>
                        <div class="text-muted small py-3">Пока нет заблокированных IP.</div>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($stats['topIps'] as $ip => $count): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                    <code><?= Html::encode($ip) ?></code>
                                    <div>
                                        <span class="badge bg-danger rounded-pill me-2"><?= $count ?> заблокировано</span>
                                        <a href="<?= Url::to(['quick-whitelist', 'ip' => $ip]) ?>" class="btn btn-sm btn-outline-success py-0 px-2" title="В белый список">В белый</a>
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
                        <div class="text-muted small py-3">Блокировок еще не зафиксировано.</div>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($stats['topReasons'] as $reason => $count): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                    <span><?= Html::encode($reason) ?></span>
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
        <div class="card-header bg-transparent border-0 pt-3 pb-2 d-flex justify-content-between align-items-center flex-wrap">
            <h5 class="card-title fw-bold mb-0">Лента последних событий (Live Feed)</h5>
            <span class="badge bg-light text-dark border">Последние <?= count($events) ?> записей</span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($events)): ?>
                <div class="p-4 text-center text-muted">
                    Событий трафика пока нет. Как только на сайт зайдут посетители или боты, они появятся в этой таблице.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 170px;">Время</th>
                                <th style="width: 140px;">IP адрес</th>
                                <th style="width: 110px;">Действие</th>
                                <th>Причина / Источник</th>
                                <th>User-Agent</th>
                                <th style="width: 120px;" class="text-end">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($events as $event): ?>
                                <?php
                                $action = (string) ($event['action'] ?? '');
                                $badgeClass = match ($action) {
                                    'blocked' => 'bg-danger',
                                    'allowed_search_bot' => 'bg-success',
                                    'challenged' => 'bg-warning text-dark',
                                    default => 'bg-secondary',
                                };
                                $ip = (string) ($event['ip'] ?? '');
                                ?>
                                <tr>
                                    <td class="small text-muted font-monospace"><?= Html::encode($event['date'] ?? '') ?></td>
                                    <td>
                                        <code><?= Html::encode($ip) ?></code>
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
                                            <a href="<?= Url::to(['quick-block', 'ip' => $ip]) ?>" class="btn btn-sm btn-outline-danger py-0 px-2" title="Заблокировать этот IP">Бан</a>
                                        <?php else: ?>
                                            <a href="<?= Url::to(['quick-whitelist', 'ip' => $ip]) ?>" class="btn btn-sm btn-outline-success py-0 px-2" title="Добавить IP в белый список">В белый</a>
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
