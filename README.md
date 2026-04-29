# cakk-transport

`cakk-transport` — transport-only HTTP API.

Коротко:
- transport знает только `agent`, `route`, `lane`, `payload`, `meta`, `session`, `zone`
- payload передаётся raw bytes
- обычные клиенты читают состояние через state endpoints
- `system`-агент зоны читает raw `/updates`

Нормальная документация для frontend/API теперь лежит здесь:
- [API.md](/home/web/Документы/cakk-transport/API.md)

В `API.md` есть:
- актуальный REST reference по тому, что реально есть в коде
- формы request/response
- auth и object shapes
- current sync endpoints
- и отдельный раздел с **идеальным** lazy-updates контрактом, под который потом можно подтянуть реализацию

## Local setup

1. Создать `etc/config.php` на основе `etc/config.php.example`.
2. Убедиться, что в PHP включен `pdo_mysql`.
3. Выполнить `composer install`.
4. Выполнить `php bin/init-schema.php`.
5. Запустить `php -S 127.0.0.1:8080 index.php`.

## Acceptance

Полный приёмочный прогон:

```bash
php bin/acceptance.php
```
