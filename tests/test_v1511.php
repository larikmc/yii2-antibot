<?php

declare(strict_types=1);

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
Yii::setAlias('@common', $testRuntime . '/common');

if (Yii::$app === null) {
    new \yii\web\Application([
        'id' => 'testapp',
        'basePath' => __DIR__,
        'language' => 'ru-RU',
        'components' => [
            'request' => [
                'cookieValidationKey' => 'test-validation-key-32-chars-long!',
                'scriptFile' => __FILE__,
                'scriptUrl' => '/index.php',
            ],
            'session' => [
                'class' => \yii\web\Session::class,
            ],
        ],
    ]);
}

use larikmc\antibot\Antibot;
use larikmc\antibot\Module;
use larikmc\antibot\controllers\DefaultController;
use larikmc\antibot\services\I18n;

echo "========================================================\n";
echo " Yii2 AntiBot v1.5.11 Verification Suite\n";
echo "========================================================\n\n";

$passed = 0;
$failed = 0;

function assertTest(bool $condition, string $description): void
{
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] {$description}\n";
        $passed++;
    } else {
        echo "[FAIL] {$description}\n";
        $failed++;
    }
}

// Section 1: Version check
echo "--- Section 1: Version Alignment ---\n";
assertTest(Module::VERSION === '1.5.11', "1.1: Module::VERSION is 1.5.11 (actual: " . Module::VERSION . ")");
assertTest(Antibot::VERSION === '1.5.11', "1.2: Antibot::VERSION is 1.5.11 (actual: " . Antibot::VERSION . ")");

// Section 2: Controller Actions check
echo "\n--- Section 2: Controller & Actions Structure ---\n";
$module = new Module('antibot', Yii::$app);
$controller = new DefaultController('default', $module);

assertTest(method_exists($controller, 'actionIndex'), "2.1: actionIndex exists on DefaultController");
assertTest(method_exists($controller, 'actionDiagnostics'), "2.2: actionDiagnostics exists on DefaultController");
assertTest(method_exists($controller, 'actionApplyRecommendation'), "2.3: actionApplyRecommendation exists on DefaultController");
assertTest(method_exists($controller, 'actionSimulate'), "2.4: actionSimulate exists on DefaultController");

// Section 3: Views exist
echo "\n--- Section 3: View Files Integrity ---\n";
$viewPath = dirname(__DIR__) . '/src/views/default';
assertTest(file_exists($viewPath . '/index.php'), "3.1: default/index.php exists");
assertTest(file_exists($viewPath . '/diagnostics.php'), "3.2: default/diagnostics.php exists");
assertTest(file_exists(dirname(__DIR__) . '/src/views/settings/index.php'), "3.3: settings/index.php exists");
assertTest(file_exists(dirname(__DIR__) . '/src/views/ip-list/index.php'), "3.4: ip-list/index.php exists");

// Section 4: Automatic Framework Language Resolution
echo "\n--- Section 4: Automatic Framework Language Resolution ---\n";
Yii::$app->language = 'ru-RU';
assertTest(I18n::getLanguage() === 'ru', "4.1: I18n resolves 'ru' for 'ru-RU'");
assertTest(I18n::isRussian() === true, "4.2: I18n::isRussian() is true for 'ru-RU'");

Yii::$app->language = 'en-US';
assertTest(I18n::getLanguage() === 'en', "4.3: I18n resolves 'en' for 'en-US'");
assertTest(I18n::isRussian() === false, "4.4: I18n::isRussian() is false for 'en-US'");

// Reset language back to Russian
Yii::$app->language = 'ru-RU';

// Section 5: Bilingual Translations Presence
echo "\n--- Section 5: Bilingual Translation Coverage ---\n";
assertTest(I18n::t('app_title', 'ru') === 'Антибот — Мониторинг и визуальная аналитика', "5.1: RU translation for app_title");
assertTest(I18n::t('app_title', 'en') === 'AntiBot — Monitoring & Visual Analytics', "5.2: EN translation for app_title");
assertTest(I18n::t('tab_monitoring', 'ru') === '📊 Мониторинг и Журнал', "5.3: RU tab_monitoring");
assertTest(I18n::t('tab_monitoring', 'en') === '📊 Monitoring & Log', "5.4: EN tab_monitoring");

echo "\n========================================================\n";
echo " Tests finished: {$passed} passed, {$failed} failed\n";
echo "========================================================\n";

if ($failed > 0) {
    exit(1);
}
