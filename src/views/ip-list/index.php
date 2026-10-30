<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var array<int, string> $whitelist */
/** @var array<int, string> $blacklist */
/** @var array<int, string> $trustedProxies */

use yii\helpers\Html;
use yii\helpers\Url;

\larikmc\antibot\assets\AntibotDashboardAsset::register($this);

$this->title = 'Антибот — Списки IP адресов';
$this->params['breadcrumbs'] = [
    ['label' => 'Антибот', 'url' => ['/antibot/default/index']],
    ['label' => 'Списки IP'],
];
?>

<div class="antibot-ip-list">
    <!-- Modern Header -->
    <div class="antibot-header d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div>
            <h1 class="antibot-title">
                <span>📋</span> Списки IP адресов и подсетей
            </h1>
            <p class="antibot-subtitle">Белые списки, черные списки и диапазоны доверенных прокси (поддерживается нотация CIDR)</p>
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
        <a href="<?= Url::to(['/antibot/settings/index']) ?>" class="antibot-nav-link">
            ⚙️ Настройки правил
        </a>
        <a href="<?= Url::to(['/antibot/ip-list/index']) ?>" class="antibot-nav-link active">
            📋 Списки IP (White/Black)
        </a>
    </div>

    <div class="row g-4">
        <!-- Белый список (Whitelist) -->
        <div class="col-lg-4">
            <div class="antibot-card antibot-list-col antibot-list-col-whitelist h-100">
                <div class="antibot-card-header bg-success bg-opacity-10">
                    <div>
                        <h2 class="antibot-card-title text-success">
                            <span>✅</span> Белый список (Whitelist)
                        </h2>
                        <div class="small text-muted mt-1">Запросы с этих IP пропускаются без проверок</div>
                    </div>
                    <span class="badge bg-success rounded-pill px-2 py-1"><?= count($whitelist) ?></span>
                </div>
                <div class="antibot-card-body">
                    <!-- Форма добавления -->
                    <form method="post" action="<?= Url::to(['/antibot/ip-list/index']) ?>" class="mb-4">
                        <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->csrfToken) ?>
                        <input type="hidden" name="type" value="whitelist">
                        <div class="input-group">
                            <input type="text" name="ip_entry" class="form-control rounded-start-3" placeholder="127.0.0.1 или 10.0.0.0/8" required>
                            <button type="submit" class="btn btn-success rounded-end-3 px-3">+ Добавить</button>
                        </div>
                    </form>

                    <!-- Список -->
                    <h6 class="fw-bold small text-muted text-uppercase mb-2">Активные доверенные адреса:</h6>
                    <?php if (empty($whitelist)): ?>
                        <div class="text-muted small p-3 bg-light rounded-3 text-center">
                            Список пуст.
                        </div>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($whitelist as $ip): ?>
                                <li class="list-group-item antibot-ip-item d-flex justify-content-between align-items-center py-2 px-3">
                                    <code><?= Html::encode($ip) ?></code>
                                    <?= Html::beginForm(['/antibot/ip-list/delete'], 'post', ['class' => 'd-inline']) ?>
                                        <?= Html::hiddenInput('type', 'whitelist') ?>
                                        <?= Html::hiddenInput('entry', $ip) ?>
                                        <?= Html::submitButton('Удалить', [
                                            'class' => 'btn btn-sm btn-outline-danger rounded-pill py-0 px-2',
                                            'data-confirm' => "Удалить '{$ip}' из белого списка?",
                                        ]) ?>
                                    <?= Html::endForm() ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Черный список (Blacklist) -->
        <div class="col-lg-4">
            <div class="antibot-card antibot-list-col antibot-list-col-blacklist h-100">
                <div class="antibot-card-header bg-danger bg-opacity-10">
                    <div>
                        <h2 class="antibot-card-title text-danger">
                            <span>🚫</span> Черный список (Blacklist)
                        </h2>
                        <div class="small text-muted mt-1">Запросы с этих IP немедленно блокируются (403)</div>
                    </div>
                    <span class="badge bg-danger rounded-pill px-2 py-1"><?= count($blacklist) ?></span>
                </div>
                <div class="antibot-card-body">
                    <!-- Форма добавления -->
                    <form method="post" action="<?= Url::to(['/antibot/ip-list/index']) ?>" class="mb-4">
                        <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->csrfToken) ?>
                        <input type="hidden" name="type" value="blacklist">
                        <div class="input-group">
                            <input type="text" name="ip_entry" class="form-control rounded-start-3" placeholder="185.220.101.5 или 45.146.0.0/16" required>
                            <button type="submit" class="btn btn-danger rounded-end-3 px-3">+ Добавить</button>
                        </div>
                    </form>

                    <!-- Список -->
                    <h6 class="fw-bold small text-muted text-uppercase mb-2">Заблокированные адреса:</h6>
                    <?php if (empty($blacklist)): ?>
                        <div class="text-muted small p-3 bg-light rounded-3 text-center">
                            Список пуст. Ни один IP пока не заблокирован вручную.
                        </div>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($blacklist as $ip): ?>
                                <li class="list-group-item antibot-ip-item d-flex justify-content-between align-items-center py-2 px-3">
                                    <code><?= Html::encode($ip) ?></code>
                                    <?= Html::beginForm(['/antibot/ip-list/delete'], 'post', ['class' => 'd-inline']) ?>
                                        <?= Html::hiddenInput('type', 'blacklist') ?>
                                        <?= Html::hiddenInput('entry', $ip) ?>
                                        <?= Html::submitButton('Удалить', [
                                            'class' => 'btn btn-sm btn-outline-danger rounded-pill py-0 px-2',
                                            'data-confirm' => "Удалить '{$ip}' из черного списка?",
                                        ]) ?>
                                    <?= Html::endForm() ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Доверенные прокси (Trusted Proxies) -->
        <div class="col-lg-4">
            <div class="antibot-card antibot-list-col antibot-list-col-proxies h-100">
                <div class="antibot-card-header bg-primary bg-opacity-10">
                    <div>
                        <h2 class="antibot-card-title text-primary">
                            <span>🛡️</span> Доверенные прокси
                        </h2>
                        <div class="small text-muted mt-1">Разрешает чтение X-Forwarded-For и CF-Connecting-IP</div>
                    </div>
                    <span class="badge bg-primary rounded-pill px-2 py-1"><?= count($trustedProxies) ?></span>
                </div>
                <div class="antibot-card-body">
                    <!-- Форма добавления -->
                    <form method="post" action="<?= Url::to(['/antibot/ip-list/index']) ?>" class="mb-4">
                        <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->csrfToken) ?>
                        <input type="hidden" name="type" value="trustedProxies">
                        <div class="input-group">
                            <input type="text" name="ip_entry" class="form-control rounded-start-3" placeholder="173.245.48.0/20 или 127.0.0.1" required>
                            <button type="submit" class="btn btn-primary rounded-end-3 px-3">+ Добавить</button>
                        </div>
                    </form>

                    <!-- Список -->
                    <h6 class="fw-bold small text-muted text-uppercase mb-2">Активные прокси-узлы:</h6>
                    <?php if (empty($trustedProxies)): ?>
                        <div class="text-muted small p-3 bg-light rounded-3 text-center">
                            Список пуст. Заголовки прокси игнорируются для защиты от подмены IP.
                        </div>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($trustedProxies as $ip): ?>
                                <li class="list-group-item antibot-ip-item d-flex justify-content-between align-items-center py-2 px-3">
                                    <code><?= Html::encode($ip) ?></code>
                                    <?= Html::beginForm(['/antibot/ip-list/delete'], 'post', ['class' => 'd-inline']) ?>
                                        <?= Html::hiddenInput('type', 'trustedProxies') ?>
                                        <?= Html::hiddenInput('entry', $ip) ?>
                                        <?= Html::submitButton('Удалить', [
                                            'class' => 'btn btn-sm btn-outline-danger rounded-pill py-0 px-2',
                                            'data-confirm' => "Удалить '{$ip}' из списка прокси?",
                                        ]) ?>
                                    <?= Html::endForm() ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
