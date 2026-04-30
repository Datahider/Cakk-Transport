# cakk-transport API

Актуальный REST API transport-ядра.

Этот файл описывает:
- то, что **есть в коде сейчас**;
- и отдельно идеальный контракт lazy-updates, под который потом можно подтянуть реализацию.

## Base

- Base URL: `http://127.0.0.1:8080`
- Все JSON-ответы имеют вид:
  - success: `{"ok":true,...}`
  - error: `{"ok":false,"error":"..."}`
- Исключение: `GET /payloads/{payload_id}/body` возвращает `application/octet-stream`
- Времена сериализуются как строки формата `Y-m-d H:i:s.u`

## End-state goals

Этот раздел фиксирует целевую модель transport API, даже если отдельные места ещё не доведены до неё в коде.

- `system`-агент является полным администратором своей `zone`.
- Для `system` transport не должен вводить дополнительных membership/role ограничений на zone-local операции.
- Если приложению нужна своя бизнес-политика доступа, инвайтов, форс-добавления, форс-удаления и других orchestration-решений, она реализуется через `system`, а не через ограничения transport-а на `system`.
- Transport surface должен стремиться к полному CRUD-покрытию по своим сущностям.
- Если операция кажется продуктово бессмысленной, это не повод не иметь endpoint; это повод вернуть явную ошибку на поддерживаемом endpoint-е.

## Auth

Публичные endpoint’ы:
- `GET /health`
- `POST /register`
- `POST /login`

Все остальные endpoint’ы требуют:
- `Authorization: Bearer <session_token>`

Session token:
- выдаётся при `register` и `login`
- живёт `180 days`
- продлевается на каждый запрос с валидным bearer session token

## Core objects

`agent`:
- `agent_id: int`
- `zone: string`
- `is_system: bool`
- `created_at: string`
- `updated_at: string`
- `revision: string`
- `meta?: object<string,string>`

`session`:
- `session_id: int`
- `device_label: ?string`
- `is_current: bool`
- `created_at: string`
- `updated_at: string`
- `last_seen_at: string`
- `expires_at: string`
- `revoked_at: ?string`

`route`:
- `route_id: int`
- `zone: string`
- `owner_agent_id: int`
- `default_lane_id: ?int`
- `created_at: string`
- `updated_at: string`
- `revision: string`
- `meta?: object<string,string>`

`lane`:
- `lane_id: int`
- `route_id: int`
- `is_default: bool`
- `created_by_agent_id: int`
- `payload_count: int`
- `last_payload_id: ?int`
- `read_state: ?lane_read_state`
- `created_at: string`
- `updated_at: string`
- `revision: string`
- `meta?: object<string,string>`

`lane_read_state`:
- `lane_id: int`
- `agent_id: int`
- `last_read_payload_id: ?int`
- `created_at: string`
- `updated_at: string`
- `read_at: string`
- `revision: string`

`payload`:
- `payload_id: int`
- `lane_id: int`
- `author_agent_id: int`
- `payload_sha256: string`
- `payload_size: int`
- `created_at: string`
- `updated_at: string`
- `revision: string`

`payload_meta`:
- `payload_meta_id: int`
- `payload_id: int`
- `agent_id: int`
- `meta: object<string,string>`  
  Сейчас в одной записи должен быть ровно один key/value.
- `created_at: string`
- `updated_at: string`
- `revision: string`

`update`:
- `update_id: int`
- `zone: string`
- `agent_id: ?int`
- `kind: string`
- `route_id: ?int`
- `lane_id: ?int`
- `payload_id: ?int`
- `payload_meta_id: ?int`
- `covered: string[]`
- `data: object`
- `created_at: string`

## Registration and login

### `POST /register`

Request:
```json
{
  "zone": "11111111-1111-4111-8111-111111111111",
  "is_system": false,
  "device_label": "desktop"
}
```

Rules:
- если `is_system=true`, агент может быть создан только первым в зоне
- если `is_system=false`, зона уже должна существовать

Response:
```json
{
  "ok": true,
  "agent": { "...": "..." },
  "password": "generated-password",
  "session": { "...": "..." },
  "session_token": "bearer-token"
}
```

### `POST /login`

Request:
```json
{
  "agent_id": "42",
  "password": "secret",
  "device_label": "phone"
}
```

Response:
```json
{
  "ok": true,
  "agent": { "...": "..." },
  "session": { "...": "..." },
  "session_token": "bearer-token"
}
```

## Agent endpoints

- `GET /me`
- `GET /me?meta=all`
- `GET /me?meta=title,avatar`
- `GET /agents/{agent_id}`
- `GET /agents/{agent_id}?meta=title,avatar`
- `GET /me/meta`
- `POST /me/meta`
- `PUT /me/meta`
- `PATCH /me/meta`
- `DELETE /me/meta`
- `GET /sessions`
- `DELETE /sessions/{session_id}`

