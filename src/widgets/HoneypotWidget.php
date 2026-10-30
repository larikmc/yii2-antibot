<?php

declare(strict_types=1);

namespace larikmc\antibot\widgets;

use Yii;
use yii\base\Widget;
use yii\helpers\Html;

/**
 * Invisible Honeypot & Form Timing Protection Widget.
 *
 * Catches automated form-submitting spam bots without captchas.
 *
 * Usage in ActiveForm views:
 * ```php
 * <?= \larikmc\antibot\widgets\HoneypotWidget::widget() ?>
 * ```
 *
 * Validation in Controller / Model:
 * ```php
 * $check = \larikmc\antibot\widgets\HoneypotWidget::validate(Yii::$app->request->post());
 * if (!$check['valid']) {
 *     Yii::$app->session->setFlash('error', $check['error']);
 *     return $this->refresh();
 * }
 * ```
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class HoneypotWidget extends Widget
{
    public const DEFAULT_FIELD_NAME = 'antibot_hp_email_confirm';
    public const DEFAULT_TOKEN_NAME = 'antibot_hp_time_token';
    public const MIN_SUBMIT_SECONDS = 1.5;

    /**
     * @var string Name of the invisible honeypot trap field.
     */
    public string $fieldName = self::DEFAULT_FIELD_NAME;

    /**
     * @var string Name of the timing token field.
     */
    public string $tokenName = self::DEFAULT_TOKEN_NAME;

    /**
     * {@inheritdoc}
     */
    public function run(): string
    {
        $time = time();
        $secret = Yii::$app !== null ? (Yii::$app->request->cookieValidationKey ?: 'antibot_hp') : 'antibot_hp';
        $token = base64_encode(sprintf('%d|%s', $time, hash_hmac('sha256', (string) $time, $secret)));

        $trapInput = Html::textInput($this->fieldName, '', [
            'autocomplete' => 'off',
            'tabindex' => '-1',
            'aria-hidden' => 'true',
            'style' => 'position:absolute!important;left:-9999px!important;top:-9999px!important;opacity:0!important;height:0!important;width:0!important;z-index:-1!important;pointer-events:none!important;',
        ]);

        $tokenInput = Html::hiddenInput($this->tokenName, $token);

        return $trapInput . "\n" . $tokenInput;
    }

    /**
     * Validates whether submitted form data passes honeypot and timing checks.
     *
     * @param array<string, mixed> $postData Typically Yii::$app->request->post().
     * @param float $minSeconds Minimum seconds required to fill the form (default 1.5s).
     * @param string $fieldName Honeypot field name.
     * @param string $tokenName Timing token field name.
     * @return array{valid: bool, error: string|null}
     */
    public static function validate(
        array $postData,
        float $minSeconds = self::MIN_SUBMIT_SECONDS,
        string $fieldName = self::DEFAULT_FIELD_NAME,
        string $tokenName = self::DEFAULT_TOKEN_NAME
    ): array {
        // 1. Honeypot field must be completely empty
        if (!empty($postData[$fieldName])) {
            return [
                'valid' => false,
                'error' => 'Обнаружена автоматическая отправка формы (Honeypot trap).',
            ];
        }

        // 2. Validate timing token
        if (empty($postData[$tokenName])) {
            return [
                'valid' => false,
                'error' => 'Отсутствует защитный токен формы.',
            ];
        }

        $decoded = base64_decode((string) $postData[$tokenName], true);
        if ($decoded === false) {
            return [
                'valid' => false,
                'error' => 'Недействительный защитный токен.',
            ];
        }

        $parts = explode('|', $decoded);
        if (count($parts) !== 2) {
            return [
                'valid' => false,
                'error' => 'Поврежденный токен формы.',
            ];
        }

        [$timeStr, $signature] = $parts;
        $timestamp = (int) $timeStr;
        $secret = Yii::$app !== null ? (Yii::$app->request->cookieValidationKey ?: 'antibot_hp') : 'antibot_hp';

        if (!hash_equals(hash_hmac('sha256', $timeStr, $secret), $signature)) {
            return [
                'valid' => false,
                'error' => 'Недействительная подпись токена формы.',
            ];
        }

        $elapsed = time() - $timestamp;
        if ($elapsed < $minSeconds) {
            // Log automated form submission attempt
            self::logHoneypotViolation('Форма отправлена слишком быстро (' . $elapsed . 's)');
            return [
                'valid' => false,
                'error' => 'Форма отправлена слишком быстро. Пожалуйста, попробуйте еще раз.',
            ];
        }

        // Token shouldn't be older than 24 hours
        if ($elapsed > 86400) {
            return [
                'valid' => false,
                'error' => 'Срок действия формы истек. Обновите страницу и отправьте снова.',
            ];
        }

        return [
            'valid' => true,
            'error' => null,
        ];
    }

    /**
     * Inspects current request POST data using Honeypot rules.
     *
     * @return array{valid: bool, error: string|null}
     */
    public static function inspect(?\yii\web\Request $request = null): array
    {
        $req = $request ?? (Yii::$app !== null ? Yii::$app->request : null);
        if ($req === null || !$req->isPost) {
            return ['valid' => true, 'error' => null];
        }

        return self::validate($req->post());
    }

    /**
     * Quick helper to validate current POST submission for honeypot and timing violations.
     *
     * @return bool True if submission is clean; false if bot activity was detected.
     */
    public static function validateSubmission(): bool
    {
        return self::inspect()['valid'];
    }

    private static function logHoneypotViolation(string $reason): void
    {
        if (Yii::$app !== null && Yii::$app->has('antibot')) {
            /** @var \larikmc\antibot\Antibot $antibot */
            $antibot = Yii::$app->get('antibot');
            $url = '';
            try {
                $url = Yii::$app->request->getAbsoluteUrl();
            } catch (\Throwable) {
                $url = (string) ($_SERVER['REQUEST_URI'] ?? '/');
            }
            $antibot->getStorage()->logEvent([
                'ip' => $antibot->resolveClientIp(),
                'userAgent' => Yii::$app->request->getUserAgent() ?? '',
                'action' => 'honeypot_triggered',
                'reason' => $reason,
                'url' => $url,
            ]);
        }
    }
}
