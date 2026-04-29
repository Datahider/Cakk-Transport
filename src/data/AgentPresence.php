<?php

declare(strict_types=1);

namespace CakkTransport\data;

final class AgentPresence extends UpdatableTransportDBObject
{
    public const METADATA = [
        'id' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
        'agent_id' => 'BIGINT UNSIGNED NOT NULL',
        'connection_count' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'created_at' => 'DATETIME(6) NOT NULL',
        'updated_at' => 'DATETIME(6) NOT NULL',
        'revision' => 'DATETIME(6) NOT NULL',
        'PRIMARY KEY' => 'id',
        'UNIQUE INDEX agent_id' => 'agent_id',
        'INDEX revision' => 'revision',
    ];
}
