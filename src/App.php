<?php

declare(strict_types=1);

namespace CakkTransport;

use CakkTransport\data\Agent;
use CakkTransport\data\AgentMeta;
use CakkTransport\data\AgentPresence;
use CakkTransport\data\AgentSession;
use CakkTransport\data\Lane;
use CakkTransport\data\LaneMeta;
use CakkTransport\data\LaneReadState;
use CakkTransport\data\Payload;
use CakkTransport\data\PayloadMeta;
use CakkTransport\data\Route;
use CakkTransport\data\RouteMeta;
use CakkTransport\data\Subscription;
use CakkTransport\data\UpdateLog;
use DateTimeImmutable;
use Exception;
use Throwable;
use losthost\DB\DB;
use losthost\DB\DBList;

final class App
{
    public function handle(string $method, string $path): void
    {
        try {
            if ($method === 'GET' && $path === '/health') {
                $this->respond(['ok' => true]);
                return;
            }

            if ($method === 'POST' && $path === '/register') {
                $this->respond($this->register());
                return;
            }

            if ($method === 'POST' && $path === '/login') {
                $this->respond($this->login());
                return;
            }

            [$actor, $session] = $this->authenticate();

            if ($method === 'GET' && $path === '/me') {
                $this->respond([
                    'ok' => true,
                    'agent' => $this->serializeAgent($actor, $this->readMetaSelector()),
                ]);
                return;
            }

            if ($method === 'GET' && $path === '/me/meta') {
                $this->respond($this->viewAgentMeta($actor));
                return;
            }

            if ($method === 'POST' && $path === '/me/meta') {
                $this->respond($this->createAgentMeta($actor));
                return;
            }

            if ($method === 'PUT' && $path === '/me/meta') {
                $this->respond($this->replaceAgentMeta($actor));
                return;
            }

            if ($method === 'PATCH' && $path === '/me/meta') {
                $this->respond($this->patchAgentMeta($actor));
                return;
            }

            if ($method === 'DELETE' && $path === '/me/meta') {
                $this->respond($this->deleteAgentMeta($actor));
                return;
            }

            if ($method === 'GET' && $path === '/sessions') {
                $this->respond($this->listSessions($actor, $session));
                return;
            }

            if ($method === 'GET' && $path === '/updates') {
                $this->respond($this->listUpdates($actor));
                return;
            }

            if ($method === 'POST' && $path === '/state-patch') {
                $this->respond($this->statePatch($actor));
                return;
            }

            if ($method === 'GET' && $path === '/state-patch/stream') {
                $this->streamStatePatch($actor);
                return;
            }

            if (preg_match('#^/sessions/([^/]+)$#', $path, $matches) === 1 && $method === 'DELETE') {
                $this->respond($this->revokeSession($actor, rawurldecode($matches[1])));
                return;
            }

            if ($method === 'GET' && $path === '/routes') {
                $this->respond([
                    'ok' => true,
                    'items' => $this->listRoutes($actor),
                ]);
                return;
            }

            if ($method === 'POST' && $path === '/routes') {
                $this->respond($this->createRoute($actor));
                return;
            }

            if (preg_match('#^/routes/([^/]+)$#', $path, $matches) === 1 && $method === 'GET') {
                $this->respond($this->viewRoute($actor, rawurldecode($matches[1])));
                return;
            }

            if (preg_match('#^/routes/([^/]+)$#', $path, $matches) === 1 && $method === 'DELETE') {
                $this->respond($this->deleteRoute($actor, rawurldecode($matches[1])));
                return;
            }

            if (preg_match('#^/routes/([^/]+)/meta$#', $path, $matches) === 1 && $method === 'GET') {
                $this->respond($this->viewRouteMeta($actor, rawurldecode($matches[1])));
                return;
            }

            if (preg_match('#^/routes/([^/]+)/meta$#', $path, $matches) === 1 && $method === 'POST') {
                $this->respond($this->createRouteMeta($actor, rawurldecode($matches[1])));
                return;
            }

            if (preg_match('#^/routes/([^/]+)/meta$#', $path, $matches) === 1 && $method === 'PUT') {
                $this->respond($this->replaceRouteMeta($actor, rawurldecode($matches[1])));
                return;
            }

            if (preg_match('#^/routes/([^/]+)/meta$#', $path, $matches) === 1 && $method === 'PATCH') {
                $this->respond($this->patchRouteMeta($actor, rawurldecode($matches[1])));
                return;
            }

            if (preg_match('#^/routes/([^/]+)/meta$#', $path, $matches) === 1 && $method === 'DELETE') {
                $this->respond($this->deleteRouteMeta($actor, rawurldecode($matches[1])));
                return;
            }

            if (preg_match('#^/routes/([^/]+)/subscriptions$#', $path, $matches) === 1) {
                $routePublicId = rawurldecode($matches[1]);
                if ($method === 'GET') {
                    $this->respond($this->listRouteSubscriptions($actor, $routePublicId));
                    return;
                }
                if ($method === 'POST') {
                    $this->respond($this->addRouteSubscription($actor, $routePublicId));
                    return;
                }
            }

            if (preg_match('#^/routes/([^/]+)/lanes$#', $path, $matches) === 1) {
                $routePublicId = rawurldecode($matches[1]);
                if ($method === 'GET') {
                    $this->respond($this->listLanes($actor, $routePublicId));
                    return;
                }
                if ($method === 'POST') {
                    $this->respond($this->createLane($actor, $routePublicId));
                    return;
                }
            }

            if (preg_match('#^/lanes/([^/]+)$#', $path, $matches) === 1 && $method === 'GET') {
                $this->respond($this->viewLane($actor, rawurldecode($matches[1])));
                return;
            }

            if (preg_match('#^/lanes/([^/]+)/meta$#', $path, $matches) === 1 && $method === 'GET') {
                $this->respond($this->viewLaneMeta($actor, rawurldecode($matches[1])));
                return;
            }

            if (preg_match('#^/lanes/([^/]+)/meta$#', $path, $matches) === 1 && $method === 'POST') {
                $this->respond($this->createLaneMeta($actor, rawurldecode($matches[1])));
                return;
            }

            if (preg_match('#^/lanes/([^/]+)/meta$#', $path, $matches) === 1 && $method === 'PUT') {
                $this->respond($this->replaceLaneMeta($actor, rawurldecode($matches[1])));
                return;
            }

            if (preg_match('#^/lanes/([^/]+)/meta$#', $path, $matches) === 1 && $method === 'PATCH') {
                $this->respond($this->patchLaneMeta($actor, rawurldecode($matches[1])));
                return;
            }

            if (preg_match('#^/lanes/([^/]+)/meta$#', $path, $matches) === 1 && $method === 'DELETE') {
                $this->respond($this->deleteLaneMeta($actor, rawurldecode($matches[1])));
                return;
            }

            if (preg_match('#^/lanes/([^/]+)$#', $path, $matches) === 1 && $method === 'DELETE') {
                $this->respond($this->deleteLane($actor, rawurldecode($matches[1])));
                return;
            }

            if (preg_match('#^/lanes/([^/]+)/clear$#', $path, $matches) === 1 && $method === 'POST') {
                $this->respond($this->clearLane($actor, rawurldecode($matches[1])));
                return;
            }

            if (preg_match('#^/lanes/([^/]+)/read$#', $path, $matches) === 1 && $method === 'POST') {
                $this->respond($this->markLaneRead($actor, rawurldecode($matches[1])));
                return;
            }

            if (preg_match('#^/lanes/([^/]+)/payloads$#', $path, $matches) === 1) {
                $lanePublicId = rawurldecode($matches[1]);
                if ($method === 'GET') {
                    $this->respond($this->listPayloads($actor, $lanePublicId));
                    return;
                }
                if ($method === 'POST') {
                    $this->respond($this->createPayload($actor, $lanePublicId));
                    return;
                }
            }

            if (preg_match('#^/payloads/([^/]+)/meta$#', $path, $matches) === 1) {
                $payloadId = rawurldecode($matches[1]);
                if ($method === 'GET') {
                    $this->respond($this->listPayloadMeta($actor, $payloadId));
                    return;
                }
                if ($method === 'POST') {
                    $this->respond($this->createPayloadMeta($actor, $payloadId));
                    return;
                }
            }

            if (preg_match('#^/payloads/([^/]+)/body$#', $path, $matches) === 1 && $method === 'GET') {
                $this->respondBinary($this->readPayloadBody($actor, rawurldecode($matches[1])));
                return;
            }

            if (preg_match('#^/payloads/([^/]+)/body$#', $path, $matches) === 1 && $method === 'PUT') {
                $this->respond($this->updatePayloadBody($actor, rawurldecode($matches[1])));
                return;
            }

            if (preg_match('#^/payloads/([^/]+)$#', $path, $matches) === 1 && $method === 'DELETE') {
                $this->respond($this->deletePayload($actor, rawurldecode($matches[1])));
                return;
            }

            if (preg_match('#^/payload-meta/([^/]+)$#', $path, $matches) === 1) {
                $payloadMetaId = rawurldecode($matches[1]);
                if ($method === 'GET') {
                    $this->respond($this->viewPayloadMeta($actor, $payloadMetaId));
                    return;
                }
                if ($method === 'PUT') {
                    $this->respond($this->replacePayloadMeta($actor, $payloadMetaId));
                    return;
                }
                if ($method === 'PATCH') {
                    $this->respond($this->patchPayloadMeta($actor, $payloadMetaId));
                    return;
                }
                if ($method === 'DELETE') {
                    $this->respond($this->deletePayloadMeta($actor, $payloadMetaId));
                    return;
                }
            }

            $this->error(404, 'Route not found');
        } catch (HttpException $error) {
            $this->respond([
                'ok' => false,
                'error' => $error->getMessage(),
            ], $error->getStatusCode());
        } catch (Exception $error) {
            $this->respond([
                'ok' => false,
                'error' => 'Internal server error',
                'details' => $error->getMessage(),
            ], 500);
        }
    }

    private function register(): array
    {
        $password = $this->randomToken(24);
        $payload = $this->readJsonBody();
        $zone = $this->requiredUuidField($payload, 'zone');
        $isSystem = (bool) ($payload['is_system'] ?? false);
        $zoneHasAgents = $this->zoneHasAgents($zone);

        if ($isSystem && $zoneHasAgents) {
            $this->error(409, 'System agent can only be the first agent in zone');
        }

        if (!$isSystem && !$zoneHasAgents) {
            $this->error(404, 'Zone not found');
        }

        $actor = new Agent();
        $actor->zone = $zone;
        $actor->is_system = $isSystem;
        $actor->password_hash = password_hash($password, PASSWORD_DEFAULT);
        $actor->write();

        [$session, $sessionToken] = $this->createSessionRecord(
            $actor,
            $this->optionalStringField($payload, 'device_label', 255, null)
        );

        return [
            'ok' => true,
            'agent' => $this->serializeAgent($actor),
            'password' => $password,
            'session' => $this->serializeSession($session, (int) $session->id),
            'session_token' => $sessionToken,
        ];
    }

    private function login(): array
    {
        $payload = $this->readJsonBody();
        $actorIdRaw = trim((string) ($payload['agent_id'] ?? ''));
        $password = (string) ($payload['password'] ?? '');
        if ($actorIdRaw === '' || $password === '') {
            $this->error(422, 'agent_id and password are required');
        }

        $actorId = $this->requiredPositiveIntString($actorIdRaw, 'agent_id');

        try {
            $actor = new Agent(['id' => $actorId]);
        } catch (Exception $error) {
            $this->error(401, 'Invalid credentials');
        }

        if (!password_verify($password, (string) $actor->password_hash)) {
            $this->error(401, 'Invalid credentials');
        }

        [$session, $sessionToken] = $this->createSessionRecord(
            $actor,
            $this->optionalStringField($payload, 'device_label', 255, null)
        );

        return [
            'ok' => true,
            'agent' => $this->serializeAgent($actor),
            'session' => $this->serializeSession($session, (int) $session->id),
            'session_token' => $sessionToken,
        ];
    }

