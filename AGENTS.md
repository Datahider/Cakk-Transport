# AGENTS.md

Локальные правила проекта `cakk-transport`.

## Контракт

- Проект реализует только transport API.
- Сервер не интерпретирует payload и не знает типов сообщений.
- Любая бизнес-логика рендера, декодирования и plugin matching находится на стороне клиента.
- `system`-агент является полным zone-admin: в своей `zone` он должен иметь возможность выполнять любой transport-level запрос без дополнительных membership/role ограничений.
- Если приложению нужна специфическая социальная или организационная политика, она реализуется поверх transport через `system`-агента, а не вшивается как ограничение transport-а на `system`.
- Каждый сущностный surface transport-а должен стремиться к полному CRUD-покрытию endpoint-ами, даже если отдельные операции кажутся продуктово бессмысленными.
- Если операция поддерживается endpoint-ом, но в конкретном состоянии не имеет смысла, сервер должен отвечать явной ошибкой; неполнота endpoint-модели не считается допустимой заменой такой ошибки.
- Базовые сущности проекта:
  - actor
  - actor session
  - route
  - subscription
  - lane
  - payload with opaque body
  - payload meta
- `zone` изолирует независимые overlay-системы на одном transport.

## Ограничения

- Не добавлять типизацию payload в transport layer.
- Не добавлять preview, mentions, reply, attachments semantics или любой иной message-specific contract.
- Не добавлять fallback и эвристики для распознавания payload.
- Auth держать простой и явный: bearer session token через `actor_session`.

## Sync Contract

- Для realtime-sync целевая модель проекта:
  - state tables
  - append-only `update_log`
  - bootstrap snapshot
  - `updates after_id`
- Не строить transport-sync на diff текущего состояния относительно client snapshot как на primary-модели.
- Допустимы polling и/или SSE, но поверх одного и того же `update_log`.
- Один DB transaction должен порождать ровно одну запись в `update_log`.
- Один `update_log` record должен представлять один атомарный transport event для клиента.
- Нельзя дробить одну server transaction на несколько client-visible update records.
- Причина: клиент не должен иметь возможность применить только часть изменений одной server transaction и оказаться в промежуточном состоянии, которого на сервере никогда не существовало.

## Transactional Bootstrap

- Консистентный bootstrap должен собираться внутри одной read transaction.
- В этой transaction нужно:
  - открыть snapshot read
  - прочитать `MAX(update_id)` как watermark `X`
  - собрать snapshot state
  - вернуть snapshot вместе с `latest_update_id = X`
- После этого клиент читает только `updates after X`.
- Это корректно только если:
  - state mutation и запись в `update_log` коммитятся в одной и той же DB transaction
  - все таблицы, участвующие в bootstrap и update log, transactional (`InnoDB`)
  - `update_log` не пишется асинхронно после коммита state
- Если эти условия нарушить, snapshot/update consistency теряется.

## Update Coverage Discipline

- Для mutating use case нужен явный transaction context с declared scope изменяемых object kinds.
- `beginTran(...)` задаёт, какие object kinds разрешено менять внутри use case.
- Любой write объекта вне declared scope должен приводить к exception.
- В транзакции должна быть ровно одна операция записи в `update_log`.
- `update_log(..., covered: ...)` должен явно перечислять все object kinds, которые были затронуты этой транзакцией.
- `commit()` должен валидировать, что фактически touched object kinds полностью покрыты `covered`.

## Технический стек

- PHP
- MySQL/MariaDB
- `losthost/db`

## Runtime Notes

- Любой `curl` к локальному dev-server `http://127.0.0.1:8080` должен всегда идти с коротким `--max-time`, включая `register`, `login`, `health` и любые smoke-test запросы.
- Причина: если watcher уже упёрся в зависший `php -S`, `curl` без `--max-time` клинит `exec`-очередь и блокирует все следующие job'ы.
