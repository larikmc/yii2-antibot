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
use yii\helpers\Html;
use yii\helpers\Url;

\larikmc\antibot\assets\AntibotDashboardAsset::register($this);

$this->title = 'Антибот — Диагностика и Советник правил';
$this->params['breadcrumbs'] = [
    ['label' => 'Антибот', 'url' => ['/antibot/default/index']],
    ['label' => 'Диагностика и Советник'],
];

$perfTotal = (int) ($perf['total_checks'] ?? 0);
$perfAvg = ($perf['avg_ms'] ?? null) !== null ? number_format((float) $perf['avg_ms'], 3) : null;
$perfP95 = ($perf['p95_ms'] ?? null) !== null ? number_format((float) $perf['p95_ms'], 3) : null;
$activeLang = $currentLang ?? 'ru';
?>

<div class="antibot-diagnostics">
    <!-- Header Card -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 p-4 bg-white">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div class="d-flex align-items-center gap-3">
                <div style="width: 52px; height: 52px; border-radius: 14px; background: linear-gradient(135deg, #eef2ff 0%, #e0e7ff 100%); color: #4f46e5; display: inline-flex; align-items: center; justify-content: center; font-size: 1.75rem; box-shadow: inset 0 1px 2px rgba(255,255,255,0.8), 0 2px 6px rgba(79,70,229,0.15); flex-shrink: 0;">
                    🩺
                </div>
                <div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <h1 class="h4 fw-bold mb-0 text-dark" style="letter-spacing: -0.02em;">Диагностика и Советник правил</h1>
                        <span class="badge bg-light text-muted border rounded-pill px-2 py-1 small fw-normal">v<?= Module::VERSION ?></span>
                        <div class="antibot-lang-pill">
                            <a href="<?= Url::current(['lang' => 'ru']) ?>" class="<?= $activeLang === 'ru' ? 'active' : '' ?>">RU</a>
                            <a href="<?= Url::current(['lang' => 'en']) ?>" class="<?= $activeLang === 'en' ? 'active' : '' ?>">EN</a>
                        </div>
                    </div>
                    <p class="text-muted small mb-0 mt-1">
                        <?= $activeLang === 'en'
                            ? 'System health check, heuristic rule recommendations, traffic rule simulator and audit trail'
                            : 'Самодиагностика компонентов, рекомендации советника, симулятор правил и аудит изменений' ?>
                    </p>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <a href="<?= Url::to(['/antibot/default/index']) ?>" class="btn btn-outline-secondary btn-sm rounded-pill px-3 py-2 fw-semibold shadow-sm">
                    ← В мониторинг
                </a>
            </div>
        </div>

        <!-- Modern Segmented Navigation Tabs (4 Tabs) -->
        <hr class="my-3 text-muted opacity-25">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 pt-1">
            <div class="d-inline-flex p-1 bg-light border rounded-pill gap-1 shadow-sm">
                <a href="<?= Url::to(['/antibot/default/index']) ?>" class="btn btn-sm btn-light text-dark rounded-pill px-4 py-2 fw-semibold text-decoration-none border-0">
                    📊 Мониторинг и Журнал
                </a>
                <a href="<?= Url::to(['/antibot/default/diagnostics']) ?>" class="btn btn-sm btn-primary rounded-pill px-4 py-2 fw-semibold text-decoration-none shadow-sm">
                    🩺 Диагностика и Советник
                </a>
                <a href="<?= Url::to(['/antibot/settings/index']) ?>" class="btn btn-sm btn-light text-dark rounded-pill px-4 py-2 fw-semibold text-decoration-none border-0">
                    ⚙️ Настройки правил
                </a>
                <a href="<?= Url::to(['/antibot/ip-list/index']) ?>" class="btn btn-sm btn-light text-dark rounded-pill px-4 py-2 fw-semibold text-decoration-none border-0">
                    📋 Списки IP (White/Black)
                </a>
            </div>
            <div class="d-flex align-items-center gap-2 text-muted small">
                <span class="d-none d-md-inline">Статус модуля:</span>
                <span class="badge <?= $antibot->enabled ? 'bg-success bg-opacity-10 text-success border border-success border-opacity-25' : 'bg-secondary bg-opacity-10 text-secondary border' ?> rounded-pill px-3 py-1 fw-semibold">
                    <?= $antibot->enabled ? '● Активен' : '○ Отключен' ?>
                </span>
            </div>
        </div>
    </div>

    <!-- 1. Self-Test Diagnostics Section -->
    <?php if (!empty($selfTest)): ?>
        <div class="antibot-card mb-4">
            <div class="antibot-card-header">
                <div>
                    <h5 class="antibot-card-title">🩺 Состояние системы и самодиагностика (Self-Test)</h5>
                    <div class="text-muted small">Автоматическая проверка прав записи, сетевых DNS-резолверов, целостности ключей и адаптеров</div>
                </div>
                <?php
                    $overallBadge = match ($selfTest['overall'] ?? 'ok') {
                        'fail' => 'bg-danger text-white',
                        'warning' => 'bg-warning text-dark',
                        default => 'bg-success text-white',
                    };
                    $overallText = match ($selfTest['overall'] ?? 'ok') {
                        'fail' => 'Требует внимания',
                        'warning' => 'Предупреждение',
                        default => 'Система исправна',
                    };
                ?>
                <span class="badge <?= $overallBadge ?> rounded-pill px-3 py-2 fw-bold"><?= $overallText ?></span>
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
                            <div class="p-3 rounded-3 bg-light border h-100">
                                <div class="d-flex align-items-center mb-1">
                                    <span class="me-2 fs-5"><?= $chkIcon ?></span>
                                    <strong class="<?= $chkBadge ?> fs-6"><?= Html::encode($chk['name']) ?></strong>
                                </div>
                                <div class="small text-muted mb-1"><?= Html::encode($chk['message']) ?></div>
                                <?php if (!empty($chk['recommendation'])): ?>
                                    <div class="small text-danger fw-semibold mt-1">Рекомендация: <?= Html::encode($chk['recommendation']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- 2. Heuristic Rule Advisor Section -->
    <div class="antibot-card mb-4 border-start border-4 border-primary">
        <div class="antibot-card-header">
            <div class="d-flex align-items-center gap-2">
                <h5 class="antibot-card-title">💡 Эвристический советник правил (Rule Advisor)</h5>
                <span class="badge bg-primary rounded-pill"><?= count($recommendations) ?></span>
            </div>
            <div class="text-muted small">Интеллектуальный анализ трафика и рекомендации по безопасности</div>
        </div>
        <div class="antibot-card-body">
            <div class="p-3 bg-light rounded-3 mb-3 small text-muted border">
                <strong>Что это такое:</strong> Советник непрерывно анализирует реальные обращения в журнале событий. Если он замечает подозрительные сканеры, агрессивных ботов или незащищенные маршруты, он предлагает готовые решения для включения в один клик без риска сломать сайт.
            </div>

            <?php if (empty($recommendations)): ?>
                <div class="alert alert-success d-flex align-items-center gap-3 mb-0 rounded-3 border-0">
                    <span class="fs-4">✅</span>
                    <div>
                        <div class="fw-bold">Текущие правила оптимальны!</div>
                        <div class="small">Советник проверил журнал событий: аномалий и незаблокированных угроз не обнаружено. Все политики безопасности работают в штатном режиме.</div>
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
                            <div class="alert <?= $alertClass ?> mb-0 d-flex justify-content-between align-items-center flex-wrap gap-2 rounded-3 border-0 shadow-sm">
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
                                            'class' => 'btn btn-sm btn-dark rounded-pill px-3 shadow-sm',
                                            'data-confirm' => 'Применить это правило к конфигурации сайта?',
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
                <h5 class="antibot-card-title">🧪 Симулятор правил ("Что-Если" анализ)</h5>
                <p class="text-muted small mb-0">Тестируйте гипотетические правила на реальной истории трафика без риска для посетителей</p>
            </div>
        </div>
        <div class="antibot-card-body">
            <div class="p-3 bg-light rounded-3 mb-3 small text-muted border">
                <strong>Как работает симулятор:</strong> Вы можете задать экспериментальный лимит (например, максимум 15 запросов за минуту или блокировку скрипта <code>curl</code>) и проверить, сколько реальных посетителей или ботов попали бы под ограничение. Ваши текущие рабочие настройки при этом <strong>не меняются</strong>.
            </div>

            <form method="get" action="<?= Url::to(['/antibot/default/diagnostics']) ?>" class="row g-3 align-items-end mb-3">
                <div class="col-md-3">
                    <label class="form-label small fw-bold">Тип проверки:</label>
                    <select name="sim_type" class="form-select form-select-sm rounded-3">
                        <option value="ratelimit" <?= ($simType ?? '') === 'ratelimit' ? 'selected' : '' ?>>Частота запросов (Rate Limit)</option>
                        <option value="ua" <?= ($simType ?? '') === 'ua' ? 'selected' : '' ?>>Маска User-Agent</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold">Макс. запросов / Шаблон UA:</label>
                    <?php if (($simType ?? '') === 'ua'): ?>
                        <input type="text" name="sim_ua" class="form-control form-control-sm rounded-3" value="<?= Html::encode($simUa ?? 'Python') ?>">
                    <?php else: ?>
                        <input type="number" name="sim_max" class="form-control form-control-sm rounded-3" value="<?= (int) ($simMax ?? 20) ?>" min="1" max="1000">
                    <?php endif; ?>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Окно (сек):</label>
                    <input type="number" name="sim_window" class="form-control form-control-sm rounded-3" value="<?= (int) ($simWindow ?? 60) ?>" min="5" max="3600">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-sm btn-primary rounded-3 w-100">Запустить тест</button>
                </div>
            </form>

            <?php if (!empty($simResult)): ?>
                <div class="p-3 rounded-3 bg-white border shadow-sm">
                    <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
                        <div>
                            <strong class="text-dark">Результат анализа:</strong>
                            <span class="text-muted ms-1"><?= Html::encode($simResult['notice']) ?></span>
                        </div>
                        <div class="d-flex gap-2">
                            <span class="badge bg-secondary rounded-pill">Выборка: <?= (int) $simResult['sampleSize'] ?> событий</span>
                            <span class="badge bg-danger rounded-pill">Было бы заблокировано: <?= (int) $simResult['wouldBlock'] ?> (<?= $simResult['impactPercent'] ?>%)</span>
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
                        <h5 class="antibot-card-title">⏱️ Производительность проверок</h5>
                        <div class="text-muted small">Накладные расходы антибота на каждый HTTP-запрос</div>
                    </div>
                </div>
                <div class="antibot-card-body">
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <div class="p-3 rounded-3 bg-light border text-center">
                                <div class="small text-muted mb-1">Среднее время</div>
                                <div class="fs-4 fw-bold text-primary">
                                    <?= $perfAvg !== null ? $perfAvg . ' <span class="fs-6 fw-normal text-muted">мс</span>' : '—' ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="p-3 rounded-3 bg-light border text-center">
                                <div class="small text-muted mb-1">p95 перцентиль</div>
                                <div class="fs-4 fw-bold text-dark">
                                    <?= $perfP95 !== null ? $perfP95 . ' <span class="fs-6 fw-normal text-muted">мс</span>' : '—' ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="small text-muted">
                        <div class="d-flex justify-content-between py-1 border-bottom">
                            <span>Всего проинспектировано:</span>
                            <strong class="text-dark"><?= number_format($perfTotal) ?></strong>
                        </div>
                        <div class="d-flex justify-content-between py-1 border-bottom">
                            <span>Пропущено легитимных:</span>
                            <strong class="text-success"><?= (int) ($perf['allowed'] ?? 0) ?></strong>
                        </div>
                        <div class="d-flex justify-content-between py-1 border-bottom">
                            <span>Адаптер Rate Limit:</span>
                            <code><?= Html::encode($antibot->rateLimitAdapter) ?></code>
                        </div>
                        <div class="d-flex justify-content-between py-1">
                            <span>Окно лимитирования:</span>
                            <span class="text-dark"><?= (int) $antibot->rateLimitWindow ?> сек.</span>
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
                        <h5 class="antibot-card-title">📜 Журнал изменений правил (Audit Trail)</h5>
                        <div class="text-muted small">История сохранения настроек и применения рекомендаций</div>
                    </div>
                </div>
                <div class="antibot-card-body p-0">
                    <?php if (empty($auditTrail)): ?>
                        <div class="p-4 text-center text-muted small">
                            История изменений пока пуста. Настройки не менялись.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0" style="font-size: 0.85rem;">
                                <thead class="table-light">
                                    <tr>
                                        <th>Время</th>
                                        <th>IP автора</th>
                                        <th>Действие</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($auditTrail as $audit): ?>
                                        <tr>
                                            <td class="text-muted font-monospace"><?= Html::encode($audit['date'] ?? date('Y-m-d H:i:s', (int) ($audit['timestamp'] ?? time()))) ?></td>
                                            <td><code><?= Html::encode($audit['actor_ip'] ?? '127.0.0.1') ?></code></td>
                                            <td>
                                                <span class="badge bg-light text-dark border"><?= Html::encode($audit['action'] ?? 'settings_saved') ?></span>
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