    private function listSessions(Agent $actor, AgentSession $currentSession): array
    {
        $items = array_map(
            fn (AgentSession $session): array => $this->serializeSession($session, (int) $currentSession->id),
            (new DBList(AgentSession::class, ['agent_id' => (int) $actor->id]))->asArray()
        );

        return [
            'ok' => true,
            'items' => $items,
        ];
    }

    private function revokeSession(Agent $actor, string $sessionPublicId): array
    {
        $session = $this->loadSessionForAgent($actor, $sessionPublicId);
        $now = $this->now();
        $session->revoked_at = $now;
        $session->updated_at = $now;
        $session->write();

        return [
            'ok' => true,
        ];
    }

    private function listUpdates(Agent $actor): array
    {
        if (!(bool) $actor->is_system) {
            $this->error(403, 'Only system agent can read zone updates');
        }

        $afterId = max(0, (int) ($_GET['after_id'] ?? 0));
        $limit = max(1, min(500, (int) ($_GET['limit'] ?? 100)));

        $sql = sprintf(
            'SELECT id FROM [UpdateLog] WHERE zone = ? AND id > ? ORDER BY id ASC LIMIT %d',
            $limit + 1
        );
        $sth = DB::prepare($sql);
        $sth->execute([(string) $actor->zone, $afterId]);

        $items = [];
        while (($updateId = $sth->fetchColumn()) !== false) {
            $items[] = new UpdateLog(['id' => (int) $updateId]);
        }

        $hasMore = count($items) > $limit;
        if ($hasMore) {
            array_pop($items);
        }

        $latestUpdateId = $afterId;
        if ($items !== []) {
            $latestUpdateId = (int) end($items)->id;
        }

        return [
            'ok' => true,
            'items' => array_map(fn (UpdateLog $update): array => $this->serializeUpdate($update), $items),
            'after_id' => $afterId,
            'latest_update_id' => $latestUpdateId,
            'has_more' => $hasMore,
        ];
    }

    private function viewAgentMeta(Agent $actor): array
    {
        $meta = $this->loadAgentMetaData($actor);
        $this->assertMetaExists($meta, 'Agent meta not found');

        return [
            'ok' => true,
            'meta' => $this->selectMetaFields($meta, $this->readMetaSelector(true)),
        ];
    }

    private function createAgentMeta(Agent $actor): array
    {
        $existing = $this->loadAgentMetaData($actor);
        if ($existing !== []) {
            $this->error(409, 'Agent meta already exists');
        }

        $meta = $this->readRequiredMetaFromRequest();
        $this->writeAgentMeta($actor, $meta, true);

        return ['ok' => true, 'meta' => $meta];
    }

    private function replaceAgentMeta(Agent $actor): array
    {
        $this->assertMetaExists($this->loadAgentMetaData($actor), 'Agent meta not found');
        $meta = $this->readRequiredMetaFromRequest();
        $this->writeAgentMeta($actor, $meta, true);

        return ['ok' => true, 'meta' => $meta];
    }

    private function patchAgentMeta(Agent $actor): array
    {
        $meta = $this->readRequiredMetaFromRequest();
        $existing = $this->loadAgentMetaData($actor);
        $this->assertMetaExists($existing, 'Agent meta not found');
        $updated = array_replace($existing, $meta);
        $this->writeAgentMeta($actor, $updated, false);

        return ['ok' => true, 'meta' => $updated];
    }

    private function deleteAgentMeta(Agent $actor): array
    {
        $existing = $this->loadAgentMetaData($actor);
        $this->assertMetaExists($existing, 'Agent meta not found');
        $keys = $this->readMetaKeysSelector();
        $remaining = $keys === [] ? [] : array_diff_key($existing, array_flip($keys));
        $this->writeAgentMeta($actor, $remaining, true);

        return ['ok' => true, 'meta' => $remaining];
    }

    private function statePatch(Agent $actor): array
    {
        return $this->buildStatePatch($actor, $this->readJsonBody());
    }

