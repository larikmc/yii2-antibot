<?php

declare(strict_types=1);

namespace larikmc\antibot\data;

use Yii;
use yii\helpers\Json;

/**
 * Manages official Integral Ad Science (IAS) crawler IP addresses.
 *
 * Provides offline-first verification for ias_crawler and ias_wombles
 * with zero-latency visitor inspection and background/on-demand updating
 * from https://integralads.com/policy-docs/iasbot.json.
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class IasRanges
{
    public const SOURCE_URL = 'https://integralads.com/policy-docs/iasbot.json';

    /**
     * Fallback official IP list (snapshot from IAS official documentation).
     */
    public const IPS = [
        '3.217.168.199',
        '3.226.51.67',
        '18.214.43.70',
        '23.21.212.31',
        '34.195.212.30',
        '35.175.92.196',
        '92.42.105.32',
        '92.42.105.33',
        '92.42.105.34',
        '92.42.105.35',
        '92.42.105.36',
        '92.42.105.37',
        '92.42.105.38',
        '92.42.105.39',
        '92.204.170.15',
        '92.204.190.235',
        '92.204.248.55',
        '151.106.17.251',
        '162.211.125.4',
        '162.211.125.5',
        '162.211.125.6',
        '162.211.125.7',
        '162.211.125.8',
        '173.224.127.64',
        '173.224.127.65',
        '173.224.127.66',
        '173.224.127.67',
        '173.224.127.68',
        '173.224.127.69',
        '173.224.127.70',
        '173.224.127.71',
    ];

    /**
     * In-memory cache for process lifecycle.
     * @var array<int, string>|null
     */
    private static ?array $cachedIps = null;

    /**
     * Resolves the runtime storage file path for updated IAS IP lists.
     */
    public static function resolveRuntimeFile(): string
    {
        if (Yii::$app !== null && Yii::getAlias('@common', false) !== false) {
            $dir = Yii::getAlias('@common/runtime/antibot');
        } elseif (Yii::$app !== null && Yii::getAlias('@runtime', false) !== false) {
            $dir = Yii::getAlias('@runtime/antibot');
        } else {
            $dir = sys_get_temp_dir() . '/antibot';
        }

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir . '/ias_crawlers.json';
    }

    /**
     * Resolves the bundled snapshot file path.
     */
    public static function resolveBundledFile(): string
    {
        return __DIR__ . '/ias_crawlers.json';
    }

    /**
     * Returns the active list of IAS crawler IP addresses.
     * Never performs blocking network calls during visitor inspection.
     *
     * @return array<int, string>
     */
    public static function getIps(): array
    {
        if (self::$cachedIps !== null) {
            return self::$cachedIps;
        }

        // 1. Try runtime updated file
        $runtimeFile = self::resolveRuntimeFile();
        if (file_exists($runtimeFile)) {
            $content = @file_get_contents($runtimeFile);
            if ($content !== false) {
                $ips = self::parseJson($content);
                if (!empty($ips)) {
                    self::$cachedIps = $ips;
                    return self::$cachedIps;
                }
            }
        }

        // 2. Try bundled JSON file
        $bundledFile = self::resolveBundledFile();
        if (file_exists($bundledFile)) {
            $content = @file_get_contents($bundledFile);
            if ($content !== false) {
                $ips = self::parseJson($content);
                if (!empty($ips)) {
                    self::$cachedIps = $ips;
                    return self::$cachedIps;
                }
            }
        }

        // 3. Fallback to PHP constant
        self::$cachedIps = self::IPS;
        return self::$cachedIps;
    }

    /**
     * Checks whether an IP belongs to IAS crawler infrastructure.
     */
    public static function match(string $ip): bool
    {
        return in_array($ip, self::getIps(), true);
    }

    /**
     * Updates the IAS IP snapshot from official endpoint.
     * If update fails, existing valid list is strictly preserved.
     *
     * @param int $timeoutSeconds Timeout for HTTP request.
     * @return array{success: bool, updated: bool, count: int, message: string}
     */
    public static function update(int $timeoutSeconds = 5): array
    {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => $timeoutSeconds,
                'header' => "User-Agent: Yii2-AntiBot-Updater/1.6 (IAS Sync; +https://github.com/larikmc/yii2-antibot)\r\nAccept: application/json\r\n",
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $content = @file_get_contents(self::SOURCE_URL, false, $ctx);
        if ($content === false || trim($content) === '') {
            return [
                'success' => false,
                'updated' => false,
                'count' => count(self::getIps()),
                'message' => 'Failed to reach IAS endpoint. Last valid snapshot preserved.',
            ];
        }

        $ips = self::parseJson($content);
        if (empty($ips)) {
            return [
                'success' => false,
                'updated' => false,
                'count' => count(self::getIps()),
                'message' => 'IAS response contained no valid IP addresses. Last valid snapshot preserved.',
            ];
        }

        // Write safely to runtime directory
        $runtimeFile = self::resolveRuntimeFile();
        $tmpFile = $runtimeFile . '.' . uniqid('tmp_', true);
        if (@file_put_contents($tmpFile, $content, LOCK_EX) !== false) {
            @rename($tmpFile, $runtimeFile);
            self::$cachedIps = $ips;

            return [
                'success' => true,
                'updated' => true,
                'count' => count($ips),
                'message' => sprintf('Successfully updated %d IAS crawler IPs.', count($ips)),
            ];
        }

        return [
            'success' => false,
            'updated' => false,
            'count' => count(self::getIps()),
            'message' => 'Could not write runtime cache file. Last valid snapshot preserved.',
        ];
    }

    /**
     * Parses JSON response from IAS endpoint.
     *
     * @param string $jsonString
     * @return array<int, string>
     */
    private static function parseJson(string $jsonString): array
    {
        try {
            $data = Json::decode($jsonString);
            if (!is_array($data) || !isset($data['publicIPs']) || !is_array($data['publicIPs'])) {
                return [];
            }

            $ips = [];
            foreach ($data['publicIPs'] as $item) {
                if (is_array($item)) {
                    $ip = $item['ipv4'] ?? ($item['ipv6'] ?? ($item['ip'] ?? null));
                    if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                        $ips[] = $ip;
                    }
                } elseif (is_string($item) && filter_var($item, FILTER_VALIDATE_IP) !== false) {
                    $ips[] = $item;
                }
            }

            return array_values(array_unique($ips));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Resets in-memory cached state (useful in tests).
     */
    public static function resetCache(): void
    {
        self::$cachedIps = null;
    }
}
