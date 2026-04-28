<?php

declare(strict_types=1);

namespace CakkTransport\data;

use losthost\DB\DBObject;

final class AgentMeta extends DBObject
{
    public const METADATA = [
        'id' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
        'agent_id' => 'BIGINT UNSIGNED NOT NULL',
        'meta_key' => 'VARCHAR(191) NOT NULL',
        'meta_value_json' => 'LONGTEXT NOT NULL',
        'updated_at' => 'DATETIME NOT NULL',
        'PRIMARY KEY' => 'id',
        'UNIQUE INDEX agent_key' => ['agent_id', 'meta_key'],
        'INDEX agent_id' => 'agent_id',
    ];
}
