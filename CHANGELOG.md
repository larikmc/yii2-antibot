# Changelog

All notable changes to `larikmc/yii2-antibot` will be documented in this file.

## [1.0.1] - 2026-10-29

### Improved
- Added proxy and CDN headers inspection (`CF-Connecting-IP`, `X-Real-IP`, `X-Forwarded-For`) with `$trustProxyHeaders`.
- Added shared multi-tier log storage resolution (`@common/runtime/logs/antibot_events.json`) for Yii2 Advanced template.
- Normalized view route resolution to prevent routing context conflicts in embedded modules.

## [1.0.0] - 2026-10-29

### Added
- Initial release of `larikmc/yii2-antibot`.
- Core `Antibot` bootstrap component with multi-layered inspection flow.
- Two-way Reverse DNS (PTR + Forward DNS) verification for Googlebot, YandexBot, Bingbot, Mail.ru.
- Spoofed / fake search engine bot detection and instant mitigation.
- AI crawlers and LLM scraper detection (GPTBot, ClaudeBot, Bytespider, CCBot, PerplexityBot).
- Commercial scrapers and SEO parsers detection (Ahrefs, Semrush, DotBot, MJ12bot).
- Automated CLI tools and HTTP libraries detection (cURL, Wget, Python-requests, Scrapy, Guzzle).
- Sliding window cache-backed Rate Limiter against HTTP flood and aggressive scraping.
- Cryptographically signed cookie tokens with IP subnet binding.
- Invisible form Honeypot widget with cryptographic timing validation.
- Client-side background verification and headless browser detection (`navigator.webdriver`).
- Dedicated visual admin dashboard (`Module`) with live traffic feed, KPI statistics, and IP lists.
- Persistent JSON configuration storage (`@common/config/antibot.json`).
