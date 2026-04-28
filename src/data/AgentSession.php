<?php

declare(strict_types=1);

namespace CakkTransport\data;

use losthost\DB\DBObject;

final class AgentSession extends DBObject
{
    public const METADATA = [
        'id' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
        'agent_id' => 'BIGINT UNSIGNED NOT NULL',
        'token_hash' => 'CHAR(64) NOT NULL',
        'device_label' => 'VARCHAR(255) NULL',
        'created_at' => 'DATETIME NOT NULL',
        'last_seen_at' => 'DATETIME NOT NULL',
        'expires_at' => 'DATETIME NOT NULL',
        'revoked_at' => 'DATETIME NULL',
        'PRIMARY KEY' => 'id',
        'UNIQUE INDEX token_hash' => 'token_hash',
        'INDEX agent_id' => 'agent_id',
        'INDEX expires_at' => 'expires_at',
    ];
}
