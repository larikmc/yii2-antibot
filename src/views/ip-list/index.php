<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var array<int, string> $whitelist */
/** @var array<int, string> $blacklist */
/** @var array<int, string> $trustedProxies */

use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'Антибот — Списки IP адресов';
$this->params['breadcrumbs'] = [
    ['label' => 'Антибот', 'url' => ['/antibot/default/index']],
    ['label' => 'Списки IP'],
];
?>

<div class="antibot-ip-list">
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 fw-bold mb-1">📋 Управление списками IP адресов</h1>
            <p class="text-muted mb-0">Белые списки, черные списки и подсети доверенных прокси (поддерживается CIDR)</p>
        </div>
        <div>
            <a href="<?= Url::to(['/antibot/default/index']) ?>" class="btn btn-outline-secondary">
                ← Вернуться в дашборд
            </a>
        </div>
    </div>

    <div class="row g-4">
        <!-- Белый список (Whitelist) -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-3 h-100">
                <div class="card-header bg-success bg-opacity-10 border-0 pt-3 pb-2">
                    <h5 class="fw-bold text-success mb-0">✅ Белый список (Whitelist)</h5>
                    <div class="small text-muted">Запросы с этих IP пропускаются без проверок.</div>
                </div>
                <div class="card-body">
                    <!-- Форма добавления -->
                    <form method="post" action="<?= Url::to(['/antibot/ip-list/index']) ?>" class="mb-4">
                        <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->csrfToken) ?>
                        <input type="hidden" name="type" value="whitelist">
                        <div class="input-group">
                            <input type="text" name="ip_entry" class="form-control" placeholder="127.0.0.1 или 10.0.0.0/8" required>
                            <button type="submit" class="btn btn-success">+ Добавить</button>
                        </div>
                    </form>

                    <!-- Список -->
                    <h6 class="fw-bold small text-muted text-uppercase mb-2">Активные доверенные адреса (<?= count($whitelist) ?>):</h6>
                    <?php if (empty($whitelist)): ?>
                        <div class="text-muted small">Список пуст.</div>
                    <?php else: ?>
                        <ul class="list-group list-group-flush border rounded-2">
                            <?php foreach ($whitelist as $ip): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center py-2 px-3">
                                    <code><?= Html::encode($ip) ?></code>
                                    <?= Html::beginForm(['/antibot/ip-list/delete'], 'post', ['class' => 'd-inline']) ?>
                                        <?= Html::hiddenInput('type', 'whitelist') ?>
                                        <?= Html::hiddenInput('entry', $ip) ?>
                                        <?= Html::submitButton('Удалить', [
                                            'class' => 'btn btn-sm btn-outline-danger py-0 px-2',
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
            <div class="card border-0 shadow-sm rounded-3 h-100">
                <div class="card-header bg-danger bg-opacity-10 border-0 pt-3 pb-2">
                    <h5 class="fw-bold text-danger mb-0">🚫 Черный список (Blacklist)</h5>
                    <div class="small text-muted">Запросы с этих IP немедленно блокируются (403).</div>
                </div>
                <div class="card-body">
                    <!-- Форма добавления -->
                    <form method="post" action="<?= Url::to(['/antibot/ip-list/index']) ?>" class="mb-4">
                        <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->csrfToken) ?>
                        <input type="hidden" name="type" value="blacklist">
                        <div class="input-group">
                            <input type="text" name="ip_entry" class="form-control" placeholder="185.220.101.5 или 45.146.0.0/16" required>
                            <button type="submit" class="btn btn-danger">+ Добавить</button>
                        </div>
                    </form>

                    <!-- Список -->
                    <h6 class="fw-bold small text-muted text-uppercase mb-2">Заблокированные адреса (<?= count($blacklist) ?>):</h6>
                    <?php if (empty($blacklist)): ?>
                        <div class="text-muted small">Список пуст. Ни один IP пока не заблокирован вручную.</div>
                    <?php else: ?>
                        <ul class="list-group list-group-flush border rounded-2">
                            <?php foreach ($blacklist as $ip): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center py-2 px-3">
                                    <code><?= Html::encode($ip) ?></code>
                                    <?= Html::beginForm(['/antibot/ip-list/delete'], 'post', ['class' => 'd-inline']) ?>
                                        <?= Html::hiddenInput('type', 'blacklist') ?>
                                        <?= Html::hiddenInput('entry', $ip) ?>
                                        <?= Html::submitButton('Удалить', [
                                            'class' => 'btn btn-sm btn-outline-danger py-0 px-2',
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
            <div class="card border-0 shadow-sm rounded-3 h-100">
                <div class="card-header bg-primary bg-opacity-10 border-0 pt-3 pb-2">
                    <h5 class="fw-bold text-primary mb-0">🛡️ Доверенные прокси</h5>
                    <div class="small text-muted">Разрешает чтение X-Forwarded-For и CF-Connecting-IP.</div>
                </div>
                <div class="card-body">
                    <!-- Форма добавления -->
                    <form method="post" action="<?= Url::to(['/antibot/ip-list/index']) ?>" class="mb-4">
                        <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->csrfToken) ?>
                        <input type="hidden" name="type" value="trustedProxies">
                        <div class="input-group">
                            <input type="text" name="ip_entry" class="form-control" placeholder="173.245.48.0/20 (Cloudflare)" required>
                            <button type="submit" class="btn btn-primary">+ Добавить</button>
                        </div>
                    </form>

                    <!-- Список -->
                    <h6 class="fw-bold small text-muted text-uppercase mb-2">Активные прокси-узлы (<?= count($trustedProxies) ?>):</h6>
                    <?php if (empty($trustedProxies)): ?>
                        <div class="text-muted small">Список пуст. Заголовки прокси игнорируются для безопасности.</div>
                    <?php else: ?>
                        <ul class="list-group list-group-flush border rounded-2">
                            <?php foreach ($trustedProxies as $ip): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center py-2 px-3">
                                    <code><?= Html::encode($ip) ?></code>
                                    <?= Html::beginForm(['/antibot/ip-list/delete'], 'post', ['class' => 'd-inline']) ?>
                                        <?= Html::hiddenInput('type', 'trustedProxies') ?>
                                        <?= Html::hiddenInput('entry', $ip) ?>
                                        <?= Html::submitButton('Удалить', [
                                            'class' => 'btn btn-sm btn-outline-danger py-0 px-2',
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
