<?php

declare(strict_types=1);

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
use losthost\DB\DB;

require __DIR__ . '/../src/bootstrap.php';

final class AcceptanceRunner
{
    private string $projectRoot;
    private string $baseUrl = '';
    /** @var resource|null */
    private $serverProcess = null;
    /** @var array<int, resource>|null */
    private ?array $serverPipes = null;
    private string $serverLogPath = '';
    private int $step = 0;
    private int $assertions = 0;
    private int $port = 0;

    /** @var array<string, mixed> */
    private array $context = [
        'zones' => [],
        'agents' => [],
        'passwords' => [],
        'tokens' => [],
        'sessions' => [],
        'routes' => [],
        'lanes' => [],
        'payloads' => [],
        'payload_meta' => [],
        'updates_after' => [],
    ];

    public function __construct()
    {
        $this->projectRoot = dirname(__DIR__);
    }

    public function run(): void
    {
        $startedAt = microtime(true);
        $this->resetSchema();
        $this->startServer();

        try {
            $this->scenarioHealth();
            $this->scenarioRegistration();
            $this->scenarioAuthenticationAndSessions();
            $this->scenarioAgentMeta();
            $this->scenarioRoutesAndSubscriptions();
            $this->scenarioRouteMeta();
            $this->scenarioLanesAndLaneMeta();
            $this->scenarioPayloadsAndReadState();
            $this->scenarioPayloadMeta();
            $this->scenarioUpdates();
            $this->scenarioIdealLazySyncContract();
            $this->scenarioDestructiveOperations();
        } finally {
            $this->stopServer();
        }

        $elapsed = microtime(true) - $startedAt;
        $this->out(sprintf('DONE %d assertions in %.2fs', $this->assertions, $elapsed));
    }

    private function scenarioHealth(): void
    {
        $this->step('Health');
        $response = $this->json('GET', '/health');
        $this->assertStatus($response, 200);
        $this->assertTrue(($response['json']['ok'] ?? false) === true, 'health ok');

        $docs = $this->raw('GET', '/docs');
        $this->assertStatus($docs, 200);
        $this->assertTrue($this->responseHeaderContains($docs, 'Content-Type:', 'text/markdown; charset=utf-8'), 'docs content type');
        $this->assertTrue(str_contains($docs['body'], '# cakk-transport API'), 'docs body title');

        $unauthorized = $this->json('GET', '/me');
        $this->assertStatus($unauthorized, 401);
        $this->assertSame('Bearer token required', $unauthorized['json']['error'] ?? null, 'missing bearer');
    }

    private function scenarioRegistration(): void
    {
        $this->step('Registration');

        $zone1 = '11111111-1111-4111-8111-111111111111';
        $zone2 = '22222222-2222-4222-8222-222222222222';
        $this->context['zones']['z1'] = $zone1;
        $this->context['zones']['z2'] = $zone2;

        $missingZoneUser = $this->json('POST', '/register', [
            'zone' => $zone1,
        ]);
        $this->assertStatus($missingZoneUser, 404);
        $this->assertSame('Zone not found', $missingZoneUser['json']['error'] ?? null, 'user cannot bootstrap zone');

        $invalidZone = $this->json('POST', '/register', [
            'zone' => 'not-a-uuid',
        ]);
        $this->assertStatus($invalidZone, 422);
        $this->assertSame('zone must be a UUID', $invalidZone['json']['error'] ?? null, 'invalid zone uuid');

        $sys1 = $this->json('POST', '/register', [
            'zone' => $zone1,
            'is_system' => true,
            'device_label' => 'sys1-main',
        ]);
        $this->assertStatus($sys1, 200);
        $this->rememberAgent('sys1', $sys1);
        $this->assertTrue(($sys1['json']['agent']['is_system'] ?? false) === true, 'system flag set');

        $duplicateSystem = $this->json('POST', '/register', [
            'zone' => $zone1,
            'is_system' => true,
        ]);
        $this->assertStatus($duplicateSystem, 409);
        $this->assertSame('System agent can only be the first agent in zone', $duplicateSystem['json']['error'] ?? null, 'duplicate system rejected');

        foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $label) {
            $user = $this->json('POST', '/register', [
                'zone' => $zone1,
                'device_label' => 'device-' . $label,
            ]);
            $this->assertStatus($user, 200);
            $this->rememberAgent($label, $user);
            $this->assertTrue(($user['json']['agent']['is_system'] ?? true) === false, 'regular agent is not system');
        }

        $sys2 = $this->json('POST', '/register', [
            'zone' => $zone2,
            'is_system' => true,
            'device_label' => 'sys2-main',
        ]);
        $this->assertStatus($sys2, 200);
        $this->rememberAgent('sys2', $sys2);