Meta contract for `POST|PUT|PATCH .../meta`:
```json
{
  "meta": {
    "title": "Alice",
    "avatar": "https://cdn.example/avatar.png"
  }
}
```

Rules:
- meta values are opaque strings only
- `POST` creates meta resource
- `PUT` fully replaces existing meta resource
- `PATCH` updates selected keys
- `DELETE /.../meta?keys=title,avatar` deletes selected keys
- `DELETE /.../meta` without `keys` deletes the whole meta resource
- `GET /.../meta` returns `200` with `{ "ok": true, "meta": {} }` when meta is absent

Agent visibility:
- `GET /agents/{agent_id}` is allowed only inside the same `zone`
- the caller must either request itself or share at least one non-deleted route with the target agent
- otherwise the server returns `404 Agent not found`

## Route endpoints

- `GET /routes`
- `GET /routes?meta=all`
- `GET /routes/{route_id}`
- `GET /routes/{route_id}?meta=title,avatar`
- `POST /routes`
- `DELETE /routes/{route_id}`

### `POST /routes`

Request:
```json
{
  "agent_ids": ["84", "96"],
  "meta": {
    "title": "Main route",
    "avatar": "route.png"
  }
}
```

Meaning:
- creates a route in caller’s zone
- creates creator subscription automatically
- `agent_ids` may be used only by `system`-agent
- if `agent_ids` is present, creates subscriptions for all listed agents
- creates one default lane automatically
- if `meta` is present, creates initial route meta in the same DB transaction as route creation

Response:
```json
{
  "ok": true,
  "route": { "...": "..." },
  "default_lane": { "...": "..." }
}
```

Route meta selector:
- `GET /routes?meta=all` returns full route meta for every listed route
- `GET /routes/{route_id}?meta=all` returns full route meta for that route
- `GET /routes/{route_id}?meta=title` returns only `title`
- `GET /routes/{route_id}?meta=title,avatar` returns only `title` and `avatar`
- when `meta` query is omitted, route payload does not include `meta`

Route meta:
- `GET /routes/{route_id}/meta`
- `POST /routes/{route_id}/meta`
- `PUT /routes/{route_id}/meta`
- `PATCH /routes/{route_id}/meta`
- `DELETE /routes/{route_id}/meta`

Subscriptions:
- `GET /routes/{route_id}/subscriptions`
- `POST /routes/{route_id}/subscriptions`
- `DELETE /routes/{route_id}/subscriptions/{agent_id}`

Membership policy:
- `POST /routes/{route_id}/subscriptions` is allowed only for `system`-agent of the same zone
- `DELETE /routes/{route_id}/subscriptions/{agent_id}` is allowed only for `system`-agent of the same zone
- ordinary agents cannot add route members through transport API
- ordinary agents cannot remove route members through transport API
- deleting owner subscription is supported by endpoint surface but returns explicit error

`POST /routes/{route_id}/subscriptions` request:
```json
{
  "agent_id": "84",
  "role": "publisher"
}
```

`DELETE /routes/{route_id}/subscriptions/{agent_id}`:
- removes target subscription from route
- returns `404` if target agent is not subscribed to this route
- returns explicit error if target subscription is route owner

Roles:
- `owner`
- `admin`
- `editor`
- `publisher`
- `guest`

## Lane endpoints

- `GET /routes/{route_id}/lanes`
- `GET /routes/{route_id}/lanes?meta=all`
- `POST /routes/{route_id}/lanes`
- `GET /lanes/{lane_id}`
- `GET /lanes/{lane_id}?meta=title`
- `DELETE /lanes/{lane_id}`
- `POST /lanes/{lane_id}/clear`
- `POST /lanes/{lane_id}/read`

Lane meta:
- `GET /lanes/{lane_id}/meta`
- `POST /lanes/{lane_id}/meta`
- `PUT /lanes/{lane_id}/meta`
- `PATCH /lanes/{lane_id}/meta`
- `DELETE /lanes/{lane_id}/meta`

### `POST /lanes/{lane_id}/read`

Request:
```json
{
  "last_read_payload_id": 123
}
```

Response:
```json
{
  "ok": true,
  "lane_read_state": { "...": "..." }
}
```

## Payload endpoints

- `GET /lanes/{lane_id}/payloads?after_id=0&limit=100`
- `POST /lanes/{lane_id}/payloads`
- `PUT /payloads/{payload_id}/body`
- `DELETE /payloads/{payload_id}`
- `GET /payloads/{payload_id}/body`

### `POST /lanes/{lane_id}/payloads`

Headers:
- `Content-Type: application/octet-stream`

Body:
- raw payload bytes

Response:
```json
{
  "ok": true,
  "payload": { "...": "..." }
}
```

