<?php

declare(strict_types=1);

/**
 * Yii2 AntiBot v1.5.9 Verification Suite
 *
 * Verifies:
 * 1. Language resolution: auto-detection from Yii::$app->language and session overrides.
 * 2. Action translation: monitored, blocked, throttled, allowed_search_bot, challenge_passed, etc.
 * 3. Reason translation in Russian:
 *    - Fake search bots (Yandex, Google, etc.)
 *    - Authentic search engines
 *    - Commercial scrapers (PetalBot, Ahrefs, etc.)
 *    - CLI and HTTP libraries (cURL, Python, etc.)
 *    - AI crawlers (GPTBot, Claude, etc.)
 *    - JS Challenge solved and failed
 *    - Rate limits and behavioral anomalies
 * 4. English fallback when lang = 'en'.
 * 5. StorageService defaults and persistence for 'language'.
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */

$autoloadCandidates = [
    __DIR__ . '/../../strb64.loc/vendor/autoload.php',
    __DIR__ . '/../../yii2-advanced.loc/vendor/autoload.php',
    dirname(__DIR__, 4) . '/vendor/autoload.php',
];

$loader = null;
foreach ($autoloadCandidates as $cand) {
    if (file_exists($cand)) {
        $loader = require_once $cand;
        break;
    }
}

if ($loader === null) {
    echo "ERROR: Unable to find Composer autoload.php in candidates.\n";
    exit(1);
}

$loader->setPsr4('larikmc\\antibot\\', [dirname(__DIR__) . '/src']);

$yiiCandidates = [
    __DIR__ . '/../../strb64.loc/vendor/yiisoft/yii2/Yii.php',
    __DIR__ . '/../../yii2-advanced.loc/vendor/yiisoft/yii2/Yii.php',
    dirname(__DIR__, 4) . '/vendor/yiisoft/yii2/Yii.php',
];

foreach ($yiiCandidates as $cand) {
    if (file_exists($cand)) {
        require_once $cand;
        break;
    }
}

$testRuntime = __DIR__ . '/runtime';
if (!is_dir($testRuntime)) {
    mkdir($testRuntime, 0777, true);
}
Yii::setAlias('@runtime', $testRuntime);

$config = [
    'id' => 'antibot-v159-test',
    'basePath' => dirname(__DIR__),
    'language' => 'ru-RU',
    'components' => [
        'cache' => [
            'class' => \yii\caching\ArrayCache::class,
        ],
        'request' => [
            'class' => \yii\web\Request::class,
            'cookieValidationKey' => 'test-validation-key-for-v159-verification',
        ],
    ],
];

new \yii\web\Application($config);

$passed = 0;
$failed = 0;

function assertCondition(bool $cond, string $msg): void {
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "[PASS] {$msg}\n";
    } else {
        $failed++;
        echo "[FAIL] {$msg}\n";
    }
}

echo "========================================================\n";
echo " Yii2 AntiBot v1.5.9 Verification Suite\n";
echo "========================================================\n\n";

use larikmc\antibot\services\I18n;
use larikmc\antibot\services\StorageService;

// -----------------------------------------------------------------------------
// SECTION 1: Language Detection
// -----------------------------------------------------------------------------
echo "--- Section 1: Language Resolution ---\n";

assertCondition(I18n::getLanguage() === 'ru', '1.1: Auto-detected ru from Yii::$app->language = ru-RU');
assertCondition(I18n::isRussian() === true, '1.2: isRussian() is true for ru-RU');

// -----------------------------------------------------------------------------
// SECTION 2: Action Translations
// -----------------------------------------------------------------------------
echo "\n--- Section 2: Action Translations ---\n";

$actMonitored = I18n::formatAction('monitored', 'ru');
assertCondition($actMonitored['label'] === 'Мониторинг', '2.1: monitored -> Мониторинг');

$actBlocked = I18n::formatAction('blocked', 'ru');
assertCondition($actBlocked['label'] === 'Заблокирован', '2.2: blocked -> Заблокирован');

$actThrottled = I18n::formatAction('throttled', 'ru');
assertCondition($actThrottled['label'] === 'Лимит превышен', '2.3: throttled -> Лимит превышен');

$actSearch = I18n::formatAction('allowed_search_bot', 'ru');
assertCondition($actSearch['label'] === 'Поисковик (OK)', '2.4: allowed_search_bot -> Поисковик (OK)');

$actChallenge = I18n::formatAction('challenge_passed', 'ru');
assertCondition($actChallenge['label'] === 'Проверка пройдена', '2.5: challenge_passed -> Проверка пройдена');

$actEnglish = I18n::formatAction('monitored', 'en');
assertCondition($actEnglish['label'] === 'Monitored', '2.6: monitored (en) -> Monitored');

// -----------------------------------------------------------------------------
// SECTION 3: Reason Translations (Russian)
// -----------------------------------------------------------------------------
echo "\n--- Section 3: Reason Translations (Russian) ---\n";

