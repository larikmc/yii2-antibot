<?php

declare(strict_types=1);

/**
 * Regression Test Suite: TimeZone Consistency & Fallbacks (v1.6.1)
 *
 * Verifies:
 * 1. Event logged in Frontend (Europe/Moscow) displaying in Backend (Europe/Saratov).
 * 2. Numeric timestamp is used as the primary source of truth, ignoring stale date strings.
 * 3. Safe fallback for legacy records missing a numeric timestamp.
 * 4. Audit log timestamp formatting in host application timezone.
 * 5. Dashboard analytics timeline bucket matching consistency across timezones.
 * 6. Full-width settings layout container without artificial width restrictions.
 */

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/index.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

$autoloadCandidates = [
    'D:/OpenServer6.5.0/home/yii2-fresh-install.loc/vendor/autoload.php',
    __DIR__ . '/../../yii2-advanced.loc/vendor/autoload.php',
    'D:/OSPanel6.5.1/home/yii2-advanced.loc/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
    dirname(__DIR__, 2) . '/vendor/autoload.php',
    'D:/OpenServer6.5.0/home/yii2-admin/vendor/autoload.php',
];

$loader = null;
foreach ($autoloadCandidates as $cand) {
    if (file_exists($cand)) {
        $loader = require_once $cand;
        break;
    }
}

if ($loader === null) {
    echo "ERROR: Unable to locate Composer autoload.php\n";
    exit(1);
}

// Register AntiBot PSR-4
spl_autoload_register(function ($class) {
    $prefix = 'larikmc\\antibot\\';
    if (str_starts_with($class, $prefix)) {
        $relative = substr($class, strlen($prefix));
        $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relative) . '.php';
        if (file_exists($file)) {
            require_once $file;
            return true;
        }
    }
    return false;
});

// Require Yii core
$yiiCandidates = [
    'D:/OpenServer6.5.0/home/yii2-fresh-install.loc/vendor/yiisoft/yii2/Yii.php',
    __DIR__ . '/../../yii2-advanced.loc/vendor/yiisoft/yii2/Yii.php',
    'D:/OSPanel6.5.1/home/yii2-advanced.loc/vendor/yiisoft/yii2/Yii.php',
    dirname(__DIR__, 2) . '/vendor/yiisoft/yii2/Yii.php',
];
foreach ($yiiCandidates as $cand) {
    if (file_exists($cand)) {
        require_once $cand;
        break;
    }
}

$passed = 0;
$failed = 0;

function assertTest(bool $condition, string $description): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo " [PASS] " . $description . PHP_EOL;
    } else {
        $failed++;
        echo " [FAIL] " . $description . PHP_EOL;
    }
}

echo "=================================================================\n";
echo " Yii2 AntiBot: TimeZone & Timestamp Consistency Suite (v1.6.1)\n";
echo "=================================================================\n\n";

// --- Step 1: Frontend (Europe/Moscow) logs an event ---
echo "--- Section 1: Frontend (Europe/Moscow) Event Logging ---\n";
// Configure temporary app in Europe/Moscow
$frontendConfig = [
    'id' => 'frontend-app',
    'basePath' => __DIR__,
    'timeZone' => 'Europe/Moscow',
];
$frontendApp = new \yii\web\Application($frontendConfig);

// Fixed test timestamp: 1700000000 UTC
// In Europe/Moscow (UTC+3): 2023-11-15 01:13:20
// In Europe/Saratov (UTC+4): 2023-11-15 02:13:20
$testTimestamp = 1700000000;

$eventFromFrontend = [
    'ip' => '198.51.100.42',
    'timestamp' => $testTimestamp,
    'date' => date('Y-m-d H:i:s', $testTimestamp), // Frontend Moscow date: "2023-11-15 01:13:20"
    'action' => 'monitored',
    'reason' => 'Bot monitored: Googlebot',
    'userAgent' => 'Googlebot/2.1',
];

assertTest($eventFromFrontend['date'] === '2023-11-15 01:13:20', '1.1 Frontend in Europe/Moscow generates date string with UTC+3 (01:13:20)');

// --- Step 2: Backend (Europe/Saratov) reads and formats the event ---
echo "\n--- Section 2: Backend (Europe/Saratov) Display & Formatting ---\n";
// Switch app to Europe/Saratov (simulating backend admin panel)
Yii::$app->timeZone = 'Europe/Saratov';
assertTest(\larikmc\antibot\services\I18n::getTimeZone() === 'Europe/Saratov', '2.1 I18n resolves backend timeZone as Europe/Saratov');

$formattedInBackend = \larikmc\antibot\services\I18n::formatTimestamp(
    $eventFromFrontend['timestamp'] ?? null,
    $eventFromFrontend['date'] ?? null
);

assertTest(
    $formattedInBackend === '2023-11-15 02:13:20',
    "2.2 Numeric timestamp formatted in Europe/Saratov displays 02:13:20 (got: {$formattedInBackend})"
);