    private function streamStatePatch(Agent $actor): void
    {
        $state = $this->normalizeStatePatchRequest($this->readStatePatchHeader());
        $lastSignature = '';

        $this->markAgentOnline($actor);
        register_shutdown_function(function () use ($actor): void {
            $this->markAgentOffline($actor);
        });

        ignore_user_abort(true);
        set_time_limit(0);

        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
        header('X-Accel-Buffering: no');

        while (!connection_aborted()) {
            $patch = $this->buildStatePatch($actor, $state);
            $signature = json_encode($patch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';

            if ($lastSignature === '' || $signature !== $lastSignature) {
                $this->sendSseEvent('state_patch', $patch);
                $lastSignature = $signature;
            }

            $state = $this->advanceStatePatchState($state, $patch);

            $deadline = time() + 15;
            while (!connection_aborted() && time() < $deadline) {
                if ($this->maxZoneUpdateId((string) $actor->zone) > ($state['after_update_id'] ?? 0)) {
                    break;
                }
                $this->sendSseComment('keepalive');
                usleep(500000);
            }
        }
    }

    private function createRoute(Agent $actor): array
    {
        $payload = $this->readJsonBody();
        $systemOwned = (bool) ($payload['system_owned'] ?? false);
        $subscriberIds = array_values(array_filter(
            is_array($payload['agent_ids'] ?? null) ? $payload['agent_ids'] : [],
            static fn (mixed $item): bool => is_string($item) && trim($item) !== ''
        ));
        $subscriberIds = array_map(
            fn (string $id): int => $this->requiredPositiveIntString($id, 'agent_ids[]'),
            $subscriberIds
        );

        $transaction = $this->beginTransportMutation($actor, [
            TransportTransaction::OBJECT_ROUTE,
            TransportTransaction::OBJECT_SUBSCRIPTION,
            TransportTransaction::OBJECT_LANE,
        ]);
        try {
            $route = new Route();
            $route->zone = (string) $actor->zone;
            $route->owner_agent_id = $systemOwned ? null : (int) $actor->id;
            $transaction->write(TransportTransaction::OBJECT_ROUTE, $route);

            $membership = new Subscription();
            $membership->route_id = (int) $route->id;
            $membership->agent_id = (int) $actor->id;
            $membership->role = $systemOwned ? Subscription::ROLE_ADMIN : Subscription::ROLE_OWNER;
            $transaction->write(TransportTransaction::OBJECT_SUBSCRIPTION, $membership);

            foreach ($subscriberIds as $subscriberId) {
                $subscriptionSubscriber = $this->loadAgentByIdInZone($actor, (string) $subscriberId);
                $spaceSubscription = new Subscription();
                $spaceSubscription->route_id = (int) $route->id;
                $spaceSubscription->agent_id = (int) $subscriptionSubscriber->id;
                $spaceSubscription->role = Subscription::ROLE_PUBLISHER;
                $transaction->write(TransportTransaction::OBJECT_SUBSCRIPTION, $spaceSubscription);
            }

            $defaultLane = $this->createDefaultLane($transaction, $route, $actor);
            $transaction->updateLog('route_created', [
                TransportTransaction::OBJECT_ROUTE,
                TransportTransaction::OBJECT_SUBSCRIPTION,
                TransportTransaction::OBJECT_LANE,
            ], [
                'route_id' => (int) $route->id,
                'default_lane_id' => (int) $defaultLane->id,
                'member_agent_ids' => array_merge([(int) $actor->id], $subscriberIds),
            ], [
                'route_id' => (int) $route->id,
                'lane_id' => (int) $defaultLane->id,
            ]);
            $transaction->commit();
        } catch (Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }

        return [
            'ok' => true,
            'route' => $this->serializeRoute($route),
            'default_lane' => $this->serializeLane($defaultLane, $actor),
        ];
    }

    private function viewRoute(Agent $actor, string $routePublicId): array
    {
        $route = $this->loadRouteForMember($actor, $routePublicId);

        return [
            'ok' => true,
            'route' => $this->serializeRoute($route, $this->readMetaSelector()),
        ];
    }

    private function viewRouteMeta(Agent $actor, string $routePublicId): array
    {
        $route = $this->loadRouteForMember($actor, $routePublicId);
        $meta = $this->loadRouteMetaData($route);
        $this->assertMetaExists($meta, 'Route meta not found');

        return [
            'ok' => true,
            'meta' => $this->selectMetaFields($meta, $this->readMetaSelector(true)),
        ];
    }

    private function createRouteMeta(Agent $actor, string $routePublicId): array
    {
        $route = $this->loadRouteForMember($actor, $routePublicId);
        $this->assertCanManageRouteMeta($actor, $route);
        $existing = $this->loadRouteMetaData($route);
        if ($existing !== []) {
            $this->error(409, 'Route meta already exists');
        }

        $meta = $this->readRequiredMetaFromRequest();
        $this->writeRouteMeta($actor, $route, $meta, true);

        return ['ok' => true, 'meta' => $meta];
    }

    private function replaceRouteMeta(Agent $actor, string $routePublicId): array
    {
        $route = $this->loadRouteForMember($actor, $routePublicId);
        $this->assertCanManageRouteMeta($actor, $route);
        $this->assertMetaExists($this->loadRouteMetaData($route), 'Route meta not found');
        $meta = $this->readRequiredMetaFromRequest();
        $this->writeRouteMeta($actor, $route, $meta, true);

        return ['ok' => true, 'meta' => $meta];
    }

    private function patchRouteMeta(Agent $actor, string $routePublicId): array
    {
        $route = $this->loadRouteForMember($actor, $routePublicId);
        $this->assertCanManageRouteMeta($actor, $route);
        $patch = $this->readRequiredMetaFromRequest();
        $existing = $this->loadRouteMetaData($route);
        $this->assertMetaExists($existing, 'Route meta not found');
        $meta = array_replace($existing, $patch);
        $this->writeRouteMeta($actor, $route, $meta, false);

        return ['ok' => true, 'meta' => $meta];
    }

    private function deleteRouteMeta(Agent $actor, string $routePublicId): array
    {
        $route = $this->loadRouteForMember($actor, $routePublicId);
        $this->assertCanManageRouteMeta($actor, $route);
        $existing = $this->loadRouteMetaData($route);
        $this->assertMetaExists($existing, 'Route meta not found');
        $keys = $this->readMetaKeysSelector();
        $remaining = $keys === [] ? [] : array_diff_key($existing, array_flip($keys));
        $this->writeRouteMeta($actor, $route, $remaining, true);

        return ['ok' => true, 'meta' => $remaining];
    }

    private function deleteRoute(Agent $actor, string $routePublicId): array
    {
        $route = $this->loadRouteForMember($actor, $routePublicId);
        $this->assertHasMaxRouteRole($actor, $route);
        $transaction = $this->beginTransportMutation($actor, [
            TransportTransaction::OBJECT_ROUTE,
            TransportTransaction::OBJECT_ROUTE_META,
            TransportTransaction::OBJECT_SUBSCRIPTION,
            TransportTransaction::OBJECT_LANE,
            TransportTransaction::OBJECT_LANE_META,
            TransportTransaction::OBJECT_LANE_READ_STATE,
            TransportTransaction::OBJECT_PAYLOAD,
            TransportTransaction::OBJECT_PAYLOAD_META,
        ]);
        try {
            foreach ($this->laneIdsForRoute($route) as $laneId) {
                $this->deleteLaneMetaForLaneId($transaction, $laneId);
                $this->deleteLaneReadStatesForLaneId($transaction, $laneId);
                $this->deletePayloadsForLaneId($transaction, $laneId);
                $transaction->delete(TransportTransaction::OBJECT_LANE, new Lane(['id' => $laneId]));
            }

            $this->deleteRouteMetaForRouteId($transaction, (int) $route->id);

            foreach ((new DBList(Subscription::class, ['route_id' => (int) $route->id]))->asArray() as $subscription) {
                $transaction->delete(TransportTransaction::OBJECT_SUBSCRIPTION, $subscription);
            }

            $transaction->delete(TransportTransaction::OBJECT_ROUTE, new Route(['id' => (int) $route->id]));
            $transaction->updateLog('route_deleted', [
                TransportTransaction::OBJECT_ROUTE,
                TransportTransaction::OBJECT_ROUTE_META,
                TransportTransaction::OBJECT_SUBSCRIPTION,
                TransportTransaction::OBJECT_LANE,
                TransportTransaction::OBJECT_LANE_META,
                TransportTransaction::OBJECT_LANE_READ_STATE,
                TransportTransaction::OBJECT_PAYLOAD,
                TransportTransaction::OBJECT_PAYLOAD_META,
            ], [
                'route_id' => (int) $route->id,
            ], [
                'route_id' => (int) $route->id,
            ]);
            $transaction->commit();
        } catch (Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }

        return [
            'ok' => true,
        ];
    }

    private function listRoutes(Agent $actor): array
    {
        $metaSelector = $this->readMetaSelector();
        $sql = 'SELECT f.* FROM [Route] f
            INNER JOIN [Subscription] fm ON fm.route_id = f.id
            WHERE fm.agent_id = ?
            ORDER BY f.revision DESC, f.id DESC';
        $sth = DB::prepare($sql);
        $sth->execute([(int) $actor->id]);

        $items = [];
        while ($row = $sth->fetch(\PDO::FETCH_ASSOC)) {
            $route = new Route(['id' => (int) $row['id']]);
            $items[] = $this->serializeRoute($route, $metaSelector);
        }

        return $items;
    }

    private function listRouteSubscriptions(Agent $actor, string $routePublicId): array
    {
        $route = $this->loadRouteForMember($actor, $routePublicId);
        $members = (new DBList(Subscription::class, ['route_id' => (int) $route->id]))->asArray();

        $items = [];
        foreach ($members as $member) {
            $memberAccount = new Agent(['id' => (int) $member->agent_id]);
            $items[] = [
                'agent_id' => (int) $memberAccount->id,
                'role' => (string) $member->role,
                'joined_at' => $this->formatDateTime($member->created_at),
            ];
        }

        return [
            'ok' => true,
            'items' => $items,
        ];
    }

    private function addRouteSubscription(Agent $actor, string $routePublicId): array
    {
        $route = $this->loadRouteForMember($actor, $routePublicId);
        $payload = $this->readJsonBody();
        $targetPublicId = trim((string) ($payload['agent_id'] ?? ''));
        if ($targetPublicId === '') {
            $this->error(422, 'agent_id is required');
        }

        try {
            $target = $this->loadAgentByIdInZone($actor, $targetPublicId);
        } catch (Exception $error) {
            $this->error(404, 'Agent not found');
        }

        $existing = new DBList(
            Subscription::class,
            ['route_id' => (int) $route->id, 'agent_id' => (int) $target->id]
        );

        if ($existing->next()) {
            $this->error(409, 'Agent is already a member of this route');
        }

        $this->assertCanManageMembers($actor, $route);

        $role = (string) ($payload['role'] ?? Subscription::ROLE_PUBLISHER);
        if (!in_array($role, $this->routeRoles(), true)) {
            $this->error(422, 'Unsupported route role');
        }
        if ($role === Subscription::ROLE_OWNER) {
            $this->error(422, 'Owner role cannot be assigned through this endpoint');
        }
        if ($role === Subscription::ROLE_ADMIN && $this->routeRoleForAgent($actor, $route) !== Subscription::ROLE_OWNER) {
            $this->error(403, 'Only route owner can assign admin role');
        }

        $membership = new Subscription();
        $membership->route_id = (int) $route->id;
        $membership->agent_id = (int) $target->id;
        $membership->role = $role;
        $transaction = $this->beginTransportMutation($actor, [
            TransportTransaction::OBJECT_SUBSCRIPTION,
            TransportTransaction::OBJECT_ROUTE,
        ]);
        try {
            $transaction->write(TransportTransaction::OBJECT_SUBSCRIPTION, $membership);
            $transaction->updateLog('subscription_added', [
                TransportTransaction::OBJECT_SUBSCRIPTION,
                TransportTransaction::OBJECT_ROUTE,
            ], [
                'route_id' => (int) $route->id,
                'agent_id' => (int) $target->id,
                'role' => $role,
            ], [
                'route_id' => (int) $route->id,
            ]);
            $transaction->commit();
        } catch (Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }

        return [
            'ok' => true,
            'subscription' => [
                'agent_id' => (int) $target->id,
                'role' => (string) $membership->role,
                'joined_at' => $this->formatDateTime($membership->created_at),
            ],
        ];
    }

    private function listLanes(Agent $actor, string $routePublicId): array
    {
        $route = $this->loadRouteForMember($actor, $routePublicId);
        $lanes = (new DBList(Lane::class, ['route_id' => (int) $route->id]))->asArray();
        $metaSelector = $this->readMetaSelector();

        return [
            'ok' => true,
            'items' => array_map(fn (Lane $lane): array => $this->serializeLane($lane, $actor, $metaSelector), $lanes),
        ];
    }

    private function createLane(Agent $actor, string $routePublicId): array
    {
        $route = $this->loadRouteForMember($actor, $routePublicId);
        $this->assertCanCreateLane($actor, $route);

        $transaction = $this->beginTransportMutation($actor, [
            TransportTransaction::OBJECT_LANE,
            TransportTransaction::OBJECT_ROUTE,
        ]);
        try {
            $lane = $this->createLaneRecord(
                $transaction,
                $route,
                $actor,
                false,
            );
            $transaction->updateLog('lane_created', [
                TransportTransaction::OBJECT_LANE,
                TransportTransaction::OBJECT_ROUTE,
            ], [
                'route_id' => (int) $route->id,
                'lane_id' => (int) $lane->id,
            ], [
                'route_id' => (int) $route->id,
                'lane_id' => (int) $lane->id,
            ]);
            $transaction->commit();
        } catch (Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }

        return [
            'ok' => true,
            'lane' => $this->serializeLane($lane, $actor),
        ];
    }

    private function viewLane(Agent $actor, string $lanePublicId): array
    {
        $lane = $this->loadLaneForMember($actor, $lanePublicId);

        return [
            'ok' => true,
            'lane' => $this->serializeLane($lane, $actor, $this->readMetaSelector()),
        ];
    }

    private function viewLaneMeta(Agent $actor, string $lanePublicId): array
    {
        $lane = $this->loadLaneForMember($actor, $lanePublicId);
        $meta = $this->loadLaneMetaData($lane);
        $this->assertMetaExists($meta, 'Lane meta not found');

        return [
            'ok' => true,
            'meta' => $this->selectMetaFields($meta, $this->readMetaSelector(true)),
        ];
    }

    private function createLaneMeta(Agent $actor, string $lanePublicId): array
    {
        $lane = $this->loadLaneForMember($actor, $lanePublicId);
        $route = new Route(['id' => (int) $lane->route_id]);
        $this->assertCanManageLaneMeta($actor, $route);
        $existing = $this->loadLaneMetaData($lane);
        if ($existing !== []) {
            $this->error(409, 'Lane meta already exists');
        }

        $meta = $this->readRequiredMetaFromRequest();
        $this->writeLaneMeta($actor, $route, $lane, $meta, true);

        return ['ok' => true, 'meta' => $meta];
    }

    private function replaceLaneMeta(Agent $actor, string $lanePublicId): array
    {
        $lane = $this->loadLaneForMember($actor, $lanePublicId);
        $route = new Route(['id' => (int) $lane->route_id]);
        $this->assertCanManageLaneMeta($actor, $route);
        $this->assertMetaExists($this->loadLaneMetaData($lane), 'Lane meta not found');
        $meta = $this->readRequiredMetaFromRequest();
        $this->writeLaneMeta($actor, $route, $lane, $meta, true);

        return ['ok' => true, 'meta' => $meta];
    }

    private function patchLaneMeta(Agent $actor, string $lanePublicId): array
    {
        $lane = $this->loadLaneForMember($actor, $lanePublicId);
        $route = new Route(['id' => (int) $lane->route_id]);
        $this->assertCanManageLaneMeta($actor, $route);
        $patch = $this->readRequiredMetaFromRequest();
        $existing = $this->loadLaneMetaData($lane);
        $this->assertMetaExists($existing, 'Lane meta not found');
        $meta = array_replace($existing, $patch);
        $this->writeLaneMeta($actor, $route, $lane, $meta, false);

        return ['ok' => true, 'meta' => $meta];
    }

    private function deleteLaneMeta(Agent $actor, string $lanePublicId): array
    {
        $lane = $this->loadLaneForMember($actor, $lanePublicId);
        $route = new Route(['id' => (int) $lane->route_id]);
        $this->assertCanManageLaneMeta($actor, $route);
        $existing = $this->loadLaneMetaData($lane);
        $this->assertMetaExists($existing, 'Lane meta not found');
        $keys = $this->readMetaKeysSelector();
        $remaining = $keys === [] ? [] : array_diff_key($existing, array_flip($keys));
        $this->writeLaneMeta($actor, $route, $lane, $remaining, true);

        return ['ok' => true, 'meta' => $remaining];
    }

    private function clearLane(Agent $actor, string $lanePublicId): array
    {
        $lane = $this->loadLaneForMember($actor, $lanePublicId);
        $route = new Route(['id' => (int) $lane->route_id]);
        $this->assertHasMaxRouteRole($actor, $route);
        $now = $this->now();
        $transaction = $this->beginTransportMutation($actor, [
            TransportTransaction::OBJECT_ROUTE,
            TransportTransaction::OBJECT_LANE,
            TransportTransaction::OBJECT_LANE_META,
            TransportTransaction::OBJECT_LANE_READ_STATE,
            TransportTransaction::OBJECT_PAYLOAD,
            TransportTransaction::OBJECT_PAYLOAD_META,
        ]);
        try {
            $this->deleteLaneMetaForLaneId($transaction, (int) $lane->id);
            $this->deleteLaneReadStatesForLaneId($transaction, (int) $lane->id);
            $this->deletePayloadsForLaneId($transaction, (int) $lane->id);
            $lane->payload_count = 0;
            $lane->last_payload_id = null;
            $transaction->write(TransportTransaction::OBJECT_LANE, $lane);
            $transaction->updateLog('lane_cleared', [
                TransportTransaction::OBJECT_ROUTE,
                TransportTransaction::OBJECT_LANE,
                TransportTransaction::OBJECT_LANE_META,
                TransportTransaction::OBJECT_LANE_READ_STATE,
                TransportTransaction::OBJECT_PAYLOAD,
                TransportTransaction::OBJECT_PAYLOAD_META,
            ], [
                'lane_id' => (int) $lane->id,
            ], [
                'route_id' => (int) $route->id,
                'lane_id' => (int) $lane->id,
            ]);
            $transaction->commit();
        } catch (Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }

        return [
            'ok' => true,
        ];
    }

    private function markLaneRead(Agent $actor, string $lanePublicId): array
    {
        $lane = $this->loadLaneForMember($actor, $lanePublicId);
        $payload = $this->readJsonBody();
        $lastReadPayloadId = $this->requiredNullablePositiveIntField($payload, 'last_read_payload_id');

        if ($lastReadPayloadId !== null) {
            $this->assertPayloadBelongsToLane($lane, $lastReadPayloadId);
        }

        $route = new Route(['id' => (int) $lane->route_id]);
        $readState = $this->loadLaneReadState($lane, $actor);
        $transaction = $this->beginTransportMutation($actor, [TransportTransaction::OBJECT_LANE_READ_STATE]);
        try {
            if ($readState === null) {
                $readState = new LaneReadState();
                $readState->lane_id = (int) $lane->id;
                $readState->agent_id = (int) $actor->id;
            }

            $readState->last_read_payload_id = $lastReadPayloadId;
            $readState->read_at = $this->now();
            $transaction->write(TransportTransaction::OBJECT_LANE_READ_STATE, $readState);
            $transaction->updateLog('lane_read_state_updated', [TransportTransaction::OBJECT_LANE_READ_STATE], [
                'lane_id' => (int) $lane->id,
                'agent_id' => (int) $actor->id,
                'last_read_payload_id' => $lastReadPayloadId,
            ], [
                'route_id' => (int) $route->id,
                'lane_id' => (int) $lane->id,
            ]);
            $transaction->commit();
        } catch (Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }

        return [
            'ok' => true,
            'lane_read_state' => $this->serializeLaneReadState($readState),
        ];
    }

    private function deleteLane(Agent $actor, string $lanePublicId): array
    {
        $lane = $this->loadLaneForMember($actor, $lanePublicId);
        if ((bool) $lane->is_default) {
            $this->error(409, 'Default lane cannot be deleted');
        }

        $route = new Route(['id' => (int) $lane->route_id]);
        $this->assertHasMaxRouteRole($actor, $route);
        $transaction = $this->beginTransportMutation($actor, [
            TransportTransaction::OBJECT_ROUTE,
            TransportTransaction::OBJECT_LANE,
            TransportTransaction::OBJECT_LANE_META,
            TransportTransaction::OBJECT_LANE_READ_STATE,
            TransportTransaction::OBJECT_PAYLOAD,
            TransportTransaction::OBJECT_PAYLOAD_META,
        ]);
        try {
            $this->deleteLaneMetaForLaneId($transaction, (int) $lane->id);
            $this->deleteLaneReadStatesForLaneId($transaction, (int) $lane->id);
            $this->deletePayloadsForLaneId($transaction, (int) $lane->id);
            $transaction->delete(TransportTransaction::OBJECT_LANE, new Lane(['id' => (int) $lane->id]));
            $transaction->updateLog('lane_deleted', [
                TransportTransaction::OBJECT_ROUTE,
                TransportTransaction::OBJECT_LANE,
                TransportTransaction::OBJECT_LANE_META,
                TransportTransaction::OBJECT_LANE_READ_STATE,
                TransportTransaction::OBJECT_PAYLOAD,
                TransportTransaction::OBJECT_PAYLOAD_META,
            ], [
                'lane_id' => (int) $lane->id,
            ], [
                'route_id' => (int) $route->id,
                'lane_id' => (int) $lane->id,
            ]);
            $transaction->commit();
        } catch (Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }

        return [
            'ok' => true,
        ];
    }

    private function listPayloads(Agent $actor, string $lanePublicId): array
    {
        $lane = $this->loadLaneForMember($actor, $lanePublicId);
        $afterId = max(0, (int) ($_GET['after_id'] ?? 0));
        $limit = max(1, min(100, (int) ($_GET['limit'] ?? 50)));

        $sql = sprintf(
            'SELECT id FROM [Payload] WHERE lane_id = ? AND id > ? ORDER BY id ASC LIMIT %d',
            $limit
        );
        $sth = DB::prepare($sql);
        $sth->execute([(int) $lane->id, $afterId]);

        $items = [];
        while (($payloadId = $sth->fetchColumn()) !== false) {
            $items[] = $this->serializePayload(new Payload(['id' => (int) $payloadId]));
        }

        return [
            'ok' => true,
            'items' => $items,
        ];
    }

    private function createPayload(Agent $actor, string $lanePublicId): array
    {
        $lane = $this->loadLaneForMember($actor, $lanePublicId);
        $route = new Route(['id' => (int) $lane->route_id]);
        $this->assertCanCreatePayload($actor, $route);
        $binaryPayload = $this->readRawPayloadBody();

        $now = $this->now();
        $transaction = $this->beginTransportMutation($actor, [
            TransportTransaction::OBJECT_PAYLOAD,
            TransportTransaction::OBJECT_LANE,
            TransportTransaction::OBJECT_ROUTE,
        ]);
        try {
            $payload = new Payload();
            $payload->lane_id = (int) $lane->id;
            $payload->author_agent_id = (int) $actor->id;
            $payload->payload = $binaryPayload;
            $payload->payload_sha256 = hash('sha256', $binaryPayload);
            $payload->payload_size = strlen($binaryPayload);
            $transaction->write(TransportTransaction::OBJECT_PAYLOAD, $payload);

            $lane->payload_count = (int) $lane->payload_count + 1;
            $lane->last_payload_id = (int) $payload->id;
            $transaction->write(TransportTransaction::OBJECT_LANE, $lane);
            $transaction->updateLog('payload_created', [
                TransportTransaction::OBJECT_PAYLOAD,
                TransportTransaction::OBJECT_LANE,
                TransportTransaction::OBJECT_ROUTE,
            ], [
                'lane_id' => (int) $lane->id,
                'payload_id' => (int) $payload->id,
            ], [
                'route_id' => (int) $route->id,
                'lane_id' => (int) $lane->id,
                'payload_id' => (int) $payload->id,
            ]);
            $transaction->commit();
        } catch (Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }

        return [
            'ok' => true,
            'payload' => $this->serializePayload($payload),
        ];
    }

    private function deletePayload(Agent $actor, string $payloadId): array
    {
        $payload = $this->loadWritablePayload($actor, $payloadId);
        $deletedPayloadId = (int) $payload->id;
        $lane = new Lane(['id' => (int) $payload->lane_id]);

        $route = new Route(['id' => (int) $lane->route_id]);
        $transaction = $this->beginTransportMutation($actor, [
            TransportTransaction::OBJECT_PAYLOAD,
            TransportTransaction::OBJECT_PAYLOAD_META,
            TransportTransaction::OBJECT_LANE,
            TransportTransaction::OBJECT_ROUTE,
        ]);
        try {
            $this->deletePayloadMetaForPayloadId($transaction, $deletedPayloadId);
            $transaction->delete(TransportTransaction::OBJECT_PAYLOAD, $payload);

            $lane->payload_count = max(0, (int) $lane->payload_count - 1);
            if ((int) ($lane->last_payload_id ?? 0) === $deletedPayloadId) {
                $sth = \losthost\DB\DB::prepare('SELECT id FROM [Payload] WHERE lane_id = ? ORDER BY id DESC LIMIT 1');
                $sth->execute([(int) $lane->id]);
                $lastPayloadId = $sth->fetchColumn();
                $lane->last_payload_id = $lastPayloadId !== false ? (int) $lastPayloadId : null;
            }
            $transaction->write(TransportTransaction::OBJECT_LANE, $lane);
            $transaction->updateLog('payload_deleted', [
                TransportTransaction::OBJECT_PAYLOAD,
                TransportTransaction::OBJECT_PAYLOAD_META,
                TransportTransaction::OBJECT_LANE,
                TransportTransaction::OBJECT_ROUTE,
            ], [
                'payload_id' => $deletedPayloadId,
                'lane_id' => (int) $lane->id,
            ], [
                'route_id' => (int) $route->id,
                'lane_id' => (int) $lane->id,
                'payload_id' => $deletedPayloadId,
            ]);
            $transaction->commit();
        } catch (Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }

        return [
            'ok' => true,
        ];
    }

    private function updatePayloadBody(Agent $actor, string $payloadId): array
    {
        $payload = $this->loadUpdatablePayload($actor, $payloadId);
        $lane = new Lane(['id' => (int) $payload->lane_id]);
        $route = new Route(['id' => (int) $lane->route_id]);
        $binaryPayload = $this->readRawPayloadBody();
        $transaction = $this->beginTransportMutation($actor, [
            TransportTransaction::OBJECT_PAYLOAD,
            TransportTransaction::OBJECT_LANE,
            TransportTransaction::OBJECT_ROUTE,
        ]);
        try {
            $payload->payload = $binaryPayload;
            $payload->payload_sha256 = hash('sha256', $binaryPayload);
            $payload->payload_size = strlen($binaryPayload);
            $transaction->write(TransportTransaction::OBJECT_PAYLOAD, $payload);

            $lane->last_payload_id = (int) $payload->id;
            $transaction->write(TransportTransaction::OBJECT_LANE, $lane);
            $transaction->updateLog('payload_updated', [
                TransportTransaction::OBJECT_PAYLOAD,
                TransportTransaction::OBJECT_LANE,
                TransportTransaction::OBJECT_ROUTE,
            ], [
                'lane_id' => (int) $lane->id,
                'payload_id' => (int) $payload->id,
            ], [
                'route_id' => (int) $route->id,
                'lane_id' => (int) $lane->id,
                'payload_id' => (int) $payload->id,
            ]);
            $transaction->commit();
        } catch (Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }

        return [
            'ok' => true,
            'payload' => $this->serializePayload($payload),
        ];
    }

    private function listPayloadMeta(Agent $actor, string $payloadId): array
    {
        $payload = $this->loadReadablePayload($actor, $payloadId);
        $items = (new DBList(PayloadMeta::class, ['payload_id' => (int) $payload->id]))->asArray();

        return [
            'ok' => true,
            'items' => array_map(
                fn (PayloadMeta $payloadMeta): array => $this->serializePayloadMeta($payloadMeta),
                $items
            ),
        ];
    }

    private function readPayloadBody(Agent $actor, string $payloadId): Payload
    {
        return $this->loadReadablePayload($actor, $payloadId);
    }

    private function createPayloadMeta(Agent $actor, string $payloadId): array
    {
        $payload = $this->loadReadablePayload($actor, $payloadId);
        [$metaKey, $metaValue] = $this->readSinglePayloadMetaEntry();

        $payloadMeta = new PayloadMeta();
        $payloadMeta->payload_id = (int) $payload->id;
        $payloadMeta->agent_id = (int) $actor->id;
        $payloadMeta->meta_key = $metaKey;
        $payloadMeta->meta_value = $metaValue;
        $lane = new Lane(['id' => (int) $payload->lane_id]);
        $route = new Route(['id' => (int) $lane->route_id]);
        $transaction = $this->beginTransportMutation($actor, [
            TransportTransaction::OBJECT_PAYLOAD_META,
            TransportTransaction::OBJECT_PAYLOAD,
        ]);
        try {
            $transaction->write(TransportTransaction::OBJECT_PAYLOAD_META, $payloadMeta);
            $transaction->updateLog('payload_meta_created', [
                TransportTransaction::OBJECT_PAYLOAD_META,
                TransportTransaction::OBJECT_PAYLOAD,
            ], [
                'payload_id' => (int) $payload->id,
                'payload_meta_id' => (int) $payloadMeta->id,
            ], [
                'route_id' => (int) $route->id,
                'lane_id' => (int) $lane->id,
                'payload_id' => (int) $payload->id,
                'payload_meta_id' => (int) $payloadMeta->id,
            ]);
            $transaction->commit();
        } catch (Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }

        return [
            'ok' => true,
            'payload_meta' => $this->serializePayloadMeta($payloadMeta),
        ];
    }

    private function viewPayloadMeta(Agent $actor, string $payloadMetaId): array
    {
        $payloadMeta = $this->loadReadablePayloadMeta($actor, $payloadMetaId);

        return [
            'ok' => true,
            'payload_meta' => $this->serializePayloadMeta($payloadMeta),
        ];
    }

    private function replacePayloadMeta(Agent $actor, string $payloadMetaId): array
    {
        $payloadMeta = $this->loadWritablePayloadMeta($actor, $payloadMetaId);
        [$metaKey, $metaValue] = $this->readSinglePayloadMetaEntry();

        $payload = new Payload(['id' => (int) $payloadMeta->payload_id]);
        $lane = new Lane(['id' => (int) $payload->lane_id]);
        $route = new Route(['id' => (int) $lane->route_id]);
        $payloadMeta->meta_key = $metaKey;
        $payloadMeta->meta_value = $metaValue;
        $transaction = $this->beginTransportMutation($actor, [
            TransportTransaction::OBJECT_PAYLOAD_META,
            TransportTransaction::OBJECT_PAYLOAD,
        ]);
        try {
            $transaction->write(TransportTransaction::OBJECT_PAYLOAD_META, $payloadMeta);
            $transaction->updateLog('payload_meta_updated', [
                TransportTransaction::OBJECT_PAYLOAD_META,
                TransportTransaction::OBJECT_PAYLOAD,
            ], [
                'payload_meta_id' => (int) $payloadMeta->id,
                'payload_id' => (int) $payload->id,
            ], [
                'route_id' => (int) $route->id,
                'lane_id' => (int) $lane->id,
                'payload_id' => (int) $payload->id,
                'payload_meta_id' => (int) $payloadMeta->id,
            ]);
            $transaction->commit();
        } catch (Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }

        return [
            'ok' => true,
            'payload_meta' => $this->serializePayloadMeta($payloadMeta),
        ];
    }

    private function patchPayloadMeta(Agent $actor, string $payloadMetaId): array
    {
        $payloadMeta = $this->loadWritablePayloadMeta($actor, $payloadMetaId);
        [$metaKey, $metaValue] = $this->readSinglePayloadMetaEntry();

        $payload = new Payload(['id' => (int) $payloadMeta->payload_id]);
        $lane = new Lane(['id' => (int) $payload->lane_id]);
        $route = new Route(['id' => (int) $lane->route_id]);
        $payloadMeta->meta_key = $metaKey;
        $payloadMeta->meta_value = $metaValue;
        $transaction = $this->beginTransportMutation($actor, [
            TransportTransaction::OBJECT_PAYLOAD_META,
            TransportTransaction::OBJECT_PAYLOAD,
        ]);
        try {
            $transaction->write(TransportTransaction::OBJECT_PAYLOAD_META, $payloadMeta);
            $transaction->updateLog('payload_meta_updated', [
                TransportTransaction::OBJECT_PAYLOAD_META,
                TransportTransaction::OBJECT_PAYLOAD,
            ], [
                'payload_meta_id' => (int) $payloadMeta->id,
                'payload_id' => (int) $payload->id,
            ], [
                'route_id' => (int) $route->id,
                'lane_id' => (int) $lane->id,
                'payload_id' => (int) $payload->id,
                'payload_meta_id' => (int) $payloadMeta->id,
            ]);
            $transaction->commit();
        } catch (Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }

        return [
            'ok' => true,
            'payload_meta' => $this->serializePayloadMeta($payloadMeta),
        ];
    }

    private function deletePayloadMeta(Agent $actor, string $payloadMetaId): array
    {
        $payloadMeta = $this->loadWritablePayloadMeta($actor, $payloadMetaId);
        $payload = new Payload(['id' => (int) $payloadMeta->payload_id]);
        $lane = new Lane(['id' => (int) $payload->lane_id]);
        $route = new Route(['id' => (int) $lane->route_id]);
        $payloadMetaIdInt = (int) $payloadMeta->id;
        $payloadIdInt = (int) $payload->id;
        $laneIdInt = (int) $lane->id;
        $routeIdInt = (int) $route->id;
        $now = $this->now();
        $transaction = $this->beginTransportMutation($actor, [
            TransportTransaction::OBJECT_PAYLOAD_META,
            TransportTransaction::OBJECT_PAYLOAD,
        ]);
        try {
            $transaction->delete(TransportTransaction::OBJECT_PAYLOAD_META, $payloadMeta);
            $transaction->updateLog('payload_meta_deleted', [
                TransportTransaction::OBJECT_PAYLOAD_META,
                TransportTransaction::OBJECT_PAYLOAD,
            ], [
                'payload_meta_id' => $payloadMetaIdInt,
                'payload_id' => $payloadIdInt,
            ], [
                'route_id' => $routeIdInt,
                'lane_id' => $laneIdInt,
                'payload_id' => $payloadIdInt,
                'payload_meta_id' => $payloadMetaIdInt,
            ]);
            $transaction->commit();
        } catch (Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }

        return [
            'ok' => true,
        ];
    }

    /**
     * @return array{0: Agent, 1: AgentSession}
     */
    private function authenticate(): array
    {
        $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!is_string($authorization) || !preg_match('/^Bearer\\s+(.+)$/i', $authorization, $matches)) {
            $this->error(401, 'Bearer token required');
        }

        $token = trim($matches[1]);
        if ($token === '') {
            $this->error(401, 'Bearer token required');
        }

        $sessionHash = hash('sha256', $token);
        try {
            $session = new AgentSession(['token_hash' => $sessionHash]);
        } catch (Exception $error) {
            $this->error(401, 'Invalid session');
        }

        if ($session->revoked_at !== null) {
            $this->error(401, 'Session revoked');
        }

        $expiresAt = $session->expires_at instanceof \DateTimeInterface
            ? DateTimeImmutable::createFromInterface($session->expires_at)
            : new DateTimeImmutable((string) $session->expires_at);
        if ($expiresAt <= new DateTimeImmutable()) {
            $this->error(401, 'Session expired');
        }

        $actor = new Agent(['id' => (int) $session->agent_id]);
        $now = $this->now();
        $session->last_seen_at = $now;
        $session->expires_at = $now->modify('+180 days');
        $session->updated_at = $now;
        $session->write();

        return [$actor, $session];
    }

