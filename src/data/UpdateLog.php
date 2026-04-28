<?php

declare(strict_types=1);

namespace CakkTransport\data;

use losthost\DB\DBObject;

final class UpdateLog extends DBObject
{
    public const METADATA = [
        'id' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
        'zone' => 'CHAR(36) NOT NULL',
        'agent_id' => 'BIGINT UNSIGNED NULL',
        'kind' => 'VARCHAR(64) NOT NULL',
        'route_id' => 'BIGINT UNSIGNED NULL',
        'lane_id' => 'BIGINT UNSIGNED NULL',
        'payload_id' => 'BIGINT UNSIGNED NULL',
        'payload_meta_id' => 'BIGINT UNSIGNED NULL',
        'covered_json' => 'LONGTEXT NOT NULL',
        'data_json' => 'LONGTEXT NOT NULL',
        'created_at' => 'DATETIME NOT NULL',
        'PRIMARY KEY' => 'id',
        'INDEX zone_id' => ['zone', 'id'],
        'INDEX kind' => 'kind',
        'INDEX route_id' => 'route_id',
        'INDEX lane_id' => 'lane_id',
        'INDEX payload_id' => 'payload_id',
        'INDEX payload_meta_id' => 'payload_meta_id',
    ];
}
