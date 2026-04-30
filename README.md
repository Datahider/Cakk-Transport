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

## Production

Для production рекомендуется `nginx + php-fpm`, а не `php -S`.

Example nginx site config:
- [etc/nginx.conf.example](/home/web/Документы/cakk-transport/etc/nginx.conf.example)

## Production prerequisites

- PHP `8.3` with `pdo_mysql`
- MySQL/MariaDB
- `composer install --no-dev --prefer-dist`
- `nginx + php-fpm`

## Config

Runtime config lives in `etc/config.php`.

Required constants:
- `DB_HOST`
- `DB_USER`
- `DB_PASS`
- `DB_NAME`
- `DB_PREF`
- `DB_CHARSET`

Starter template:
- [etc/config.php.example](/home/web/Документы/cakk-transport/etc/config.php.example)

`etc/config.php` must not be committed.

## Deploy layout

Minimal expected layout on server:

```text
/srv/cakk-transport/
  index.php
  src/
  vendor/
  etc/config.php
  bin/
```

The sample nginx config assumes project root is served directly and all requests are routed to `index.php`.

## Deploy procedure

1. Upload project files to target directory.
2. Run `composer install --no-dev --prefer-dist`.
3. Create `etc/config.php`.
4. Point nginx root to project directory and enable the site using [etc/nginx.conf.example](/home/web/Документы/cakk-transport/etc/nginx.conf.example).
5. Ensure `Authorization` header is forwarded to PHP. The example config already does this through `fastcgi_param HTTP_AUTHORIZATION $http_authorization;`.
6. Reload `php-fpm` and `nginx`.
7. Run one schema bootstrap: `php bin/init-schema.php`.
8. Run acceptance or at least smoke checks against deployed host.

## Schema and DB privileges

Current DB layer performs schema initialization from runtime bootstrap as a property of the underlying library.

Current project stance:
- in current dev-team-level-prod this is acceptable;
- if runtime DB user lacks required DDL permissions, the application should fail fast instead of silently degrading;
- for a harder production split, schema management can later be moved to a separate release path.

This means:
- `php bin/init-schema.php` is the explicit bootstrap command;
- web requests still hit the same schema-check path during runtime;
- restricting DB privileges may intentionally turn startup/runtime into a visible failure.

## Smoke checks

All local or remote `curl` checks should use short timeouts.

Examples:

```bash
curl --max-time 3 http://127.0.0.1:8080/health
curl --max-time 5 \
  -H 'Content-Type: application/json' \
  -d '{"zone":"11111111-1111-4111-8111-111111111111","is_system":true}' \
  http://127.0.0.1:8080/register
```

Recommended post-deploy checks:
- `GET /health` returns `200` and `{"ok":true}`
- `POST /register` for a new zone creates first `system` agent
- `POST /login` returns `session_token`
- authenticated `GET /me` works through nginx, proving `Authorization` forwarding is correct

## Acceptance

Full acceptance run:

```bash
php bin/acceptance.php
```

## Rollback

Minimal rollback procedure:

1. Switch nginx/php-fpm back to previous release directory.
2. Restore previous `vendor/` set if dependency set changed.
3. Restore database from backup if rollback requires schema/data reversal.

This project currently has no dedicated migration ledger, so DB rollback must be treated as an operational decision, not as an automatic step.

## Logging and diagnostics

- `500` responses currently include internal `details` field in development-oriented flows.
- This is acceptable for current internal stage, but should be removed or gated before real public production exposure.
- For incident analysis, preserve nginx access/error logs and php-fpm logs.

## Dependency policy

Current project allows internal development dependencies that are also maintained by the same team, including `losthost/db`.

Current stance:
- for internal dev-team-level-prod this is acceptable;
- for public or harder production, package releases and stricter dependency versioning should be introduced.

## Regression policy

- main regression gate is `php bin/acceptance.php`
- run it after transport API changes and before deploys whenever possible