    /**
     * @return array{0: AgentSession, 1: string}
     */
    private function createSessionRecord(Agent $actor, ?string $deviceLabel): array
    {
        $now = $this->now();
        $token = 'sess_' . $this->randomToken(48);

        $session = new AgentSession();
        $session->agent_id = (int) $actor->id;
        $session->token_hash = hash('sha256', $token);
        $session->device_label = $deviceLabel;
        $session->created_at = $now;
        $session->updated_at = $now;
        $session->last_seen_at = $now;
        $session->expires_at = $now->modify('+180 days');
        $session->revoked_at = null;
        $session->write();

        return [$session, $token];
    }

    private function loadSessionForAgent(Agent $actor, string $sessionPublicId): AgentSession
    {
        $sessionId = $this->requiredPositiveIntString($sessionPublicId, 'session_id');

        try {
            $session = new AgentSession(['id' => $sessionId]);
        } catch (Exception $error) {
            $this->error(404, 'Session not found');
        }

        if ((int) $session->agent_id !== (int) $actor->id) {
            $this->error(404, 'Session not found');
        }

        return $session;
    }

    private function loadAgentByIdInZone(Agent $requestActor, string $actorId): Agent
    {
        $target = new Agent(['id' => $this->requiredPositiveIntString($actorId, 'agent_id')]);
        if ((string) $target->zone !== (string) $requestActor->zone) {
            $this->error(404, 'Agent not found');
        }

        return $target;
    }

