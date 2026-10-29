# Yii2 Antibot (`larikmc/yii2-antibot`)

[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE.md)
[![Latest Version](https://img.shields.io/badge/release-v1.1.0-blue.svg)](https://github.com/larikmc/yii2-antibot/releases/tag/v1.1.0)
[![PHP](https://img.shields.io/badge/PHP-8.0%20|%208.1%20|%208.2%20|%208.3%20|%208.4%20|%208.5-blue)](https://php.net)
[![Yii2](https://img.shields.io/badge/Yii2-2.0.x-orange.svg)](https://yiiframework.com)

**Высокопроизводительный автономный модуль антибот-защиты для Yii2 с визуальной панелью управления.**  
Обеспечивает комплексную защиту веб-сайта от парсеров, AI-краулеров, фейковых поисковиков, спам-ботов и HTTP-флуда со временем проверки **менее 0.3 мс**.

---

## ⚡ Главные преимущества v1.1.0

1. **Безопасность для SEO (Reverse DNS PTR + Anti-DNS-DoS)**:  
   Настоящие поисковые роботы (Яндекс, Google, Bing, Mail.ru) **никогда не блокируются**. Подлинность проверяется через двусторонний DNS-запрос (PTR + прямой lookup) с кэшированием на 24 часа.  
   *Защита от DNS DoS:* встроен лимит одновременных DNS-запросов и дифференцированное кэширование (успех — 24ч, фейк — 15м, таймаут — 5м).
2. **Отсечение фейковых поисковиков**:  
   Любые боты, отправляющие заголовок `User-Agent: YandexBot` или `Googlebot` с посторонних хостингов или прокси, мгновенно вычисляются и блокируются. Ошибки DNS трактуются безопасно как `unknown` и не приводят к ложным блокировкам.
3. **Гранулярное управление ИИ-краулерами (AI Bots)**:  
   Четкое разделение на:
   - **Поисковые ИИ-боты:** `OAI-SearchBot`, `ChatGPT-User`, `PerplexityBot` (по умолчанию разрешены для сохранения поискового трафика).
   - **Скраперы для обучения:** `GPTBot`, `ClaudeBot`, `Bytespider` (TikTok), `CCBot`, `Google-Extended` (блокируются).
   - Индивидуальные политики для каждого бота: *Разрешить (Allow), Мониторить (Monitor), Ограничивать (Throttle), Блокировать (Block)*.
4. **Блокировка парсеров и CLI-инструментов**:  
   Отсечение `cURL`, `Wget`, `python-requests`, `aiohttp`, `Scrapy`, `GuzzleHttp`, `Go-http-client`, а также коммерческих агрегаторов (`AhrefsBot`, `SemrushBot`, `MJ12bot`).
5. **Безопасное определение IP (Trusted Proxies)**:  
   Защита от подмены IP: заголовки `CF-Connecting-IP`, `X-Forwarded-For` и `X-Real-IP` принимаются **только** если непосредственный адрес сервера входит в явный список `trustedProxies` (поддерживается CIDR).
6. **Адаптивный Rate Limiter (Защита от флуда)**:  
   - `CacheLimiter` (по умолчанию): неблокирующий dual-bucket алгоритм. Предотвращает исчерпание воркеров PHP-FPM под нагрузкой. Безопасно деградирует (fail-open) при сбое кеша.
   - `RedisLimiter`: атомарный sliding-window алгоритм на Lua-скриптах Redis.
7. **Безопасность для API и AJAX**:  
   Автоматическое распознавание AJAX, Fetch, REST API и вебхуков: при блокировке или ограничении частоты клиенты получают чистый JSON со статусом `403` или `429` (`Retry-After`), без HTML-страниц и капч.
8. **JSON Lines (`.jsonl`) логгирование**:  
   Атомарная append-only запись событий за 0.001 мс без постоянного чтения и перезаписи всего JSON-файла. Автоматическая ротация (5 МБ) и безопасная миграция со старого формата.
9. **Защищенная панель управления**:  
   Интеграция с RBAC (`antibot.manage`), ограничение ролей (по умолчанию `admin`), защита всех изменяющих действий через CSRF и метод POST.

---

## 📦 Установка

```bash
composer require larikmc/yii2-antibot
```

Или добавьте в `composer.json`:

```json
{
    "require": {
        "larikmc/yii2-antibot": "^1.1.0"
    }
}
```

---

## 🚀 Быстрый старт за 3 шага

### Шаг 1. Подключите компонент (`common/config/main.php`)

```php
'components' => [
    'antibot' => [
        'class' => \larikmc\antibot\Antibot::class,
        // Если сайт работает за Cloudflare или Nginx:
        // 'trustedProxies' => ['127.0.0.1', '173.245.48.0/20', '103.21.244.0/22'],
    ],
],
'bootstrap' => [
    'antibot',
],
```

### Шаг 2. Подключите модуль админки (`backend/config/main.php`)

```php
'modules' => [
    'antibot' => [
        'class' => \larikmc\antibot\Module::class,
        'accessRoles' => ['admin'],
        'accessPermission' => 'antibot.manage', // Опционально при использовании RBAC
    ],
],
```

Добавьте пункт меню в `larikmc/yii2-admin`:

```php
[
    'icon' => 'shield',
    'label' => 'Антибот',
    'url' => ['/antibot'],
],
```

### Шаг 3. Подключите виджет в разметку (`frontend/views/layouts/main.php`)

Внутри секции `<head>`:

```php
<?= \larikmc\antibot\widgets\AntibotWidget::widget() ?>
```

---

## 🛡️ Защита веб-форм (Honeypot)

Для защиты форм обратной связи, комментариев и оформления заказов:

```php
<?php $form = ActiveForm::begin(); ?>

    <?= \larikmc\antibot\widgets\HoneypotWidget::widget() ?>

    <?= $form->field($model, 'name') ?>
    <?= $form->field($model, 'email') ?>
    ...
<?php ActiveForm::end(); ?>
```

В контроллере:

```php
if (Yii::$app->request->isPost) {
    if (!\larikmc\antibot\widgets\HoneypotWidget::validateSubmission()) {
        throw new \yii\web\ForbiddenHttpException('Спам-активность отклонена.');
    }
}
```

---

## ⚙️ Тонкая настройка маршрутов (Action Filter)

Для ограничения частоты на критичных экшенах (авторизация, поиск):

```php
public function behaviors(): array
{
    return [
        'antibot' => [
            'class' => \larikmc\antibot\filters\AntibotFilter::class,
            'maxRequests' => 5,
            'window' => 60,
            'only' => ['login', 'signup'],
        ],
    ];
}
```

---

## 🌐 Рекомендуемая конфигурация Nginx (Раннее отсечение флуда)

Для максимальной производительности при массированном HTTP-флуде рекомендуется настроить лимит Nginx перед PHP-FPM:

```nginx
# В секции http:
limit_req_zone $binary_remote_addr zone=anti_flood:10m rate=30r/s;
limit_req_status 429;

# В секции server / location:
location / {
    limit_req zone=anti_flood burst=50 nodelay;
    try_files $uri $uri/ /index.php?$args;
}
```

---

## 📄 Лицензия

MIT License. Свободное использование в коммерческих и некоммерческих проектах.  
Автор: **LarikMC** ([64web.ru](https://64web.ru))
