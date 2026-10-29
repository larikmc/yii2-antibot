<?php

declare(strict_types=1);

namespace larikmc\antibot\services\pow;

use larikmc\antibot\services\TokenService;
use Yii;
use yii\caching\CacheInterface;

/**
 * Local Proof-of-Work (PoW) computation challenge service.
 *
 * Provides 100% on-premise, zero-cloud client verification challenges.
 * Forces automated bots and scrapers to expend significant CPU cycles to solve
 * a cryptographic SHA-256 partial hash collision before gaining access.
 *
 * Legitimate human browsers solve the challenge smoothly in 50-800ms using Web Crypto API.
 * Verified search engine bots are never subjected to PoW challenges.
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class PowService
{
    public const DIFFICULTY_LIGHT = 'light';
    public const DIFFICULTY_MEDIUM = 'medium';
    public const DIFFICULTY_STRONG = 'strong';

    public const TARGET_LIGHT = '000';      // ~4,096 iterations (50-100ms)
    public const TARGET_MEDIUM = '0000';    // ~65,536 iterations (300-800ms)
    public const TARGET_STRONG = '00000';   // ~1,048,576 iterations (2-4s)

    public const MAX_AGE_SECONDS = 120;     // Seed valid for 2 minutes

    /**
     * Resolves the target hex prefix for a given difficulty level.
     */
    public static function resolveTarget(string $difficulty): string
    {
        return match ($difficulty) {
            self::DIFFICULTY_LIGHT => self::TARGET_LIGHT,
            self::DIFFICULTY_STRONG => self::TARGET_STRONG,
            default => self::TARGET_MEDIUM,
        };
    }

    /**
     * Generates a signed Proof-of-Work challenge seed for client evaluation.
     *
     * @param string $ip Client IP.
     * @param string $secretKey Secret signing key.
     * @param string $difficulty Difficulty level ('light', 'medium', 'strong').
     * @return array{seed: string, target: string, difficulty: string, timestamp: int}
     */
    public static function generateChallenge(string $ip, string $secretKey, string $difficulty = self::DIFFICULTY_MEDIUM): array
    {
        $time = time();
        $target = self::resolveTarget($difficulty);
        $random = bin2hex(random_bytes(8));
        $ipPrefix = TokenService::extractIpPrefix($ip);

        // seed payload: timestamp|ipPrefix|difficulty|random
        $payload = sprintf('%d|%s|%s|%s', $time, $ipPrefix, $difficulty, $random);
        $signature = substr(hash_hmac('sha256', $payload, $secretKey), 0, 32);
        $packed = base64_encode($payload . '|' . $signature);

        return [
            'seed' => $packed,
            'target' => $target,
            'difficulty' => $difficulty,
            'timestamp' => $time,
        ];
    }

    /**
     * Validates a submitted Proof-of-Work solution.
     *
     * @param string $packedSeed The packed seed string sent to the client.
     * @param string $nonce The nonce discovered by the client.
     * @param string $clientIp Current client IP address.
     * @param string $secretKey Secret signing key.
     * @param CacheInterface|null $cache Optional cache for replay attack prevention.
     * @return array{valid: bool, error: string|null}
     */
    public static function validateSolution(
        string $packedSeed,
        string $nonce,
        string $clientIp,
        string $secretKey,
        ?CacheInterface $cache = null
    ): array {
        if (trim($packedSeed) === '' || trim($nonce) === '') {
            return ['valid' => false, 'error' => 'Missing seed or nonce'];
        }

        $decoded = base64_decode($packedSeed, true);
        if ($decoded === false) {
            return ['valid' => false, 'error' => 'Invalid seed encoding'];
        }

        $parts = explode('|', $decoded);
        if (count($parts) !== 5) {
            return ['valid' => false, 'error' => 'Malformed challenge seed structure'];
        }

        [$timeStr, $ipPrefix, $difficulty, $random, $signature] = $parts;
        $timestamp = (int) $timeStr;

        // 1. Check expiration (max 120 seconds)
        if (time() - $timestamp > self::MAX_AGE_SECONDS || $timestamp > time() + 10) {
            return ['valid' => false, 'error' => 'Proof-of-work challenge expired'];
        }

        // 2. Check IP subnet binding
        $currentIpPrefix = TokenService::extractIpPrefix($clientIp);
        if ($ipPrefix !== $currentIpPrefix) {
            return ['valid' => false, 'error' => 'IP address mismatch'];
        }

        // 3. Verify cryptographic HMAC signature
        $payload = sprintf('%d|%s|%s|%s', $timestamp, $ipPrefix, $difficulty, $random);
        $expectedSignature = substr(hash_hmac('sha256', $payload, $secretKey), 0, 32);
        if (!hash_equals($expectedSignature, $signature)) {
            return ['valid' => false, 'error' => 'Invalid challenge signature'];
        }

        // 4. Verify the mathematical hash collision FIRST
        // An incorrect nonce must NEVER invalidate or consume a legitimate challenge seed!
        $targetPrefix = self::resolveTarget($difficulty);
        $computedHash = hash('sha256', $packedSeed . '|' . $nonce);

        if (!str_starts_with($computedHash, $targetPrefix)) {
            return ['valid' => false, 'error' => 'Incorrect nonce solution'];
        }

        // 5. Mark challenge as used and protect against replay attacks ONLY upon successful solution
        if ($cache !== null) {
            $replayKey = 'antibot_pow_used_' . md5($packedSeed);
            if (method_exists($cache, 'add')) {
                // Atomic check-and-set prevents concurrent parallel race-condition solves
                $added = $cache->add($replayKey, 1, self::MAX_AGE_SECONDS);
                if (!$added) {
                    return ['valid' => false, 'error' => 'Replay attack detected: challenge already used'];
                }
            } else {
                if ($cache->get($replayKey) !== false) {
                    return ['valid' => false, 'error' => 'Replay attack detected: challenge already used'];
                }
                $cache->set($replayKey, 1, self::MAX_AGE_SECONDS);
            }
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * Renders a standalone, stylish HTML challenge page with client-side Web Crypto solver.
     *
     * @param string $packedSeed Packed seed.
     * @param string $target Required target hex prefix.
     * @param string $returnUrl Target URL to redirect upon solving.
     * @param string|null $reason Optional notice to display.
     * @return string Standalone HTML page.
     */
    public static function renderChallengeHtml(
        string $packedSeed,
        string $target,
        string $returnUrl,
        ?string $reason = null
    ): string {
        $encodedSeed = htmlspecialchars($packedSeed, ENT_QUOTES, 'UTF-8');
        $encodedTarget = htmlspecialchars($target, ENT_QUOTES, 'UTF-8');
        $encodedReturnUrl = htmlspecialchars($returnUrl, ENT_QUOTES, 'UTF-8');
        $encodedReason = htmlspecialchars($reason ?? 'Выполняется автоматическая проверка безопасности браузера...', ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Проверка безопасности браузера | AntiBot</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 0;
            min-height: 100vh;
            background: #0f172a;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .challenge-card {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 16px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            max-width: 480px;
            width: 90%;
            padding: 36px 32px;
            text-align: center;
        }
        .shield-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 20px;
            background: linear-gradient(135deg, #3b82f6, #06b6d4);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 0 20px rgba(59, 130, 246, 0.4);
            font-size: 30px;
        }
        h1 {
            font-size: 22px;
            margin: 0 0 10px;
            font-weight: 700;
            color: #ffffff;
        }
        p {
            font-size: 14px;
            color: #94a3b8;
            line-height: 1.5;
            margin: 0 0 24px;
        }
        .progress-container {
            background: #0f172a;
            border-radius: 10px;
            height: 8px;
            overflow: hidden;
            position: relative;
            margin-bottom: 16px;
        }
        .progress-bar {
            width: 0%;
            height: 100%;
            background: linear-gradient(90deg, #3b82f6, #10b981);
            border-radius: 10px;
            transition: width 0.2s ease;
            box-shadow: 0 0 10px rgba(16, 185, 129, 0.5);
        }
        .status-text {
            font-size: 13px;
            color: #38bdf8;
            font-family: monospace;
            min-height: 20px;
        }
        .noscript-warn {
            color: #ef4444;
            font-size: 13px;
            margin-top: 15px;
        }
    </style>
</head>
<body>
    <div class="challenge-card">
        <div class="shield-icon">🛡️</div>
        <h1>Проверка безопасности</h1>
        <p>{$encodedReason}</p>

        <div class="progress-container">
            <div id="progressBar" class="progress-bar"></div>
        </div>
        <div id="statusText" class="status-text">Инициализация вычислений...</div>

        <noscript>
            <div class="noscript-warn">
                Для прохождения проверки необходимо включить JavaScript в браузере.
            </div>
        </noscript>
    </div>

    <form id="solveForm" method="post" action="{$encodedReturnUrl}" style="display:none;">
        <input type="hidden" name="antibot_pow_solve" value="1">
        <input type="hidden" name="seed" value="{$encodedSeed}">
        <input type="hidden" name="nonce" id="nonceInput" value="">
    </form>

    <script>
    (async function() {
        var seed = "{$encodedSeed}";
        var target = "{$encodedTarget}";
        var bar = document.getElementById('progressBar');
        var status = document.getElementById('statusText');
        var form = document.getElementById('solveForm');
        var nonceInput = document.getElementById('nonceInput');

        if (!window.crypto || !window.crypto.subtle) {
            status.textContent = 'Web Crypto не поддерживается. Обновите браузер.';
            return;
        }

        var encoder = new TextEncoder();
        var nonce = 0;
        var batchSize = 1000;
        var solved = false;
        var foundNonce = '';

        status.textContent = 'Вычисление криптографического решения...';
        bar.style.width = '20%';

        async function computeBatch() {
            for (var i = 0; i < batchSize; i++) {
                nonce++;
                var testStr = seed + '|' + nonce;
                var data = encoder.encode(testStr);
                var hashBuffer = await crypto.subtle.digest('SHA-256', data);
                var hashArray = Array.from(new Uint8Array(hashBuffer));
                var hashHex = hashArray.map(function(b) {
                    return b.toString(16).padStart(2, '0');
                }).join('');

                if (hashHex.indexOf(target) === 0) {
                    solved = true;
                    foundNonce = String(nonce);
                    break;
                }
            }

            if (solved) {
                bar.style.width = '100%';
                status.textContent = 'Проверка успешно пройдена! Перенаправление...';
                nonceInput.value = foundNonce;
                setTimeout(function() {
                    form.submit();
                }, 150);
            } else {
                var progress = Math.min(85, 20 + Math.floor((nonce / 65536) * 65));
                bar.style.width = progress + '%';
                setTimeout(computeBatch, 0);
            }
        }

        setTimeout(computeBatch, 50);
    })();
    </script>
</body>
</html>
HTML;
    }
}
