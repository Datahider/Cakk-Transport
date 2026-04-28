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
use losthost\DB\DB;

require __DIR__ . '/../vendor/autoload.php';

$configFile = __DIR__ . '/../etc/config.php';
if (!is_file($configFile)) {
    throw new RuntimeException('Missing etc/config.php');
}

require $configFile;

DB::connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PREF, DB_CHARSET);

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
