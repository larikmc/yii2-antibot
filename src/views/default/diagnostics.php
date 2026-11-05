<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var larikmc\antibot\Antibot $antibot */
/** @var larikmc\antibot\Module $module */
/** @var array<int, array<string, mixed>> $recommendations */
/** @var array<string, mixed>|null $simResult */
/** @var string $simType */
/** @var int $simMax */
/** @var int $simWindow */
/** @var string $simUa */
/** @var array<int, array<string, mixed>> $auditTrail */
/** @var array<string, mixed> $perf */
/** @var array<string, mixed> $selfTest */
/** @var string|null $currentLang */

use larikmc\antibot\Module;
use larikmc\antibot\services\I18n;
use yii\helpers\Html;
use yii\helpers\Url;

\larikmc\antibot\assets\AntibotDashboardAsset::register($this);

$activeLang = $currentLang ?? I18n::getLanguage();

$this->title = I18n::t('diagnostics_title', $activeLang);
$this->params['breadcrumbs'] = [
    ['label' => 'Антибот', 'url' => ['/antibot/default/index']],
    ['label' => I18n::t('tab_diagnostics', $activeLang)],
];

$perfTotal = (int) ($perf['total_checks'] ?? 0);
$perfAvg = ($perf['avg_ms'] ?? null) !== null ? number_format((float) $perf['avg_ms'], 3) : null;
$perfP95 = ($perf['p95_ms'] ?? null) !== null ? number_format((float) $perf['p95_ms'], 3) : null;
?>

