<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var larikmc\antibot\Antibot $antibot */
/** @var larikmc\antibot\Module $module */
/** @var array<string, mixed> $stats */
/** @var array<int, array<string, mixed>> $events */
/** @var array<string, mixed> $perf */
/** @var array<string, mixed> $analyticsData */
/** @var string|null $currentLang */

use larikmc\antibot\Module;
use larikmc\antibot\services\I18n;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\helpers\Url;

\larikmc\antibot\assets\AntibotDashboardAsset::register($this);

$activeLang = $currentLang ?? I18n::getLanguage();

$this->title = I18n::t('app_title', $activeLang);
$this->params['breadcrumbs'] = [
    ['label' => 'Антибот', 'url' => ['/antibot/default/index']],
    ['label' => I18n::t('tab_monitoring', $activeLang)],
];

$perfTotal = (int) ($perf['total_checks'] ?? 0);
?>

<div class="antibot-dashboard">
    <!-- Header Card (Matching Laricum C Admin UI Kit) -->
    <div class="antibot-header-panel">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div class="d-flex align-items-center gap-3">
                <div class="antibot-header-icon">
                    🛡️
                </div>
                <div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <h1 class="antibot-header-title"><?= Html::encode(I18n::t('header_title', $activeLang)) ?></h1>
                        <span class="badge bg-light text-muted border rounded-pill px-2 py-1 small fw-normal">v<?= Module::VERSION ?></span>
                        <span class="badge <?= $antibot->mode === 'monitoring' ? 'badge-soft-info' : ($antibot->mode === 'strict' ? 'badge-soft-danger' : 'badge-soft-primary') ?> rounded-pill px-3 py-1 d-inline-flex align-items-center gap-2" style="font-size: 0.82rem; font-weight: 600;">
                            <span style="width: 7px; height: 7px; border-radius: 50%; background: currentColor; display: inline-block; box-shadow: 0 0 6px currentColor;"></span>
                            <?= Html::encode(I18n::t('mode_label', $activeLang)) ?> <?= Html::encode(match($antibot->mode) {
                                'monitoring' => I18n::t('mode_monitoring', $activeLang),
                                'strict' => I18n::t('mode_strict', $activeLang),
                                default => I18n::t('mode_standard', $activeLang)
                            }) ?>
                        </span>
                    </div>
                    <p class="antibot-header-subtitle">
                        <?= Html::encode(I18n::t('header_desc', $activeLang)) ?>
                    </p>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <?php if (!empty($events)): ?>
                    <?= Html::beginForm(['/antibot/default/clear-events'], 'post', ['class' => 'd-inline']) ?>
                        <?= Html::submitButton(I18n::t('clear_log_btn', $activeLang), [
                            'class' => 'btn btn-outline-danger btn-sm rounded-pill px-3 py-2 fw-semibold shadow-sm',
                            'data-confirm' => I18n::t('clear_log_confirm', $activeLang),
                        ]) ?>
                    <?= Html::endForm() ?>
                <?php endif; ?>
                <a href="<?= Url::to(['/antibot/settings/index']) ?>" class="btn btn-outline-secondary btn-sm rounded-pill px-3 py-2 fw-semibold shadow-sm">
                    <?= Html::encode(I18n::t('settings_btn', $activeLang)) ?>
                </a>
            </div>
        </div>

        <!-- Segmented Navigation Tabs (Matching UI Kit .nav-pills-solid) -->
        <div class="antibot-nav-wrap">
            <ul class="nav-pills-solid mb-0">
                <li>
                    <a href="<?= Url::to(['/antibot/default/index']) ?>" class="nav-link active">
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
                    <a href="<?= Url::to(['/antibot/ip-list/index']) ?>" class="nav-link">
                        <?= Html::encode(I18n::t('tab_iplist', $activeLang)) ?>
                    </a>
                </li>
            </ul>
            <div class="d-flex align-items-center gap-2 small">
                <span class="text-muted d-none d-md-inline"><?= Html::encode(I18n::t('status_module', $activeLang)) ?></span>
                <span class="badge <?= $antibot->enabled ? 'badge-soft-success' : 'badge-soft-secondary' ?> rounded-pill px-3 py-1 fw-semibold">
                    <?= Html::encode($antibot->enabled ? I18n::t('status_active', $activeLang) : I18n::t('status_disabled', $activeLang)) ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Active Mode & System Status Alerts -->
    <?php if ($module->hasInsecureAccessConfig()): ?>
        <div class="antibot-alert-card alert-danger">
            <div class="d-flex align-items-center gap-3">
                <div style="font-size: 1.35rem; flex-shrink: 0;">⚠️</div>
                <div>
                    <strong><?= Html::encode(I18n::t('alert_sec_warning_title', $activeLang)) ?></strong>
                    <div class="small text-muted mt-1"><?= Html::encode(I18n::t('alert_sec_warning_text', $activeLang)) ?></div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($antibot->hasSecretKeyWarning()): ?>
        <div class="antibot-alert-card alert-warning">
            <div class="d-flex align-items-center gap-3">
                <div style="font-size: 1.35rem; flex-shrink: 0;">🔑</div>
                <div>
                    <strong><?= Html::encode(I18n::t('alert_secret_warning_title', $activeLang)) ?></strong>
                    <div class="small text-muted mt-1"><?= Html::encode(I18n::t('alert_secret_warning_text', $activeLang)) ?></div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!$antibot->enabled): ?>
        <div class="antibot-alert-card alert-warning">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                <div class="d-flex align-items-center gap-3">
                    <div style="font-size: 1.35rem; flex-shrink: 0;">⚠️</div>
                    <div>
                        <strong><?= Html::encode(I18n::t('alert_disabled_title', $activeLang)) ?></strong>
                        <span class="text-muted ms-1"><?= Html::encode(I18n::t('alert_disabled_text', $activeLang)) ?></span>
                    </div>
                </div>
                <div>
                    <a href="<?= Url::to(['/antibot/settings/index']) ?>" class="btn btn-sm btn-warning rounded-pill px-3 py-1 fw-semibold text-dark shadow-sm">
                        <?= Html::encode(I18n::t('btn_enable_protection', $activeLang)) ?>
                    </a>
                </div>
            </div>
        </div>
    <?php elseif ($antibot->mode === 'monitoring'): ?>
        <div class="antibot-alert-card alert-info">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                <div class="d-flex align-items-center gap-3">
                    <div style="font-size: 1.35rem; flex-shrink: 0;">ℹ️</div>
                    <div>
                        <strong><?= Html::encode(I18n::t('alert_monitoring_title', $activeLang)) ?></strong>
                        <span class="text-muted ms-1"><?= Html::encode(I18n::t('alert_monitoring_text', $activeLang)) ?></span>
                    </div>
                </div>
                <div>
                    <a href="<?= Url::to(['/antibot/settings/index']) ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3 py-1 fw-semibold shadow-sm">
                        <?= Html::encode(I18n::t('btn_change_mode', $activeLang)) ?>
                    </a>
                </div>
            </div>
        </div>
    <?php elseif ($antibot->mode === 'strict'): ?>
        <div class="antibot-alert-card alert-danger">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                <div class="d-flex align-items-center gap-3">
                    <div style="font-size: 1.35rem; flex-shrink: 0;">🚨</div>
                    <div>
                        <strong><?= Html::encode(I18n::t('alert_strict_title', $activeLang)) ?></strong>
                        <span class="text-muted ms-1"><?= Html::encode(I18n::t('alert_strict_text', $activeLang)) ?></span>
                    </div>
                </div>
                <div>
                    <a href="<?= Url::to(['/antibot/settings/index']) ?>" class="btn btn-sm btn-outline-danger rounded-pill px-3 py-1 fw-semibold shadow-sm">
                        <?= Html::encode(I18n::t('btn_change_mode', $activeLang)) ?>
                    </a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- LEVEL 1: Compact 4 KPI Cards Grid -->
    <div class="row g-3 mb-4">
        <!-- 1. Total Checks -->
        <div class="col-sm-6 col-lg-3">
            <div class="antibot-kpi-card">
                <div>
                    <div class="antibot-kpi-top">
                        <span class="antibot-kpi-label"><?= Html::encode(I18n::t('kpi_inspected', $activeLang)) ?></span>
                        <div class="antibot-kpi-icon-wrap antibot-kpi-icon-primary">🌐</div>
                    </div>
                    <div class="antibot-kpi-value"><?= number_format($perfTotal) ?></div>
                </div>
                <div class="antibot-kpi-footer">
                    <span><?= Html::encode(I18n::t('kpi_total_requests', $activeLang)) ?></span>
                    <span class="antibot-kpi-tag antibot-kpi-tag-success">✓ <?= (int) ($perf['allowed'] ?? 0) ?> OK</span>
                </div>
            </div>
        </div>

        <!-- 2. Suspicious Events Recorded -->
        <div class="col-sm-6 col-lg-3">
            <div class="antibot-kpi-card">
                <div>
                    <div class="antibot-kpi-top">
                        <span class="antibot-kpi-label"><?= Html::encode(I18n::t('kpi_events', $activeLang)) ?></span>
                        <div class="antibot-kpi-icon-wrap antibot-kpi-icon-warning">📝</div>
                    </div>
                    <div class="antibot-kpi-value"><?= number_format((int) ($stats['total'] ?? 0)) ?></div>
                </div>
                <div class="antibot-kpi-footer">
                    <span><?= Html::encode(I18n::t('kpi_last_24h', $activeLang)) ?></span>
                    <strong><?= (int) ($stats['last24h']['total'] ?? 0) ?></strong>
                </div>
            </div>
        </div>

        <!-- 3. Blocked (403) or Monitored -->
        <div class="col-sm-6 col-lg-3">
            <div class="antibot-kpi-card">
                <div>
                    <div class="antibot-kpi-top">
                        <span class="antibot-kpi-label"><?= Html::encode(I18n::t('kpi_blocked', $activeLang)) ?></span>
                        <div class="antibot-kpi-icon-wrap antibot-kpi-icon-danger">🛑</div>
                    </div>
                    <div class="antibot-kpi-value text-danger"><?= number_format((int) ($stats['blocked'] ?? 0)) ?></div>
                </div>
                <div class="antibot-kpi-footer">
                    <span><?= Html::encode(I18n::t('kpi_last_24h', $activeLang)) ?></span>
                    <?php if (($stats['monitored'] ?? 0) > 0 || ($perf['monitored'] ?? 0) > 0): ?>
                        <span class="antibot-kpi-tag antibot-kpi-tag-info">👁 <?= (int) max($stats['monitored'] ?? 0, $perf['monitored'] ?? 0) ?> <?= Html::encode(I18n::t('kpi_in_monitoring', $activeLang)) ?></span>
                    <?php else: ?>
                        <strong class="text-danger"><?= (int) ($stats['last24h']['blocked'] ?? 0) ?></strong>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- 4. Search Bots (SEO) -->
        <div class="col-sm-6 col-lg-3">
            <div class="antibot-kpi-card">
                <div>
                    <div class="antibot-kpi-top">
                        <span class="antibot-kpi-label"><?= Html::encode(I18n::t('kpi_search_bots', $activeLang)) ?></span>
                        <div class="antibot-kpi-icon-wrap antibot-kpi-icon-success">🤖</div>
                    </div>
                    <div class="antibot-kpi-value text-success"><?= number_format((int) ($stats['searchBots'] ?? 0)) ?></div>
                </div>
                <div class="antibot-kpi-footer">
                    <span><?= Html::encode(I18n::t('kpi_search_desc', $activeLang)) ?></span>
                    <span class="antibot-kpi-tag antibot-kpi-tag-success">Reverse DNS</span>
                </div>
            </div>
        </div>
    </div>

    <!-- LEVEL 2: Chart #1 — Wide Timeline Dynamics Chart (Line / Area) -->
    <div class="antibot-card mb-4">
        <div class="antibot-card-header">
            <div>
                <h2 class="antibot-card-title">
                    <span><?= Html::encode(I18n::t('chart_dynamics_title', $activeLang)) ?></span>
                </h2>
                <div class="text-muted small mt-1">
                    <?= Html::encode(I18n::t('chart_dynamics_desc', $activeLang)) ?>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <!-- Segmented Control Period Switcher -->
                <div class="antibot-segmented-control" role="group">
                    <button type="button" class="antibot-btn-period active" data-period="24h"><?= Html::encode(I18n::t('period_24h', $activeLang)) ?></button>
                    <button type="button" class="antibot-btn-period" data-period="7d"><?= Html::encode(I18n::t('period_7d', $activeLang)) ?></button>
                    <button type="button" class="antibot-btn-period" data-period="all"><?= Html::encode(I18n::t('period_all', $activeLang)) ?></button>
                </div>
                <!-- Refresh Button with Cooldown -->
                <button type="button" class="antibot-btn-refresh" id="antibot-btn-refresh">
                    <span class="antibot-refresh-icon">🔄</span> <?= Html::encode(I18n::t('btn_refresh', $activeLang)) ?>
                </button>
            </div>
        </div>
        <div class="antibot-card-body">
            <!-- Metadata & Honesty Badges -->
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2 small">
                <div class="d-flex gap-2 flex-wrap align-items-center">
                    <span id="antibot-badge-coverage" class="antibot-badge-soft badge-soft-success border">
                        ⏱ <strong><?= Html::encode(I18n::t('badge_full_window', $activeLang)) ?></strong> (<?= Html::encode(I18n::t('period_24h', $activeLang)) ?>)
                    </span>
                    <span class="antibot-badge-soft">
                        <?= Html::encode(I18n::t('badge_events_processed', $activeLang)) ?> <strong id="antibot-badge-processed" class="ms-1"><?= (int) ($analyticsData['events_processed'] ?? 0) ?></strong>
                    </span>
                    <span class="antibot-badge-soft text-muted d-none d-md-inline">
                        <?= Html::encode(I18n::t('badge_generated_at', $activeLang)) ?> <span id="antibot-badge-generated"><?= Html::encode($analyticsData['generated_at'] ?? date('Y-m-d H:i:s')) ?></span>
                    </span>
                </div>
                <div class="text-muted fst-italic small">
                    <?= Html::encode(I18n::t('chart_disclaimer', $activeLang)) ?>
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
                            <span><?= Html::encode(I18n::t('chart_dist_title', $activeLang)) ?></span>
                        </h2>
                        <div class="text-muted small"><?= Html::encode(I18n::t('chart_dist_desc', $activeLang)) ?></div>
                    </div>
                </div>
                <div class="antibot-card-body">
                    <div id="antibot-chart-distribution" class="antibot-chart-container"></div>
                </div>
            </div>
        </div>

        <!-- Chart #3: Main Trigger Reasons (Horizontal Bar Chart) -->
        <div class="col-lg-7">
            <div class="antibot-card h-100">
                <div class="antibot-card-header">
                    <div>
                        <h2 class="antibot-card-title">
                            <span><?= Html::encode(I18n::t('chart_reasons_title', $activeLang)) ?></span>
                        </h2>
                        <div class="text-muted small"><?= Html::encode(I18n::t('chart_reasons_desc', $activeLang)) ?></div>
                    </div>
                </div>
                <div class="antibot-card-body">
                    <div id="antibot-chart-reasons" class="antibot-chart-container"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- LEVEL 4: Chart #4 — Most Targeted URLs (Horizontal Bar Chart) -->
    <div class="antibot-card mb-4">
        <div class="antibot-card-header">
            <div>
                <h2 class="antibot-card-title">
                    <span><?= Html::encode(I18n::t('chart_routes_title', $activeLang)) ?></span>
                </h2>
                <div class="text-muted small">
                    <?= Html::encode(I18n::t('chart_routes_desc', $activeLang)) ?>
                </div>
            </div>
        </div>
        <div class="antibot-card-body">
            <div id="antibot-chart-routes" class="antibot-chart-container"></div>
        </div>
    </div>

    <!-- LEVEL 5: Live Event Feed Table (Directly visible below the charts) -->
    <div class="antibot-card mb-4">
        <div class="antibot-card-header">
            <div>
                <h5 class="antibot-card-title"><?= Html::encode(I18n::t('table_title', $activeLang)) ?></h5>
                <span class="text-muted small"><?= Html::encode(I18n::t('table_desc', $activeLang)) ?></span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-light text-dark border rounded-pill px-3 py-1">JSON Lines (.jsonl)</span>
            </div>
        </div>
        <div class="antibot-card-body p-0">
            <?php if (empty($events)): ?>
                <div class="p-4 text-center text-muted">
                    <?= Html::encode(I18n::t('table_empty', $activeLang)) ?>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle antibot-table mb-0">
                        <thead>
                            <tr>
                                <th style="width: 170px;"><?= Html::encode(I18n::t('th_time', $activeLang)) ?></th>
                                <th style="width: 150px;"><?= Html::encode(I18n::t('th_ip', $activeLang)) ?></th>
                                <th style="width: 140px;"><?= Html::encode(I18n::t('th_action', $activeLang)) ?></th>
                                <th><?= Html::encode(I18n::t('th_reason', $activeLang)) ?></th>
                                <th><?= Html::encode(I18n::t('th_ua', $activeLang)) ?></th>
                                <th class="text-end" style="width: 120px;"><?= Html::encode(I18n::t('th_actions', $activeLang)) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($events as $event): ?>
                                <?php
                                    $action = (string) ($event['action'] ?? '');
                                    $actionData = I18n::formatAction($action, $activeLang);
                                    $reasonData = I18n::formatReason((string) ($event['reason'] ?? ''), $activeLang);
                                    $ip = (string) ($event['ip'] ?? '');
                                ?>
                                <tr>
                                    <td class="small text-muted font-monospace">
                                        <?= Html::encode($event['date'] ?? date('Y-m-d H:i:s', (int) ($event['timestamp'] ?? time()))) ?>
                                    </td>
                                    <td>
                                        <code class="antibot-code-badge"><?= Html::encode($ip) ?></code>
                                    </td>
                                    <td>
                                        <span class="badge <?= $actionData['badgeClass'] ?> rounded-pill px-2 py-1 shadow-sm d-inline-flex align-items-center gap-1" title="<?= Html::encode($actionData['description']) ?>">
                                            <span><?= $actionData['icon'] ?></span>
                                            <span><?= Html::encode($actionData['label']) ?></span>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2 flex-wrap">
                                            <span class="badge bg-light text-secondary border small rounded-pill px-2 py-0" style="font-size: 0.72rem; font-weight: 600;"><?= Html::encode($reasonData['badge']) ?></span>
                                            <span class="small fw-semibold text-dark" title="<?= Html::encode($reasonData['raw']) ?>"><?= Html::encode($reasonData['title']) ?></span>
                                        </div>
                                    </td>
                                    <td class="small text-muted text-truncate" style="max-width: 300px;" title="<?= Html::encode($event['userAgent'] ?? '') ?>">
                                        <?= Html::encode($event['userAgent'] ?? '—') ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($action !== 'blocked'): ?>
                                            <?= Html::beginForm(['/antibot/default/quick-block'], 'post', ['class' => 'd-inline']) ?>
                                                <?= Html::hiddenInput('ip', $ip) ?>
                                                <?= Html::submitButton(I18n::t('btn_ban', $activeLang), ['class' => 'btn btn-sm btn-outline-danger rounded-pill py-0 px-2', 'title' => I18n::t('ban_ip_title', $activeLang)]) ?>
                                            <?= Html::endForm() ?>
                                        <?php else: ?>
                                            <?= Html::beginForm(['/antibot/default/quick-whitelist'], 'post', ['class' => 'd-inline']) ?>
                                                <?= Html::hiddenInput('ip', $ip) ?>
                                                <?= Html::submitButton(I18n::t('btn_whitelist', $activeLang), ['class' => 'btn btn-sm btn-outline-success rounded-pill py-0 px-2', 'title' => I18n::t('whitelist_ip_title', $activeLang)]) ?>
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
// Initialize ECharts with pre-rendered data, dynamic API endpoint, and active language
$initialDataJson = Json::encode($analyticsData);
$analyticsEndpointUrl = Url::to(['/antibot/default/analytics-data']);
$this->registerJs("
    if (window.AntibotCharts) {
        window.AntibotCharts.init({$initialDataJson}, '{$analyticsEndpointUrl}', '{$activeLang}');
    }
");
?>
