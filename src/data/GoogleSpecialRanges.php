<?php

declare(strict_types=1);

namespace larikmc\antibot\data;

use larikmc\antibot\services\IpMatcher;
use Yii;
use yii\helpers\Json;

/**
 * Manages official Google Special Crawlers CIDR ranges (AdsBot, FeedFetcher, etc.).
 *
 * Provides offline-first verification with zero-latency visitor inspection and
 * background/on-demand updating from official Google endpoint:
 * https://developers.google.com/static/crawling/ipranges/special-crawlers.json
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class GoogleSpecialRanges
{
    public const SOURCE_URL = 'https://developers.google.com/static/crawling/ipranges/special-crawlers.json';

    /**
     * In-memory cache for process lifecycle.
     * @var array<int, string>|null
     */
    private static ?array $cachedCidrs = null;
    public const CIDRS = array (
  0 => '108.177.2.0/27',
  1 => '108.177.2.128/27',
  2 => '108.177.2.160/27',
  3 => '108.177.2.192/27',
  4 => '108.177.2.224/27',
  5 => '108.177.2.32/27',
  6 => '108.177.2.64/27',
  7 => '108.177.2.96/27',
  8 => '192.178.16.0/27',
  9 => '192.178.16.128/27',
  10 => '192.178.16.160/27',
  11 => '192.178.16.192/27',
  12 => '192.178.16.224/27',
  13 => '192.178.16.32/27',
  14 => '192.178.16.64/27',
  15 => '192.178.16.96/27',
  16 => '192.178.17.0/27',
  17 => '2001:4860:4801:2008::/64',
  18 => '2001:4860:4801:200c::/64',
  19 => '2001:4860:4801:200d::/64',
  20 => '2001:4860:4801:2010::/64',
  21 => '2001:4860:4801:2011::/64',
  22 => '2001:4860:4801:2012::/64',
  23 => '2001:4860:4801:2013::/64',
  24 => '2001:4860:4801:2014::/64',
  25 => '2001:4860:4801:2015::/64',
  26 => '2001:4860:4801:2016::/64',
  27 => '2001:4860:4801:2017::/64',
  28 => '2001:4860:4801:2018::/64',
  29 => '2001:4860:4801:2019::/64',
  30 => '2001:4860:4801:201a::/64',
  31 => '2001:4860:4801:201b::/64',
  32 => '2001:4860:4801:201c::/64',
  33 => '2001:4860:4801:201d::/64',
  34 => '2001:4860:4801:201e::/64',
  35 => '2001:4860:4801:201f::/64',
  36 => '2001:4860:4801:2020::/64',
  37 => '2001:4860:4801:2021::/64',
  38 => '2001:4860:4801:2022::/64',
  39 => '2001:4860:4801:2023::/64',
  40 => '2001:4860:4801:2024::/64',
  41 => '2001:4860:4801:2025::/64',
  42 => '2001:4860:4801:2026::/64',
  43 => '2001:4860:4801:2027::/64',
  44 => '2001:4860:4801:2028::/64',
  45 => '2001:4860:4801:2029::/64',
  46 => '2001:4860:4801:202a::/64',
  47 => '2001:4860:4801:202b::/64',
  48 => '2001:4860:4801:202c::/64',
  49 => '2001:4860:4801:202d::/64',
  50 => '2001:4860:4801:202e::/64',
  51 => '2001:4860:4801:202f::/64',
  52 => '2001:4860:4801:2030::/64',
  53 => '2001:4860:4801:2031::/64',
  54 => '2001:4860:4801:2032::/64',
  55 => '2001:4860:4801:2033::/64',
  56 => '2001:4860:4801:2034::/64',
  57 => '2001:4860:4801:2035::/64',
  58 => '2001:4860:4801:2036::/64',
  59 => '2001:4860:4801:2037::/64',
  60 => '2001:4860:4801:2038::/64',
  61 => '2001:4860:4801:2039::/64',
  62 => '2001:4860:4801:203a::/64',
  63 => '2001:4860:4801:203b::/64',
  64 => '2001:4860:4801:203c::/64',
  65 => '2001:4860:4801:203d::/64',
  66 => '2001:4860:4801:203e::/64',
  67 => '2001:4860:4801:203f::/64',
  68 => '2001:4860:4801:2040::/64',
  69 => '2001:4860:4801:2041::/64',
  70 => '2001:4860:4801:2042::/64',
  71 => '2001:4860:4801:2043::/64',
  72 => '2001:4860:4801:2044::/64',
  73 => '2001:4860:4801:2045::/64',
  74 => '2001:4860:4801:2046::/64',
  75 => '2001:4860:4801:2047::/64',
  76 => '2001:4860:4801:2048::/64',
  77 => '2001:4860:4801:2049::/64',
  78 => '2001:4860:4801:204a::/64',
  79 => '2001:4860:4801:204b::/64',
  80 => '2001:4860:4801:204c::/64',
  81 => '2001:4860:4801:204d::/64',
  82 => '2001:4860:4801:204e::/64',
  83 => '2001:4860:4801:2050::/64',
  84 => '2001:4860:4801:2051::/64',
  85 => '2001:4860:4801:2052::/64',
  86 => '2001:4860:4801:2053::/64',
  87 => '2001:4860:4801:2054::/64',
  88 => '2001:4860:4801:2055::/64',
  89 => '2001:4860:4801:2056::/64',
  90 => '2001:4860:4801:2060::/64',
  91 => '2001:4860:4801:2061::/64',
  92 => '2001:4860:4801:2062::/64',
  93 => '2001:4860:4801:2063::/64',
  94 => '2001:4860:4801:2064::/64',
  95 => '2001:4860:4801:2065::/64',
  96 => '2001:4860:4801:2066::/64',
  97 => '2001:4860:4801:2067::/64',
  98 => '2001:4860:4801:2068::/64',
  99 => '2001:4860:4801:2069::/64',
  100 => '2001:4860:4801:206a::/64',
  101 => '2001:4860:4801:206b::/64',
  102 => '2001:4860:4801:206c::/64',
  103 => '2001:4860:4801:206d::/64',
  104 => '2001:4860:4801:206e::/64',
  105 => '2001:4860:4801:206f::/64',
  106 => '2001:4860:4801:2070::/64',
  107 => '2001:4860:4801:2071::/64',
  108 => '2001:4860:4801:2072::/64',
  109 => '2001:4860:4801:2073::/64',
  110 => '2001:4860:4801:2074::/64',
  111 => '2001:4860:4801:2075::/64',
  112 => '2001:4860:4801:2076::/64',
  113 => '2001:4860:4801:2077::/64',
  114 => '2001:4860:4801:2078::/64',
  115 => '2001:4860:4801:2079::/64',
  116 => '2001:4860:4801:207a::/64',
  117 => '2001:4860:4801:207b::/64',
  118 => '2001:4860:4801:207c::/64',
  119 => '2001:4860:4801:207d::/64',
  120 => '2001:4860:4801:207e::/64',
  121 => '2001:4860:4801:207f::/64',
  122 => '2001:4860:4801:2080::/64',
  123 => '2001:4860:4801:2081::/64',
  124 => '2001:4860:4801:2082::/64',
  125 => '2001:4860:4801:2083::/64',
  126 => '2001:4860:4801:2084::/64',
  127 => '2001:4860:4801:2085::/64',
  128 => '2001:4860:4801:2086::/64',
  129 => '2001:4860:4801:2087::/64',
  130 => '2001:4860:4801:2088::/64',
  131 => '2001:4860:4801:2090::/64',
  132 => '2001:4860:4801:2091::/64',
  133 => '2001:4860:4801:2092::/64',
  134 => '2001:4860:4801:2093::/64',
  135 => '2001:4860:4801:2094::/64',
  136 => '2001:4860:4801:2095::/64',
  137 => '2001:4860:4801:2096::/64',
  138 => '2001:4860:4801:2097::/64',
  139 => '2001:4860:4801:20a0::/64',
  140 => '2001:4860:4801:20a1::/64',
  141 => '2001:4860:4801:20a2::/64',
  142 => '2001:4860:4801:20a3::/64',
  143 => '2001:4860:4801:20a4::/64',
  144 => '2001:4860:4801:20a5::/64',
  145 => '2001:4860:4801:20a6::/64',
  146 => '2001:4860:4801:20b0::/64',
  147 => '2001:4860:4801:20b1::/64',
  148 => '2001:4860:4801:20b2::/64',
  149 => '2001:4860:4801:20b3::/64',
  150 => '2001:4860:4801:20b4::/64',
  151 => '2001:4860:4801:20b5::/64',
  152 => '2001:4860:4801:20b6::/64',
  153 => '209.85.238.0/27',
  154 => '209.85.238.128/27',
  155 => '209.85.238.160/27',
  156 => '209.85.238.192/27',
  157 => '209.85.238.224/27',
  158 => '209.85.238.32/27',
  159 => '209.85.238.64/27',
  160 => '209.85.238.96/27',
  161 => '66.249.87.0/27',
  162 => '66.249.87.128/27',
  163 => '66.249.87.160/27',
  164 => '66.249.87.192/27',
  165 => '66.249.87.224/27',
  166 => '66.249.87.32/27',
  167 => '66.249.87.64/27',
  168 => '66.249.87.96/27',
  169 => '66.249.89.0/27',
  170 => '66.249.89.128/27',
  171 => '66.249.89.160/27',
  172 => '66.249.89.192/27',
  173 => '66.249.89.224/27',
  174 => '66.249.89.32/27',
  175 => '66.249.89.64/27',
  176 => '66.249.89.96/27',
  177 => '66.249.90.0/27',
  178 => '66.249.90.128/27',
  179 => '66.249.90.160/27',
  180 => '66.249.90.192/27',
  181 => '66.249.90.224/27',
  182 => '66.249.90.32/27',
  183 => '66.249.90.64/27',
  184 => '66.249.90.96/27',
  185 => '66.249.91.0/27',
  186 => '66.249.91.128/27',
  187 => '66.249.91.160/27',
  188 => '66.249.91.192/27',
  189 => '66.249.91.224/27',
  190 => '66.249.91.32/27',
  191 => '66.249.91.64/27',
  192 => '66.249.91.96/27',
  193 => '66.249.92.0/27',
  194 => '66.249.92.128/27',
  195 => '66.249.92.160/27',
  196 => '66.249.92.192/27',
  197 => '66.249.92.224/27',
  198 => '66.249.92.32/27',
  199 => '66.249.92.64/27',
  200 => '66.249.92.96/27',
  201 => '72.14.199.0/27',
  202 => '72.14.199.128/27',
  203 => '72.14.199.160/27',
  204 => '72.14.199.192/27',
  205 => '72.14.199.224/27',
  206 => '72.14.199.32/27',
  207 => '72.14.199.64/27',
  208 => '72.14.199.96/27',
  209 => '74.125.148.0/27',
  210 => '74.125.148.128/27',
  211 => '74.125.148.160/27',
  212 => '74.125.148.192/27',
  213 => '74.125.148.224/27',
  214 => '74.125.148.32/27',
  215 => '74.125.148.64/27',
  216 => '74.125.148.96/27',
  217 => '74.125.149.0/27',
  218 => '74.125.149.128/27',
  219 => '74.125.149.160/27',
  220 => '74.125.149.192/27',
  221 => '74.125.149.224/27',
  222 => '74.125.149.32/27',
  223 => '74.125.149.64/27',
  224 => '74.125.149.96/27',
  225 => '74.125.150.0/27',
  226 => '74.125.150.128/27',
  227 => '74.125.150.160/27',
  228 => '74.125.150.192/27',
  229 => '74.125.150.32/27',
  230 => '74.125.150.64/27',
  231 => '74.125.150.96/27',
  232 => '74.125.151.0/27',
  233 => '74.125.151.128/27',
  234 => '74.125.151.160/27',
  235 => '74.125.151.192/27',
  236 => '74.125.151.224/27',
  237 => '74.125.151.32/27',
  238 => '74.125.151.64/27',
  239 => '74.125.151.96/27',
  240 => '74.125.216.0/27',
  241 => '74.125.216.128/27',
  242 => '74.125.216.160/27',
  243 => '74.125.216.192/27',
  244 => '74.125.216.224/27',
  245 => '74.125.216.32/27',
  246 => '74.125.216.64/27',
  247 => '74.125.216.96/27',
  248 => '74.125.217.0/27',
  249 => '74.125.217.128/27',
  250 => '74.125.217.160/27',
  251 => '74.125.217.192/27',
  252 => '74.125.217.224/27',
  253 => '74.125.217.32/27',
  254 => '74.125.217.64/27',
  255 => '74.125.217.96/27',
  256 => '74.125.218.0/27',
  257 => '74.125.218.128/27',
  258 => '74.125.218.160/27',
  259 => '74.125.218.192/27',
  260 => '74.125.218.224/27',
  261 => '74.125.218.32/27',
  262 => '74.125.218.64/27',
  263 => '74.125.218.96/27',
  264 => '74.125.219.0/27',
  265 => '74.125.219.128/27',
  266 => '74.125.219.160/27',
  267 => '74.125.219.192/27',
  268 => '74.125.219.224/27',
  269 => '74.125.219.32/27',
  270 => '74.125.219.64/27',
  271 => '74.125.219.96/27',
);

    /**
     * Resolves the runtime storage file path for updated Google Special Crawlers CIDRs.
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

        return $dir . '/google_special_crawlers.json';
    }

    /**
     * Resolves the bundled snapshot file path.
     */
    public static function resolveBundledFile(): string
    {
        return __DIR__ . '/google_special_crawlers.json';
    }

    /**
     * Returns the active list of Google Special Crawlers CIDR ranges.
     * Never performs blocking network calls during visitor inspection.
     *
     * @return array<int, string>
     */
    public static function getCidrs(): array
    {
        if (self::$cachedCidrs !== null) {
            return self::$cachedCidrs;
        }

        // 1. Try runtime updated file
        $runtimeFile = self::resolveRuntimeFile();
        if (file_exists($runtimeFile)) {
            $content = @file_get_contents($runtimeFile);
            if ($content !== false) {
                $cidrs = self::parseJson($content);
                if (!empty($cidrs)) {
                    self::$cachedCidrs = $cidrs;
                    return self::$cachedCidrs;
                }
            }
        }

        // 2. Try bundled JSON file
        $bundledFile = self::resolveBundledFile();
        if (file_exists($bundledFile)) {
            $content = @file_get_contents($bundledFile);
            if ($content !== false) {
                $cidrs = self::parseJson($content);
                if (!empty($cidrs)) {
                    self::$cachedCidrs = $cidrs;
                    return self::$cachedCidrs;
                }
            }
        }

        // 3. Fallback to PHP constant
        self::$cachedCidrs = self::CIDRS;
        return self::$cachedCidrs;
    }

    /**
     * Checks whether an IP belongs to Google Special Crawlers subnets.
     */
    public static function match(string $ip): bool
    {
        return IpMatcher::match($ip, self::getCidrs());
    }

    /**
     * Updates the Google Special Crawlers snapshot from official Google endpoint.
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
                'header' => "User-Agent: Yii2-AntiBot-Updater/1.6 (Google Ranges Sync; +https://github.com/larikmc/yii2-antibot)\r\nAccept: application/json\r\n",
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
                'count' => count(self::getCidrs()),
                'message' => 'Failed to reach Google endpoint. Last valid snapshot preserved.',
            ];
        }

        $cidrs = self::parseJson($content);
        if (empty($cidrs)) {
            return [
                'success' => false,
                'updated' => false,
                'count' => count(self::getCidrs()),
                'message' => 'Google response contained no valid prefix entries. Last valid snapshot preserved.',
            ];
        }

        // Write safely to runtime directory
        $runtimeFile = self::resolveRuntimeFile();
        $tmpFile = $runtimeFile . '.' . uniqid('tmp_', true);
        if (@file_put_contents($tmpFile, $content, LOCK_EX) !== false) {
            @rename($tmpFile, $runtimeFile);
            self::$cachedCidrs = $cidrs;

            return [
                'success' => true,
                'updated' => true,
                'count' => count($cidrs),
                'message' => sprintf('Successfully updated %d Google Special Crawlers subnets.', count($cidrs)),
            ];
        }

        return [
            'success' => false,
            'updated' => false,
            'count' => count(self::getCidrs()),
            'message' => 'Could not write runtime cache file. Last valid snapshot preserved.',
        ];
    }

    /**
     * Parses JSON response from Google special-crawlers endpoint.
     *
     * @param string $jsonString
     * @return array<int, string>
     */
    private static function parseJson(string $jsonString): array
    {
        try {
            $data = Json::decode($jsonString);
            if (!is_array($data) || !isset($data['prefixes']) || !is_array($data['prefixes'])) {
                return [];
            }

            $cidrs = [];
            foreach ($data['prefixes'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $cidr = $item['ipv4Prefix'] ?? ($item['ipv6Prefix'] ?? null);
                if (is_string($cidr) && str_contains($cidr, '/')) {
                    $cidrs[] = $cidr;
                }
            }

            return array_values(array_unique($cidrs));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Resets in-memory cached state (useful in tests).
     */
    public static function resetCache(): void
    {
        self::$cachedCidrs = null;
    }
}
