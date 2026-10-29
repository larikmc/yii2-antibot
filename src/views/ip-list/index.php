<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var array<int, string> $whitelist */
/** @var array<int, string> $blacklist */

use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'Антибот — Белые и черные списки IP';
$this->params['breadcrumbs'] = [
    ['label' => 'Антибот', 'url' => ['/antibot/default/index']],
    ['label' => 'Списки IP'],
];
?>

<div class="antibot-ip-lists">
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 fw-bold mb-1">📋 Списки IP-адресов и подсетей</h1>
            <p class="text-muted mb-0">Поддерживаются одиночные IP (1.2.3.4) и подсети в формате CIDR (192.168.0.0/24)</p>
        </div>
        <div>
            <a href="<?= Url::to(['/antibot/default/index']) ?>" class="btn btn-outline-secondary">
                ← Вернуться в дашборд
            </a>
        </div>
    </div>

    <div class="row g-4">
        <!-- Белый список (Whitelist) -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm rounded-3 h-100">
                <div class="card-header bg-success bg-opacity-10 border-0 pt-3 pb-2">
                    <h5 class="fw-bold text-success mb-0">✅ Белый список (Whitelist)</h5>
                    <div class="small text-muted">Запросы с этих IP пропускаются без каких-либо проверок.</div>
                </div>
                <div class="card-body">
                    <!-- Форма добавления -->
                    <form method="post" action="<?= Url::to(['/antibot/ip-list/index']) ?>" class="mb-4">
                        <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->csrfToken) ?>
                        <input type="hidden" name="type" value="whitelist">
                        <div class="input-group">
                            <input type="text" name="ip_entry" class="form-control" placeholder="Например: 95.26.144.17 или 10.0.0.0/8" required>
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
                                    <a href="<?= Url::to(['/antibot/ip-list/delete', 'type' => 'whitelist', 'entry' => $ip]) ?>"
                                       class="btn btn-sm btn-outline-danger py-0 px-2"
                                       data-confirm="Удалить '<?= Html::encode($ip) ?>' из белого списка?">Удалить</a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Черный список (Blacklist) -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm rounded-3 h-100">
                <div class="card-header bg-danger bg-opacity-10 border-0 pt-3 pb-2">
                    <h5 class="fw-bold text-danger mb-0">🚫 Черный список (Blacklist)</h5>
                    <div class="small text-muted">Запросы с этих IP немедленно блокируются (403 Forbidden).</div>
                </div>
                <div class="card-body">
                    <!-- Форма добавления -->
                    <form method="post" action="<?= Url::to(['/antibot/ip-list/index']) ?>" class="mb-4">
                        <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->csrfToken) ?>
                        <input type="hidden" name="type" value="blacklist">
                        <div class="input-group">
                            <input type="text" name="ip_entry" class="form-control" placeholder="Например: 185.220.101.5 или 45.146.0.0/16" required>
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
                                    <a href="<?= Url::to(['/antibot/ip-list/delete', 'type' => 'blacklist', 'entry' => $ip]) ?>"
                                       class="btn btn-sm btn-outline-danger py-0 px-2"
                                       data-confirm="Удалить '<?= Html::encode($ip) ?>' из черного списка?">Удалить</a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