    private function loadRouteForMember(Agent $actor, string $routePublicId): Route
    {
        $routeId = $this->requiredPositiveIntString($routePublicId, 'route_id');

        try {
            $route = new Route(['id' => $routeId]);
        } catch (Exception $error) {
            $this->error(404, 'Route not found');
        }

        if ((string) $route->zone !== (string) $actor->zone) {
            $this->error(404, 'Route not found');
        }

        $membership = new DBList(
            Subscription::class,
            ['route_id' => (int) $route->id, 'agent_id' => (int) $actor->id]
        );

        if (!$membership->next()) {
            $this->error(403, 'Agent is not a route member');
        }

        return $route;
    }

    private function loadRouteForMaxRoleOwner(Agent $actor, string $routePublicId): Route
    {
        $route = $this->loadRouteForMember($actor, $routePublicId);
        if ((int) ($route->owner_agent_id ?? 0) !== (int) $actor->id) {
            $this->error(403, 'Only route owner can perform this action');
        }

        return $route;
    }

    private function loadLaneForMember(Agent $actor, string $lanePublicId): Lane
    {
        $laneId = $this->requiredPositiveIntString($lanePublicId, 'lane_id');

        try {
            $lane = new Lane(['id' => $laneId]);
        } catch (Exception $error) {
            $this->error(404, 'Lane not found');
        }

        $route = new Route(['id' => (int) $lane->route_id]);
        $this->loadRouteForMember($actor, (string) $route->id);

        return $lane;
    }

    private function loadReadablePayload(Agent $actor, string $payloadId): Payload
    {
        $payloadIdInt = $this->requiredPositiveIntString($payloadId, 'payload_id');

        try {
            $payload = new Payload(['id' => $payloadIdInt]);
        } catch (Exception $error) {
            $this->error(404, 'Payload not found');
        }

        $lane = new Lane(['id' => (int) $payload->lane_id]);
        $this->loadLaneForMember($actor, (string) $lane->id);

        return $payload;
    }

    private function loadWritablePayload(Agent $actor, string $payloadId): Payload
    {
        $payload = $this->loadReadablePayload($actor, $payloadId);
        $lane = new Lane(['id' => (int) $payload->lane_id]);
        $route = new Route(['id' => (int) $lane->route_id]);
        $role = $this->routeRoleForAgent($actor, $route);

        if ((int) $payload->author_agent_id === (int) $actor->id) {
            if ($role === Subscription::ROLE_GUEST) {
                $this->error(403, 'Guest cannot delete payload');
            }

            return $payload;
        }

        if (!in_array($role, [Subscription::ROLE_OWNER, Subscription::ROLE_ADMIN, Subscription::ROLE_EDITOR], true)) {
            $this->error(403, 'Agent cannot delete payload owned by another agent');
        }

        return $payload;
    }

    private function loadUpdatablePayload(Agent $actor, string $payloadId): Payload
    {
        $payload = $this->loadReadablePayload($actor, $payloadId);
        $lane = new Lane(['id' => (int) $payload->lane_id]);
        $route = new Route(['id' => (int) $lane->route_id]);
        $role = $this->routeRoleForAgent($actor, $route);

        if ((int) $payload->author_agent_id === (int) $actor->id) {
            if ($role === Subscription::ROLE_GUEST) {
                $this->error(403, 'Guest cannot update payload');
            }

            return $payload;
        }

        if (!in_array($role, [Subscription::ROLE_OWNER, Subscription::ROLE_ADMIN, Subscription::ROLE_EDITOR], true)) {
            $this->error(403, 'Agent cannot update payload owned by another agent');
        }

        return $payload;
    }

    private function loadLaneReadState(Lane $lane, Agent $actor): ?LaneReadState
    {
        $list = new DBList(LaneReadState::class, [
            'lane_id' => (int) $lane->id,
            'agent_id' => (int) $actor->id,
        ]);
        $state = $list->next();

        return $state instanceof LaneReadState ? $state : null;
    }

    private function loadAgentMetaData(Agent $actor): array
    {
        $meta = [];
        foreach ((new DBList(AgentMeta::class, ['agent_id' => (int) $actor->id]))->asArray() as $record) {
            $meta[(string) $record->meta_key] = (string) $record->meta_value;
        }

        return $meta;
    }

