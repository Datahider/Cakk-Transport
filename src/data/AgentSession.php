<?php

declare(strict_types=1);

namespace CakkTransport\data;

final class AgentSession extends TransportDBObject
{
    public const METADATA = [
        'id' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
        'agent_id' => 'BIGINT UNSIGNED NOT NULL',
        'token_hash' => 'CHAR(64) NOT NULL',
        'device_label' => 'VARCHAR(255) NULL',
        'created_at' => 'DATETIME(6) NOT NULL',
        'updated_at' => 'DATETIME(6) NOT NULL',
        'last_seen_at' => 'DATETIME(6) NOT NULL',
        'expires_at' => 'DATETIME(6) NOT NULL',
        'revoked_at' => 'DATETIME(6) NULL',
        'PRIMARY KEY' => 'id',
        'UNIQUE INDEX token_hash' => 'token_hash',
        'INDEX agent_id' => 'agent_id',
        'INDEX expires_at' => 'expires_at',
    ];
}