// Fake Yandex
$r1 = I18n::formatReason('Fake search bot spoofing Yandex', 'ru');
assertCondition(str_contains($r1['title'], 'Поддельный бот') && str_contains($r1['title'], 'Яндекс'), "3.1: Fake Yandex translated ({$r1['title']})");
assertCondition($r1['badge'] === 'Фейк-бот', '3.1b: Fake Yandex badge is Фейк-бот');

// Authentic Yandex
$r2 = I18n::formatReason('Authentic Search Engine: Yandex', 'ru');
assertCondition(str_contains($r2['title'], 'Подлинный поисковый робот') && str_contains($r2['title'], 'Яндекс'), "3.2: Authentic Yandex translated ({$r2['title']})");
assertCondition($r2['badge'] === 'Поисковик', '3.2b: Authentic Yandex badge is Поисковик');

// PetalBot scraper
$r3 = I18n::formatReason('Commercial scraper blocked: PetalBot', 'ru');
assertCondition(str_contains($r3['title'], 'Коммерческий парсер / скрапер: PetalBot'), "3.3: PetalBot scraper translated ({$r3['title']})");
assertCondition($r3['badge'] === 'Скрапер', '3.3b: Scraper badge is Скрапер');

// cURL CLI
$r4 = I18n::formatReason('Automated CLI or HTTP library: cURL', 'ru');
assertCondition(str_contains($r4['title'], 'Автоматическая утилита / скрипт: cURL'), "3.4: cURL CLI translated ({$r4['title']})");
assertCondition($r4['badge'] === 'CLI / Скрипт', '3.4b: CLI badge is CLI / Скрипт');

// Missing UA
$r5 = I18n::formatReason('Empty or missing User-Agent header', 'ru');
assertCondition(str_contains($r5['title'], 'Пустой или отсутствующий'), "3.5: Missing UA translated ({$r5['title']})");

// JS Challenge Solved
$r6 = I18n::formatReason('JS Challenge Solved', 'ru');
assertCondition(str_contains($r6['title'], 'Браузерная проверка успешно решена'), "3.6: JS Challenge Solved translated ({$r6['title']})");

// AI Crawler
$r7 = I18n::formatReason('AI crawler blocked: GPTBot', 'ru');
assertCondition(str_contains($r7['title'], 'AI-краулер (нейросеть): GPTBot'), "3.7: GPTBot translated ({$r7['title']})");
assertCondition($r7['badge'] === 'AI-бот', '3.7b: AI crawler badge is AI-бот');

// Rate limit
$r8 = I18n::formatReason('Rate limit exceeded (60 req/min)', 'ru');
assertCondition(str_contains($r8['title'], 'Превышен лимит частоты запросов'), "3.8: Rate limit translated ({$r8['title']})");

// Behavioral anomaly
$r9 = I18n::formatReason('Behavioral anomaly: high frequency burst', 'ru');
assertCondition(str_contains($r9['title'], 'Поведенческая аномалия: серия быстрых запросов'), "3.9: Behavioral anomaly translated ({$r9['title']})");

// Honey trap
$r10 = I18n::formatReason('Honey trap URL visited', 'ru');
assertCondition(str_contains($r10['title'], 'Переход по скрытой ссылке-ловушке'), "3.10: Honey trap translated ({$r10['title']})");

// -----------------------------------------------------------------------------
// SECTION 4: English Mode
// -----------------------------------------------------------------------------
echo "\n--- Section 4: English Translations ---\n";

$rEn = I18n::formatReason('Fake search bot spoofing Google', 'en');
assertCondition($rEn['title'] === 'Fake search bot spoofing Google', '4.1: English mode preserves English title');
assertCondition($rEn['badge'] === 'Fake Bot', '4.2: English badge is Fake Bot');

// -----------------------------------------------------------------------------
// SECTION 5: StorageService Defaults & Persistence
// -----------------------------------------------------------------------------
echo "\n--- Section 5: StorageService Integration ---\n";

$testStorageFile = $testRuntime . '/test_storage_v159_' . uniqid() . '.json';
$storage = new StorageService([
    'storageFile' => $testStorageFile,
]);

$loaded = $storage->loadSettings();
assertCondition(isset($loaded['language']), '5.1: language key exists in loaded defaults');
assertCondition($loaded['language'] === 'auto', '5.2: language defaults to auto in StorageService');

$loaded['language'] = 'ru';
$saved = $storage->saveSettings($loaded);
assertCondition($saved === true, '5.3: saveSettings() returned true');

$storage2 = new StorageService([
    'storageFile' => $testStorageFile,
]);
$reloaded = $storage2->loadSettings();
assertCondition($reloaded['language'] === 'ru', '5.4: language persisted value (ru) reloaded correctly');

@unlink($testStorageFile);
@rmdir($testRuntime);

echo "\n========================================================\n";
echo " Tests finished: {$passed} passed, {$failed} failed\n";
echo "========================================================\n";

exit($failed > 0 ? 1 : 0);
