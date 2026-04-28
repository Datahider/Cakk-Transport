<?php

declare(strict_types=1);

namespace CakkTransport\data;

use losthost\DB\DBObject;

final class LaneReadState extends DBObject
{
    public const METADATA = [
        'id' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
        'lane_id' => 'BIGINT UNSIGNED NOT NULL',
        'agent_id' => 'BIGINT UNSIGNED NOT NULL',
        'last_read_payload_id' => 'BIGINT UNSIGNED NULL',
        'read_at' => 'DATETIME NOT NULL',
        'PRIMARY KEY' => 'id',
        'UNIQUE INDEX lane_agent' => ['lane_id', 'agent_id'],
        'INDEX agent_id' => 'agent_id',
    ];
}
