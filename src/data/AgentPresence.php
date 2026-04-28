<?php

declare(strict_types=1);

namespace CakkTransport\data;

use losthost\DB\DBObject;

final class AgentPresence extends DBObject
{
    public const METADATA = [
        'id' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
        'agent_id' => 'BIGINT UNSIGNED NOT NULL',
        'connection_count' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'updated_at' => 'DATETIME NOT NULL',
        'PRIMARY KEY' => 'id',
        'UNIQUE INDEX agent_id' => 'agent_id',
    ];
}