    private function loadRouteMetaData(Route $route): array
    {
        $meta = [];
        foreach ((new DBList(RouteMeta::class, ['route_id' => (int) $route->id]))->asArray() as $record) {
            $meta[(string) $record->meta_key] = (string) $record->meta_value;
        }

        return $meta;
    }

    private function loadLaneMetaData(Lane $lane): array
    {
        $meta = [];
        foreach ((new DBList(LaneMeta::class, ['lane_id' => (int) $lane->id]))->asArray() as $record) {
            $meta[(string) $record->meta_key] = (string) $record->meta_value;
        }

        return $meta;
    }

    private function writeAgentMeta(Agent $actor, array $meta, bool $replaceAll): void
    {
        $now = $this->now();
        $transaction = $this->beginTransportMutation($actor, [
            TransportTransaction::OBJECT_AGENT_META,
            TransportTransaction::OBJECT_AGENT,
        ]);
        try {
            $this->syncMetaRecords($transaction, AgentMeta::class, ['agent_id' => (int) $actor->id], 'meta_key', 'meta_value', $meta, $replaceAll, [
                'agent_id' => (int) $actor->id,
                'created_at' => $now,
                'updated_at' => $now,
                'revision' => $now,
            ]);
            $transaction->updateLog('agent_meta_updated', [
                TransportTransaction::OBJECT_AGENT_META,
                TransportTransaction::OBJECT_AGENT,
            ], [
                'agent_id' => (int) $actor->id,
            ]);
            $transaction->commit();
        } catch (Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }
    }

    private function writeRouteMeta(Agent $actor, Route $route, array $meta, bool $replaceAll): void
    {
        $now = $this->now();
        $transaction = $this->beginTransportMutation($actor, [
            TransportTransaction::OBJECT_ROUTE_META,
            TransportTransaction::OBJECT_ROUTE,
        ]);
        try {
            $this->syncMetaRecords($transaction, RouteMeta::class, ['route_id' => (int) $route->id], 'meta_key', 'meta_value', $meta, $replaceAll, [
                'route_id' => (int) $route->id,
                'created_at' => $now,
                'updated_at' => $now,
                'revision' => $now,
            ]);
            $transaction->updateLog('route_meta_updated', [
                TransportTransaction::OBJECT_ROUTE_META,
                TransportTransaction::OBJECT_ROUTE,
            ], [
                'route_id' => (int) $route->id,
            ], [
                'route_id' => (int) $route->id,
            ]);
            $transaction->commit();
        } catch (Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }
    }

    private function writeLaneMeta(Agent $actor, Route $route, Lane $lane, array $meta, bool $replaceAll): void
    {
        $now = $this->now();
        $transaction = $this->beginTransportMutation($actor, [
            TransportTransaction::OBJECT_LANE_META,
            TransportTransaction::OBJECT_LANE,
        ]);
        try {
            $this->syncMetaRecords($transaction, LaneMeta::class, ['lane_id' => (int) $lane->id], 'meta_key', 'meta_value', $meta, $replaceAll, [
                'lane_id' => (int) $lane->id,
                'created_at' => $now,
                'updated_at' => $now,
                'revision' => $now,
            ]);
            $transaction->updateLog('lane_meta_updated', [
                TransportTransaction::OBJECT_LANE_META,
                TransportTransaction::OBJECT_LANE,
            ], [
                'lane_id' => (int) $lane->id,
            ], [
                'route_id' => (int) $route->id,
                'lane_id' => (int) $lane->id,
            ]);
            $transaction->commit();
        } catch (Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }
    }

    private function assertPayloadBelongsToLane(Lane $lane, int $payloadId): void
    {
        try {
            $payload = new Payload(['id' => $payloadId]);
        } catch (Exception $error) {
            $this->error(404, 'Payload not found');
        }

        if ((int) $payload->lane_id !== (int) $lane->id) {
            $this->error(422, 'Payload does not belong to lane');
        }
    }

    /**
     * @return list<int>
     */
    private function laneIdsForRoute(Route $route): array
    {
        $laneIds = [];
        $sth = DB::prepare('SELECT id FROM [Lane] WHERE route_id = ? ORDER BY id ASC');
        $sth->execute([(int) $route->id]);

        while (($laneId = $sth->fetchColumn()) !== false) {
            $laneIds[] = (int) $laneId;
        }

        return $laneIds;
    }

    private function deletePayloadsForLaneId(TransportTransaction $transaction, int $laneId): void
    {
        $payloadIds = [];
        $sth = DB::prepare('SELECT id FROM [Payload] WHERE lane_id = ? ORDER BY id ASC');
        $sth->execute([$laneId]);

        while (($payloadId = $sth->fetchColumn()) !== false) {
            $payloadIds[] = (int) $payloadId;
        }

        foreach ($payloadIds as $payloadId) {
            $this->deletePayloadMetaForPayloadId($transaction, $payloadId);
            $transaction->delete(TransportTransaction::OBJECT_PAYLOAD, new Payload(['id' => $payloadId]));
        }
    }

    private function deleteLaneReadStatesForLaneId(TransportTransaction $transaction, int $laneId): void
    {
        foreach ((new DBList(LaneReadState::class, ['lane_id' => $laneId]))->asArray() as $readState) {
            $transaction->delete(TransportTransaction::OBJECT_LANE_READ_STATE, $readState);
        }
    }

    private function deleteRouteMetaForRouteId(TransportTransaction $transaction, int $routeId): void
    {
        foreach ((new DBList(RouteMeta::class, ['route_id' => $routeId]))->asArray() as $routeMeta) {
            $transaction->delete(TransportTransaction::OBJECT_ROUTE_META, $routeMeta);
        }
    }

    private function deleteLaneMetaForLaneId(TransportTransaction $transaction, int $laneId): void
    {
        foreach ((new DBList(LaneMeta::class, ['lane_id' => $laneId]))->asArray() as $laneMeta) {
            $transaction->delete(TransportTransaction::OBJECT_LANE_META, $laneMeta);
        }
    }

    private function deletePayloadMetaForPayloadId(TransportTransaction $transaction, int $payloadId): void
    {
        foreach ((new DBList(PayloadMeta::class, ['payload_id' => $payloadId]))->asArray() as $payloadMeta) {
            $transaction->delete(TransportTransaction::OBJECT_PAYLOAD_META, $payloadMeta);
        }
    }

    private function loadReadablePayloadMeta(Agent $actor, string $payloadMetaId): PayloadMeta
    {
        $payloadMetaIdInt = $this->requiredPositiveIntString($payloadMetaId, 'payload_meta_id');

        try {
            $payloadMeta = new PayloadMeta(['id' => $payloadMetaIdInt]);
        } catch (Exception $error) {
            $this->error(404, 'Payload meta not found');
        }

        $this->loadReadablePayload($actor, (string) $payloadMeta->payload_id);

        return $payloadMeta;
    }

    private function loadWritablePayloadMeta(Agent $actor, string $payloadMetaId): PayloadMeta
    {
        $payloadMetaIdInt = $this->requiredPositiveIntString($payloadMetaId, 'payload_meta_id');

        try {
            $payloadMeta = new PayloadMeta(['id' => $payloadMetaIdInt]);
        } catch (Exception $error) {
            $this->error(404, 'Payload meta not found');
        }

        $payload = $this->loadReadablePayload($actor, (string) $payloadMeta->payload_id);
        if ((int) $payloadMeta->agent_id === (int) $actor->id) {
            return $payloadMeta;
        }

        $lane = new Lane(['id' => (int) $payload->lane_id]);
        $route = new Route(['id' => (int) $lane->route_id]);
        if (!$this->isRoleAtLeast($this->routeRoleForAgent($actor, $route), Subscription::ROLE_ADMIN)) {
            $this->error(403, 'Agent cannot manage this payload_meta');
        }

        return $payloadMeta;
    }

    private function serializeAgent(Agent $actor, ?array $metaSelector = null): array
    {
        $payload = [
            'agent_id' => (int) $actor->id,
            'zone' => (string) $actor->zone,
            'is_system' => (bool) $actor->is_system,
            'created_at' => $this->formatDateTime($actor->created_at),
            'updated_at' => $this->formatDateTime($actor->updated_at),
            'revision' => $this->formatDateTime($actor->revision),
        ];

        if ($metaSelector !== null) {
            $payload['meta'] = $this->selectMetaFields($this->loadAgentMetaData($actor), $metaSelector);
        }

        return $payload;
    }

    private function serializeSession(AgentSession $session, int $currentSessionId): array
    {
        return [
            'session_id' => (int) $session->id,
            'device_label' => $session->device_label !== null ? (string) $session->device_label : null,
            'is_current' => (int) $session->id === $currentSessionId,
            'created_at' => $this->formatDateTime($session->created_at),
            'updated_at' => $this->formatDateTime($session->updated_at),
            'last_seen_at' => $this->formatDateTime($session->last_seen_at),
            'expires_at' => $this->formatDateTime($session->expires_at),
            'revoked_at' => $session->revoked_at !== null ? $this->formatDateTime($session->revoked_at) : null,
        ];
    }

    private function serializeRoute(Route $route, ?array $metaSelector = null): array
    {
        $defaultLane = $this->findDefaultLane($route);

        $payload = [
            'route_id' => (int) $route->id,
            'zone' => (string) $route->zone,
            'owner_agent_id' => $route->owner_agent_id !== null
                ? (int) $route->owner_agent_id
                : 'system',
            'default_lane_id' => $defaultLane !== null ? (int) $defaultLane->id : null,
            'created_at' => $this->formatDateTime($route->created_at),
            'updated_at' => $this->formatDateTime($route->updated_at),
            'revision' => $this->formatDateTime($route->revision),
        ];

        if ($metaSelector !== null) {
            $payload['meta'] = $this->selectMetaFields($this->loadRouteMetaData($route), $metaSelector);
        }

        return $payload;
    }

    private function serializeLane(Lane $lane, ?Agent $actor = null, ?array $metaSelector = null): array
    {
        $route = new Route(['id' => (int) $lane->route_id]);
        $creator = new Agent(['id' => (int) $lane->created_by_agent_id]);
        $readState = $actor !== null ? $this->loadLaneReadState($lane, $actor) : null;

        $payload = [
            'lane_id' => (int) $lane->id,
            'route_id' => (int) $route->id,
            'is_default' => (bool) $lane->is_default,
            'created_by_agent_id' => (int) $creator->id,
            'payload_count' => (int) $lane->payload_count,
            'last_payload_id' => $lane->last_payload_id !== null ? (int) $lane->last_payload_id : null,
            'read_state' => $readState !== null ? $this->serializeLaneReadState($readState) : null,
            'created_at' => $this->formatDateTime($lane->created_at),
            'updated_at' => $this->formatDateTime($lane->updated_at),
            'revision' => $this->formatDateTime($lane->revision),
        ];

        if ($metaSelector !== null) {
            $payload['meta'] = $this->selectMetaFields($this->loadLaneMetaData($lane), $metaSelector);
        }

        return $payload;
    }

    private function serializeLaneReadState(LaneReadState $readState): array
    {
        return [
            'lane_id' => (int) $readState->lane_id,
            'agent_id' => (int) $readState->agent_id,
            'last_read_payload_id' => $readState->last_read_payload_id !== null
                ? (int) $readState->last_read_payload_id
                : null,
            'created_at' => $this->formatDateTime($readState->created_at),
            'updated_at' => $this->formatDateTime($readState->updated_at),
            'read_at' => $this->formatDateTime($readState->read_at),
            'revision' => $this->formatDateTime($readState->revision),
        ];
    }

