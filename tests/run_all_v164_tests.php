<?php

declare(strict_types=1);

$suites = [
    'test_v1514.php' => 'v1.5.14 Core & Security Suite',
    'test_v160_catalog.php' => 'v1.6.0 Bot Catalog & Access Control Suite',
    'test_v160_final_audit.php' => 'v1.6.0 Final Audit & Verification Suite',
    'test_v161_timezone.php' => 'v1.6.1 TimeZone, Export & Column Filters Suite',
    'test_v162_dns_cache.php' => 'v1.6.2 DNS Budget & Persistent Caching Suite',
    'test_v162_negative_cache.php' => 'v1.6.2 Negative Caching & Block Optimization Suite',
    'test_v162_final_acceptance.php' => 'v1.6.2 Final Acceptance Verification Suite',
    'test_v163_challenge_bfcache.php' => 'v1.6.3 JS Challenge BFCache & Policy Compliance Suite',
    'test_v164_meta_replay_export.php' => 'v1.6.4 Meta Crawlers, Replay Protection & Export Suite',
];

echo "=================================================================\n";
echo " Yii2 AntiBot: Master Test Suite Runner for v1.6.4 Release\n";
echo "=================================================================\n\n";

$phpCli = defined('PHP_BINARY') && file_exists(PHP_BINARY) ? PHP_BINARY : 'D:\\OSPanel6.5.1\\modules\\PHP-8.3\\PHP.exe';
$allPassed = true;
$totalAsserts = 0;

foreach ($suites as $file => $name) {
    $filePath = __DIR__ . '/' . $file;
    echo "Running {$name} ({$file})...\n";
    $cmd = escapeshellarg($phpCli) . ' ' . escapeshellarg($filePath);
    $output = [];
    $returnCode = 0;
    exec($cmd, $output, $returnCode);

    $fullText = implode("\n", $output);

    if ($returnCode === 0) {
        $count = 0;
        if (preg_match('/TOTAL TESTS:\s*(\d+)/i', $fullText, $m)) {
            $count = (int)$m[1];
        } elseif (preg_match('/Total Assertions:\s*(\d+)/i', $fullText, $m)) {
            $count = (int)$m[1];
        } elseif (preg_match('/Results:\s*(\d+)\s*\/\s*(\d+)/i', $fullText, $m)) {
            $count = (int)$m[2];
        }
        $totalAsserts += $count;
        echo " -> PASS: {$count} checks passed.\n";
    } else {
        $allPassed = false;
        echo " -> FAIL (exit code {$returnCode})!\n";
        $lastLines = array_slice($output, -10);
        echo implode("\n", $lastLines) . "\n";
    }
    echo "\n";
}

echo "=================================================================\n";
if ($allPassed) {
    echo " Master Test Run Summary: ALL SUITES PASSED (100%)\n";
    echo " Total Verified Assertions Across Suites: {$totalAsserts}\n";
} else {
    echo " Master Test Run Summary: SOME SUITES FAILED!\n";
}
echo "=================================================================\n";

exit($allPassed ? 0 : 1);
