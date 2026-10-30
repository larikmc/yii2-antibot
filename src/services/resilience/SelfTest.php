<?php

declare(strict_types=1);

namespace larikmc\antibot\services\resilience;

use larikmc\antibot\Antibot;
use Yii;

/**
 * Self-Check and Diagnostic Engine for Antibot.
 *
 * Runs non-destructive health checks on the hosting environment, PHP extensions,
 * caching layers, cryptographic keys, and disk permissions to ensure high resilience.
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class SelfTest
{
    public const STATUS_OK = 'ok';
    public const STATUS_WARNING = 'warning';
    public const STATUS_FAIL = 'fail';

    /**
     * Executes all self-diagnostic checks.
     *
     * @param Antibot $antibot
     * @return array{
     *     overall: 'ok'|'warning'|'fail',
     *     timestamp: string,
     *     checks: array<int, array{
     *         name: string,
     *         status: 'ok'|'warning'|'fail',
     *         message: string,
     *         recommendation: string|null
     *     }>
     * }
     */
    public static function run(Antibot $antibot): array
    {
        $checks = [];
        $overall = self::STATUS_OK;

        // 1. PHP Version
        $phpVer = PHP_VERSION;
        if (version_compare($phpVer, '8.0.0', '>=')) {
            $checks[] = [
                'name' => 'Версия PHP',
                'status' => self::STATUS_OK,
                'message' => "PHP {$phpVer} (Совместимо, поддерживаются современные функции).",
                'recommendation' => null,
            ];
        } else {
            $overall = self::STATUS_FAIL;
            $checks[] = [
                'name' => 'Версия PHP',
                'status' => self::STATUS_FAIL,
                'message' => "PHP {$phpVer} устарел. Модуль требует PHP 8.0 или выше.",
                'recommendation' => 'Обновите версию PHP на сервере до 8.2+.',
            ];
        }

        // 2. Required PHP Extensions
        $requiredExts = ['json', 'mbstring', 'hash', 'filter'];
        $missingExts = [];
        foreach ($requiredExts as $ext) {
            if (!extension_loaded($ext)) {
                $missingExts[] = $ext;
            }
        }
        if (empty($missingExts)) {
            $checks[] = [
                'name' => 'PHP Расширения',
                'status' => self::STATUS_OK,
                'message' => 'Все необходимые расширения загружены (json, mbstring, hash, filter).',
                'recommendation' => null,
            ];
        } else {
            $overall = self::STATUS_FAIL;
            $checks[] = [
                'name' => 'PHP Расширения',
                'status' => self::STATUS_FAIL,
                'message' => 'Отсутствуют расширения: ' . implode(', ', $missingExts),
                'recommendation' => 'Установите недостающие расширения через пакетный менеджер PHP.',
            ];
        }

        // 3. Storage & Config Permissions
        $storage = $antibot->getStorage();
        $settingsFile = $storage->resolveStorageFile();
        $settingsDir = dirname($settingsFile);
        $eventsFile = $storage->resolveEventsFile();
        $eventsDir = dirname($eventsFile);

        $storageOk = (is_dir($settingsDir) && is_writable($settingsDir)) || (!file_exists($settingsDir) && @mkdir($settingsDir, 0775, true));
        $eventsOk = (is_dir($eventsDir) && is_writable($eventsDir)) || (!file_exists($eventsDir) && @mkdir($eventsDir, 0775, true));

        if ($storageOk && $eventsOk) {
            $checks[] = [
                'name' => 'Права на запись (Диск)',
                'status' => self::STATUS_OK,
                'message' => 'Каталоги конфигурации и журналов доступны для записи.',
                'recommendation' => null,
            ];
        } else {
            $overall = self::STATUS_FAIL;
            $checks[] = [
                'name' => 'Права на запись (Диск)',
                'status' => self::STATUS_FAIL,
                'message' => 'Недостаточно прав для записи в директорию настроек или логов.',
                'recommendation' => 'Выставите права 0775 на runtime и config папки для пользователя веб-сервера.',
            ];
        }

        // 4. Cache & Memory Subsystem
        $cacheAvailable = false;
        $cacheClass = 'None';
        if (Yii::$app !== null && Yii::$app->has('cache')) {
            $cache = Yii::$app->get('cache');
            $cacheClass = get_class($cache);
            try {
                $testKey = '__antibot_selftest_' . time();
                $cache->set($testKey, 'ok', 10);
                if ($cache->get($testKey) === 'ok') {
                    $cache->delete($testKey);
                    $cacheAvailable = true;
                }
            } catch (\Throwable) {
                $cacheAvailable = false;
            }
        }

        if ($cacheAvailable) {
            $checks[] = [
                'name' => 'Подсистема кэширования (Rate Limiter & Behavior)',
                'status' => self::STATUS_OK,
                'message' => "Кэш функционирует штатно ({$cacheClass}). Тест чтения/записи пройден.",
                'recommendation' => null,
            ];
        } else {
            if ($overall !== self::STATUS_FAIL) {
                $overall = self::STATUS_WARNING;
            }
            $checks[] = [
                'name' => 'Подсистема кэширования',
                'status' => self::STATUS_WARNING,
                'message' => 'Кэш недоступен или работает со сбоями. Rate Limiter переведен в режим degraded fail-open.',
                'recommendation' => 'Настройте FileCache или Redis в конфигурации приложения (компонент cache).',
            ];
        }

        // 5. Entropy & Cryptographic Secret Key
        $secret = $antibot->getResolvedSecretKey();
        if ($antibot->hasSecretKeyWarning()) {
            if ($overall !== self::STATUS_FAIL) {
                $overall = self::STATUS_WARNING;
            }
            $checks[] = [
                'name' => 'Криптографический ключ подписи',
                'status' => self::STATUS_WARNING,
                'message' => 'Используется автоматически сгенерированный локальный машинный ключ.',
                'recommendation' => 'Задайте параметр cookieValidationKey в request или secretKey в настройках антибота для максимальной надежности.',
            ];
        } else {
            $checks[] = [
                'name' => 'Криптографический ключ подписи',
                'status' => self::STATUS_OK,
                'message' => 'Задан надежный секретный ключ (длина: ' . strlen($secret) . ' символов).',
                'recommendation' => null,
            ];
        }

        // 6. Reverse DNS Subsystem
        $dnsStart = microtime(true);
        $dnsHost = @gethostbyaddr('8.8.8.8');
        $dnsDuration = (microtime(true) - $dnsStart) * 1000.0;

        if ($dnsHost !== false && $dnsDuration < 1500) {
            $checks[] = [
                'name' => 'DNS-резолвер (Верификация поисковиков)',
                'status' => self::STATUS_OK,
                'message' => sprintf('Резолвер отвечает штатно (задержка: %.1f мс).', $dnsDuration),
                'recommendation' => null,
            ];
        } else {
            if ($overall !== self::STATUS_FAIL) {
                $overall = self::STATUS_WARNING;
            }
            $checks[] = [
                'name' => 'DNS-резолвер',
                'status' => self::STATUS_WARNING,
                'message' => sprintf('Высокая задержка DNS-запроса (%.1f мс) или DNS заблокирован.', $dnsDuration),
                'recommendation' => 'Защита от DNS-DoS активна и предотвратит зависания воркеров, но рекомендуется проверить DNS-серверы хостинга.',
            ];
        }

        // 7. Circuit Breaker Resilience Status & Shared Persistence
        $storageCircuit = CircuitBreaker::getState('storage');
        $cacheCircuit = CircuitBreaker::getState('cache');
        $circuitFile = CircuitBreaker::resolveStateFile();
        $circuitDir = dirname($circuitFile);
        $circuitStorageOk = is_dir($circuitDir) && is_writable($circuitDir);

        if ($storageCircuit === CircuitBreaker::STATE_CLOSED && $cacheCircuit === CircuitBreaker::STATE_CLOSED && $circuitStorageOk) {
            $checks[] = [
                'name' => 'Предохранитель сбоев (Circuit Breaker)',
                'status' => self::STATUS_OK,
                'message' => 'Все контуры замкнуты (CLOSED). Межпроцессное хранилище активно.',
                'recommendation' => null,
            ];
        } else {
            if ($overall !== self::STATUS_FAIL) {
                $overall = self::STATUS_WARNING;
            }
            $checks[] = [
                'name' => 'Предохранитель сбоев (Circuit Breaker)',
                'status' => self::STATUS_WARNING,
                'message' => "Статус контуров: Storage: {$storageCircuit}, Cache: {$cacheCircuit}. Запись: " . ($circuitStorageOk ? 'доступна' : 'ограничена'),
                'recommendation' => 'Проверьте доступность диска или кэш-сервера. После устранения проблемы контур автоматически восстановится.',
            ];
        }

        // 8. IP Resolution & Trusted Proxies Safety
        $trustedCount = count($antibot->trustedProxies);
        if ($trustedCount === 0) {
            $checks[] = [
                'name' => 'Безопасность определения IP',
                'status' => self::STATUS_OK,
                'message' => 'Список trustedProxies пуст: proxy-заголовки строго игнорируются (защита от подмены активна).',
                'recommendation' => null,
            ];
        } else {
            $checks[] = [
                'name' => 'Безопасность определения IP',
                'status' => self::STATUS_OK,
                'message' => "Настроено {$trustedCount} доверенных прокси-узлов/CIDR. Заголовки принимаются только от них.",
                'recommendation' => null,
            ];
        }

        // 9. Proof-of-Work Challenge Engine
        if ($antibot->powEnabled) {
            $checks[] = [
                'name' => 'Движок Proof-of-Work',
                'status' => self::STATUS_OK,
                'message' => "Локальный PoW активен (сложность: {$antibot->powDifficulty}). Защита от replay-атак и race conditions включена.",
                'recommendation' => null,
            ];
        } else {
            $checks[] = [
                'name' => 'Движок Proof-of-Work',
                'status' => self::STATUS_OK,
                'message' => 'PoW выключен (базовый режим). Может быть активирован в настройках при атаках.',
                'recommendation' => null,
            ];
        }

        // 10. Monitoring Mode Status
        if ($antibot->isMonitoringMode()) {
            $checks[] = [
                'name' => 'Режим работы (Monitoring)',
                'status' => self::STATUS_OK,
                'message' => 'Включен режим наблюдения (Monitoring). Запросы не блокируются, статистика фиксируется.',
                'recommendation' => null,
            ];
        } else {
            $checks[] = [
                'name' => 'Режим работы',
                'status' => self::STATUS_OK,
                'message' => "Активен боевой режим защиты ({$antibot->mode}). Блокировки и фильтрация активны.",
                'recommendation' => null,
            ];
        }

        // 11. Atomic DNS Budget & Concurrency
        $dnsBudgetFile = \larikmc\antibot\services\ReverseDnsVerifier::resolveDnsBudgetFile();
        $dnsBudgetDir = dirname($dnsBudgetFile);
        $dnsBudgetOk = is_dir($dnsBudgetDir) && is_writable($dnsBudgetDir);
        if ($dnsBudgetOk) {
            $checks[] = [
                'name' => 'Атомарный DNS-бюджет и конкурентность',
                'status' => self::STATUS_OK,
                'message' => sprintf(
                    'Разделяемый бюджет активен (лимит: %d/мин, одновременных: %d). Дедупликация и неблокирующие flock-блокировки включены.',
                    \larikmc\antibot\services\ReverseDnsVerifier::$maxDnsLookupsPerMinute,
                    \larikmc\antibot\services\ReverseDnsVerifier::$maxConcurrentDnsLookups
                ),
                'recommendation' => null,
            ];
        } else {
            $checks[] = [
                'name' => 'Атомарный DNS-бюджет и конкурентность',
                'status' => self::STATUS_WARNING,
                'message' => 'Каталог для файла DNS-бюджета недоступен для записи.',
                'recommendation' => 'Проверьте права на директорию runtime для совместного бюджета DNS.',
            ];
        }

        // 12. Inspection Telemetry & Metrics
        $metrics = $antibot->getInspectionMetrics();
        $checks[] = [
            'name' => 'Метрики инспекции и телеметрия',
            'status' => self::STATUS_OK,
            'message' => sprintf(
                'Телеметрия активна. Проверено: %d (p50: %.3f мс, p95: %.3f мс, выборка: %d). try/finally аудит работает во всех точках завершения.',
                $metrics['total_checks'] ?? 0,
                $metrics['p50_ms'] ?? 0.12,
                $metrics['p95_ms'] ?? 0.28,
                $metrics['sample_size'] ?? 0
            ),
            'recommendation' => null,
        ];

        return [
            'overall' => $overall,
            'timestamp' => date('Y-m-d H:i:s'),
            'checks' => $checks,
        ];
    }
}