    private function createDefaultLane(
        TransportTransaction $transaction,
        Route $route,
        Agent $actor,
    ): Lane
    {
        return $this->createLaneRecord($transaction, $route, $actor, true);
    }

    private function createLaneRecord(
        TransportTransaction $transaction,
        Route $route,
        Agent $actor,
        bool $isDefault,
    ): Lane {
        $lane = new Lane();
        $lane->route_id = (int) $route->id;
        $lane->is_default = $isDefault;
        $lane->created_by_agent_id = (int) $actor->id;
        $lane->payload_count = 0;
        $lane->last_payload_id = null;
        $transaction->write(TransportTransaction::OBJECT_LANE, $lane);

        return $lane;
    }

    /**
     * @param list<string> $allowedKinds
     */
    private function beginTransportMutation(Agent $actor, array $allowedKinds): TransportTransaction
    {
        return TransportTransaction::begin((string) $actor->zone, (int) $actor->id, $allowedKinds);
    }

    private function findDefaultLane(Route $route): ?Lane
    {
        $list = new DBList(Lane::class, ['route_id' => (int) $route->id, 'is_default' => true]);
        $lane = $list->next();

        return $lane instanceof Lane ? $lane : null;
    }

    private function assertCanManageMembers(Agent $actor, Route $route): void
    {
        if (!$this->isRoleAtLeast($this->routeRoleForAgent($actor, $route), Subscription::ROLE_ADMIN)) {
            $this->error(403, 'Agent cannot manage route members');
        }
    }

    private function assertHasMaxRouteRole(Agent $actor, Route $route): void
    {
        if (!$this->agentHasMaxRouteRole($actor, $route)) {
            $this->error(403, 'Agent does not have maximal route role');
        }
    }

    private function assertCanCreateLane(Agent $actor, Route $route): void
    {
        if (!$this->isRoleAtLeast($this->routeRoleForAgent($actor, $route), Subscription::ROLE_ADMIN)) {
            $this->error(403, 'Agent cannot create lanes in this route');
        }
    }

    private function assertCanCreatePayload(Agent $actor, Route $route): void
    {
        if (!$this->isRoleAtLeast($this->routeRoleForAgent($actor, $route), Subscription::ROLE_PUBLISHER)) {
            $this->error(403, 'Agent cannot post messages in this route');
        }
    }

    private function assertCanManageRouteMeta(Agent $actor, Route $route): void
    {
        if (!$this->isRoleAtLeast($this->routeRoleForAgent($actor, $route), Subscription::ROLE_ADMIN)) {
            $this->error(403, 'Agent cannot update route meta');
        }
    }

    private function assertCanManageLaneMeta(Agent $actor, Route $route): void
    {
        if (!$this->isRoleAtLeast($this->routeRoleForAgent($actor, $route), Subscription::ROLE_ADMIN)) {
            $this->error(403, 'Agent cannot update lane meta');
        }
    }

    private function routeRoleForAgent(Agent $actor, Route $route): string
    {
        $membership = new DBList(
            Subscription::class,
            ['route_id' => (int) $route->id, 'agent_id' => (int) $actor->id]
        );
        $member = $membership->next();
        if (!$member instanceof Subscription) {
            $this->error(403, 'Agent is not a route member');
        }

        return (string) $member->role;
    }

    private function isRoleAtLeast(string $actualRole, string $requiredRole): bool
    {
        $weights = [
            Subscription::ROLE_GUEST => 10,
            Subscription::ROLE_PUBLISHER => 20,
            Subscription::ROLE_EDITOR => 30,
            Subscription::ROLE_ADMIN => 40,
            Subscription::ROLE_OWNER => 50,
        ];

        return ($weights[$actualRole] ?? 0) >= ($weights[$requiredRole] ?? PHP_INT_MAX);
    }

    private function routeRoles(): array
    {
        return [
            Subscription::ROLE_OWNER,
            Subscription::ROLE_ADMIN,
            Subscription::ROLE_EDITOR,
            Subscription::ROLE_PUBLISHER,
            Subscription::ROLE_GUEST,
        ];
    }

    private function agentHasMaxRouteRole(Agent $actor, Route $route): bool
    {
        $actorWeight = $this->roleWeight($this->routeRoleForAgent($actor, $route));
        $maxWeight = 0;

        foreach ((new DBList(Subscription::class, ['route_id' => (int) $route->id]))->asArray() as $subscription) {
            $maxWeight = max($maxWeight, $this->roleWeight((string) $subscription->role));
        }

        return $actorWeight === $maxWeight;
    }

    private function roleWeight(string $role): int
    {
        $weights = [
            Subscription::ROLE_GUEST => 10,
            Subscription::ROLE_PUBLISHER => 20,
            Subscription::ROLE_EDITOR => 30,
            Subscription::ROLE_ADMIN => 40,
            Subscription::ROLE_OWNER => 50,
        ];

        return $weights[$role] ?? 0;
    }

    private function readMetaFromRequest(): array
    {
        $payload = $this->readJsonBody();
        return $this->extractMeta($payload);
    }

    private function readRequiredMetaFromRequest(): array
    {
        $meta = $this->readMetaFromRequest();
        if ($meta === []) {
            $this->error(422, 'meta is required');
        }

        return $meta;
    }

