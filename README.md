# cakk-transport

`cakk-transport` - transport-only HTTP API.

Сервер знает только:

- agents;
- `agent.zone`;
- routes;
- `route.zone`;
- тип route `group|dialog`;
- lanes;
- общий default lane для каждого route;
- жёсткие поля профиля и оформления:
  - `route.avatar_url`
  - `lane.icon`
- права agent внутри route;
- права agent внутри route;
- членство;
- доставку opaque payload.

## Zone

`zone` - это UUID-идентификатор изоляционного домена.

- agent передаёт свою `zone` при регистрации;
- route наследует `zone` автора при создании;
- agent может иметь subscription только внутри своей `zone`;
- agent или route нельзя найти по id через transport, если запрашивающий agent находится в другой `zone`.

Сервер не знает:

- типы сообщений;
- текст это или нет;
- плагины;
- шифрование;
- превью ссылок;
- вложения;
- reply/mentions/markup.

## Auth

Все приватные endpoint'ы используют Bearer session token:

- `Authorization: Bearer <session_token>`
- session token выдаётся при `register` и `login`
- session живёт `180 days` и продлевается на каждый успешный authenticated запрос
- пароль нужен только для `login`

## Payload contract

При отправке payload клиент передаёт raw bytes:

- `POST /lanes/{lane_id}/payloads`
- `Content-Type: application/octet-stream`
- body = payload bytes

При чтении transport возвращает только metadata в JSON, а сами байты — только через `GET /payloads/{payload_id}/body`.

## Endpoints

### Public

- `GET /health`
- `POST /register`
- `POST /login`

### Authenticated

- `GET /me`
- `GET /me/meta`
- `POST /me/meta`
- `PUT /me/meta`
- `PATCH /me/meta`
- `DELETE /me/meta`
- `GET /updates?after_id=0&limit=100`
- `GET /sessions`
- `DELETE /sessions/{session_id}`
- `GET /routes`
- `POST /routes`
- `GET /routes/{route_id}`
- `GET /routes/{route_id}/meta`
- `POST /routes/{route_id}/meta`
- `PUT /routes/{route_id}/meta`
- `PATCH /routes/{route_id}/meta`
- `DELETE /routes/{route_id}/meta`
- `DELETE /routes/{route_id}`
- `GET /routes/{route_id}/subscriptions`
- `POST /routes/{route_id}/subscriptions`
- `GET /routes/{route_id}/lanes`
- `POST /routes/{route_id}/lanes`
- `GET /lanes/{lane_id}`
- `GET /lanes/{lane_id}/meta`
- `POST /lanes/{lane_id}/meta`
- `PUT /lanes/{lane_id}/meta`
- `PATCH /lanes/{lane_id}/meta`
- `DELETE /lanes/{lane_id}/meta`
- `DELETE /lanes/{lane_id}`
- `POST /lanes/{lane_id}/clear`
- `GET /lanes/{lane_id}/payloads?after_id=0&limit=100`
- `POST /lanes/{lane_id}/payloads`
- `DELETE /payloads/{payload_id}`
- `GET /payloads/{payload_id}/body`
- `GET /payloads/{payload_id}/meta`
- `POST /payloads/{payload_id}/meta`
- `GET /payload-meta/{payload_meta_id}`
- `PUT /payload-meta/{payload_meta_id}`
- `PATCH /payload-meta/{payload_meta_id}`
- `DELETE /payload-meta/{payload_meta_id}`

## Roles

- `owner` - любые действия
- `admin` - любые действия внутри route, кроме назначения других `admin`
- `editor` - публикация payload и удаление любого payload в route
- `publisher` - публикация payload и удаление только своих payload
- `guest` - только чтение route/lane/payload, но с возможностью писать собственный `payload_meta`

## Destructive actions

- `clear lane` может agent с максимальной ролью в route
- `delete lane` может agent с максимальной ролью в route
- `delete route` может agent с максимальной ролью в route
- если максимальная роль есть у нескольких agents, право есть у каждого
- `default lane` удалить нельзя, но очищать можно

## Local setup

1. Создать `etc/config.php` на основе `etc/config.php.example`.
2. Убедиться, что в PHP включен `pdo_mysql`.
3. Выполнить `composer install`.
4. Выполнить `php bin/init-schema.php`.
5. Запустить `php -S 127.0.0.1:8080 index.php`.

## Example

Регистрация:

```bash
curl -X POST \
  -H 'Content-Type: application/json' \
  -d '{"zone":"11111111-1111-4111-8111-111111111111","device_label":"desktop"}' \
  http://127.0.0.1:8080/register
```

Логин:

```bash
curl -X POST \
  -H 'Content-Type: application/json' \
  -d '{"agent_id":"42","password":"secret","device_label":"phone"}' \
  http://127.0.0.1:8080/login
```

Создание route:

```bash
curl -H "Authorization: Bearer $SESSION_TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"type":"group","title":"Main route","avatar_url":"https://cdn.example/route.png"}' \
  http://127.0.0.1:8080/routes
```

Создание dialog route:

```bash
curl -H "Authorization: Bearer $SESSION_TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"type":"dialog","agent_ids":["84"]}' \
  http://127.0.0.1:8080/routes
```

Отправка payload:

```bash
curl -H "Authorization: Bearer $SESSION_TOKEN" \
  -H 'Content-Type: application/octet-stream' \
  --data-binary 'Hello' \
  http://127.0.0.1:8080/lanes/12/payloads
```

Создание authored payload meta:

```bash
curl -H "Authorization: Bearer $SESSION_TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"meta":{"reaction":"like"}}' \
  http://127.0.0.1:8080/payloads/42/meta
```

Чтение raw payload body:

```bash
curl -H "Authorization: Bearer $SESSION_TOKEN" \
  http://127.0.0.1:8080/payloads/42/body
```
