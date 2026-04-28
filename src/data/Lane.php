<?php

declare(strict_types=1);

namespace CakkTransport\data;

use losthost\DB\DBObject;

final class Lane extends DBObject
{
    public const METADATA = [
        'id' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
        'route_id' => 'BIGINT UNSIGNED NOT NULL',
        'is_default' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'created_by_agent_id' => 'BIGINT UNSIGNED NOT NULL',
        'payload_count' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'last_payload_id' => 'BIGINT UNSIGNED NULL',
        'created_at' => 'DATETIME NOT NULL',
        'updated_at' => 'DATETIME NOT NULL',
        'PRIMARY KEY' => 'id',
        'INDEX route_id' => 'route_id',
        'INDEX route_is_default' => ['route_id', 'is_default'],
        'INDEX created_by_agent_id' => 'created_by_agent_id',
    ];
}
