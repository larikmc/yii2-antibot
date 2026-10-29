# Yii2 Antibot (`larikmc/yii2-antibot`)

[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE.md)
[![PHP](https://img.shields.io/badge/PHP-8.0%20|%208.1%20|%208.2%20|%208.3%20|%208.4%20|%208.5-blue)](https://php.net)
[![Yii2](https://img.shields.io/badge/Yii2-2.0.x-orange.svg)](https://yiiframework.com)

**Высокопроизводительный автономный модуль антибот-защиты для Yii2 с визуальной панелью управления.**  
Обеспечивает комплексную защиту веб-сайта от парсеров, AI-краулеров, фейковых поисковиков, спам-ботов и HTTP-флуда со временем проверки **менее 0.5 мс**.

---

## ⚡ Главные преимущества

1. **Безопасность для SEO (Reverse DNS PTR)**:  
   Настоящие поисковые роботы (Яндекс, Google, Bing, Mail.ru) **никогда не блокируются**. Подлинность проверяется через двусторонний DNS-запрос (PTR + прямой lookup) с кэшированием на 24 часа.
2. **Отсечение фейковых поисковиков**:  
   Любые боты, отправляющие заголовок `User-Agent: YandexBot` или `Googlebot` с посторонних хостингов или прокси, мгновенно вычисляются и блокируются.
3. **Блокировка AI-краулеров**:  
   Защита контента от выкачивания нейросетями для обучения моделей: `GPTBot`, `ChatGPT-User`, `ClaudeBot`, `Bytespider` (TikTok), `CCBot`, `PerplexityBot`.
4. **Блокировка парсеров и CLI-инструментов**:  
   Отсечение `cURL`, `Wget`, `python-requests`, `aiohttp`, `Scrapy`, `GuzzleHttp`, `Go-http-client`, а также коммерческих агрегаторов (`AhrefsBot`, `SemrushBot`, `MJ12bot`).
5. **Бесшовный JS-челлендж (0 капч для людей)**:  
   Проверка признаков реального браузера (`navigator.webdriver`, плагины, экран, события) и выдача криптографически подписанной куки без раздражающих картинок и капч.
6. **Honeypot-защита форм**:  
   Встроенный виджет невидимых полей-ловушек и криптографический тайминг отправки формы для защиты обратной связи, заказов и комментариев.
7. **Rate Limiter (Защита от флуда)**:  
   Ограничение частоты запросов с одного IP (sliding window) для предотвращения сканирования и спама.
8. **Визуальная панель управления**:  
   Дашборд со сводкой трафика, живой лентой событий, топом заблокированных IP и управлением белыми/черными списками (включая CIDR-подсети).

---

## 📦 Установка

```bash
composer require larikmc/yii2-antibot
```

Или добавьте в `composer.json`:

```json
{
    "require": {
        "larikmc/yii2-antibot": "^1.0.0"
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
    ],
],
```

### Шаг 2. Подключите модуль админки (`backend/config/main.php`)

```php
'modules' => [
    'antibot' => [
        'class' => \larikmc\antibot\Module::class,
        'accessRoles' => ['@'], // доступ только для авторизованных администраторов
    ],
],
```

Добавьте пункт в меню админки (например, в `larikmc/yii2-admin`):

```php
[
    'icon' => 'shield',
    'label' => 'Антибот',
    'url' => ['/antibot'],
],
```

Панель управления будет доступна по адресу: `/admin/antibot`.

### Шаг 3. Подключите клиентский виджет (`frontend/views/layouts/main.php`)

В секцию `<head>` или перед `</body>`:

```php
<?= \larikmc\antibot\widgets\AntibotWidget::widget() ?>
```

Готово! Весь трафик автоматически фильтруется и защищается.

---

## 🍯 Защита форм от спама (Honeypot)

В представлении формы (например, обратной связи):

```php
<?php $form = ActiveForm::begin(); ?>

    <?= $form->field($model, 'name')->textInput() ?>
    <?= $form->field($model, 'email')->textInput() ?>
    <?= $form->field($model, 'message')->textarea() ?>

    <!-- Невидимый виджет защиты от спам-ботов -->
    <?= \larikmc\antibot\widgets\HoneypotWidget::widget() ?>

    <button type="submit" class="btn btn-primary">Отправить</button>

<?php ActiveForm::end(); ?>
```

В контроллере при обработке отправки:

```php
if ($this->request->isPost) {
    $check = \larikmc\antibot\widgets\HoneypotWidget::validate($this->request->post());
    if (!$check['valid']) {
        Yii::$app->session->setFlash('error', $check['error']);
        return $this->refresh();
    }

    if ($model->load($this->request->post()) && $model->save()) {
        // ...
    }
}
```

---

## 🎛️ Режимы работы (Modes)

1. **`monitoring` (Мониторинг)**:  
   Рекомендуется для первого запуска. Модуль анализирует трафик и пишет в лог подозрительных ботов, но не блокирует запросы. Позволяет оценить реальную картину трафика.
2. **`standard` (Стандартный)**:  
   Рекомендуемый режим. Блокирует явных ботов, AI-краулеры, поддельные поисковики и флудеров. Живые пользователи заходят на сайт мгновенно без каких-либо экранов проверки.
3. **`strict` (Строгий)**:  
   При первом посещении сайта каждый неизвестный клиент проходит быструю фоновую проверку браузера (JS-челлендж за 0.5 сек). Парсеры без JavaScript отсекаются на 100%.

---

## ⚙️ Справочник параметров конфигурации

| Параметр | Тип | По умолчанию | Описание |
|---|---|---|---|
| `enabled` | `bool` | `true` | Включение / выключение модуля |
| `mode` | `string` | `'standard'` | Режим: `'monitoring'`, `'standard'`, `'strict'` |
| `blockAiCrawlers` | `bool` | `true` | Блокировка GPTBot, ClaudeBot, Bytespider и др. |
| `blockCommercialScrapers` | `bool` | `true` | Блокировка Ahrefs, Semrush, MJ12bot и др. |
| `blockCliAndLibraries` | `bool` | `true` | Блокировка cURL, Wget, Python, Scrapy, Guzzle |
| `verifySearchBots` | `bool` | `true` | Проверка подлинности поисковиков (Reverse DNS) |
| `blockFakeSearchBots` | `bool` | `true` | Мгновенная блокировка фейковых поисковых ботов |
| `rateLimitEnabled` | `bool` | `true` | Ограничение частоты запросов |
| `rateLimitMax` | `int` | `60` | Максимальное число запросов за интервал |
| `rateLimitWindow` | `int` | `60` | Интервал окна в секундах |
| `whitelist` | `array` | `['127.0.0.1', '::1']` | Доверенные IP или подсети (CIDR: `1.2.3.0/24`) |
| `blacklist` | `array` | `[]` | Заблокированные IP или подсети |
| `customBlockMessage` | `string` | `'Access Denied...'` | Текст сообщения на 403 экране |
| `storageFile` | `string\|null` | `null` | Путь к файлу настроек (по умолч. `@common/config/antibot.json`) |

---

## 📄 Лицензия

MIT License. Автор: [LarikMC](https://64web.ru)