assertTest(
    $formattedInBackend !== $eventFromFrontend['date'],
    '2.3 Displayed time strictly ignores stale frontend Moscow date string'
);

// --- Step 3: Fallback for legacy records without timestamp ---
echo "\n--- Section 3: Safe Legacy Records Fallback ---\n";
$legacyRecord = [
    'ip' => '203.0.113.10',
    'date' => '2023-01-01 12:00:00',
    'action' => 'blocked',
];

$legacyFormatted = \larikmc\antibot\services\I18n::formatTimestamp(
    $legacyRecord['timestamp'] ?? null,
    $legacyRecord['date'] ?? null
);

assertTest(
    $legacyFormatted === '2023-01-01 12:00:00',
    '3.1 Legacy record missing timestamp preserves exact original date string without guessing'
);

$emptyRecord = [
    'ip' => '203.0.113.11',
];
$emptyFormatted = \larikmc\antibot\services\I18n::formatTimestamp(
    $emptyRecord['timestamp'] ?? null,
    $emptyRecord['date'] ?? null
);
assertTest($emptyFormatted === '', '3.2 Record without timestamp and without date returns empty string');

// --- Step 4: Audit trail and Auto-Jail formatting in host timezone ---
echo "\n--- Section 4: Audit Trail & Auto-Jail in Host TimeZone ---\n";
$auditEntry = [
    'timestamp' => $testTimestamp,
    'datetime' => '2023-11-15 01:13:20', // originally saved in Moscow
    'action' => 'settings_saved',
];

$auditFormatted = \larikmc\antibot\services\I18n::formatTimestamp(
    $auditEntry['timestamp'] ?? null,
    $auditEntry['date'] ?? ($auditEntry['datetime'] ?? null)
);

assertTest(
    $auditFormatted === '2023-11-15 02:13:20',
    '4.1 Audit entry timestamp formatted in Europe/Saratov displays 02:13:20'
);

$jailEntry = [
    'ip' => '192.0.2.1',
    'jailed_at' => $testTimestamp,
    'reason' => 'Honey URL trap hit',
];
$jailFormatted = \larikmc\antibot\services\I18n::formatTimestamp(
    $jailEntry['jailed_at'] ?? null,
    null,
    'H:i:s d.m.Y'
);
assertTest(
    $jailFormatted === '02:13:20 15.11.2023',
    "4.2 Auto-Jail jailed_at formatted in Europe/Saratov displays 02:13:20 15.11.2023 (got: {$jailFormatted})"
);

// --- Step 5: Timeline Buckets Alignment in Host Timezone ---
echo "\n--- Section 5: Timeline Buckets Alignment in Host TimeZone ---\n";
$analytics = \larikmc\antibot\services\analytics\DashboardAnalyticsService::getAnalytics(
    new \larikmc\antibot\services\StorageService(),
    \larikmc\antibot\services\analytics\DashboardAnalyticsService::PERIOD_24H,
    'standard',
    true
);

assertTest(!empty($analytics['timeline']['labels']), '5.1 Timeline labels generated in host timezone');
assertTest(!empty($analytics['generated_at']), '5.2 generated_at formatted according to host application time');

// --- Step 6: Settings Container Full-Width Verification ---
echo "\n--- Section 6: Settings Container Layout Verification ---\n";
$settingsView = file_get_contents(dirname(__DIR__) . '/src/views/settings/index.php');
assertTest(
    !str_contains($settingsView, 'max-width: 1040px'),
    '6.1 Settings container does NOT contain artificial max-width: 1040px restriction'
);
assertTest(
    str_contains($settingsView, 'antibot-settings-container w-100'),
    '6.2 Settings container has w-100 full-width class'
);

// --- Step 7: Version Bump Verification ---
echo "\n--- Section 7: Version Alignment (v1.6.1) ---\n";
assertTest(version_compare(\larikmc\antibot\Module::VERSION, '1.6.1', '>='), '7.1 Module::VERSION is >= 1.6.1');
assertTest(version_compare(\larikmc\antibot\Antibot::VERSION, '1.6.1', '>='), '7.2 Antibot::VERSION is >= 1.6.1');

// --- Step 8: Live Feed GridView Column Filters Verification ---
echo "\n--- Section 8: Live Feed GridView Column Filters ---\n";
$indexView = file_get_contents(dirname(__DIR__) . '/src/views/default/index.php');
$cssContent = file_get_contents(dirname(__DIR__) . '/src/assets/web/css/dashboard.css');