### `PUT /payloads/{payload_id}/body`

Headers:
- `Content-Type: application/octet-stream`

Body:
- new raw payload bytes

Response:
```json
{
  "ok": true,
  "payload": { "...": "..." }
}
```

### `GET /payloads/{payload_id}/body`

Response:
- `Content-Type: application/octet-stream`
- `Content-Length: <payload_size>`
- `ETag: "<payload_sha256>"`
- body: raw payload bytes

## Payload meta endpoints

- `GET /payloads/{payload_id}/meta`
- `POST /payloads/{payload_id}/meta`
- `GET /payload-meta/{payload_meta_id}`
- `PUT /payload-meta/{payload_meta_id}`
- `PATCH /payload-meta/{payload_meta_id}`
- `DELETE /payload-meta/{payload_meta_id}`

Request for `POST /payloads/{payload_id}/meta`:
```json
{
  "meta": {
    "reaction": "like"
  }
}
```

Rule:
- payload meta record must contain exactly one entry

## Updates

### `GET /updates?after_id=0&limit=100`

Current implementation:
- доступен только `system`-агенту зоны, то есть zone owner / bootstrap agent
- возвращает zone-wide durable updates

Response:
```json
{
  "ok": true,
  "items": [
    {
      "update_id": 1,
      "kind": "route_created",
      "covered": ["route", "subscription", "lane"],
      "data": {},
      "created_at": "2026-04-29 12:00:00.123456"
    }
  ]
}
```

## Lazy sync contract

### Goal

Обычный клиент не читает raw update log.  
Он синкает только то, что реально показывает пользователю.

Raw updates:
- `GET /updates?after_id=...` остаётся только для `system`-агента зоны
- обычный клиент не использует `/updates` как primary sync path

### Endpoints

Ниже перечислен текущий read-sync API для обычного клиента.

#### `POST /sync/routes`

Request:
```json
{
  "skip": 0,
  "limit": 50,
  "items": [
    { "id": 101, "revision": "2026-04-29 12:00:00.123456" }
  ]
}
```

Response:
```json
{
  "ok": true,
  "items": [
    { "id": 101, "revision": "2026-04-29 12:05:00.123456", "is_deleted": false }
  ]
}
```

#### `POST /sync/routes/{route_id}/lanes`

Request:
```json
{
  "skip": 0,
  "limit": 50,
  "items": [
    { "id": 501, "revision": "2026-04-29 12:00:00.123456" }
  ]
}
```

Response:
```json
{
  "ok": true,
  "items": [
    { "id": 501, "revision": "2026-04-29 12:05:00.123456", "is_deleted": false }
  ]
}
```

#### `POST /sync/lanes/{lane_id}/payloads`

Request:
```json
{
  "skip": 20,
  "limit": 50,
  "items": [
    { "id": 9001, "revision": "2026-04-29 12:00:00.123456" }
  ]
}
```

Response:
```json
{
  "ok": true,
  "items": [
    { "id": 9001, "revision": "2026-04-29 12:05:00.123456", "is_deleted": false }
  ]
}
```

#### `GET /payloads/{payload_id}/body`

Назначение:
- отдельная догрузка raw payload body
- sync endpoint-ы не возвращают body inline

### List sync

Для `routes`, `lanes` и `payloads` используется один и тот же алгоритм:
- клиент шлёт `skip`, `limit` и свой локальный список `[{id, revision}, ...]`
- сервер берёт текущее окно по `skip/limit`, упорядоченное по `revision desc, id desc`
- сервер выкидывает совпадающие `id+revision`
- по остальным отдаёт:
  - `id`
  - `revision`
  - `is_deleted`

Важная семантика:
- ответ не является авторитативным составом окна
- если объект не вернулся в `items`, это не гарантирует, что он всё ещё входит в текущий slice сервера
- контракт означает только: "дай отсутствующие или изменившиеся элементы из этого диапазона"
- если клиенту нужен более точный снимок, он повторно запрашивает нужный диапазон, например `skip=0`

### Payload sync

Payload sync использует тот же контракт `skip/limit/items`.
- клиент запрашивает только тот диапазон lane, который ему сейчас нужен
- сервер отдаёт только `payload_id + revision + is_deleted`
- body клиент догружает отдельно через `GET /payloads/{id}/body`

### Deletions

Для sync используется soft-delete:
- объект не исчезает сразу физически
- получает новую `revision`
- помечается `is_deleted = true`
- и попадает в sync response своего диапазона
- обычные read endpoint-ы после этого считают его отсутствующим

Тогда клиент может убрать его из локального кэша без отдельного delete-event.

### Why this contract

Такой контракт:
- не требует per-agent update queues
- дешёв на write-path
- хорошо работает для больших списков, где горячим обычно бывает только верх
- оставляет `system`-агенту raw update log только для push и служебной логики
