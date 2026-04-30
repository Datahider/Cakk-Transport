<?php

declare(strict_types=1);

use CakkTransport\data\Payload;
use CakkTransport\data\PayloadMeta;
use CakkTransport\data\Lane;
use CakkTransport\data\LaneMeta;
use CakkTransport\data\LaneReadState;
use CakkTransport\data\Route;
use CakkTransport\data\RouteMeta;
use CakkTransport\data\Agent;
use CakkTransport\data\AgentMeta;
use CakkTransport\data\AgentPresence;
use CakkTransport\data\AgentSession;
use CakkTransport\data\Subscription;
use CakkTransport\data\UpdateLog;

require __DIR__ . '/../src/bootstrap.php';

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

echo "Schema is ready.\n";