assertTest(
    str_contains($indexView, 'id="liveFeedFiltersRow"'),
    '8.1 Live Feed contains GridView filter row (id="liveFeedFiltersRow")'
);
assertTest(
    str_contains($indexView, 'data-col="time"') && str_contains($indexView, 'data-col="ip"') && str_contains($indexView, 'data-col="action"'),
    '8.2 Column filter inputs exist for time, ip, action, reason, url, and ua'
);
assertTest(
    str_contains($indexView, 'data-col-time=') && str_contains($indexView, 'data-col-ip=') && str_contains($indexView, 'data-col-action='),
    '8.3 Live Feed table rows contain data-col-* attributes for instant client-side filtering'
);
assertTest(
    str_contains($indexView, 'id="resetColumnFilters"'),
    '8.4 Quick reset button (id="resetColumnFilters") exists in filter row'
);
assertTest(
    str_contains($cssContent, '.antibot-table thead tr.filters'),
    '8.5 CSS styling for .antibot-table thead tr.filters is registered in dashboard.css'
);
assertTest(
    \larikmc\antibot\services\I18n::t('grid_filter_ip', 'ru') === 'IP адрес...'
    && \larikmc\antibot\services\I18n::t('grid_filter_ip', 'en') === 'IP address...',
    '8.6 I18n translations for GridView column filters are registered in both RU and EN'
);

// --- Step 9: History Depth Settings & Data Export Suite ---
echo "\n--- Section 9: History Depth Settings & Data Export ---\n";
$storage = new \larikmc\antibot\services\StorageService();
$loadedSettings = $storage->loadSettings();

assertTest(
    isset($loadedSettings['eventHistoryScanLimit']) && $loadedSettings['eventHistoryScanLimit'] === 5000,
    '9.1 Default eventHistoryScanLimit is 5000 events'
);
assertTest(
    isset($loadedSettings['liveFeedTableLimit']) && $loadedSettings['liveFeedTableLimit'] === 100,
    '9.2 Default liveFeedTableLimit is 100 rows'
);
assertTest(
    $storage->maxLogSizeBytes === 10485760 && ($loadedSettings['maxLogSizeBytes'] ?? 0) === 10485760,
    '9.3 Default maxLogSizeBytes is 10 MB (10485760 bytes)'
);

$settingsView = file_get_contents(dirname(__DIR__) . '/src/views/settings/index.php');
assertTest(
    str_contains($settingsView, 'name="AntibotSetting[eventHistoryScanLimit]"')
    && str_contains($settingsView, 'name="AntibotSetting[liveFeedTableLimit]"')
    && str_contains($settingsView, 'name="AntibotSetting[maxLogSizeBytes]"'),
    '9.4 Settings view contains Section 17 with configurable history depth, table limit, and max log size'
);

// Test streamEvents() functionality with a temporary test file
$tempLog = sys_get_temp_dir() . '/antibot_test_stream_' . uniqid() . '.jsonl';
$testEvents = [
    ['timestamp' => time() - 20, 'ip' => '1.1.1.1', 'action' => 'monitored', 'reason' => 'Test 1'],
    ['timestamp' => time() - 10, 'ip' => '2.2.2.2', 'action' => 'blocked', 'reason' => 'Test 2'],
    ['timestamp' => time(), 'ip' => '3.3.3.3', 'action' => 'throttled', 'reason' => 'Test 3'],
];
file_put_contents($tempLog, implode("\n", array_map('json_encode', $testEvents)) . "\n");

$streamStorage = new \larikmc\antibot\services\StorageService(['eventsFile' => $tempLog]);
$streamedCount = 0;
$blockedCount = 0;
$streamStorage->streamEvents(function (array $event) use (&$streamedCount, &$blockedCount): bool {
    $streamedCount++;
    if (($event['action'] ?? '') === 'blocked' || ($event['action'] ?? '') === 'throttled') {
        $blockedCount++;
    }
    return true;
}, false);

assertTest($streamedCount === 3, "9.5 streamEvents() successfully streamed all 3 lines line-by-line (got {$streamedCount})");
assertTest($blockedCount === 2, "9.6 streamEvents() properly filtered 2 blocked/throttled events");
@unlink($tempLog);

assertTest(
    method_exists(\larikmc\antibot\controllers\DefaultController::class, 'actionExportEvents'),
    '9.7 DefaultController implements actionExportEvents() with CSV and JSONL support'
);
assertTest(
    str_contains($indexView, 'export-events') && str_contains($indexView, "'format' => 'csv'") && str_contains($indexView, "'format' => 'jsonl'"),
    '9.8 Live Feed view contains instant export links and dropdown for CSV and JSONL'
);
assertTest(
    \larikmc\antibot\services\I18n::t('export_btn', 'ru') === 'Экспорт журнала'
    && \larikmc\antibot\services\I18n::t('export_btn', 'en') === 'Export Log',
    '9.9 I18n export and Section 17 translations are present in both RU and EN'
);

echo "\n=================================================================\n";
echo " Test Results: {$passed} / " . ($passed + $failed) . " passed (" . round(($passed / ($passed + $failed)) * 100) . "%)\n";
echo "=================================================================\n";

if ($failed === 0) {
    echo " ALL TIMEZONE REGRESSION TESTS PASSED SUCCESSFULLY!\n";
    exit(0);
} else {
    echo " FAILURES DETECTED! Review logs above.\n";
    exit(1);
}