<div class="antibot-diagnostics">
    <!-- Header Card (Matching Laricum C Admin UI Kit) -->
    <div class="antibot-header-panel">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div class="d-flex align-items-center gap-3">
                <div class="antibot-header-icon">
                    🩺
                </div>
                <div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <h1 class="antibot-header-title"><?= Html::encode(I18n::t('diag_advisor_title', $activeLang)) ?></h1>
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
                        <?= Html::encode(I18n::t('header_desc_diagnostics', $activeLang)) ?>
                    </p>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
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
                    <a href="<?= Url::to(['/antibot/default/diagnostics']) ?>" class="nav-link active">
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

    <!-- 1. Self-Test Diagnostics Section -->
    <?php if (!empty($selfTest)): ?>
        <div class="antibot-card mb-4">
            <div class="antibot-card-header">
                <div>
                    <h5 class="antibot-card-title"><?= Html::encode(I18n::t('diag_selftest_title', $activeLang)) ?></h5>
                    <div class="text-muted small"><?= Html::encode(I18n::t('diag_selftest_desc', $activeLang)) ?></div>
                </div>
                <?php
                    $overallBadge = match ($selfTest['overall'] ?? 'ok') {
                        'fail' => 'badge-soft-danger',
                        'warning' => 'badge-soft-warning',
                        default => 'badge-soft-success',
                    };
                    $overallText = match ($selfTest['overall'] ?? 'ok') {
                        'fail' => I18n::t('diag_system_fail', $activeLang),
                        'warning' => I18n::t('diag_system_warning', $activeLang),
                        default => I18n::t('diag_system_ok', $activeLang),
                    };
                ?>
                <span class="badge <?= $overallBadge ?> rounded-pill px-3 py-2 fw-bold"><?= Html::encode($overallText) ?></span>
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
                            <div class="p-3 rounded-3 border h-100" style="background: rgba(113, 157, 235, 0.05); border-color: var(--ab-line) !important;">
                                <div class="d-flex align-items-center mb-1">
                                    <span class="me-2 fs-5"><?= $chkIcon ?></span>
                                    <strong class="<?= $chkBadge ?> fs-6"><?= Html::encode($chk['name']) ?></strong>
                                </div>
                                <div class="small text-muted mb-1"><?= Html::encode($chk['message']) ?></div>
                                <?php if (!empty($chk['recommendation'])): ?>
                                    <div class="small text-danger fw-semibold mt-1"><?= Html::encode(I18n::t('diag_recommendation', $activeLang)) ?> <?= Html::encode($chk['recommendation']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- 2. Heuristic Rule Advisor Section -->
    <div class="antibot-card mb-4" style="border-left: 5px solid #3189f7 !important;">
        <div class="antibot-card-header">
            <div class="d-flex align-items-center gap-2">
                <h5 class="antibot-card-title"><?= Html::encode(I18n::t('diag_advisor_title', $activeLang)) ?></h5>
                <span class="badge bg-primary rounded-pill"><?= count($recommendations) ?></span>
            </div>
            <div class="text-muted small"><?= Html::encode(I18n::t('diag_advisor_desc', $activeLang)) ?></div>
        </div>
        <div class="antibot-card-body">
            <div class="p-3 rounded-3 mb-3 small text-muted border" style="background: rgba(113, 157, 235, 0.05); border-color: var(--ab-line) !important;">
                <?= Html::encode(I18n::t('diag_advisor_what', $activeLang)) ?>
            </div>

            <?php if (empty($recommendations)): ?>
                <div class="antibot-alert-card alert-success d-flex align-items-center gap-3 mb-0">
                    <span class="fs-4">✅</span>
                    <div>
                        <div class="fw-bold"><?= Html::encode(I18n::t('diag_advisor_optimal_title', $activeLang)) ?></div>
                        <div class="small text-muted"><?= Html::encode(I18n::t('diag_advisor_optimal_desc', $activeLang)) ?></div>
                    </div>
                </div>
            <?php else: ?>
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
                            <div class="antibot-alert-card <?= $alertClass ?> mb-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
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
                                        <?= Html::submitButton(I18n::t('diag_apply_rec_btn', $activeLang), [
                                            'class' => 'btn btn-sm btn-primary rounded-pill px-3 shadow-sm',
                                            'data-confirm' => I18n::t('diag_apply_rec_confirm', $activeLang),
                                        ]) ?>
                                    <?= Html::endForm() ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 3. Rule Simulator Section -->
    <div class="antibot-card mb-4">
        <div class="antibot-card-header">
            <div>
                <h5 class="antibot-card-title"><?= Html::encode(I18n::t('diag_simulator_title', $activeLang)) ?></h5>
                <p class="text-muted small mb-0"><?= Html::encode(I18n::t('diag_simulator_desc', $activeLang)) ?></p>
            </div>
        </div>
        <div class="antibot-card-body">
            <div class="p-3 rounded-3 mb-3 small text-muted border" style="background: rgba(113, 157, 235, 0.05); border-color: var(--ab-line) !important;">
                <?= Html::encode(I18n::t('diag_simulator_how', $activeLang)) ?>
            </div>

            <form method="get" action="<?= Url::to(['/antibot/default/diagnostics']) ?>" class="row g-3 align-items-end mb-3 antibot-sim-form">
                <div class="col-md-3">
                    <label class="form-label small fw-bold"><?= Html::encode(I18n::t('diag_sim_check_type', $activeLang)) ?></label>
                    <select name="sim_type" class="form-select rounded-3">
                        <option value="ratelimit" <?= ($simType ?? '') === 'ratelimit' ? 'selected' : '' ?>><?= Html::encode(I18n::t('diag_sim_opt_ratelimit', $activeLang)) ?></option>
                        <option value="ua" <?= ($simType ?? '') === 'ua' ? 'selected' : '' ?>><?= Html::encode(I18n::t('diag_sim_opt_ua', $activeLang)) ?></option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold"><?= Html::encode(I18n::t('diag_sim_max_or_ua', $activeLang)) ?></label>
                    <?php if (($simType ?? '') === 'ua'): ?>
                        <input type="text" name="sim_ua" class="form-control rounded-3" value="<?= Html::encode($simUa ?? 'Python') ?>">
                    <?php else: ?>
                        <input type="number" name="sim_max" class="form-control rounded-3" value="<?= (int) ($simMax ?? 20) ?>" min="1" max="1000">
                    <?php endif; ?>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold"><?= Html::encode(I18n::t('diag_sim_window', $activeLang)) ?></label>
                    <input type="number" name="sim_window" class="form-control rounded-3" value="<?= (int) ($simWindow ?? 60) ?>" min="5" max="3600">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary rounded-3 w-100"><?= Html::encode(I18n::t('diag_sim_run_btn', $activeLang)) ?></button>
                </div>
            </form>

            <?php if (!empty($simResult)): ?>
                <div class="p-3 rounded-3 border" style="background: var(--ab-surface); border-color: var(--ab-line) !important;">
                    <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
                        <div>
                            <strong><?= Html::encode(I18n::t('diag_sim_res_title', $activeLang)) ?></strong>
                            <span class="text-muted ms-1"><?= Html::encode($simResult['notice']) ?></span>
                        </div>
                        <div class="d-flex gap-2">
                            <span class="badge badge-soft-secondary rounded-pill"><?= Html::encode(I18n::t('diag_sim_sample', $activeLang)) ?> <?= (int) $simResult['sampleSize'] ?></span>
                            <span class="badge badge-soft-danger rounded-pill"><?= Html::encode(I18n::t('diag_sim_would_block', $activeLang)) ?> <?= (int) $simResult['wouldBlock'] ?> (<?= $simResult['impactPercent'] ?>%)</span>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 4. Performance & Audit Trail Row -->
    <div class="row g-4 mb-4">
        <!-- Inspection Performance -->
        <div class="col-lg-5">
            <div class="antibot-card h-100">
                <div class="antibot-card-header">
                    <div>
                        <h5 class="antibot-card-title"><?= Html::encode(I18n::t('diag_perf_title', $activeLang)) ?></h5>
                        <div class="text-muted small"><?= Html::encode(I18n::t('diag_perf_desc', $activeLang)) ?></div>
                    </div>
                </div>
                <div class="antibot-card-body">
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <div class="p-3 rounded-3 border text-center" style="background: rgba(113, 157, 235, 0.05); border-color: var(--ab-line) !important;">
                                <div class="small text-muted mb-1"><?= Html::encode(I18n::t('diag_perf_avg', $activeLang)) ?></div>
                                <div class="fs-4 fw-bold text-primary">
                                    <?= $perfAvg !== null ? $perfAvg . ' <span class="fs-6 fw-normal text-muted">' . Html::encode(I18n::t('diag_perf_ms', $activeLang)) . '</span>' : '—' ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="p-3 rounded-3 border text-center" style="background: rgba(113, 157, 235, 0.05); border-color: var(--ab-line) !important;">
                                <div class="small text-muted mb-1"><?= Html::encode(I18n::t('diag_perf_p95', $activeLang)) ?></div>
                                <div class="fs-4 fw-bold">
                                    <?= $perfP95 !== null ? $perfP95 . ' <span class="fs-6 fw-normal text-muted">' . Html::encode(I18n::t('diag_perf_ms', $activeLang)) . '</span>' : '—' ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="small text-muted">
                        <div class="d-flex justify-content-between py-1 border-bottom" style="border-color: var(--ab-line) !important;">
                            <span><?= Html::encode(I18n::t('diag_perf_total', $activeLang)) ?></span>
                            <strong><?= number_format($perfTotal) ?></strong>
                        </div>
                        <div class="d-flex justify-content-between py-1 border-bottom" style="border-color: var(--ab-line) !important;">
                            <span><?= Html::encode(I18n::t('diag_perf_allowed', $activeLang)) ?></span>
                            <strong class="text-success"><?= (int) ($perf['allowed'] ?? 0) ?></strong>
                        </div>
                        <div class="d-flex justify-content-between py-1 border-bottom" style="border-color: var(--ab-line) !important;">
                            <span><?= Html::encode(I18n::t('diag_perf_adapter', $activeLang)) ?></span>
                            <code class="antibot-code-badge"><?= Html::encode($antibot->rateLimitAdapter) ?></code>
                        </div>
                        <div class="d-flex justify-content-between py-1">
                            <span><?= Html::encode(I18n::t('diag_perf_window', $activeLang)) ?></span>
                            <span><?= (int) $antibot->rateLimitWindow ?> <?= Html::encode(I18n::t('diag_perf_sec', $activeLang)) ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Audit Trail (Audit of changes) -->
        <div class="col-lg-7">
            <div class="antibot-card h-100">
                <div class="antibot-card-header">
                    <div>
                        <h5 class="antibot-card-title"><?= Html::encode(I18n::t('diag_audit_title', $activeLang)) ?></h5>
                        <div class="text-muted small"><?= Html::encode(I18n::t('diag_audit_desc', $activeLang)) ?></div>
                    </div>
                </div>
                <div class="antibot-card-body p-0">
                    <?php if (empty($auditTrail)): ?>
                        <div class="p-4 text-center text-muted small">
                            <?= Html::encode(I18n::t('diag_audit_empty', $activeLang)) ?>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0 antibot-table" style="font-size: 0.85rem;">
                                <thead>
                                    <tr>
                                        <th><?= Html::encode(I18n::t('diag_audit_th_time', $activeLang)) ?></th>
                                        <th><?= Html::encode(I18n::t('diag_audit_th_ip', $activeLang)) ?></th>
                                        <th><?= Html::encode(I18n::t('diag_audit_th_action', $activeLang)) ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($auditTrail as $audit): ?>
                                        <tr>
                                            <td class="text-muted font-monospace"><?= Html::encode($audit['date'] ?? date('Y-m-d H:i:s', (int) ($audit['timestamp'] ?? time()))) ?></td>
                                            <td><code class="antibot-code-badge"><?= Html::encode($audit['actor_ip'] ?? '127.0.0.1') ?></code></td>
                                            <td>
                                                <span class="badge badge-soft-secondary"><?= Html::encode($audit['action'] ?? 'settings_saved') ?></span>
                                                <?php if (!empty($audit['details'])): ?>
                                                    <span class="small text-muted ms-1"><?= Html::encode(is_array($audit['details']) ? json_encode($audit['details'], JSON_UNESCAPED_UNICODE) : (string) $audit['details']) ?></span>
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
    </div>
</div>
