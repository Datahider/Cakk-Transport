<?php

declare(strict_types=1);

namespace CakkTransport\data;

use losthost\DB\DBObject;

final class Subscription extends DBObject
{
    public const ROLE_OWNER = 'owner';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_EDITOR = 'editor';
    public const ROLE_PUBLISHER = 'publisher';
    public const ROLE_GUEST = 'guest';

    public const METADATA = [
        'id' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
        'route_id' => 'BIGINT UNSIGNED NOT NULL',
        'agent_id' => 'BIGINT UNSIGNED NOT NULL',
        'role' => 'ENUM("owner","admin","editor","publisher","guest") NOT NULL',
        'created_at' => 'DATETIME NOT NULL',
        'PRIMARY KEY' => 'id',
        'UNIQUE INDEX route_agent' => ['route_id', 'agent_id'],
        'INDEX agent_id' => 'agent_id',
    ];
}