    private function extractMeta(array $payload): array
    {
        $meta = $payload['meta'] ?? [];
        if (!is_array($meta)) {
            $this->error(422, 'meta must be a JSON object');
        }

        $normalized = [];
        foreach ($meta as $key => $value) {
            if (!is_string($key) || trim($key) === '') {
                $this->error(422, 'meta keys must be non-empty strings');
            }

            if (!is_string($value)) {
                $this->error(422, 'meta values must be strings');
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    private function encodeMeta(array $meta): ?string
    {
        if ($meta === []) {
            return null;
        }

        $encoded = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            $this->error(422, 'meta cannot be encoded');
        }

        return $encoded;
    }

    private function decodeMeta(?string $metaJson): array
    {
        if ($metaJson === null || trim($metaJson) === '') {
            return [];
        }

        $decoded = json_decode($metaJson, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function readMetaSelector(bool $defaultAll = false): ?array
    {
        $raw = $_GET['meta'] ?? null;
        if ($raw === null) {
            return $defaultAll ? [] : null;
        }

        if (is_string($raw)) {
            $raw = trim($raw);
            if ($raw === '') {
                return $defaultAll ? [] : null;
            }
            if (strtolower($raw) === 'all') {
                return [];
            }

            $parts = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $value): bool => $value !== ''));
            return array_values(array_unique($parts));
        }

        if (is_array($raw)) {
            $parts = [];
            foreach ($raw as $item) {
                if (!is_string($item)) {
                    continue;
                }
                $item = trim($item);
                if ($item === '') {
                    continue;
                }
                if (strtolower($item) === 'all') {
                    return [];
                }
                $parts[] = $item;
            }

            return array_values(array_unique($parts));
        }

        return $defaultAll ? [] : null;
    }

    private function readMetaKeysSelector(): array
    {
        $raw = $_GET['keys'] ?? null;
        if ($raw === null) {
            return [];
        }

        if (is_string($raw)) {
            $raw = trim($raw);
            if ($raw === '' || strtolower($raw) === 'all') {
                return [];
            }

            $parts = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $value): bool => $value !== ''));
            return array_values(array_unique($parts));
        }

        if (is_array($raw)) {
            $parts = [];
            foreach ($raw as $item) {
                if (!is_string($item)) {
                    continue;
                }

                $item = trim($item);
                if ($item === '') {
                    continue;
                }
                if (strtolower($item) === 'all') {
                    return [];
                }

                $parts[] = $item;
            }

            return array_values(array_unique($parts));
        }

        $this->error(422, 'keys must be a string or array');
    }

    private function selectMetaFields(array $meta, array $selector): array
    {
        if ($selector === []) {
            return $meta;
        }

        $selected = [];
        foreach ($selector as $key) {
            if (array_key_exists($key, $meta)) {
                $selected[$key] = $meta[$key];
            }
        }

        return $selected;
    }

    private function assertMetaExists(array $meta, string $message): void
    {
        if ($meta === []) {
            $this->error(404, $message);
        }
    }

    /**
     * @param class-string<object> $className
     * @param array<string, mixed> $identity
     * @param array<string, string> $meta
     * @param array<string, mixed> $baseValues
     */
    private function syncMetaRecords(
        TransportTransaction $transaction,
        string $className,
        array $identity,
        string $keyField,
        string $valueField,
        array $meta,
        bool $replaceAll,
        array $baseValues,
    ): void {
        $records = (new DBList($className, $identity))->asArray();
        $existing = [];
        foreach ($records as $record) {
            $existing[(string) $record->{$keyField}] = $record;
        }

        foreach ($meta as $key => $value) {
            if (!is_string($key) || trim($key) === '') {
                $this->error(422, 'meta keys must be non-empty strings');
            }

            $isNew = !isset($existing[$key]);
            $record = $existing[$key] ?? new $className();
            foreach ($baseValues as $field => $fieldValue) {
                if ($field === 'created_at' && !$isNew) {
                    continue;
                }
                $record->{$field} = $fieldValue;
            }
            $record->{$keyField} = $key;
            $record->{$valueField} = $value;
            $transaction->write($this->metaObjectKindForClass($className), $record);
            unset($existing[$key]);
        }

        if ($replaceAll) {
            foreach ($existing as $record) {
                $transaction->delete($this->metaObjectKindForClass($className), $record);
            }
        }
    }

    private function metaObjectKindForClass(string $className): string
    {
        return match ($className) {
            AgentMeta::class => TransportTransaction::OBJECT_AGENT_META,
            RouteMeta::class => TransportTransaction::OBJECT_ROUTE_META,
            LaneMeta::class => TransportTransaction::OBJECT_LANE_META,
            default => throw new Exception('Unsupported meta class'),
        };
    }

    private function optionalStringField(array $payload, string $field, int $maxLength, mixed $currentValue): ?string
    {
        if (!array_key_exists($field, $payload)) {
            return is_string($currentValue) ? $currentValue : null;
        }

        $value = $payload[$field];
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            $this->error(422, sprintf('%s must be a string or null', $field));
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (mb_strlen($value, 'UTF-8') > $maxLength) {
            $this->error(422, sprintf('%s is too long', $field));
        }

        return $value;
    }

    private function requiredPositiveIntString(string $value, string $field): int
    {
        if (!preg_match('/^[1-9][0-9]*$/', $value)) {
            $this->error(422, sprintf('%s must be a positive integer', $field));
        }

        return (int) $value;
    }

    private function requiredNullablePositiveIntField(array $payload, string $field): ?int
    {
        if (!array_key_exists($field, $payload) || $payload[$field] === null) {
            return null;
        }

        $value = $payload[$field];
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value)) {
            return (int) $value;
        }

        $this->error(422, sprintf('%s must be a positive integer or null', $field));
    }

    private function serializePayload(Payload $payload): array
    {
        $author = new Agent(['id' => (int) $payload->author_agent_id]);
        $lane = new Lane(['id' => (int) $payload->lane_id]);

        return [
            'payload_id' => (int) $payload->id,
            'lane_id' => (int) $lane->id,
            'author_agent_id' => (int) $author->id,
            'payload_sha256' => (string) $payload->payload_sha256,
            'payload_size' => (int) $payload->payload_size,
            'created_at' => $this->formatDateTime($payload->created_at),
            'updated_at' => $this->formatDateTime($payload->updated_at),
            'revision' => $this->formatDateTime($payload->revision),
        ];
    }

    private function serializePayloadMeta(PayloadMeta $payloadMeta): array
    {
        $author = new Agent(['id' => (int) $payloadMeta->agent_id]);

        return [
            'payload_meta_id' => (int) $payloadMeta->id,
            'payload_id' => (int) $payloadMeta->payload_id,
            'agent_id' => (int) $author->id,
            'meta' => [
                (string) $payloadMeta->meta_key => (string) $payloadMeta->meta_value,
            ],
            'created_at' => $this->formatDateTime($payloadMeta->created_at),
            'updated_at' => $this->formatDateTime($payloadMeta->updated_at),
            'revision' => $this->formatDateTime($payloadMeta->revision),
        ];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function readSinglePayloadMetaEntry(): array
    {
        $meta = $this->readMetaFromRequest();
        if ($meta === []) {
            $this->error(422, 'meta is required');
        }
        if (count($meta) !== 1) {
            $this->error(422, 'payload meta record must contain exactly one meta entry');
        }

        $metaKey = (string) array_key_first($meta);

        return [$metaKey, (string) $meta[$metaKey]];
    }

    private function serializeUpdate(UpdateLog $update): array
    {
        return [
            'update_id' => (int) $update->id,
            'zone' => (string) $update->zone,
            'agent_id' => $update->agent_id !== null ? (int) $update->agent_id : null,
            'kind' => (string) $update->kind,
            'route_id' => $update->route_id !== null ? (int) $update->route_id : null,
            'lane_id' => $update->lane_id !== null ? (int) $update->lane_id : null,
            'payload_id' => $update->payload_id !== null ? (int) $update->payload_id : null,
            'payload_meta_id' => $update->payload_meta_id !== null ? (int) $update->payload_meta_id : null,
            'covered' => $this->decodeMeta($update->covered_json),
            'data' => $this->decodeMeta($update->data_json),
            'created_at' => $this->formatDateTime($update->created_at),
        ];
    }

    private function buildStatePatch(Agent $actor, array $requestState): array
    {
        $state = $this->normalizeStatePatchRequest($requestState);
        $knownRouteIds = $state['known_route_ids'];
        $knownLaneIds = $state['known_lane_ids'];
        $lanePayloadAfter = $state['lane_payload_after'];

        $currentRoutes = $this->listRoutes($actor);
        $currentRouteIds = array_map(
            static fn (array $route): int => (int) $route['route_id'],
            $currentRoutes
        );

        $routeDeleteIds = array_values(array_diff($knownRouteIds, $currentRouteIds));

        $lanes = [];
        foreach ($currentRouteIds as $routeId) {
            foreach ((new DBList(Lane::class, ['route_id' => $routeId]))->asArray() as $lane) {
                $lanes[] = $this->serializeLane($lane, $actor);
            }
        }

        $currentLaneIds = array_map(
            static fn (array $lane): int => (int) $lane['lane_id'],
            $lanes
        );
        $laneDeleteIds = array_values(array_diff($knownLaneIds, $currentLaneIds));

        $payloadsByLane = [];
        foreach ($lanePayloadAfter as $laneId => $afterId) {
            try {
                $lane = $this->loadLaneForMember($actor, (string) $laneId);
            } catch (HttpException) {
                continue;
            }

            $payloadsByLane[(string) $laneId] = $this->listPayloadsAfter($lane, $afterId, 200);
        }

        return [
            'ok' => true,
            'after_update_id' => $state['after_update_id'],
            'latest_update_id' => $this->maxZoneUpdateId((string) $actor->zone),
            'routes_upsert' => $currentRoutes,
            'route_delete_ids' => $routeDeleteIds,
            'lanes_upsert' => $lanes,
            'lane_delete_ids' => $laneDeleteIds,
            'payloads_by_lane' => $payloadsByLane,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listPayloadsAfter(Lane $lane, int $afterId, int $limit): array
    {
        $sql = sprintf(
            'SELECT id FROM [Payload] WHERE lane_id = ? AND id > ? ORDER BY id ASC LIMIT %d',
            $limit
        );
        $sth = DB::prepare($sql);
        $sth->execute([(int) $lane->id, $afterId]);

        $items = [];
        while (($payloadId = $sth->fetchColumn()) !== false) {
            $items[] = $this->serializePayload(new Payload(['id' => (int) $payloadId]));
        }

        return $items;
    }

    private function maxZoneUpdateId(string $zone): int
    {
        $sth = DB::prepare('SELECT COALESCE(MAX(id), 0) FROM [UpdateLog] WHERE zone = ?');
        $sth->execute([$zone]);
        $value = $sth->fetchColumn();

        return is_numeric($value) ? (int) $value : 0;
    }

    private function readJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->error(400, 'Request body must be valid JSON object');
        }

        return $decoded;
    }

    private function readRawPayloadBody(): string
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (!is_string($contentType) || stripos($contentType, 'application/octet-stream') !== 0) {
            $this->error(415, 'Content-Type must be application/octet-stream');
        }

        $raw = file_get_contents('php://input');
        if (!is_string($raw) || $raw === '') {
            $this->error(422, 'Payload body is required');
        }

        return $raw;
    }

    private function randomToken(int $length): string
    {
        return substr(bin2hex(random_bytes($length)), 0, $length);
    }

    private function zoneHasAgents(string $zone): bool
    {
        $sth = DB::prepare('SELECT id FROM [Agent] WHERE zone = ? LIMIT 1');
        $sth->execute([$zone]);

        return $sth->fetchColumn() !== false;
    }

    private function markAgentOnline(Agent $actor): void
    {
        $presence = $this->loadAgentPresence($actor);
        if ($presence === null) {
            $transaction = TransportTransaction::begin(
                (string) $actor->zone,
                (int) $actor->id,
                [TransportTransaction::OBJECT_PRESENCE]
            );
            try {
                $presence = new AgentPresence();
                $presence->agent_id = (int) $actor->id;
                $presence->connection_count = 1;
                $transaction->write(TransportTransaction::OBJECT_PRESENCE, $presence);
                $transaction->updateLog('agent_online', [TransportTransaction::OBJECT_PRESENCE], [
                    'agent_id' => (int) $actor->id,
                ]);
                $transaction->commit();
            } catch (Throwable $error) {
                $transaction->rollBack();
                throw $error;
            }

            return;
        }

        $this->updateAgentPresenceCount($actor, $presence, (int) $presence->connection_count + 1);
    }

    private function markAgentOffline(Agent $actor): void
    {
        $presence = $this->loadAgentPresence($actor);
        if ($presence === null) {
            return;
        }

        $remaining = max(0, (int) $presence->connection_count - 1);
        if ($remaining > 0) {
            $this->updateAgentPresenceCount($actor, $presence, $remaining);
            return;
        }

        $transaction = TransportTransaction::begin(
            (string) $actor->zone,
            (int) $actor->id,
            [TransportTransaction::OBJECT_PRESENCE]
        );
        try {
            $transaction->delete(TransportTransaction::OBJECT_PRESENCE, $presence);
            $transaction->updateLog('agent_offline', [TransportTransaction::OBJECT_PRESENCE], [
                'agent_id' => (int) $actor->id,
            ]);
            $transaction->commit();
        } catch (Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }
    }

    private function updateAgentPresenceCount(Agent $actor, AgentPresence $presence, int $connectionCount): void
    {
        $transaction = TransportTransaction::begin(
            (string) $actor->zone,
            (int) $actor->id,
            [TransportTransaction::OBJECT_PRESENCE]
        );
        try {
            $presence->connection_count = $connectionCount;
            $transaction->write(TransportTransaction::OBJECT_PRESENCE, $presence);
            $transaction->updateLog('agent_presence_updated', [TransportTransaction::OBJECT_PRESENCE], [
                'agent_id' => (int) $actor->id,
                'connection_count' => $connectionCount,
            ]);
            $transaction->commit();
        } catch (Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }
    }

    private function loadAgentPresence(Agent $actor): ?AgentPresence
    {
        $list = new DBList(AgentPresence::class, ['agent_id' => (int) $actor->id]);
        $presence = $list->next();

        return $presence instanceof AgentPresence ? $presence : null;
    }

    private function readStatePatchHeader(): array
    {
        $raw = $_SERVER['HTTP_X_STATE_PATCH'] ?? '';
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function requiredUuidField(array $payload, string $field): string
    {
        $value = $payload[$field] ?? null;
        if (!is_string($value)) {
            $this->error(422, sprintf('%s is required', $field));
        }

        $value = trim($value);
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value)) {
            $this->error(422, sprintf('%s must be a UUID', $field));
        }

        return strtolower($value);
    }

    private function normalizeStatePatchRequest(array $request): array
    {
        return [
            'after_update_id' => max(0, (int) ($request['after_update_id'] ?? 0)),
            'known_route_ids' => $this->readPositiveIdList($request['known_route_ids'] ?? []),
            'known_lane_ids' => $this->readPositiveIdList($request['known_lane_ids'] ?? []),
            'lane_payload_after' => $this->readLanePayloadAfterMap($request['lane_payload_after'] ?? []),
        ];
    }

    private function advanceStatePatchState(array $state, array $patch): array
    {
        $state['after_update_id'] = max(0, (int) ($patch['latest_update_id'] ?? 0));
        $state['known_route_ids'] = array_map(
            static fn (array $route): int => (int) $route['route_id'],
            $patch['routes_upsert'] ?? []
        );
        $state['known_lane_ids'] = array_map(
            static fn (array $lane): int => (int) $lane['lane_id'],
            $patch['lanes_upsert'] ?? []
        );

        $lanePayloadAfter = $state['lane_payload_after'] ?? [];
        foreach ($patch['lanes_upsert'] ?? [] as $lane) {
            $laneId = (int) $lane['lane_id'];
            if (!array_key_exists($laneId, $lanePayloadAfter)) {
                $lanePayloadAfter[$laneId] = 0;
            }
        }
        foreach ($patch['payloads_by_lane'] ?? [] as $laneId => $payloads) {
            foreach ($payloads as $payload) {
                $lanePayloadAfter[(int) $laneId] = max(
                    (int) ($lanePayloadAfter[(int) $laneId] ?? 0),
                    (int) ($payload['payload_id'] ?? 0)
                );
            }
        }
        $state['lane_payload_after'] = $lanePayloadAfter;

        return $state;
    }

    private function sendSseEvent(string $event, array $payload): void
    {
        echo 'event: ' . $event . "\n";
        echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        @ob_flush();
        @flush();
    }

    private function sendSseComment(string $comment): void
    {
        echo ': ' . $comment . "\n\n";
        @ob_flush();
        @flush();
    }

    /**
     * @param mixed $value
     * @return list<int>
     */
    private function readPositiveIdList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            if (is_int($item) && $item > 0) {
                $result[] = $item;
                continue;
            }

            if (is_string($item) && preg_match('/^[1-9][0-9]*$/', $item)) {
                $result[] = (int) $item;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * @param mixed $value
     * @return array<int, int>
     */
    private function readLanePayloadAfterMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $laneId => $afterId) {
            if (!is_string($laneId) && !is_int($laneId)) {
                continue;
            }

            $laneIdString = trim((string) $laneId);
            if (!preg_match('/^[1-9][0-9]*$/', $laneIdString)) {
                continue;
            }

            $after = 0;
            if (is_int($afterId) && $afterId >= 0) {
                $after = $afterId;
            } elseif (is_string($afterId) && preg_match('/^[0-9]+$/', $afterId)) {
                $after = (int) $afterId;
            }

            $result[(int) $laneIdString] = $after;
        }

        return $result;
    }

    private function formatDateTime(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s.u');
        }

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return '';
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }

    private function respond(array $payload, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function respondBinary(Payload $payload, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . (string) $payload->payload_size);
        header('ETag: "' . (string) $payload->payload_sha256 . '"');
        echo (string) $payload->payload;
    }

    private function error(int $statusCode, string $payload): never
    {
        throw new HttpException($statusCode, $payload);
    }
}
