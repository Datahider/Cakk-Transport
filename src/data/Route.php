<?php

declare(strict_types=1);

namespace CakkTransport\data;

use losthost\DB\DBObject;

final class Route extends DBObject
{
    public const METADATA = [
        'id' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
        'zone' => 'CHAR(36) NOT NULL DEFAULT "00000000-0000-0000-0000-000000000000"',
        'owner_agent_id' => 'BIGINT UNSIGNED NULL',
        'created_at' => 'DATETIME NOT NULL',
        'PRIMARY KEY' => 'id',
        'INDEX zone' => 'zone',
        'INDEX owner_agent_id' => 'owner_agent_id',
    ];
}
