<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var array<int, string> $whitelist */
/** @var array<int, string> $blacklist */
/** @var array<int, string> $trustedProxies */
/** @var \larikmc\antibot\Antibot|null $antibot */

use larikmc\antibot\Module;
use larikmc\antibot\services\I18n;
use yii\helpers\Html;
use yii\helpers\Url;

\larikmc\antibot\assets\AntibotDashboardAsset::register($this);

$activeLang = I18n::getLanguage();

$this->title = I18n::t('ip_list_title', $activeLang);
$this->params['breadcrumbs'] = [
    ['label' => 'Антибот', 'url' => ['/antibot/default/index']],
    ['label' => I18n::t('tab_iplist', $activeLang)],
];
?>

<div class="antibot-ip-list">
    <!-- Header Card (Matching Laricum C Admin UI Kit) -->
    <div class="antibot-header-panel">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div class="d-flex align-items-center gap-3">
                <div class="antibot-header-icon">
                    📋
                </div>
                <div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <h1 class="antibot-header-title"><?= Html::encode(I18n::t('iplist_header_title', $activeLang)) ?></h1>
                        <span class="badge bg-light text-muted border rounded-pill px-2 py-1 small fw-normal">v<?= Module::VERSION ?></span>
                    </div>
                    <p class="antibot-header-subtitle"><?= Html::encode(I18n::t('header_desc_iplist', $activeLang)) ?></p>
                </div>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-primary btn-sm rounded-pill px-3 py-2 fw-semibold shadow-sm" data-bs-toggle="collapse" data-bs-target="#bulkImportPanel">
                    <?= Html::encode(I18n::t('bulk_import_btn', $activeLang)) ?>
                </button>
                <a href="<?= Url::to(['/antibot/default/index']) ?>" class="btn btn-outline-secondary btn-sm rounded-pill px-3 py-2 fw-semibold shadow-sm">
                    <?= Html::encode(I18n::t('nav_back_to_monitoring', $activeLang)) ?>
                </a>
            </div>
        </div>

        <!-- Segmented Navigation Tabs (Matching UI Kit .nav-pills-solid) -->
        <div class="antibot-nav-wrap">
            <ul class="nav-pills-solid mb-0">
                <li>
                    <a href="<?= Url::to(['/antibot/default/index']) ?>" class="nav-link">
                        <?= Html::encode(I18n::t('tab_monitoring', $activeLang)) ?>
                    </a>
                </li>
                <li>
                    <a href="<?= Url::to(['/antibot/default/diagnostics']) ?>" class="nav-link">
                        <?= Html::encode(I18n::t('tab_diagnostics', $activeLang)) ?>
                    </a>
                </li>
                <li>
                    <a href="<?= Url::to(['/antibot/settings/index']) ?>" class="nav-link">
                        <?= Html::encode(I18n::t('tab_settings', $activeLang)) ?>
                    </a>
                </li>
                <li>
                    <a href="<?= Url::to(['/antibot/ip-list/index']) ?>" class="nav-link active">
                        <?= Html::encode(I18n::t('tab_iplist', $activeLang)) ?>
                    </a>
                </li>
            </ul>
        </div>
    </div>

    <!-- Collapsible Bulk Import Panel -->
    <div class="collapse mb-4" id="bulkImportPanel">
        <div class="antibot-card p-3">
            <h5 class="fw-bold mb-1 d-flex align-items-center gap-2">
                <span>📥</span> <?= Html::encode(I18n::t('bulk_import_title', $activeLang)) ?>
            </h5>
            <p class="text-muted small mb-3"><?= Html::encode(I18n::t('bulk_import_desc', $activeLang)) ?></p>
            <form method="post" action="<?= Url::to(['/antibot/ip-list/index']) ?>">
                <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->csrfToken) ?>
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">Целевой список:</label>
                        <select name="type" class="form-select rounded-3">
                            <option value="blacklist">⛔ Черный список (Blacklist)</option>
                            <option value="whitelist">✅ Белый список (Whitelist)</option>
                            <option value="trustedProxies">🛡️ Доверенные прокси (Proxies)</option>
                        </select>
                    </div>
                    <div class="col-md-9">
                        <label class="form-label small fw-bold">IP-адреса или подсети CIDR:</label>
                        <textarea name="bulk_entries" class="form-control rounded-3 font-monospace" rows="3" placeholder="185.220.101.5&#10;194.67.210.0/24&#10;2a02:6b8::/32" required></textarea>
                    </div>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-secondary rounded-pill px-3 btn-sm" data-bs-toggle="collapse" data-bs-target="#bulkImportPanel">Отмена</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold btn-sm"><?= Html::encode(I18n::t('bulk_import_submit', $activeLang)) ?></button>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-4">
        <!-- Белый список (Whitelist) -->
        <div class="col-lg-4">
            <div class="antibot-card antibot-list-col antibot-list-col-whitelist h-100">
                <div class="antibot-card-header" style="background: rgba(7, 155, 112, 0.08);">
                    <div>
                        <h2 class="antibot-card-title text-success">
                            <span>✅</span> <?= Html::encode(I18n::t('iplist_whitelist_title', $activeLang)) ?>
                        </h2>
                        <div class="small text-muted mt-1"><?= Html::encode(I18n::t('iplist_whitelist_desc', $activeLang)) ?></div>
                    </div>
                    <span class="badge badge-soft-success rounded-pill px-2 py-1"><?= count($whitelist) ?></span>
                </div>
                <div class="antibot-card-body">
                    <!-- Форма добавления -->
                    <form method="post" action="<?= Url::to(['/antibot/ip-list/index']) ?>" class="mb-4">
                        <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->csrfToken) ?>
                        <input type="hidden" name="type" value="whitelist">
                        <div class="input-group">
                            <input type="text" name="ip_entry" class="form-control" placeholder="127.0.0.1 / 10.0.0.0/8" required>
                            <button type="submit" class="btn btn-success px-3"><?= Html::encode(I18n::t('iplist_add_btn', $activeLang)) ?></button>
                        </div>
                    </form>

                    <!-- Список -->
                    <h6 class="fw-bold small text-muted text-uppercase mb-2"><?= Html::encode(I18n::t('iplist_whitelist_active', $activeLang)) ?></h6>
                    <?php if (empty($whitelist)): ?>
                        <div class="text-muted small p-3 rounded-3 text-center" style="background: rgba(113, 157, 235, 0.05); border: 1px solid var(--ab-line);">
                            <?= Html::encode(I18n::t('iplist_whitelist_empty', $activeLang)) ?>
                        </div>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($whitelist as $ip): ?>
                                <li class="list-group-item antibot-ip-item d-flex justify-content-between align-items-center py-2 px-3">
                                    <code class="antibot-code-badge"><?= Html::encode($ip) ?></code>
                                    <?= Html::beginForm(['/antibot/ip-list/delete'], 'post', ['class' => 'd-inline']) ?>
                                        <?= Html::hiddenInput('type', 'whitelist') ?>
                                        <?= Html::hiddenInput('entry', $ip) ?>
                                        <?= Html::submitButton(I18n::t('iplist_del_btn', $activeLang), [
                                            'class' => 'btn btn-sm btn-outline-danger rounded-pill py-0 px-2',
                                            'data-confirm' => I18n::t('iplist_del_confirm_white', $activeLang, ['entry' => $ip]),
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
                <div class="antibot-card-header" style="background: rgba(226, 78, 108, 0.08);">
                    <div>
                        <h2 class="antibot-card-title text-danger">
                            <span>🚫</span> <?= Html::encode(I18n::t('iplist_blacklist_title', $activeLang)) ?>
                        </h2>
                        <div class="small text-muted mt-1"><?= Html::encode(I18n::t('iplist_blacklist_desc', $activeLang)) ?></div>
                    </div>
                    <span class="badge badge-soft-danger rounded-pill px-2 py-1"><?= count($blacklist) ?></span>
                </div>
                <div class="antibot-card-body">
                    <!-- Форма добавления -->
                    <form method="post" action="<?= Url::to(['/antibot/ip-list/index']) ?>" class="mb-4">
                        <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->csrfToken) ?>
                        <input type="hidden" name="type" value="blacklist">
                        <div class="input-group">
                            <input type="text" name="ip_entry" class="form-control" placeholder="185.220.101.5 / 45.146.0.0/16" required>
                            <button type="submit" class="btn btn-danger px-3"><?= Html::encode(I18n::t('iplist_add_btn', $activeLang)) ?></button>
                        </div>
                    </form>

                    <!-- Список -->
                    <h6 class="fw-bold small text-muted text-uppercase mb-2"><?= Html::encode(I18n::t('iplist_blacklist_active', $activeLang)) ?></h6>
                    <?php if (empty($blacklist)): ?>
                        <div class="text-muted small p-3 rounded-3 text-center" style="background: rgba(113, 157, 235, 0.05); border: 1px solid var(--ab-line);">
                            <?= Html::encode(I18n::t('iplist_blacklist_empty', $activeLang)) ?>
                        </div>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($blacklist as $ip): ?>
                                <li class="list-group-item antibot-ip-item d-flex justify-content-between align-items-center py-2 px-3">
                                    <code class="antibot-code-badge"><?= Html::encode($ip) ?></code>
                                    <?= Html::beginForm(['/antibot/ip-list/delete'], 'post', ['class' => 'd-inline']) ?>
                                        <?= Html::hiddenInput('type', 'blacklist') ?>
                                        <?= Html::hiddenInput('entry', $ip) ?>
                                        <?= Html::submitButton(I18n::t('iplist_del_btn', $activeLang), [
                                            'class' => 'btn btn-sm btn-outline-danger rounded-pill py-0 px-2',
                                            'data-confirm' => I18n::t('iplist_del_confirm_black', $activeLang, ['entry' => $ip]),
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
                <div class="antibot-card-header" style="background: rgba(49, 137, 247, 0.08);">
                    <div>
                        <h2 class="antibot-card-title text-primary">
                            <span>🛡️</span> <?= Html::encode(I18n::t('iplist_proxies_title', $activeLang)) ?>
                        </h2>
                        <div class="small text-muted mt-1"><?= Html::encode(I18n::t('iplist_proxies_desc', $activeLang)) ?></div>
                    </div>
                    <span class="badge badge-soft-primary rounded-pill px-2 py-1"><?= count($trustedProxies) ?></span>
                </div>
                <div class="antibot-card-body">
                    <!-- Форма добавления -->
                    <form method="post" action="<?= Url::to(['/antibot/ip-list/index']) ?>" class="mb-4">
                        <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->csrfToken) ?>
                        <input type="hidden" name="type" value="trustedProxies">
                        <div class="input-group">
                            <input type="text" name="ip_entry" class="form-control" placeholder="173.245.48.0/20 / 127.0.0.1" required>
                            <button type="submit" class="btn btn-primary px-3"><?= Html::encode(I18n::t('iplist_add_btn', $activeLang)) ?></button>
                        </div>
                    </form>

                    <?php if (isset($antibot) && $antibot->autoTrustLocalProxy): ?>
                        <div class="antibot-alert-card alert-info py-2 px-3 small rounded-3 mb-3 d-flex align-items-center gap-2">
                            <span>⚡</span>
                            <div>
                                <strong><?= Html::encode(I18n::t('iplist_proxies_autotrust', $activeLang)) ?></strong><br>
                                <?= Html::encode(I18n::t('iplist_proxies_autotrust_desc', $activeLang)) ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Список -->
                    <h6 class="fw-bold small text-muted text-uppercase mb-2"><?= Html::encode(I18n::t('iplist_proxies_active', $activeLang)) ?></h6>
                    <?php if (empty($trustedProxies)): ?>
                        <div class="text-muted small p-3 rounded-3 text-center" style="background: rgba(113, 157, 235, 0.05); border: 1px solid var(--ab-line);">
                            <?= Html::encode(I18n::t('iplist_proxies_empty', $activeLang)) ?>
                        </div>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($trustedProxies as $ip): ?>
                                <li class="list-group-item antibot-ip-item d-flex justify-content-between align-items-center py-2 px-3">
                                    <code class="antibot-code-badge"><?= Html::encode($ip) ?></code>
                                    <?= Html::beginForm(['/antibot/ip-list/delete'], 'post', ['class' => 'd-inline']) ?>
                                        <?= Html::hiddenInput('type', 'trustedProxies') ?>
                                        <?= Html::hiddenInput('entry', $ip) ?>
                                        <?= Html::submitButton(I18n::t('iplist_del_btn', $activeLang), [
                                            'class' => 'btn btn-sm btn-outline-danger rounded-pill py-0 px-2',
                                            'data-confirm' => I18n::t('iplist_del_confirm_proxy', $activeLang, ['entry' => $ip]),
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