        $z2user = $this->json('POST', '/register', [
            'zone' => $zone2,
            'device_label' => 'z2-user',
        ]);
        $this->assertStatus($z2user, 200);
        $this->rememberAgent('z2user', $z2user);
    }

    private function scenarioAuthenticationAndSessions(): void
    {
        $this->step('Authentication and sessions');

        $wrongPassword = $this->json('POST', '/login', [
            'agent_id' => (string) $this->agentId('a'),
            'password' => 'wrong-password',
        ]);
        $this->assertStatus($wrongPassword, 401);
        $this->assertSame('Invalid credentials', $wrongPassword['json']['error'] ?? null, 'wrong password rejected');

        $badActorId = $this->json('POST', '/login', [
            'agent_id' => 'abc',
            'password' => 'whatever',
        ]);
        $this->assertStatus($badActorId, 422);
        $this->assertSame('agent_id must be a positive integer', $badActorId['json']['error'] ?? null, 'agent_id validation');

        $missingActor = $this->json('POST', '/login', [
            'agent_id' => '999999',
            'password' => 'whatever',
        ]);
        $this->assertStatus($missingActor, 401);
        $this->assertSame('Invalid credentials', $missingActor['json']['error'] ?? null, 'missing agent rejected');

        $aSecond = $this->json('POST', '/login', [
            'agent_id' => (string) $this->agentId('a'),
            'password' => $this->password('a'),
            'device_label' => 'a-second',
        ]);
        $this->assertStatus($aSecond, 200);
        $this->assertTrue(array_key_exists('revoked_at', $aSecond['json']['session'] ?? []), 'session includes revoked_at');
        $this->assertSame(null, $aSecond['json']['session']['revoked_at'], 'active session revoked_at is null');
        $this->context['tokens']['a2'] = (string) $aSecond['json']['session_token'];
        $this->context['sessions']['a2'] = (int) $aSecond['json']['session']['session_id'];

        $sessions = $this->json('GET', '/sessions', null, $this->token('a'));
        $this->assertStatus($sessions, 200);
        $this->assertTrue(count($sessions['json']['items'] ?? []) >= 2, 'two sessions listed');

        $deleteBadSessionId = $this->json('DELETE', '/sessions/not-a-number', null, $this->token('a'));
        $this->assertStatus($deleteBadSessionId, 422);
        $this->assertSame('session_id must be a positive integer', $deleteBadSessionId['json']['error'] ?? null, 'invalid session id rejected');

        $deleteForeignSession = $this->json(
            'DELETE',
            '/sessions/' . $this->context['sessions']['b'],
            null,
            $this->token('a')
        );
        $this->assertStatus($deleteForeignSession, 404);
        $this->assertSame('Session not found', $deleteForeignSession['json']['error'] ?? null, 'cannot revoke foreign session');

        $revokeOwn = $this->json(
            'DELETE',
            '/sessions/' . $this->context['sessions']['a2'],
            null,
            $this->token('a')
        );
        $this->assertStatus($revokeOwn, 200);
        $revokedUse = $this->json('GET', '/me', null, $this->token('a2'));
        $this->assertStatus($revokedUse, 401);
        $this->assertSame('Session revoked', $revokedUse['json']['error'] ?? null, 'revoked token blocked');
    }

    private function scenarioAgentMeta(): void
    {
        $this->step('Agent meta');

        $me = $this->json('GET', '/me', null, $this->token('a'));
        $this->assertStatus($me, 200);
        $this->assertSame($this->agentId('a'), $me['json']['agent']['agent_id'] ?? null, 'me returns current agent');

        $meAllMissing = $this->json('GET', '/me?meta=all', null, $this->token('a'));
        $this->assertStatus($meAllMissing, 200);
        $this->assertEmbeddedMetaObject($meAllMissing, '"agent":{"agent_id"', 'me meta selector returns empty object');

        $missing = $this->json('GET', '/me/meta', null, $this->token('a'));
        $this->assertStatus($missing, 200);
        $this->assertEmptyMetaObjectResponse($missing, 'missing agent meta returns empty object');

        $badValue = $this->json('POST', '/me/meta', [
            'meta' => ['title' => true],
        ], $this->token('a'));
        $this->assertStatus($badValue, 422);
        $this->assertSame('meta values must be strings', $badValue['json']['error'] ?? null, 'meta values must be strings');

        $badKey = $this->json('POST', '/me/meta', [
            'meta' => ['' => 'x'],
        ], $this->token('a'));
        $this->assertStatus($badKey, 422);
        $this->assertSame('meta keys must be non-empty strings', $badKey['json']['error'] ?? null, 'meta keys validation');

        $create = $this->json('POST', '/me/meta', [
            'meta' => ['title' => 'Alice', 'avatar' => 'alice.png', 'note' => 'hello', 'phone' => '123456'],
            'meta_options' => ['phone' => ['is_private' => true]],
        ], $this->token('a'));
        $this->assertStatus($create, 200);
        $this->assertSame('Alice', $create['json']['meta']['title'] ?? null, 'agent meta created');

        $duplicate = $this->json('POST', '/me/meta', [
            'meta' => ['title' => 'Again'],
        ], $this->token('a'));
        $this->assertStatus($duplicate, 409);
        $this->assertSame('Agent meta already exists', $duplicate['json']['error'] ?? null, 'duplicate agent meta rejected');

        $subset = $this->json('GET', '/me?meta=title,avatar', null, $this->token('a'));
        $this->assertStatus($subset, 200);
        $this->assertSame(['title' => 'Alice', 'avatar' => 'alice.png'], $subset['json']['agent']['meta'] ?? null, 'meta selector on me');

        $selfAll = $this->json('GET', '/me?meta=all', null, $this->token('a'));
        $this->assertStatus($selfAll, 200);
        $this->assertSame('123456', $selfAll['json']['agent']['meta']['phone'] ?? null, 'self sees private agent meta');

        $replace = $this->json('PUT', '/me/meta', [
            'meta' => ['title' => 'Alice 2', 'status' => 'online', 'phone' => '123456'],
            'meta_options' => ['phone' => ['is_private' => true]],
        ], $this->token('a'));
        $this->assertStatus($replace, 200);
        $this->assertSame(['title' => 'Alice 2', 'status' => 'online', 'phone' => '123456'], $replace['json']['meta'] ?? null, 'agent meta replaced');

        $patch = $this->json('PATCH', '/me/meta', [
            'meta' => ['avatar' => 'alice-2.png', 'phone' => '123999'],
        ], $this->token('a'));
        $this->assertStatus($patch, 200);
        $this->assertSame('alice-2.png', $patch['json']['meta']['avatar'] ?? null, 'agent meta patched');
        $this->assertSame('123999', $patch['json']['meta']['phone'] ?? null, 'patch updates private key without changing visibility');

        $putPrivateWithoutFlag = $this->json('PUT', '/me/meta', [
            'meta' => ['title' => 'Alice 3', 'phone' => '777777'],
        ], $this->token('a'));
        $this->assertStatus($putPrivateWithoutFlag, 422);
        $this->assertSame('PUT requires explicit is_private for existing private key phone', $putPrivateWithoutFlag['json']['error'] ?? null, 'put rejects silent replacement of private key');

        $deleteKeys = $this->json('DELETE', '/me/meta?keys=avatar', null, $this->token('a'));
        $this->assertStatus($deleteKeys, 200);
        $this->assertTrue(!array_key_exists('avatar', $deleteKeys['json']['meta'] ?? []), 'agent meta key deleted');

        $deleteAll = $this->json('DELETE', '/me/meta', null, $this->token('a'));
        $this->assertStatus($deleteAll, 200);
        $this->assertEmptyMetaObjectResponse($deleteAll, 'agent meta fully deleted');

        $missingAgain = $this->json('GET', '/me/meta', null, $this->token('a'));
        $this->assertStatus($missingAgain, 200);
        $this->assertEmptyMetaObjectResponse($missingAgain, 'agent meta stays empty after delete');

        $patchMissing = $this->json('PATCH', '/me/meta', [
            'meta' => ['title' => 'Patched from empty'],
        ], $this->token('a'));
        $this->assertStatus($patchMissing, 200);
        $this->assertSame('Patched from empty', $patchMissing['json']['meta']['title'] ?? null, 'patch creates empty agent meta');

        $putMissing = $this->json('PUT', '/me/meta', [
            'meta' => ['title' => 'Nope'],
        ], $this->token('b'));
        $this->assertStatus($putMissing, 404);
        $this->assertSame('Agent meta not found', $putMissing['json']['error'] ?? null, 'put requires existing meta');

        $ordinaryForeignMetaDenied = $this->json('GET', '/agents/' . $this->agentId('c') . '/meta', null, $this->token('a'));
        $this->assertStatus($ordinaryForeignMetaDenied, 403);
        $this->assertSame('Only system agent can read foreign agent meta', $ordinaryForeignMetaDenied['json']['error'] ?? null, 'ordinary agent cannot read foreign agent meta');

        $systemMissingForeignMeta = $this->json('GET', '/agents/' . $this->agentId('c') . '/meta', null, $this->token('sys1'));
        $this->assertStatus($systemMissingForeignMeta, 200);
        $this->assertEmptyMetaObjectResponse($systemMissingForeignMeta, 'system gets empty foreign agent meta');

        $systemViewAgentMetaAll = $this->json('GET', '/agents/' . $this->agentId('c') . '?meta=all', null, $this->token('sys1'));
        $this->assertStatus($systemViewAgentMetaAll, 200);
        $this->assertEmbeddedMetaObject($systemViewAgentMetaAll, '"agent":{"agent_id"', 'system sees empty agent meta as object');

        $systemCreateForeignMeta = $this->json('POST', '/agents/' . $this->agentId('c') . '/meta', [
            'meta' => ['ops_note' => 'watch', 'priority' => 'high'],
            'meta_options' => ['ops_note' => ['is_private' => true]],
        ], $this->token('sys1'));
        $this->assertStatus($systemCreateForeignMeta, 200);
        $this->assertSame('watch', $systemCreateForeignMeta['json']['meta']['ops_note'] ?? null, 'system can create foreign agent meta');

        $systemViewForeignMeta = $this->json('GET', '/agents/' . $this->agentId('c') . '/meta?meta=ops_note', null, $this->token('sys1'));
        $this->assertStatus($systemViewForeignMeta, 200);
        $this->assertSame(['ops_note' => 'watch'], $systemViewForeignMeta['json']['meta'] ?? null, 'system can select foreign agent meta');

        $systemViewForeignAgentAll = $this->json('GET', '/agents/' . $this->agentId('c') . '?meta=all', null, $this->token('sys1'));
        $this->assertStatus($systemViewForeignAgentAll, 200);
        $this->assertSame('watch', $systemViewForeignAgentAll['json']['agent']['meta']['ops_note'] ?? null, 'system sees private foreign agent meta in agent payload');

        $systemPatchForeignMeta = $this->json('PATCH', '/agents/' . $this->agentId('c') . '/meta', [
            'meta' => ['priority' => 'urgent'],
        ], $this->token('sys1'));
        $this->assertStatus($systemPatchForeignMeta, 200);
        $this->assertSame('urgent', $systemPatchForeignMeta['json']['meta']['priority'] ?? null, 'system can patch foreign agent meta');

        $systemDeleteForeignMetaKey = $this->json('DELETE', '/agents/' . $this->agentId('c') . '/meta?keys=priority', null, $this->token('sys1'));
        $this->assertStatus($systemDeleteForeignMetaKey, 200);
        $this->assertTrue(!array_key_exists('priority', $systemDeleteForeignMetaKey['json']['meta'] ?? []), 'system can delete foreign agent meta key');

        $systemDeleteForeignMetaAll = $this->json('DELETE', '/agents/' . $this->agentId('c') . '/meta', null, $this->token('sys1'));
        $this->assertStatus($systemDeleteForeignMetaAll, 200);
        $this->assertEmptyMetaObjectResponse($systemDeleteForeignMetaAll, 'system can delete foreign agent meta');

        $systemPatchForeignMetaMissing = $this->json('PATCH', '/agents/' . $this->agentId('c') . '/meta', [
            'meta' => ['ops_note' => 'recreated'],
        ], $this->token('sys1'));
        $this->assertStatus($systemPatchForeignMetaMissing, 200);
        $this->assertSame('recreated', $systemPatchForeignMetaMissing['json']['meta']['ops_note'] ?? null, 'system patch creates empty foreign agent meta');
    }

    private function scenarioRoutesAndSubscriptions(): void
    {
        $this->step('Routes and subscriptions');

        $emptyRoutes = $this->json('GET', '/routes', null, $this->token('a'));
        $this->assertStatus($emptyRoutes, 200);
        $this->assertSame([], $emptyRoutes['json']['items'] ?? null, 'route list initially empty');

        $crossZoneRoute = $this->json('POST', '/routes', [
            'agent_ids' => [(string) $this->agentId('z2user')],
        ], $this->token('a'));
        $this->assertStatus($crossZoneRoute, 403);
        $this->assertSame('Only system agent can set initial route members', $crossZoneRoute['json']['error'] ?? null, 'ordinary agent cannot set initial members');

        $routeMain = $this->json('POST', '/routes', [
            'meta' => [
                'title' => 'Main route',
                'avatar' => 'route.png',
                'kind' => 'overlay',
            ],
        ], $this->token('a'));
        $this->assertStatus($routeMain, 200);
        $this->rememberRoute('main', $routeMain);

        $systemRoute = $this->json('POST', '/routes', [], $this->token('sys1'));
        $this->assertStatus($systemRoute, 200);
        $this->rememberRoute('system', $systemRoute);
        $this->assertSame($this->agentId('sys1'), $systemRoute['json']['route']['owner_agent_id'] ?? null, 'system agent becomes route owner');
        $this->assertSame('publisher', $systemRoute['json']['route']['default_role'] ?? null, 'route default_role defaults to publisher');

        $badDefaultRole = $this->json('POST', '/routes', [
            'default_role' => 'owner',
        ], $this->token('sys1'));
        $this->assertStatus($badDefaultRole, 422);
        $this->assertSame('default_role cannot be owner', $badDefaultRole['json']['error'] ?? null, 'owner default_role rejected');

        $guestRoute = $this->json('POST', '/routes', [
            'default_role' => 'guest',
            'agent_ids' => [(string) $this->agentId('f')],
        ], $this->token('sys1'));
        $this->assertStatus($guestRoute, 200);
        $this->rememberRoute('guest_default', $guestRoute);
        $this->assertSame('guest', $guestRoute['json']['route']['default_role'] ?? null, 'route stores guest default_role');

        $guestRouteSubs = $this->json('GET', '/routes/' . $this->routeId('guest_default') . '/subscriptions', null, $this->token('sys1'));
        $this->assertStatus($guestRouteSubs, 200);
        $this->assertSame('guest', $this->findSubscriptionRole($guestRouteSubs, $this->agentId('f')), 'initial members use route default_role');

        $adminRoute = $this->json('POST', '/routes', [
            'default_role' => 'admin',
        ], $this->token('sys1'));
        $this->assertStatus($adminRoute, 200);
        $this->rememberRoute('admin_default', $adminRoute);
        $this->assertSame('admin', $adminRoute['json']['route']['default_role'] ?? null, 'route stores admin default_role');

        $routesAfterCreate = $this->json('GET', '/routes', null, $this->token('a'));
        $this->assertStatus($routesAfterCreate, 200);
        $this->assertTrue(count($routesAfterCreate['json']['items'] ?? []) >= 1, 'route list populated');

        $viewRoute = $this->json('GET', '/routes/' . $this->routeId('main'), null, $this->token('a'));
        $this->assertStatus($viewRoute, 200);
        $this->assertSame($this->routeId('main'), $viewRoute['json']['route']['route_id'] ?? null, 'view route works');

        $systemViewRoute = $this->json('GET', '/routes/' . $this->routeId('main'), null, $this->token('sys1'));
        $this->assertStatus($systemViewRoute, 200);
        $this->assertSame($this->routeId('main'), $systemViewRoute['json']['route']['route_id'] ?? null, 'system can view foreign route in zone');

        $viewRouteSubset = $this->json('GET', '/routes/' . $this->routeId('main') . '?meta=title,avatar', null, $this->token('a'));
        $this->assertStatus($viewRouteSubset, 200);
        $this->assertSame(['title' => 'Main route', 'avatar' => 'route.png'], $viewRouteSubset['json']['route']['meta'] ?? null, 'route returns requested meta subset');

        $viewRouteAll = $this->json('GET', '/routes/' . $this->routeId('main') . '?meta=all', null, $this->token('a'));
        $this->assertStatus($viewRouteAll, 200);
        $this->assertSame([
            'title' => 'Main route',
            'avatar' => 'route.png',
            'kind' => 'overlay',
        ], $viewRouteAll['json']['route']['meta'] ?? null, 'route returns full meta with meta=all');

        $listRoutesSubset = $this->json('GET', '/routes?meta=title', null, $this->token('a'));
        $this->assertStatus($listRoutesSubset, 200);
        $this->assertSame(['title' => 'Main route'], $this->findRouteMetaInList($listRoutesSubset, $this->routeId('main')), 'route list returns selected meta only');

        $systemRouteList = $this->json('GET', '/routes?meta=title', null, $this->token('sys1'));
        $this->assertStatus($systemRouteList, 200);
        $this->assertSame(['title' => 'Main route'], $this->findRouteMetaInList($systemRouteList, $this->routeId('main')), 'system route list is zone-wide');

        $ownerCannotManage = $this->json('POST', '/routes/' . $this->routeId('main') . '/subscriptions', [
            'agent_id' => (string) $this->agentId('c'),
        ], $this->token('a'));
        $this->assertStatus($ownerCannotManage, 403);
        $this->assertSame('Only system agent can add route members', $ownerCannotManage['json']['error'] ?? null, 'ordinary owner cannot manage members');

        $addB = $this->json('POST', '/routes/' . $this->routeId('main') . '/subscriptions', [
            'agent_id' => (string) $this->agentId('b'),
        ], $this->token('sys1'));
        $this->assertStatus($addB, 200);

        $ownerCannotViewMember = $this->json('GET', '/routes/' . $this->routeId('main') . '/subscriptions/' . $this->agentId('b'), null, $this->token('a'));
        $this->assertStatus($ownerCannotViewMember, 403);
        $this->assertSame('Only system agent can view individual route members', $ownerCannotViewMember['json']['error'] ?? null, 'ordinary owner cannot view individual member');

        $systemViewMember = $this->json('GET', '/routes/' . $this->routeId('main') . '/subscriptions/' . $this->agentId('b'), null, $this->token('sys1'));
        $this->assertStatus($systemViewMember, 200);
        $this->assertSame('publisher', $systemViewMember['json']['subscription']['role'] ?? null, 'system can view individual member');

        $systemPutMember = $this->json('PUT', '/routes/' . $this->routeId('main') . '/subscriptions/' . $this->agentId('b'), [
            'role' => 'editor',
        ], $this->token('sys1'));
        $this->assertStatus($systemPutMember, 200);
        $this->assertSame('editor', $systemPutMember['json']['subscription']['role'] ?? null, 'system can replace member role');

        $systemPatchMember = $this->json('PATCH', '/routes/' . $this->routeId('main') . '/subscriptions/' . $this->agentId('b'), [
            'role' => 'publisher',
        ], $this->token('sys1'));
        $this->assertStatus($systemPatchMember, 200);
        $this->assertSame('publisher', $systemPatchMember['json']['subscription']['role'] ?? null, 'system can patch member role');

        $subsInitial = $this->json('GET', '/routes/' . $this->routeId('main') . '/subscriptions', null, $this->token('a'));
        $this->assertStatus($subsInitial, 200);
        $this->assertTrue(count($subsInitial['json']['items'] ?? []) === 2, 'initial subscriptions include owner and publisher');

        $bMeta = $this->json('POST', '/me/meta', [
            'meta' => ['title' => 'Agent B', 'avatar' => 'b.png', 'phone' => '555000'],
            'meta_options' => ['phone' => ['is_private' => true]],
        ], $this->token('b'));
        $this->assertStatus($bMeta, 200);

        $viewSharedAgent = $this->json('GET', '/agents/' . $this->agentId('b') . '?meta=title', null, $this->token('a'));
        $this->assertStatus($viewSharedAgent, 200);
        $this->assertSame($this->agentId('b'), $viewSharedAgent['json']['agent']['agent_id'] ?? null, 'shared route agent visible');
        $this->assertSame(['title' => 'Agent B'], $viewSharedAgent['json']['agent']['meta'] ?? null, 'agent selector returns requested meta');

        $viewSharedAgentAll = $this->json('GET', '/agents/' . $this->agentId('b') . '?meta=all', null, $this->token('a'));
        $this->assertStatus($viewSharedAgentAll, 200);
        $this->assertTrue(!array_key_exists('phone', $viewSharedAgentAll['json']['agent']['meta'] ?? []), 'other agent does not see private agent meta');

        $viewNonSharedAgent = $this->json('GET', '/agents/' . $this->agentId('c'), null, $this->token('a'));
        $this->assertStatus($viewNonSharedAgent, 404);
        $this->assertSame('Agent not found', $viewNonSharedAgent['json']['error'] ?? null, 'non-shared same-zone agent hidden');

        $systemViewAgent = $this->json('GET', '/agents/' . $this->agentId('c'), null, $this->token('sys1'));
        $this->assertStatus($systemViewAgent, 200);
        $this->assertSame($this->agentId('c'), $systemViewAgent['json']['agent']['agent_id'] ?? null, 'system sees any same-zone agent');

        $viewCrossZoneAgent = $this->json('GET', '/agents/' . $this->agentId('z2user'), null, $this->token('a'));
        $this->assertStatus($viewCrossZoneAgent, 404);
        $this->assertSame('Agent not found', $viewCrossZoneAgent['json']['error'] ?? null, 'cross-zone agent hidden');

        $duplicate = $this->json('POST', '/routes/' . $this->routeId('main') . '/subscriptions', [
            'agent_id' => (string) $this->agentId('b'),
        ], $this->token('sys1'));
        $this->assertStatus($duplicate, 409);
        $this->assertSame('Agent is already a member of this route', $duplicate['json']['error'] ?? null, 'duplicate member rejected');

        $badRole = $this->json('POST', '/routes/' . $this->routeId('main') . '/subscriptions', [
            'agent_id' => (string) $this->agentId('c'),
            'role' => 'bad-role',
        ], $this->token('sys1'));
        $this->assertStatus($badRole, 422);
        $this->assertSame('Unsupported route role', $badRole['json']['error'] ?? null, 'bad role rejected');

        $ownerRole = $this->json('POST', '/routes/' . $this->routeId('main') . '/subscriptions', [
            'agent_id' => (string) $this->agentId('c'),
            'role' => 'owner',
        ], $this->token('sys1'));
        $this->assertStatus($ownerRole, 422);
        $this->assertSame('Owner role cannot be assigned through this endpoint', $ownerRole['json']['error'] ?? null, 'owner assignment rejected');

        $ownerRoleUpdate = $this->json('PUT', '/routes/' . $this->routeId('main') . '/subscriptions/' . $this->agentId('b'), [
            'role' => 'owner',
        ], $this->token('sys1'));
        $this->assertStatus($ownerRoleUpdate, 422);
        $this->assertSame('Owner role cannot be assigned through this endpoint', $ownerRoleUpdate['json']['error'] ?? null, 'owner role update rejected');

        $addAdmin = $this->json('POST', '/routes/' . $this->routeId('main') . '/subscriptions', [
            'agent_id' => (string) $this->agentId('c'),
            'role' => 'admin',
        ], $this->token('sys1'));
        $this->assertStatus($addAdmin, 200);

        $addGuest = $this->json('POST', '/routes/' . $this->routeId('main') . '/subscriptions', [
            'agent_id' => (string) $this->agentId('d'),
            'role' => 'guest',
        ], $this->token('sys1'));
        $this->assertStatus($addGuest, 200);

        $addEditor = $this->json('POST', '/routes/' . $this->routeId('main') . '/subscriptions', [
            'agent_id' => (string) $this->agentId('e'),
            'role' => 'editor',
        ], $this->token('sys1'));
        $this->assertStatus($addEditor, 200);

        $publisherCannotManage = $this->json('POST', '/routes/' . $this->routeId('main') . '/subscriptions', [
            'agent_id' => (string) $this->agentId('f'),
        ], $this->token('b'));
        $this->assertStatus($publisherCannotManage, 403);
        $this->assertSame('Only system agent can add route members', $publisherCannotManage['json']['error'] ?? null, 'publisher cannot manage members');

        $systemAddsPublisher = $this->json('POST', '/routes/' . $this->routeId('main') . '/subscriptions', [
            'agent_id' => (string) $this->agentId('f'),
            'role' => 'publisher',
        ], $this->token('sys1'));
        $this->assertStatus($systemAddsPublisher, 200);

        $systemAddsAdminByDefault = $this->json('POST', '/routes/' . $this->routeId('admin_default') . '/subscriptions', [
            'agent_id' => (string) $this->agentId('e'),
        ], $this->token('sys1'));
        $this->assertStatus($systemAddsAdminByDefault, 200);
        $this->assertSame('admin', $systemAddsAdminByDefault['json']['subscription']['role'] ?? null, 'missing role uses route default_role');

        $ownerCannotDeleteMember = $this->json('DELETE', '/routes/' . $this->routeId('main') . '/subscriptions/' . $this->agentId('f'), null, $this->token('a'));
        $this->assertStatus($ownerCannotDeleteMember, 403);
        $this->assertSame('Only system agent can remove route members', $ownerCannotDeleteMember['json']['error'] ?? null, 'ordinary owner cannot remove members');

        $systemDeleteMember = $this->json('DELETE', '/routes/' . $this->routeId('main') . '/subscriptions/' . $this->agentId('f'), null, $this->token('sys1'));
        $this->assertStatus($systemDeleteMember, 200);

        $deleteMissingMember = $this->json('DELETE', '/routes/' . $this->routeId('main') . '/subscriptions/' . $this->agentId('f'), null, $this->token('sys1'));
        $this->assertStatus($deleteMissingMember, 404);
        $this->assertSame('Subscription not found', $deleteMissingMember['json']['error'] ?? null, 'missing subscription delete rejected');

        $getMissingMember = $this->json('GET', '/routes/' . $this->routeId('main') . '/subscriptions/' . $this->agentId('f'), null, $this->token('sys1'));
        $this->assertStatus($getMissingMember, 404);
        $this->assertSame('Subscription not found', $getMissingMember['json']['error'] ?? null, 'missing subscription get rejected');

        $deleteOwnerDenied = $this->json('DELETE', '/routes/' . $this->routeId('main') . '/subscriptions/' . $this->agentId('a'), null, $this->token('sys1'));
        $this->assertStatus($deleteOwnerDenied, 422);
        $this->assertSame('Route owner subscription cannot be deleted', $deleteOwnerDenied['json']['error'] ?? null, 'owner subscription delete rejected');

        $updateOwnerDenied = $this->json('PATCH', '/routes/' . $this->routeId('main') . '/subscriptions/' . $this->agentId('a'), [
            'role' => 'admin',
        ], $this->token('sys1'));
        $this->assertStatus($updateOwnerDenied, 422);
        $this->assertSame('Route owner subscription cannot be updated', $updateOwnerDenied['json']['error'] ?? null, 'owner subscription update rejected');

        $crossZoneMember = $this->json('POST', '/routes/' . $this->routeId('main') . '/subscriptions', [
            'agent_id' => (string) $this->agentId('z2user'),
            'role' => 'publisher',
        ], $this->token('sys1'));
        $this->assertStatus($crossZoneMember, 404);
        $this->assertSame('Agent not found', $crossZoneMember['json']['error'] ?? null, 'cross-zone subscription rejected');
    }

    private function scenarioRouteMeta(): void
    {
        $this->step('Route meta');

        $missing = $this->json('GET', '/routes/' . $this->routeId('system') . '/meta', null, $this->token('sys1'));
        $this->assertStatus($missing, 200);
        $this->assertEmptyMetaObjectResponse($missing, 'route meta initially empty');

        $viewRouteMetaAllMissing = $this->json('GET', '/routes/' . $this->routeId('system') . '?meta=all', null, $this->token('sys1'));
        $this->assertStatus($viewRouteMetaAllMissing, 200);
        $this->assertEmbeddedMetaObject($viewRouteMetaAllMissing, '"route":{"route_id"', 'route meta selector returns empty object');

        $publisherDenied = $this->json('POST', '/routes/' . $this->routeId('main') . '/meta', [
            'meta' => ['title' => 'blocked'],
        ], $this->token('b'));
        $this->assertStatus($publisherDenied, 403);
        $this->assertSame('Agent cannot update route meta', $publisherDenied['json']['error'] ?? null, 'publisher cannot create route meta');

        $systemPatchMain = $this->json('PATCH', '/routes/' . $this->routeId('main') . '/meta', [
            'meta' => ['ops_note' => 'system-touch'],
        ], $this->token('sys1'));
        $this->assertStatus($systemPatchMain, 200);
        $this->assertSame('system-touch', $systemPatchMain['json']['meta']['ops_note'] ?? null, 'system can patch foreign route meta');

        $create = $this->json('POST', '/routes/' . $this->routeId('system') . '/meta', [
            'meta' => ['title' => 'System route', 'avatar' => 'system.png', 'kind' => 'ops'],
        ], $this->token('sys1'));
        $this->assertStatus($create, 200);

        $duplicate = $this->json('POST', '/routes/' . $this->routeId('system') . '/meta', [
            'meta' => ['title' => 'again'],
        ], $this->token('sys1'));
        $this->assertStatus($duplicate, 409);
        $this->assertSame('Route meta already exists', $duplicate['json']['error'] ?? null, 'duplicate route meta rejected');

        $replace = $this->json('PUT', '/routes/' . $this->routeId('system') . '/meta', [
            'meta' => ['title' => 'System route 2', 'status' => 'active'],
        ], $this->token('sys1'));
        $this->assertStatus($replace, 200);

        $patch = $this->json('PATCH', '/routes/' . $this->routeId('system') . '/meta', [
            'meta' => ['avatar' => 'route-2.png'],
        ], $this->token('sys1'));
        $this->assertStatus($patch, 200);

        $deleteKey = $this->json('DELETE', '/routes/' . $this->routeId('system') . '/meta?keys=avatar', null, $this->token('sys1'));
        $this->assertStatus($deleteKey, 200);
        $this->assertTrue(!array_key_exists('avatar', $deleteKey['json']['meta'] ?? []), 'route meta key deleted');

        $deleteAll = $this->json('DELETE', '/routes/' . $this->routeId('system') . '/meta', null, $this->token('sys1'));
        $this->assertStatus($deleteAll, 200);
        $this->assertEmptyMetaObjectResponse($deleteAll, 'route meta fully deleted');

        $putMissing = $this->json('PUT', '/routes/' . $this->routeId('system') . '/meta', [
            'meta' => ['title' => 'restored'],
        ], $this->token('sys1'));
        $this->assertStatus($putMissing, 404);
        $this->assertSame('Route meta not found', $putMissing['json']['error'] ?? null, 'route put requires existing meta');

        $patchMissing = $this->json('PATCH', '/routes/' . $this->routeId('system') . '/meta', [
            'meta' => ['title' => 'restored by patch'],
        ], $this->token('sys1'));
        $this->assertStatus($patchMissing, 200);
        $this->assertSame('restored by patch', $patchMissing['json']['meta']['title'] ?? null, 'route patch creates empty meta');
    }

    private function scenarioLanesAndLaneMeta(): void
    {
        $this->step('Lanes and lane meta');

        $lanes = $this->json('GET', '/routes/' . $this->routeId('main') . '/lanes', null, $this->token('a'));
        $this->assertStatus($lanes, 200);
        $this->assertTrue(count($lanes['json']['items'] ?? []) === 1, 'route starts with default lane');

        $systemLanes = $this->json('GET', '/routes/' . $this->routeId('main') . '/lanes', null, $this->token('sys1'));
        $this->assertStatus($systemLanes, 200);
        $this->assertTrue(count($systemLanes['json']['items'] ?? []) === 1, 'system can list lanes on foreign route');

        $systemCreateLane = $this->json('POST', '/routes/' . $this->routeId('main') . '/lanes', [], $this->token('sys1'));
        $this->assertStatus($systemCreateLane, 200);
        $this->rememberLane('system_extra', $systemCreateLane);

        $systemCreateLaneMeta = $this->json('POST', '/lanes/' . $this->laneId('system_extra') . '/meta', [
            'meta' => ['title' => 'System extra lane'],
        ], $this->token('sys1'));
        $this->assertStatus($systemCreateLaneMeta, 200);
        $this->assertSame('System extra lane', $systemCreateLaneMeta['json']['meta']['title'] ?? null, 'system can create foreign lane meta');

        $publisherLaneCreateDenied = $this->json('POST', '/routes/' . $this->routeId('main') . '/lanes', [], $this->token('b'));
        $this->assertStatus($publisherLaneCreateDenied, 403);
        $this->assertSame('Agent cannot create lanes in this route', $publisherLaneCreateDenied['json']['error'] ?? null, 'publisher cannot create lane');

        $createLane = $this->json('POST', '/routes/' . $this->routeId('main') . '/lanes', [], $this->token('c'));
        $this->assertStatus($createLane, 200);
        $this->rememberLane('extra', $createLane);

        $viewLane = $this->json('GET', '/lanes/' . $this->laneId('extra'), null, $this->token('a'));
        $this->assertStatus($viewLane, 200);
        $this->assertSame($this->laneId('extra'), $viewLane['json']['lane']['lane_id'] ?? null, 'view lane works');

        $deleteDefault = $this->json('DELETE', '/lanes/' . $this->laneId('main_default'), null, $this->token('a'));
        $this->assertStatus($deleteDefault, 409);
        $this->assertSame('Default lane cannot be deleted', $deleteDefault['json']['error'] ?? null, 'default lane is protected');

        $missingMeta = $this->json('GET', '/lanes/' . $this->laneId('extra') . '/meta', null, $this->token('a'));
        $this->assertStatus($missingMeta, 200);
        $this->assertEmptyMetaObjectResponse($missingMeta, 'lane meta initially empty');

        $viewLaneMetaAllMissing = $this->json('GET', '/lanes/' . $this->laneId('extra') . '?meta=all', null, $this->token('a'));
        $this->assertStatus($viewLaneMetaAllMissing, 200);
        $this->assertEmbeddedMetaObject($viewLaneMetaAllMissing, '"lane":{"lane_id"', 'lane meta selector returns empty object');

        $publisherMetaDenied = $this->json('POST', '/lanes/' . $this->laneId('extra') . '/meta', [
            'meta' => ['title' => 'blocked'],
        ], $this->token('b'));
        $this->assertStatus($publisherMetaDenied, 403);
        $this->assertSame('Agent cannot update lane meta', $publisherMetaDenied['json']['error'] ?? null, 'publisher cannot create lane meta');

        $createMeta = $this->json('POST', '/lanes/' . $this->laneId('extra') . '/meta', [
            'meta' => ['title' => 'Extra lane', 'icon' => 'star'],
        ], $this->token('c'));
        $this->assertStatus($createMeta, 200);

        $duplicateMeta = $this->json('POST', '/lanes/' . $this->laneId('extra') . '/meta', [
            'meta' => ['title' => 'again'],
        ], $this->token('c'));
        $this->assertStatus($duplicateMeta, 409);
        $this->assertSame('Lane meta already exists', $duplicateMeta['json']['error'] ?? null, 'duplicate lane meta rejected');

        $laneWithMeta = $this->json('GET', '/lanes/' . $this->laneId('extra') . '?meta=title', null, $this->token('a'));
        $this->assertStatus($laneWithMeta, 200);
        $this->assertSame(['title' => 'Extra lane'], $laneWithMeta['json']['lane']['meta'] ?? null, 'lane meta selector');

        $replaceMeta = $this->json('PUT', '/lanes/' . $this->laneId('extra') . '/meta', [
            'meta' => ['title' => 'Extra lane 2', 'topic' => 'ops'],
        ], $this->token('c'));
        $this->assertStatus($replaceMeta, 200);

        $patchMeta = $this->json('PATCH', '/lanes/' . $this->laneId('extra') . '/meta', [
            'meta' => ['icon' => 'rocket'],
        ], $this->token('c'));
        $this->assertStatus($patchMeta, 200);

        $deleteMetaKey = $this->json('DELETE', '/lanes/' . $this->laneId('extra') . '/meta?keys=icon', null, $this->token('c'));
        $this->assertStatus($deleteMetaKey, 200);

        $deleteMetaAll = $this->json('DELETE', '/lanes/' . $this->laneId('extra') . '/meta', null, $this->token('a'));
        $this->assertStatus($deleteMetaAll, 200);
        $this->assertEmptyMetaObjectResponse($deleteMetaAll, 'lane meta fully deleted');

        $putLaneMetaMissing = $this->json('PUT', '/lanes/' . $this->laneId('extra') . '/meta', [
            'meta' => ['title' => 'restored'],
        ], $this->token('a'));
        $this->assertStatus($putLaneMetaMissing, 404);
        $this->assertSame('Lane meta not found', $putLaneMetaMissing['json']['error'] ?? null, 'lane put requires existing meta');

        $patchLaneMetaMissing = $this->json('PATCH', '/lanes/' . $this->laneId('extra') . '/meta', [
            'meta' => ['title' => 'restored by patch'],
        ], $this->token('a'));
        $this->assertStatus($patchLaneMetaMissing, 200);
        $this->assertSame('restored by patch', $patchLaneMetaMissing['json']['meta']['title'] ?? null, 'lane patch creates empty meta');
    }

    private function scenarioPayloadsAndReadState(): void
    {
        $this->step('Payloads and read state');

        $defaultLaneId = $this->laneId('main_default');
        $extraLaneId = $this->laneId('extra');

        $emptyList = $this->json('GET', '/lanes/' . $defaultLaneId . '/payloads', null, $this->token('a'));
        $this->assertStatus($emptyList, 200);
        $this->assertSame([], $emptyList['json']['items'] ?? null, 'payload list initially empty');

        $guestDenied = $this->raw(
            'POST',
            '/lanes/' . $defaultLaneId . '/payloads',
            'guest message',
            $this->token('d'),
            ['Content-Type: application/octet-stream']
        );
        $this->assertStatus($guestDenied, 403);
        $this->assertSame('Agent cannot post messages in this route', $guestDenied['json']['error'] ?? null, 'guest cannot post payload');

        $badContentType = $this->json('POST', '/lanes/' . $defaultLaneId . '/payloads', [
            'body' => 'bad',
        ], $this->token('a'));
        $this->assertStatus($badContentType, 415);
        $this->assertSame('Content-Type must be application/octet-stream', $badContentType['json']['error'] ?? null, 'content type enforced');

        $emptyBody = $this->raw(
            'POST',
            '/lanes/' . $defaultLaneId . '/payloads',
            '',
            $this->token('a'),
            ['Content-Type: application/octet-stream']
        );
        $this->assertStatus($emptyBody, 422);
        $this->assertSame('Payload body is required', $emptyBody['json']['error'] ?? null, 'empty payload rejected');

        $payloadA = $this->raw(
            'POST',
            '/lanes/' . $defaultLaneId . '/payloads',
            'hello from a',
            $this->token('a'),
            ['Content-Type: application/octet-stream']
        );
        $this->assertStatus($payloadA, 200);
        $this->rememberPayload('a1', $payloadA);

        $payloadB = $this->raw(
            'POST',
            '/lanes/' . $defaultLaneId . '/payloads',
            'hello from b',
            $this->token('b'),
            ['Content-Type: application/octet-stream']
        );
        $this->assertStatus($payloadB, 200);
        $this->rememberPayload('b1', $payloadB);

        $payloadExtra = $this->raw(
            'POST',
            '/lanes/' . $extraLaneId . '/payloads',
            'hello from extra lane',
            $this->token('a'),
            ['Content-Type: application/octet-stream']
        );
        $this->assertStatus($payloadExtra, 200);
        $this->rememberPayload('extra1', $payloadExtra);

        $listAfter = $this->json('GET', '/lanes/' . $defaultLaneId . '/payloads?after_id=0&limit=10', null, $this->token('a'));
        $this->assertStatus($listAfter, 200);
        $this->assertTrue(count($listAfter['json']['items'] ?? []) === 2, 'payload list populated');

        $listAfterOne = $this->json('GET', '/lanes/' . $defaultLaneId . '/payloads?after_id=' . $this->payloadId('a1'), null, $this->token('a'));
        $this->assertStatus($listAfterOne, 200);
        $this->assertTrue(count($listAfterOne['json']['items'] ?? []) === 1, 'after_id filters payload list');

        $body = $this->raw('GET', '/payloads/' . $this->payloadId('a1') . '/body', null, $this->token('a'));
        $this->assertStatus($body, 200);
        $this->assertSame('hello from a', $body['body'], 'payload body roundtrip');

        $systemBody = $this->raw('GET', '/payloads/' . $this->payloadId('a1') . '/body', null, $this->token('sys1'));
        $this->assertStatus($systemBody, 200);
        $this->assertSame('hello from a', $systemBody['body'], 'system can read foreign payload body');

        $systemUpdateBody = $this->raw(
            'PUT',
            '/payloads/' . $this->payloadId('a1') . '/body',
            'hello from system',
            $this->token('sys1'),
            ['Content-Type: application/octet-stream']
        );
        $this->assertStatus($systemUpdateBody, 200);

        $bodyAfterSystemUpdate = $this->raw('GET', '/payloads/' . $this->payloadId('a1') . '/body', null, $this->token('a'));
        $this->assertStatus($bodyAfterSystemUpdate, 200);
        $this->assertSame('hello from system', $bodyAfterSystemUpdate['body'], 'system can update foreign payload body');

        $crossZoneRead = $this->raw('GET', '/payloads/' . $this->payloadId('a1') . '/body', null, $this->token('z2user'));
        $this->assertStatus($crossZoneRead, 404);
        $this->assertSame('Lane not found', $crossZoneRead['json']['error'] ?? null, 'cross-zone payload read blocked');

        $wrongLaneRead = $this->json('POST', '/lanes/' . $defaultLaneId . '/read', [
            'last_read_payload_id' => $this->payloadId('extra1'),
        ], $this->token('b'));
        $this->assertStatus($wrongLaneRead, 422);
        $this->assertSame('Payload does not belong to lane', $wrongLaneRead['json']['error'] ?? null, 'read state validates payload lane');

        $markRead = $this->json('POST', '/lanes/' . $defaultLaneId . '/read', [
            'last_read_payload_id' => $this->payloadId('b1'),
        ], $this->token('b'));
        $this->assertStatus($markRead, 200);
        $this->assertSame($this->payloadId('b1'), $markRead['json']['lane_read_state']['last_read_payload_id'] ?? null, 'read state stored');

        $laneView = $this->json('GET', '/lanes/' . $defaultLaneId, null, $this->token('b'));
        $this->assertStatus($laneView, 200);
        $this->assertSame($this->payloadId('b1'), $laneView['json']['lane']['read_state']['last_read_payload_id'] ?? null, 'lane read_state visible');

        $systemLaneView = $this->json('GET', '/lanes/' . $defaultLaneId, null, $this->token('sys1'));
        $this->assertStatus($systemLaneView, 200);
        $this->assertSame($defaultLaneId, $systemLaneView['json']['lane']['lane_id'] ?? null, 'system can view foreign lane');

        $publisherUpdateForeign = $this->raw(
            'PUT',
            '/payloads/' . $this->payloadId('a1') . '/body',
            'forbidden update',
            $this->token('b'),
            ['Content-Type: application/octet-stream']
        );
        $this->assertStatus($publisherUpdateForeign, 403);
        $this->assertSame(
            'Agent cannot update payload owned by another agent',
            $publisherUpdateForeign['json']['error'] ?? null,
            'publisher cannot update foreign payload'
        );

        $authorUpdate = $this->raw(
            'PUT',
            '/payloads/' . $this->payloadId('a1') . '/body',
            'hello from a, edited',
            $this->token('a'),
            ['Content-Type: application/octet-stream']
        );
        $this->assertStatus($authorUpdate, 200);
        $this->assertTrue(($authorUpdate['json']['payload']['revision'] ?? '') !== '', 'payload update returns revision');

        $editedBody = $this->raw('GET', '/payloads/' . $this->payloadId('a1') . '/body', null, $this->token('a'));
        $this->assertStatus($editedBody, 200);
        $this->assertSame('hello from a, edited', $editedBody['body'], 'payload body updated');

        $publisherDeleteForeign = $this->json('DELETE', '/payloads/' . $this->payloadId('a1'), null, $this->token('b'));
        $this->assertStatus($publisherDeleteForeign, 403);
        $this->assertSame('Agent cannot delete payload owned by another agent', $publisherDeleteForeign['json']['error'] ?? null, 'publisher cannot delete foreign payload');

        $editorDeleteForeign = $this->json('DELETE', '/payloads/' . $this->payloadId('b1'), null, $this->token('e'));
        $this->assertStatus($editorDeleteForeign, 200);

        $ownerDeleteOwn = $this->json('DELETE', '/payloads/' . $this->payloadId('a1'), null, $this->token('a'));
        $this->assertStatus($ownerDeleteOwn, 200);

        $deletedBody = $this->raw('GET', '/payloads/' . $this->payloadId('a1') . '/body', null, $this->token('a'));
        $this->assertStatus($deletedBody, 404);
        $this->assertSame('Payload not found', $deletedBody['json']['error'] ?? null, 'deleted payload gone');
    }

    private function scenarioPayloadMeta(): void
    {
        $this->step('Payload meta');

        $targetPayload = $this->payloadId('extra1');

        $listEmpty = $this->json('GET', '/payloads/' . $targetPayload . '/meta', null, $this->token('a'));
        $this->assertStatus($listEmpty, 200);
        $this->assertSame([], $listEmpty['json']['items'] ?? null, 'payload meta list initially empty');

        $emptyMeta = $this->json('POST', '/payloads/' . $targetPayload . '/meta', [
            'meta' => [],
        ], $this->token('a'));
        $this->assertStatus($emptyMeta, 422);
        $this->assertSame('meta is required', $emptyMeta['json']['error'] ?? null, 'payload meta requires data');

        $multiEntry = $this->json('POST', '/payloads/' . $targetPayload . '/meta', [
            'meta' => ['reaction' => 'like', 'color' => 'blue'],
        ], $this->token('a'));
        $this->assertStatus($multiEntry, 422);
        $this->assertSame(
            'payload meta record must contain exactly one meta entry',
            $multiEntry['json']['error'] ?? null,
            'payload meta create requires single entry'
        );

        $create = $this->json('POST', '/payloads/' . $targetPayload . '/meta', [
            'meta' => ['reaction' => 'like'],
        ], $this->token('b'));
        $this->assertStatus($create, 200);
        $this->rememberPayloadMeta('b_reaction', $create);

        $item = $this->json('GET', '/payload-meta/' . $this->payloadMetaId('b_reaction'), null, $this->token('a'));
        $this->assertStatus($item, 200);
        $this->assertSame('like', $item['json']['payload_meta']['meta']['reaction'] ?? null, 'payload meta readable');

        $guestPatchDenied = $this->json('PATCH', '/payload-meta/' . $this->payloadMetaId('b_reaction'), [
            'meta' => ['reaction' => 'love'],
        ], $this->token('d'));
        $this->assertStatus($guestPatchDenied, 403);
        $this->assertSame('Agent cannot manage this payload_meta', $guestPatchDenied['json']['error'] ?? null, 'guest cannot patch foreign payload meta');

        $adminPatch = $this->json('PATCH', '/payload-meta/' . $this->payloadMetaId('b_reaction'), [
            'meta' => ['reaction' => 'love'],
        ], $this->token('c'));
        $this->assertStatus($adminPatch, 200);
        $this->assertSame('love', $adminPatch['json']['payload_meta']['meta']['reaction'] ?? null, 'admin can patch foreign payload meta');

        $systemPatch = $this->json('PATCH', '/payload-meta/' . $this->payloadMetaId('b_reaction'), [
            'meta' => ['reaction' => 'system-love'],
        ], $this->token('sys1'));
        $this->assertStatus($systemPatch, 200);
        $this->assertSame('system-love', $systemPatch['json']['payload_meta']['meta']['reaction'] ?? null, 'system can patch foreign payload meta');

        $put = $this->json('PUT', '/payload-meta/' . $this->payloadMetaId('b_reaction'), [
            'meta' => ['reaction' => 'wow'],
        ], $this->token('b'));
        $this->assertStatus($put, 200);

        $adminDelete = $this->json('DELETE', '/payload-meta/' . $this->payloadMetaId('b_reaction'), null, $this->token('c'));
        $this->assertStatus($adminDelete, 200);

        $afterDelete = $this->json('GET', '/payload-meta/' . $this->payloadMetaId('b_reaction'), null, $this->token('a'));
        $this->assertStatus($afterDelete, 404);
        $this->assertSame('Payload meta not found', $afterDelete['json']['error'] ?? null, 'payload meta deleted');
    }

    private function scenarioUpdates(): void
    {
        $this->step('Updates');

        $ordinaryDenied = $this->json('GET', '/updates', null, $this->token('a'));
        $this->assertStatus($ordinaryDenied, 403);
        $this->assertSame('Only system agent can read zone updates', $ordinaryDenied['json']['error'] ?? null, 'ordinary agent cannot read updates');

        $systemUpdates = $this->json('GET', '/updates?after_id=0&limit=500', null, $this->token('sys1'));
        $this->assertStatus($systemUpdates, 200);
        $this->assertTrue(count($systemUpdates['json']['items'] ?? []) > 0, 'system sees zone updates');
        $updateKinds = array_map(
            static fn (array $item): string => (string) ($item['kind'] ?? ''),
            $systemUpdates['json']['items'] ?? []
        );
        $this->assertTrue(in_array('agent_registered', $updateKinds, true), 'registration appears in updates');
        $this->context['updates_after']['sys1'] = (int) ($systemUpdates['json']['latest_update_id'] ?? 0);

        $zone2Updates = $this->json('GET', '/updates?after_id=0&limit=500', null, $this->token('sys2'));
        $this->assertStatus($zone2Updates, 200);
        $zone2Items = $zone2Updates['json']['items'] ?? [];
        $this->assertTrue(count($zone2Items) > 0, 'second zone sees its own updates');
        $zone2Only = array_filter(
            $zone2Items,
            fn (array $item): bool => ($item['zone'] ?? null) === $this->context['zones']['z2']
        );
        $this->assertSame(count($zone2Items), count($zone2Only), 'other zone sees no foreign updates');

        $badTimeout = $this->json('GET', '/updates?timeout=-1', null, $this->token('sys1'));
        $this->assertStatus($badTimeout, 422);
        $this->assertSame('timeout must be a non-negative integer', $badTimeout['json']['error'] ?? null, 'updates timeout validation');

        $longPollStartedAt = microtime(true);
        $longPollEmpty = $this->json(
            'GET',
            '/updates?after_id=' . $this->context['updates_after']['sys1'] . '&limit=100&timeout=1',
            null,
            $this->token('sys1')
        );
        $longPollElapsed = microtime(true) - $longPollStartedAt;
        $this->assertStatus($longPollEmpty, 200);
        $this->assertSame([], $longPollEmpty['json']['items'] ?? null, 'long poll returns empty when timeout expires');
        $this->assertSame($this->context['updates_after']['sys1'], $longPollEmpty['json']['latest_update_id'] ?? null, 'long poll keeps latest_update_id when no new updates');
        $this->assertTrue($longPollElapsed >= 0.8, 'long poll waits before returning empty result');

        $systemRoutesSyncDenied = $this->json('POST', '/sync/routes', [
            'skip' => 0,
            'limit' => 50,
            'items' => [],
        ], $this->token('sys1'));
        $this->assertStatus($systemRoutesSyncDenied, 403);
        $this->assertSame('System agent must use /updates instead of /sync', $systemRoutesSyncDenied['json']['error'] ?? null, 'system cannot use routes sync');

        $systemLanesSyncDenied = $this->json('POST', '/sync/routes/' . $this->routeId('main') . '/lanes', [
            'skip' => 0,
            'limit' => 50,
            'items' => [],
        ], $this->token('sys1'));
        $this->assertStatus($systemLanesSyncDenied, 403);
        $this->assertSame('System agent must use /updates instead of /sync', $systemLanesSyncDenied['json']['error'] ?? null, 'system cannot use lane sync');

        $systemPayloadSyncDenied = $this->json('POST', '/sync/lanes/' . $this->laneId('extra') . '/payloads', [
            'skip' => 0,
            'limit' => 50,
            'items' => [],
        ], $this->token('sys1'));
        $this->assertStatus($systemPayloadSyncDenied, 403);
        $this->assertSame('System agent must use /updates instead of /sync', $systemPayloadSyncDenied['json']['error'] ?? null, 'system cannot use payload sync');
    }

    private function scenarioIdealLazySyncContract(): void
    {
        $this->step('Ideal lazy sync contract');

        $routesSync = $this->json('POST', '/sync/routes', [
            'skip' => 0,
            'limit' => 50,
            'items' => [],
        ], $this->token('b'));
        $this->assertStatus($routesSync, 200);
        $this->assertLazySyncItems($routesSync, 'routes bootstrap');
        $routeSnapshot = $this->toLazySyncSnapshot($routesSync);

        $routesStable = $this->json('POST', '/sync/routes', [
            'skip' => 0,
            'limit' => 50,
            'items' => $routeSnapshot,
        ], $this->token('b'));
        $this->assertStatus($routesStable, 200);
        $this->assertSame([], $routesStable['json']['items'] ?? null, 'routes sync skips matching revisions');

        $routeMetaSync = $this->json('PATCH', '/routes/' . $this->routeId('main') . '/meta', [
            'meta' => ['sync_title' => 'Main route sync'],
        ], $this->token('a'));
        $this->assertStatus($routeMetaSync, 200);

        $routesChanged = $this->json('POST', '/sync/routes', [
            'skip' => 0,
            'limit' => 50,
            'items' => $routeSnapshot,
        ], $this->token('b'));
        $this->assertStatus($routesChanged, 200);
        $this->assertLazySyncItems($routesChanged, 'routes changed');

        $lanesSync = $this->json('POST', '/sync/routes/' . $this->routeId('main') . '/lanes', [
            'skip' => 0,
            'limit' => 50,
            'items' => [],
        ], $this->token('b'));
        $this->assertStatus($lanesSync, 200);
        $this->assertLazySyncItems($lanesSync, 'lanes bootstrap');
        $laneSnapshot = $this->toLazySyncSnapshot($lanesSync);

        $lanesSkip = $this->json('POST', '/sync/routes/' . $this->routeId('main') . '/lanes', [
            'skip' => 1,
            'limit' => 1,
            'items' => [],
        ], $this->token('b'));
        $this->assertStatus($lanesSkip, 200);
        $this->assertLazySyncItems($lanesSkip, 'lanes skipped slice');
        $this->assertSame(1, count($lanesSkip['json']['items'] ?? []), 'lanes skip limit respected');

        $lanesStable = $this->json('POST', '/sync/routes/' . $this->routeId('main') . '/lanes', [
            'skip' => 0,
            'limit' => 50,
            'items' => $laneSnapshot,
        ], $this->token('b'));
        $this->assertStatus($lanesStable, 200);
        $this->assertSame([], $lanesStable['json']['items'] ?? null, 'lanes sync skips matching revisions');

        $laneMetaSync = $this->json('PATCH', '/lanes/' . $this->laneId('extra') . '/meta', [
            'meta' => ['sync_lane' => 'Extra lane sync'],
        ], $this->token('a'));
        $this->assertStatus($laneMetaSync, 200);

        $lanesChanged = $this->json('POST', '/sync/routes/' . $this->routeId('main') . '/lanes', [
            'skip' => 0,
            'limit' => 50,
            'items' => $laneSnapshot,
        ], $this->token('b'));
        $this->assertStatus($lanesChanged, 200);
        $this->assertLazySyncItems($lanesChanged, 'lanes changed');

        $payloadSync = $this->json('POST', '/sync/lanes/' . $this->laneId('extra') . '/payloads', [
            'skip' => 0,
            'limit' => 100,
            'items' => [],
        ], $this->token('b'));
        $this->assertStatus($payloadSync, 200);
        $this->assertLazySyncItems($payloadSync, 'payload bootstrap');
        $payloadSnapshot = $this->toLazySyncSnapshot($payloadSync);

        $payloadStable = $this->json('POST', '/sync/lanes/' . $this->laneId('extra') . '/payloads', [
            'skip' => 0,
            'limit' => 100,
            'items' => $payloadSnapshot,
        ], $this->token('b'));
        $this->assertStatus($payloadStable, 200);
        $this->assertSame([], $payloadStable['json']['items'] ?? null, 'payload sync skips matching revisions');

        $payloadExtra2 = $this->raw(
            'POST',
            '/lanes/' . $this->laneId('extra') . '/payloads',
            'hello from extra lane 2',
            $this->token('a'),
            ['Content-Type: application/octet-stream']
        );
        $this->assertStatus($payloadExtra2, 200);

        $payloadChanged = $this->json('POST', '/sync/lanes/' . $this->laneId('extra') . '/payloads', [
            'skip' => 0,
            'limit' => 100,
            'items' => $payloadSnapshot,
        ], $this->token('b'));
        $this->assertStatus($payloadChanged, 200);
        $this->assertLazySyncItems($payloadChanged, 'payload changed');

        $payloadSkip = $this->json('POST', '/sync/lanes/' . $this->laneId('extra') . '/payloads', [
            'skip' => 1,
            'limit' => 1,
            'items' => [],
        ], $this->token('b'));
        $this->assertStatus($payloadSkip, 200);
        $this->assertLazySyncItems($payloadSkip, 'payload skipped slice');
        $this->assertSame(1, count($payloadSkip['json']['items'] ?? []), 'payload skip limit respected');
    }

    private function scenarioDestructiveOperations(): void
    {
        $this->step('Destructive operations');

        $publisherDeleteRoute = $this->json('DELETE', '/routes/' . $this->routeId('main'), null, $this->token('b'));
        $this->assertStatus($publisherDeleteRoute, 403);
        $this->assertSame('Agent does not have maximal route role', $publisherDeleteRoute['json']['error'] ?? null, 'publisher cannot delete route');

        $laneSnapshot = $this->toLazySyncSnapshot($this->json('POST', '/sync/routes/' . $this->routeId('main') . '/lanes', [
            'skip' => 0,
            'limit' => 50,
            'items' => [],
        ], $this->token('b')));

        $payloadSnapshot = $this->toLazySyncSnapshot($this->json('POST', '/sync/lanes/' . $this->laneId('extra') . '/payloads', [
            'skip' => 0,
            'limit' => 100,
            'items' => [],
        ], $this->token('b')));

        $routeSnapshot = $this->toLazySyncSnapshot($this->json('POST', '/sync/routes', [
            'skip' => 0,
            'limit' => 50,
            'items' => [],
        ], $this->token('b')));

        $clearLaneDenied = $this->json('POST', '/lanes/' . $this->laneId('extra') . '/clear', [], $this->token('c'));
        $this->assertStatus($clearLaneDenied, 403);
        $this->assertSame('Agent does not have maximal route role', $clearLaneDenied['json']['error'] ?? null, 'admin cannot clear lane if owner exists');

        $clearLane = $this->json('POST', '/lanes/' . $this->laneId('extra') . '/clear', [], $this->token('a'));
        $this->assertStatus($clearLane, 200);

        $payloadsAfterClear = $this->json('POST', '/sync/lanes/' . $this->laneId('extra') . '/payloads', [
            'skip' => 0,
            'limit' => 100,
            'items' => $payloadSnapshot,
        ], $this->token('b'));
        $this->assertStatus($payloadsAfterClear, 200);
        $this->assertLazySyncItems($payloadsAfterClear, 'payload clear emits patch');
        $this->assertDeletedLazySyncItem($payloadsAfterClear, $this->payloadId('extra1'), 'cleared payload marked deleted');

        $deleteLaneDenied = $this->json('DELETE', '/lanes/' . $this->laneId('extra'), null, $this->token('b'));
        $this->assertStatus($deleteLaneDenied, 403);
        $this->assertSame('Agent does not have maximal route role', $deleteLaneDenied['json']['error'] ?? null, 'publisher cannot delete lane');

        $deleteLane = $this->json('DELETE', '/lanes/' . $this->laneId('extra'), null, $this->token('a'));
        $this->assertStatus($deleteLane, 200);

        $lanesAfterDelete = $this->json('POST', '/sync/routes/' . $this->routeId('main') . '/lanes', [
            'skip' => 0,
            'limit' => 50,
            'items' => $laneSnapshot,
        ], $this->token('b'));
        $this->assertStatus($lanesAfterDelete, 200);
        $this->assertLazySyncItems($lanesAfterDelete, 'lane delete emits patch');
        $this->assertDeletedLazySyncItem($lanesAfterDelete, $this->laneId('extra'), 'deleted lane marked deleted');

        $deletedLaneView = $this->json('GET', '/lanes/' . $this->laneId('extra'), null, $this->token('a'));
        $this->assertStatus($deletedLaneView, 404);
        $this->assertSame('Lane not found', $deletedLaneView['json']['error'] ?? null, 'deleted lane gone');

        $deleteRoute = $this->json('DELETE', '/routes/' . $this->routeId('main'), null, $this->token('a'));
        $this->assertStatus($deleteRoute, 200);

        $routesAfterDelete = $this->json('POST', '/sync/routes', [
            'skip' => 0,
            'limit' => 50,
            'items' => $routeSnapshot,
        ], $this->token('b'));
        $this->assertStatus($routesAfterDelete, 200);
        $this->assertLazySyncItems($routesAfterDelete, 'route delete emits patch');
        $this->assertDeletedLazySyncItem($routesAfterDelete, $this->routeId('main'), 'deleted route marked deleted');

        $deletedRoute = $this->json('GET', '/routes/' . $this->routeId('main'), null, $this->token('a'));
        $this->assertStatus($deletedRoute, 404);
        $this->assertSame('Route not found', $deletedRoute['json']['error'] ?? null, 'deleted route gone');

        $remainingRoutes = $this->json('GET', '/routes', null, $this->token('a'));
        $this->assertStatus($remainingRoutes, 200);
        $routeIds = array_map(static fn (array $item): int => (int) $item['route_id'], $remainingRoutes['json']['items'] ?? []);
        $this->assertTrue(!in_array($this->routeId('main'), $routeIds, true), 'deleted route removed from listing');
    }

    private function resetSchema(): void
    {
        $this->step('Reset schema');
        DB::dropAllTables(true, true);

        Agent::initDataStructure();
        AgentMeta::initDataStructure();
        AgentPresence::initDataStructure();
        AgentSession::initDataStructure();
        Route::initDataStructure();
        RouteMeta::initDataStructure();
        Subscription::initDataStructure();
        Lane::initDataStructure();
        LaneMeta::initDataStructure();
        LaneReadState::initDataStructure();
        Payload::initDataStructure();
        PayloadMeta::initDataStructure();
        UpdateLog::initDataStructure();

        $this->assertTrue(true, 'schema reset complete');
    }

    private function startServer(): void
    {
        $this->step('Start local server');

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new RuntimeException('Failed to allocate local port: ' . $errstr);
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        if (!is_string($name) || !str_contains($name, ':')) {
            throw new RuntimeException('Failed to read allocated local port');
        }
        $this->port = (int) substr(strrchr($name, ':'), 1);
        $this->baseUrl = 'http://127.0.0.1:' . $this->port;
        $this->serverLogPath = '/home/web/tmp/cakk-transport-acceptance-server.log';

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['file', $this->serverLogPath, 'a'],
            2 => ['file', $this->serverLogPath, 'a'],
        ];

        $this->serverProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, 'index.php'],
            $descriptorSpec,
            $this->serverPipes,
            $this->projectRoot,
            ['PHP_CLI_SERVER_WORKERS' => '4']
        );

        if (!is_resource($this->serverProcess)) {
            throw new RuntimeException('Failed to start local PHP server');
        }

        $ready = false;
        for ($attempt = 0; $attempt < 30; $attempt++) {
            usleep(200000);
            $response = $this->raw('GET', '/health', null, null, [], false);
            if (($response['status'] ?? 0) === 200) {
                $ready = true;
                break;
            }
        }

        if (!$ready) {
            throw new RuntimeException('Local server did not become ready. Log: ' . $this->serverLogPath);
        }

        $this->assertTrue(true, 'server is ready on ' . $this->baseUrl);
    }

    private function stopServer(): void
    {
        if (is_array($this->serverPipes)) {
            foreach ($this->serverPipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            $this->serverPipes = null;
        }

        if (is_resource($this->serverProcess)) {
            proc_terminate($this->serverProcess);
            proc_close($this->serverProcess);
            $this->serverProcess = null;
        }
    }

    /**
     * @param array<string, mixed>|null $jsonBody
     * @return array{status:int,headers:array<int,string>,body:string,json:array<string,mixed>}
     */
    private function json(string $method, string $path, ?array $jsonBody = null, ?string $token = null): array
    {
        $headers = ['Content-Type: application/json'];
        $body = $jsonBody !== null ? json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $response = $this->raw($method, $path, $body, $token, $headers);
        $decoded = [];
        if ($response['body'] !== '') {
            $decoded = json_decode($response['body'], true);
            if (!is_array($decoded)) {
                throw new RuntimeException('Expected JSON object response for ' . $method . ' ' . $path . ': ' . $response['body']);
            }
        }
        $response['json'] = $decoded;

        return $response;
    }

    /**
     * @param list<string> $headers
     * @return array{status:int,headers:array<int,string>,body:string,json:array<string,mixed>}
     */
    private function raw(
        string $method,
        string $path,
        ?string $body = null,
        ?string $token = null,
        array $headers = [],
        bool $failOnTransportError = true,
    ): array {
        $httpHeaders = $headers;
        if ($token !== null) {
            $httpHeaders[] = 'Authorization: Bearer ' . $token;
        }
        if ($body !== null && !array_filter($httpHeaders, static fn (string $header): bool => stripos($header, 'Content-Length:') === 0)) {
            $httpHeaders[] = 'Content-Length: ' . strlen($body);
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $httpHeaders),
                'content' => $body ?? '',
                'ignore_errors' => true,
                'timeout' => 5,
            ],
        ]);

        $httpResponseHeader = [];
        $responseBody = @file_get_contents($this->baseUrl . $path, false, $context);
        if ($responseBody === false) {
            if (!$failOnTransportError) {
                return [
                    'status' => 0,
                    'headers' => [],
                    'body' => '',
                    'json' => [],
                ];
            }

            throw new RuntimeException('HTTP request failed: ' . $method . ' ' . $path);
        }

        /** @var array<int,string> $http_response_header */
        $headersOut = $http_response_header ?? [];
        $status = $this->parseStatusCode($headersOut);

        $decoded = [];
        if ($responseBody !== '') {
            $maybeJson = json_decode($responseBody, true);
            if (is_array($maybeJson)) {
                $decoded = $maybeJson;
            }
        }

        return [
            'status' => $status,
            'headers' => $headersOut,
            'body' => $responseBody,
            'json' => $decoded,
        ];
    }

    /**
     * @param array{json:array<string, mixed>} $response
     */
    private function assertLazySyncItems(array $response, string $label): void
    {
        $items = $response['json']['items'] ?? null;
        $this->assertTrue(is_array($items), $label . ' returns items array');
        $this->assertTrue(count($items) >= 1, $label . ' returns items');
        $this->assertTrue(array_key_exists('id', $items[0] ?? []), $label . ' item contains id');
        $this->assertTrue(array_key_exists('revision', $items[0] ?? []), $label . ' item contains revision');
        $this->assertTrue(array_key_exists('is_deleted', $items[0] ?? []), $label . ' item contains is_deleted');
    }

    /**
     * @param array{json:array<string, mixed>} $response
     */
    private function assertDeletedLazySyncItem(array $response, int $id, string $label): void
    {
        $items = $response['json']['items'] ?? [];
        foreach ($items as $item) {
            if (!is_array($item) || (int) ($item['id'] ?? 0) !== $id) {
                continue;
            }

            $this->assertSame(true, (bool) ($item['is_deleted'] ?? false), $label);
            return;
        }

        throw new RuntimeException($label . ': item not found in sync patch');
    }

    /**
     * @param array{json:array<string, mixed>} $response
     * @return list<array{id:int,revision:string}>
     */
    private function toLazySyncSnapshot(array $response): array
    {
        $items = $response['json']['items'] ?? [];
        $snapshot = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = (int) ($item['id'] ?? 0);
            $revision = (string) ($item['revision'] ?? '');
            if ($id <= 0 || $revision === '') {
                continue;
            }
            $snapshot[] = [
                'id' => $id,
                'revision' => $revision,
            ];
        }

        return $snapshot;
    }

    /**
     * @param array{status:int,headers:array<int,string>,body:string,json:array<string,mixed>} $response
     */
    private function assertStatus(array $response, int $status): void
    {
        $this->assertions++;
        if ($response['status'] !== $status) {
            throw new RuntimeException(sprintf(
                'Assertion failed: HTTP status. Expected %d, got %d. Body: %s',
                $status,
                (int) $response['status'],
                $response['body']
            ));
        }
        $this->out('  ok  HTTP status');
    }

    private function assertTrue(bool $condition, string $message): void
    {
        $this->assertions++;
        if (!$condition) {
            throw new RuntimeException('Assertion failed: ' . $message);
        }
        $this->out('  ok  ' . $message);
    }

    private function assertSame(mixed $expected, mixed $actual, string $message): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            throw new RuntimeException(sprintf(
                'Assertion failed: %s. Expected %s, got %s',
                $message,
                var_export($expected, true),
                var_export($actual, true)
            ));
        }
        $this->out('  ok  ' . $message);
    }

    /**
     * @param array{body:string,json:array<string,mixed>} $response
     */
    private function assertEmptyMetaObjectResponse(array $response, string $message): void
    {
        $this->assertions++;
        $meta = $response['json']['meta'] ?? null;
        if (!is_array($meta) || $meta !== [] || !str_contains($response['body'], '"meta":{}')) {
            throw new RuntimeException(sprintf(
                'Assertion failed: %s. Expected empty meta object, got body %s',
                $message,
                $response['body']
            ));
        }
        $this->out('  ok  ' . $message);
    }

    /**
     * @param array{body:string} $response
     */
    private function assertEmbeddedMetaObject(array $response, string $prefix, string $message): void
    {
        $this->assertions++;
        if (!str_contains($response['body'], $prefix) || !str_contains($response['body'], '"meta":{}')) {
            throw new RuntimeException(sprintf(
                'Assertion failed: %s. Expected embedded empty meta object, got body %s',
                $message,
                $response['body']
            ));
        }
        $this->out('  ok  ' . $message);
    }

    private function rememberAgent(string $label, array $response): void
    {
        $this->context['agents'][$label] = (int) $response['json']['agent']['agent_id'];
        $this->context['passwords'][$label] = (string) $response['json']['password'];
        $this->context['tokens'][$label] = (string) $response['json']['session_token'];
        $this->context['sessions'][$label] = (int) $response['json']['session']['session_id'];
    }

    private function rememberRoute(string $label, array $response): void
    {
        $this->context['routes'][$label] = (int) $response['json']['route']['route_id'];
        $this->context['lanes'][$label . '_default'] = (int) $response['json']['default_lane']['lane_id'];
    }

    /**
     * @param array{json:array<string,mixed>} $response
     * @return array<string,string>|null
     */
    private function findRouteMetaInList(array $response, int $routeId): ?array
    {
        foreach (($response['json']['items'] ?? []) as $item) {
            if ((int) ($item['route_id'] ?? 0) !== $routeId) {
                continue;
            }

            $meta = $item['meta'] ?? null;
            return is_array($meta) ? $meta : null;
        }

        return null;
    }

    /**
     * @param array{json:array<string,mixed>} $response
     */
    private function findSubscriptionRole(array $response, int $agentId): ?string
    {
        foreach (($response['json']['items'] ?? []) as $item) {
            if ((int) ($item['agent_id'] ?? 0) !== $agentId) {
                continue;
            }

            return is_string($item['role'] ?? null) ? $item['role'] : null;
        }

        return null;
    }

    private function rememberLane(string $label, array $response): void
    {
        $this->context['lanes'][$label] = (int) $response['json']['lane']['lane_id'];
    }

    private function rememberPayload(string $label, array $response): void
    {
        $this->context['payloads'][$label] = (int) $response['json']['payload']['payload_id'];
    }

    private function rememberPayloadMeta(string $label, array $response): void
    {
        $this->context['payload_meta'][$label] = (int) $response['json']['payload_meta']['payload_meta_id'];
    }

    private function agentId(string $label): int
    {
        return (int) $this->context['agents'][$label];
    }

    private function password(string $label): string
    {
        return (string) $this->context['passwords'][$label];
    }

    private function token(string $label): string
    {
        return (string) $this->context['tokens'][$label];
    }

    private function routeId(string $label): int
    {
        return (int) $this->context['routes'][$label];
    }

    private function laneId(string $label): int
    {
        return (int) $this->context['lanes'][$label];
    }

    private function payloadId(string $label): int
    {
        return (int) $this->context['payloads'][$label];
    }

    private function payloadMetaId(string $label): int
    {
        return (int) $this->context['payload_meta'][$label];
    }

    /**
     * @param list<string> $headers
     */
    private function parseStatusCode(array $headers): int
    {
        if ($headers === []) {
            return 0;
        }
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $headers[0], $matches) !== 1) {
            return 0;
        }

        return (int) $matches[1];
    }

    /**
     * @param array{headers:array<int,string>} $response
     */
    private function responseHeaderContains(array $response, string $headerName, string $needle): bool
    {
        foreach ($response['headers'] as $header) {
            if (stripos($header, $headerName) !== 0) {
                continue;
            }

            return str_contains($header, $needle);
        }

        return false;
    }

    private function step(string $title): void
    {
        $this->step++;
        $this->out(sprintf("\n[%02d] %s", $this->step, $title));
    }

    private function out(string $line): void
    {
        fwrite(STDOUT, $line . PHP_EOL);
    }
}

try {
    (new AcceptanceRunner())->run();
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "\nFAIL: " . $error->getMessage() . PHP_EOL);
    exit(1);
}
