<?php

declare(strict_types=1);

namespace CakkTransport\data;

use losthost\DB\DBObject;

final class Payload extends DBObject
{
    public const METADATA = [
        'id' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
        'lane_id' => 'BIGINT UNSIGNED NOT NULL',
        'author_agent_id' => 'BIGINT UNSIGNED NOT NULL',
        'payload' => 'LONGBLOB NOT NULL',
        'payload_sha256' => 'CHAR(64) NOT NULL',
        'payload_size' => 'INT UNSIGNED NOT NULL',
        'created_at' => 'DATETIME NOT NULL',
        'PRIMARY KEY' => 'id',
        'INDEX lane_id' => 'lane_id',
        'INDEX author_agent_id' => 'author_agent_id',
    ];
}
