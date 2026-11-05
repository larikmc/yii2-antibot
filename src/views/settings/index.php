<?php

declare(strict_types=1);

/** @var yii\web\View $this */
/** @var array<string, mixed> $settings */
/** @var larikmc\antibot\Antibot $antibot */

use larikmc\antibot\Module;
use larikmc\antibot\services\I18n;
use yii\helpers\Html;
use yii\helpers\Url;

\larikmc\antibot\assets\AntibotDashboardAsset::register($this);

$activeLang = I18n::getLanguage();

$this->title = I18n::t('settings_title', $activeLang);
$this->params['breadcrumbs'] = [
    ['label' => 'Антибот', 'url' => ['/antibot/default/index']],
    ['label' => I18n::t('tab_settings', $activeLang)],
];

$trustedProxiesStr = implode("\n", (array) ($settings['trustedProxies'] ?? []));
$aiPolicies = (array) ($settings['aiCrawlerPolicies'] ?? []);
$currentMode = (string) ($settings['mode'] ?? 'standard');
?>

<div class="antibot-settings">
    <!-- Header Card (Matching Laricum C Admin UI Kit) -->
    <div class="antibot-header-panel">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div class="d-flex align-items-center gap-3">
                <div class="antibot-header-icon">
                    ⚙️
                </div>
                <div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <h1 class="antibot-header-title"><?= Html::encode(I18n::t('settings_header_title', $activeLang)) ?></h1>
                        <span class="badge bg-light text-muted border rounded-pill px-2 py-1 small fw-normal">v<?= Module::VERSION ?></span>
                    </div>
                    <p class="antibot-header-subtitle"><?= Html::encode(I18n::t('header_desc_settings', $activeLang)) ?></p>
                </div>
            </div>
            <div>
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
                    <a href="<?= Url::to(['/antibot/settings/index']) ?>" class="nav-link active">
                        <?= Html::encode(I18n::t('tab_settings', $activeLang)) ?>
                    </a>
                </li>
                <li>
                    <a href="<?= Url::to(['/antibot/ip-list/index']) ?>" class="nav-link">
                        <?= Html::encode(I18n::t('tab_iplist', $activeLang)) ?>
                    </a>
                </li>
            </ul>
        </div>
    </div>

    <form method="post" action="<?= Url::to(['/antibot/settings/index']) ?>">
        <?= Html::hiddenInput(Yii::$app->request->csrfParam, Yii::$app->request->csrfToken) ?>

        <div class="row g-4">
            <!-- Left Column: Core Rules, Modes & Crawlers -->
            <div class="col-lg-8">
                <!-- 1. Основные режимы -->
                <div class="antibot-card mb-4">
                    <div class="antibot-card-header">
                        <h2 class="antibot-card-title">
                            <span>🛡️</span> <?= Html::encode(I18n::t('settings_sec_1', $activeLang)) ?>
                        </h2>
                        <span class="antibot-card-title-badge"><?= Html::encode(I18n::t('settings_sec_1_badge', $activeLang)) ?></span>
                    </div>
                    <div class="antibot-card-body">
                        <div class="form-check form-switch mb-4 p-3 rounded-3 d-flex align-items-center justify-content-between" style="background: rgba(113, 157, 235, 0.05); border: 1px solid var(--ab-line);">
                            <div>
                                <label class="form-check-label fw-bold fs-6" for="enabledSwitch"><?= Html::encode(I18n::t('settings_enabled_label', $activeLang)) ?></label>
                                <div class="small text-muted"><?= Html::encode(I18n::t('settings_enabled_desc', $activeLang)) ?></div>
                            </div>
                            <input class="form-check-input ms-3 me-0" type="checkbox" name="AntibotSetting[enabled]" value="1" id="enabledSwitch" <?= !empty($settings['enabled']) ? 'checked' : '' ?>>
                        </div>

                        <label class="form-label fw-bold mb-2"><?= Html::encode(I18n::t('settings_strictness_label', $activeLang)) ?></label>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="antibot-mode-card <?= $currentMode === 'monitoring' ? 'is-active is-active-monitoring' : '' ?>">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="radio" name="AntibotSetting[mode]" value="monitoring" <?= $currentMode === 'monitoring' ? 'checked' : '' ?>>
                                        <span class="fw-bold text-info"><?= Html::encode(I18n::t('mode_monitoring', $activeLang)) ?></span>
                                    </div>
                                    <div class="small text-muted"><?= Html::encode(I18n::t('settings_mode_monitoring_desc', $activeLang)) ?></div>
                                </label>
                            </div>
                            <div class="col-md-4">
                                <label class="antibot-mode-card <?= $currentMode === 'standard' ? 'is-active' : '' ?>">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="radio" name="AntibotSetting[mode]" value="standard" <?= $currentMode === 'standard' ? 'checked' : '' ?>>
                                        <span class="fw-bold text-primary"><?= Html::encode(I18n::t('mode_standard', $activeLang)) ?></span>
                                    </div>
                                    <div class="small text-muted"><?= Html::encode(I18n::t('settings_mode_standard_desc', $activeLang)) ?></div>
                                </label>
                            </div>
                            <div class="col-md-4">
                                <label class="antibot-mode-card <?= $currentMode === 'strict' ? 'is-active is-active-strict' : '' ?>">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="radio" name="AntibotSetting[mode]" value="strict" <?= $currentMode === 'strict' ? 'checked' : '' ?>>
                                        <span class="fw-bold text-danger"><?= Html::encode(I18n::t('mode_strict', $activeLang)) ?></span>
                                    </div>
                                    <div class="small text-muted"><?= Html::encode(I18n::t('settings_mode_strict_desc', $activeLang)) ?></div>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 2. Поисковики и Reverse DNS -->
                <div class="antibot-card mb-4">
                    <div class="antibot-card-header">
                        <h2 class="antibot-card-title">
                            <span>🤖</span> <?= Html::encode(I18n::t('settings_sec_2', $activeLang)) ?>
                        </h2>
                        <span class="antibot-card-title-badge">Reverse DNS</span>
                    </div>
                    <div class="antibot-card-body">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="AntibotSetting[verifySearchBots]" value="1" id="verifySearchSwitch" <?= !empty($settings['verifySearchBots']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="verifySearchSwitch"><?= Html::encode(I18n::t('settings_verify_search', $activeLang)) ?></label>
                            <div class="form-text text-muted"><?= Html::encode(I18n::t('settings_verify_search_desc', $activeLang)) ?></div>
                        </div>

                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" name="AntibotSetting[blockFakeSearchBots]" value="1" id="fakeSearchSwitch" <?= !empty($settings['blockFakeSearchBots']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold text-danger" for="fakeSearchSwitch"><?= Html::encode(I18n::t('settings_block_fake', $activeLang)) ?></label>
                            <div class="form-text text-muted"><?= Html::encode(I18n::t('settings_block_fake_desc', $activeLang)) ?></div>
                        </div>
                    </div>
                </div>

                <!-- 3. Гранулярное управление ИИ-краулерами -->
                <div class="antibot-card mb-4">
                    <div class="antibot-card-header">
                        <h2 class="antibot-card-title">
                            <span>🧠</span> <?= Html::encode(I18n::t('settings_sec_3', $activeLang)) ?>
                        </h2>
                        <span class="antibot-card-title-badge">LLM & Bots</span>
                    </div>
                    <div class="antibot-card-body">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="AntibotSetting[blockAiCrawlers]" value="1" id="aiSwitch" <?= !empty($settings['blockAiCrawlers']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="aiSwitch"><?= Html::encode(I18n::t('settings_block_ai', $activeLang)) ?></label>
                            <div class="form-text text-muted"><?= Html::encode(I18n::t('settings_block_ai_desc', $activeLang)) ?></div>
                        </div>

                        <h6 class="fw-bold small text-muted text-uppercase mt-4 mb-2">Индивидуальные правила:</h6>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle antibot-table mb-0">
                                <thead>
                                    <tr>
                                        <th><?= Html::encode(I18n::t('settings_ai_table_bot', $activeLang)) ?></th>
                                        <th><?= Html::encode(I18n::t('settings_ai_table_type', $activeLang)) ?></th>
                                        <th><?= Html::encode(I18n::t('settings_ai_table_policy', $activeLang)) ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $botsList = [
                                        'OAI-SearchBot' => ['Поиск ChatGPT Search', 'search'],
                                        'ChatGPT-User' => ['Прямой переход пользователя из ChatGPT', 'search'],
                                        'PerplexityBot' => ['Поисковик Perplexity AI', 'search'],
                                        'GPTBot' => ['Сбор данных для обучения моделей OpenAI', 'training'],
                                        'ClaudeBot' => ['Сбор данных Anthropic Claude', 'training'],
                                        'Bytespider' => ['Скрапер ByteDance (TikTok)', 'training'],
                                        'CCBot' => ['Common Crawl датасет', 'training'],
                                        'Google-Extended' => ['Обучение Gemini / Bard', 'training'],
                                    ];
                                    foreach ($botsList as $bKey => [$bDesc, $bType]):
                                        $currentPolicy = $aiPolicies[$bKey] ?? ($bType === 'search' ? 'allow' : 'block');
                                    ?>
                                        <tr>
                                            <td>
                                                <strong class="text-dark"><?= Html::encode($bKey) ?></strong>
                                                <div class="small text-muted"><?= Html::encode($bDesc) ?></div>
                                            </td>
                                            <td>
                                                <span class="badge <?= $bType === 'search' ? 'badge-soft-success' : 'badge-soft-secondary' ?> rounded-pill px-2 py-1">
                                                    <?= Html::encode($bType === 'search' ? I18n::t('settings_ai_type_search', $activeLang) : I18n::t('settings_ai_type_training', $activeLang)) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <select name="AntibotSetting[aiCrawlerPolicies][<?= Html::encode($bKey) ?>]" class="form-select form-select-sm rounded-3">
                                                    <option value="allow" <?= $currentPolicy === 'allow' ? 'selected' : '' ?>><?= Html::encode(I18n::t('settings_ai_allow', $activeLang)) ?></option>
                                                    <option value="monitor" <?= $currentPolicy === 'monitor' ? 'selected' : '' ?>><?= Html::encode(I18n::t('settings_ai_monitor', $activeLang)) ?></option>
                                                    <option value="throttle" <?= $currentPolicy === 'throttle' ? 'selected' : '' ?>><?= Html::encode(I18n::t('settings_ai_throttle', $activeLang)) ?></option>
                                                    <option value="block" <?= $currentPolicy === 'block' ? 'selected' : '' ?>><?= Html::encode(I18n::t('settings_ai_block', $activeLang)) ?></option>
                                                </select>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- 4. Коммерческие парсеры и CLI -->
                <div class="antibot-card mb-4">
                    <div class="antibot-card-header">
                        <h2 class="antibot-card-title">
                            <span>🚫</span> <?= Html::encode(I18n::t('settings_sec_4', $activeLang)) ?>
                        </h2>
                    </div>
                    <div class="antibot-card-body">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="AntibotSetting[blockCommercialScrapers]" value="1" id="commercialSwitch" <?= !empty($settings['blockCommercialScrapers']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="commercialSwitch"><?= Html::encode(I18n::t('settings_block_scrapers', $activeLang)) ?></label>
                            <div class="form-text text-muted"><?= Html::encode(I18n::t('settings_block_scrapers_desc', $activeLang)) ?></div>
                        </div>

                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" name="AntibotSetting[blockCliAndLibraries]" value="1" id="cliSwitch" <?= !empty($settings['blockCliAndLibraries']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="cliSwitch"><?= Html::encode(I18n::t('settings_block_cli', $activeLang)) ?></label>
                            <div class="form-text text-muted"><?= Html::encode(I18n::t('settings_block_cli_desc', $activeLang)) ?></div>
                        </div>

                        <div class="form-check form-switch mb-0 mt-3 pt-3 border-top">
                            <input class="form-check-input" type="checkbox" name="AntibotSetting[headerConsistencyEnabled]" value="1" id="headerConsistencySwitch" <?= !empty($settings['headerConsistencyEnabled']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="headerConsistencySwitch"><?= Html::encode(I18n::t('settings_header_consistency', $activeLang)) ?></label>
                            <div class="form-text text-muted"><?= Html::encode(I18n::t('settings_header_consistency_desc', $activeLang)) ?></div>
                        </div>
                    </div>
                </div>

                <!-- 5. Поведенческий анализ (Behavior Intelligence) -->
                <div class="antibot-card mb-4">
                    <div class="antibot-card-header">
                        <h2 class="antibot-card-title">
                            <span>🕵️</span> <?= Html::encode(I18n::t('settings_sec_5', $activeLang)) ?>
                        </h2>
                    </div>
                    <div class="antibot-card-body">
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" name="AntibotSetting[behaviorAnalysisEnabled]" value="1" id="bhSwitch" <?= !empty($settings['behaviorAnalysisEnabled']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="bhSwitch"><?= Html::encode(I18n::t('settings_behavior', $activeLang)) ?></label>
                            <div class="form-text text-muted">
                                <?= Html::encode(I18n::t('settings_behavior_desc', $activeLang)) ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Rate Limiter, PoW & Proxies -->
            <div class="col-lg-4">
                <!-- 6. Rate Limiter -->
                <div class="antibot-card mb-4">
                    <div class="antibot-card-header">
                        <h2 class="antibot-card-title">
                            <span>⚡</span> <?= Html::encode(I18n::t('settings_sec_6', $activeLang)) ?>
                        </h2>
                    </div>
                    <div class="antibot-card-body">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="AntibotSetting[rateLimitEnabled]" value="1" id="rateLimitSwitch" <?= !empty($settings['rateLimitEnabled']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="rateLimitSwitch"><?= Html::encode(I18n::t('settings_ratelimit_enable', $activeLang)) ?></label>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold"><?= Html::encode(I18n::t('settings_adapter_label', $activeLang)) ?></label>
                            <select name="AntibotSetting[rateLimitAdapter]" class="form-select form-select-sm rounded-3">
                                <option value="cache" <?= ($settings['rateLimitAdapter'] ?? 'cache') === 'cache' ? 'selected' : '' ?>><?= Html::encode(I18n::t('settings_adapter_cache', $activeLang)) ?></option>
                                <option value="redis" <?= ($settings['rateLimitAdapter'] ?? '') === 'redis' ? 'selected' : '' ?>><?= Html::encode(I18n::t('settings_adapter_redis', $activeLang)) ?></option>
                            </select>
                            <div class="form-text text-muted"><?= Html::encode(I18n::t('settings_adapter_desc', $activeLang)) ?></div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold"><?= Html::encode(I18n::t('settings_ratelimit_max', $activeLang)) ?></label>
                            <input type="number" name="AntibotSetting[rateLimitMax]" class="form-control rounded-3" value="<?= (int) ($settings['rateLimitMax'] ?? 60) ?>" min="1" max="1000">
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold"><?= Html::encode(I18n::t('settings_ratelimit_window', $activeLang)) ?></label>
                            <input type="number" name="AntibotSetting[rateLimitWindow]" class="form-control rounded-3" value="<?= (int) ($settings['rateLimitWindow'] ?? 60) ?>" min="1" max="3600">
                        </div>

                        <div class="mb-0">
                            <label class="form-label small fw-bold"><?= Html::encode(I18n::t('settings_ratelimit_ban', $activeLang)) ?></label>
                            <input type="number" name="AntibotSetting[rateLimitBanDuration]" class="form-control rounded-3" value="<?= (int) ($settings['rateLimitBanDuration'] ?? 300) ?>" min="0" max="86400">
                            <div class="form-text text-muted"><?= Html::encode(I18n::t('settings_ratelimit_ban_desc', $activeLang)) ?></div>
                        </div>

                        <div class="mt-4 pt-3 border-top">
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="AntibotSetting[autoJailEnabled]" value="1" id="autoJailSwitch" <?= !empty($settings['autoJailEnabled']) ? 'checked' : '' ?>>
                                <label class="form-check-label fw-bold text-danger" for="autoJailSwitch"><?= Html::encode(I18n::t('settings_autojail_enabled', $activeLang)) ?></label>
                                <div class="form-text text-muted"><?= Html::encode(I18n::t('settings_autojail_enabled_desc', $activeLang)) ?></div>
                            </div>

                            <div class="row g-2">
                                <div class="col-sm-6">
                                    <label class="form-label small fw-bold"><?= Html::encode(I18n::t('settings_autojail_threshold', $activeLang)) ?></label>
                                    <input type="number" name="AntibotSetting[autoJailThreshold]" class="form-control rounded-3" value="<?= (int) ($settings['autoJailThreshold'] ?? 2) ?>" min="1" max="10">
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label small fw-bold"><?= Html::encode(I18n::t('settings_autojail_duration', $activeLang)) ?></label>
                                    <input type="number" name="AntibotSetting[autoJailDuration]" class="form-control rounded-3" value="<?= (int) ($settings['autoJailDuration'] ?? 1800) ?>" min="60" max="86400">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 7. Бюджет нагрузки (Weighted Limiter) -->
                <div class="antibot-card mb-4">
                    <div class="antibot-card-header">
                        <h2 class="antibot-card-title">
                            <span>⚖️</span> <?= Html::encode(I18n::t('settings_sec_7', $activeLang)) ?>
                        </h2>
                    </div>
                    <div class="antibot-card-body">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="AntibotSetting[weightedRateLimitEnabled]" value="1" id="wrlSwitch" <?= !empty($settings['weightedRateLimitEnabled']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="wrlSwitch"><?= Html::encode(I18n::t('settings_weighted_enable', $activeLang)) ?></label>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold"><?= Html::encode(I18n::t('settings_weighted_budget', $activeLang)) ?></label>
                            <input type="number" name="AntibotSetting[weightedRateLimitBudget]" class="form-control rounded-3" value="<?= (int) ($settings['weightedRateLimitBudget'] ?? 150) ?>" min="10" max="10000">
                            <div class="form-text text-muted"><?= Html::encode(I18n::t('settings_weighted_budget_desc', $activeLang)) ?></div>
                        </div>
                        <div class="mb-0">
                            <label class="form-label small fw-bold"><?= Html::encode(I18n::t('settings_weighted_window', $activeLang)) ?></label>
                            <input type="number" name="AntibotSetting[weightedRateLimitWindow]" class="form-control rounded-3" value="<?= (int) ($settings['weightedRateLimitWindow'] ?? 60) ?>" min="5" max="3600">
                        </div>
                    </div>
                </div>

                <!-- 8. Доверенные прокси (Trusted Proxies) -->
                <div class="antibot-card mb-4">
                    <div class="antibot-card-header">
                        <h2 class="antibot-card-title">
                            <span>🛡️</span> <?= Html::encode(I18n::t('settings_sec_8', $activeLang)) ?>
                        </h2>
                    </div>
                    <div class="antibot-card-body">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="autoTrustLocalProxy" name="AntibotSetting[autoTrustLocalProxy]" value="1" <?= !empty($settings['autoTrustLocalProxy']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold small" for="autoTrustLocalProxy"><?= Html::encode(I18n::t('settings_proxy_autotrust', $activeLang)) ?></label>
                            <div class="form-text text-muted small mt-1"><?= Html::encode(I18n::t('settings_proxy_autotrust_desc', $activeLang)) ?></div>
                        </div>

                        <div class="mb-0">
                            <label class="form-label small fw-bold"><?= Html::encode(I18n::t('settings_proxy_custom', $activeLang)) ?></label>
                            <textarea name="AntibotSetting[trustedProxies]" class="form-control font-monospace small rounded-3" rows="3" placeholder="10.0.0.0/8&#10;173.245.48.0/20"><?= Html::encode($trustedProxiesStr) ?></textarea>
                            <div class="form-text text-muted mt-2">
                                <?= Html::encode(I18n::t('settings_proxy_safety', $activeLang)) ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 9. Proof-of-Work -->
                <div class="antibot-card mb-4">
                    <div class="antibot-card-header">
                        <h2 class="antibot-card-title">
                            <span>🧩</span> <?= Html::encode(I18n::t('settings_sec_9', $activeLang)) ?>
                        </h2>
                    </div>
                    <div class="antibot-card-body">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="AntibotSetting[powEnabled]" value="1" id="powSwitch" <?= !empty($settings['powEnabled']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="powSwitch"><?= Html::encode(I18n::t('settings_pow_enable', $activeLang)) ?></label>
                        </div>
                        <div class="mb-0">
                            <label class="form-label small fw-bold"><?= Html::encode(I18n::t('settings_pow_diff', $activeLang)) ?></label>
                            <select name="AntibotSetting[powDifficulty]" class="form-select form-select-sm rounded-3">
                                <option value="light" <?= ($settings['powDifficulty'] ?? 'medium') === 'light' ? 'selected' : '' ?>><?= Html::encode(I18n::t('settings_pow_light', $activeLang)) ?></option>
                                <option value="medium" <?= ($settings['powDifficulty'] ?? 'medium') === 'medium' ? 'selected' : '' ?>><?= Html::encode(I18n::t('settings_pow_medium', $activeLang)) ?></option>
                                <option value="strong" <?= ($settings['powDifficulty'] ?? 'medium') === 'strong' ? 'selected' : '' ?>><?= Html::encode(I18n::t('settings_pow_strong', $activeLang)) ?></option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- 10. Ловушки (Honey URLs) -->
                <div class="antibot-card mb-4">
                    <div class="antibot-card-header">
                        <h2 class="antibot-card-title">
                            <span>🍯</span> <?= Html::encode(I18n::t('settings_sec_10', $activeLang)) ?>
                        </h2>
                    </div>
                    <div class="antibot-card-body">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="AntibotSetting[honeyTrapsEnabled]" value="1" id="honeySwitch" <?= !empty($settings['honeyTrapsEnabled']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="honeySwitch"><?= Html::encode(I18n::t('settings_traps_enable', $activeLang)) ?></label>
                        </div>
                        <div class="mb-0">
                            <label class="form-label small fw-bold"><?= Html::encode(I18n::t('settings_trap_route', $activeLang)) ?></label>
                            <input type="text" name="AntibotSetting[trapRoute]" class="form-control rounded-3" value="<?= Html::encode($settings['trapRoute'] ?? 'antibot/trap') ?>">
                        </div>
                    </div>
                </div>

                <!-- 11. Текст блокировки -->
                <div class="antibot-card mb-4">
                    <div class="antibot-card-header">
                        <h2 class="antibot-card-title">
                            <span>💬</span> <?= Html::encode(I18n::t('settings_sec_11', $activeLang)) ?>
                        </h2>
                    </div>
                    <div class="antibot-card-body">
                        <div class="mb-0">
                            <label class="form-label small fw-bold"><?= Html::encode(I18n::t('settings_block_msg', $activeLang)) ?></label>
                            <textarea name="AntibotSetting[customBlockMessage]" class="form-control rounded-3" rows="2"><?= Html::encode($settings['customBlockMessage'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- 12. Защита чувствительных маршрутов (Sensitive Routes) -->
                <div class="antibot-card mb-4">
                    <div class="antibot-card-header">
                        <h2 class="antibot-card-title">
                            <span>🎯</span> <?= Html::encode(I18n::t('settings_sec_12', $activeLang)) ?>
                        </h2>
                        <span class="antibot-card-title-badge">Route Limits</span>
                    </div>
                    <div class="antibot-card-body">
                        <p class="small text-muted mb-3">
                            <?= Html::encode(I18n::t('settings_route_rules_desc', $activeLang)) ?>
                        </p>

                        <div class="table-responsive">
                            <table class="table table-hover align-middle antibot-table mb-3" id="routeRulesTable">
                                <thead>
                                    <tr>
                                        <th><?= Html::encode(I18n::t('settings_route_pattern', $activeLang)) ?></th>
                                        <th style="width: 140px;"><?= Html::encode(I18n::t('settings_route_limit', $activeLang)) ?></th>
                                        <th style="width: 120px;"><?= Html::encode(I18n::t('settings_route_window', $activeLang)) ?></th>
                                        <th style="width: 120px;"><?= Html::encode(I18n::t('settings_route_methods', $activeLang)) ?></th>
                                        <th><?= Html::encode(I18n::t('settings_route_desc', $activeLang)) ?></th>
                                        <th style="width: 60px;"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $routeRules = (array) ($settings['routeRules'] ?? []);
                                    if (empty($routeRules)) {
                                        $routeRules = [
                                            ['pattern' => 'site/login*', 'maxRequests' => 10, 'window' => 60, 'methods' => ['POST'], 'description' => 'Авторизация / Форма входа'],
                                            ['pattern' => '*search*', 'maxRequests' => 20, 'window' => 60, 'methods' => [], 'description' => 'Поисковые запросы'],
                                            ['pattern' => '*checkout*', 'maxRequests' => 15, 'window' => 60, 'methods' => [], 'description' => 'Оформление заказа'],
                                        ];
                                    }
                                    foreach ($routeRules as $idx => $rRule):
                                        $methodsStr = implode(', ', (array) ($rRule['methods'] ?? []));
                                    ?>
                                        <tr class="route-rule-row">
                                            <td>
                                                <input type="text" name="AntibotSetting[routeRules][<?= $idx ?>][pattern]" class="form-control form-control-sm font-monospace" value="<?= Html::encode($rRule['pattern'] ?? '') ?>" placeholder="site/login*">
                                            </td>
                                            <td>
                                                <input type="number" name="AntibotSetting[routeRules][<?= $idx ?>][maxRequests]" class="form-control form-control-sm" value="<?= (int) ($rRule['maxRequests'] ?? 10) ?>" min="1" max="1000">
                                            </td>
                                            <td>
                                                <input type="number" name="AntibotSetting[routeRules][<?= $idx ?>][window]" class="form-control form-control-sm" value="<?= (int) ($rRule['window'] ?? 60) ?>" min="1" max="3600">
                                            </td>
                                            <td>
                                                <input type="text" name="AntibotSetting[routeRules][<?= $idx ?>][methods][]" class="form-control form-control-sm" value="<?= Html::encode($methodsStr) ?>" placeholder="POST, GET">
                                            </td>
                                            <td>
                                                <input type="text" name="AntibotSetting[routeRules][<?= $idx ?>][description]" class="form-control form-control-sm" value="<?= Html::encode($rRule['description'] ?? '') ?>" placeholder="Форма входа">
                                            </td>
                                            <td class="text-end">
                                                <button type="button" class="btn btn-outline-danger btn-sm rounded-circle p-0 d-inline-flex align-items-center justify-content-center btn-remove-rule" style="width: 28px; height: 28px;" title="Удалить">✕</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <button type="button" class="btn btn-outline-primary btn-sm rounded-pill px-3 py-1 fw-semibold" id="btnAddRouteRule">
                            <?= Html::encode(I18n::t('settings_route_add_btn', $activeLang)) ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sticky Bottom Save Bar -->
        <div class="antibot-sticky-bar">
            <div>
                <span class="fw-semibold text-dark"><?= Html::encode(I18n::t('settings_bar_title', $activeLang)) ?></span>
                <span class="text-muted small ms-2 d-none d-md-inline"><?= Html::encode(I18n::t('settings_bar_desc', $activeLang)) ?></span>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary rounded-pill px-4 shadow-sm fw-bold">
                    <?= Html::encode(I18n::t('settings_save_btn', $activeLang)) ?>
                </button>
            </div>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Interactive radio mode cards
    var modeCards = document.querySelectorAll('.antibot-mode-card');
    modeCards.forEach(function (card) {
        var radio = card.querySelector('input[type="radio"]');
        if (radio) {
            radio.addEventListener('change', function () {
                modeCards.forEach(function (c) {
                    c.classList.remove('is-active', 'is-active-monitoring', 'is-active-strict');
                });
                if (radio.value === 'monitoring') {
                    card.classList.add('is-active', 'is-active-monitoring');
                } else if (radio.value === 'strict') {
                    card.classList.add('is-active', 'is-active-strict');
                } else {
                    card.classList.add('is-active');
                }
            });
        }
    });

    // Dynamic Route Rules Row Management
    var tableBody = document.querySelector('#routeRulesTable tbody');
    var btnAdd = document.getElementById('btnAddRouteRule');
    if (btnAdd && tableBody) {
        btnAdd.addEventListener('click', function () {
            var newIdx = tableBody.querySelectorAll('tr').length;
            var tr = document.createElement('tr');
            tr.className = 'route-rule-row';
            tr.innerHTML = '<td><input type="text" name="AntibotSetting[routeRules][' + newIdx + '][pattern]" class="form-control form-control-sm font-monospace" placeholder="site/login*"></td>' +
                '<td><input type="number" name="AntibotSetting[routeRules][' + newIdx + '][maxRequests]" class="form-control form-control-sm" value="10" min="1"></td>' +
                '<td><input type="number" name="AntibotSetting[routeRules][' + newIdx + '][window]" class="form-control form-control-sm" value="60" min="1"></td>' +
                '<td><input type="text" name="AntibotSetting[routeRules][' + newIdx + '][methods][]" class="form-control form-control-sm" placeholder="POST"></td>' +
                '<td><input type="text" name="AntibotSetting[routeRules][' + newIdx + '][description]" class="form-control form-control-sm" placeholder="Защита действия"></td>' +
                '<td class="text-end"><button type="button" class="btn btn-outline-danger btn-sm rounded-circle p-0 d-inline-flex align-items-center justify-content-center btn-remove-rule" style="width: 28px; height: 28px;">✕</button></td>';
            tableBody.appendChild(tr);
        });

        tableBody.addEventListener('click', function (e) {
            if (e.target && e.target.classList.contains('btn-remove-rule')) {
                e.target.closest('tr').remove();
            }
        });
    }
});
</script>
