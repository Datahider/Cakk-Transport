<?php

declare(strict_types=1);

namespace CakkTransport\data;

final class Route extends UpdatableTransportDBObject
{
    public const METADATA = [
        'id' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
        'zone' => 'CHAR(36) NOT NULL DEFAULT "00000000-0000-0000-0000-000000000000"',
        'owner_agent_id' => 'BIGINT UNSIGNED NOT NULL',
        'default_role' => 'VARCHAR(32) NOT NULL',
        'last_payload_id' => 'BIGINT UNSIGNED NULL',
        'is_deleted' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'created_at' => 'DATETIME(6) NOT NULL',
        'updated_at' => 'DATETIME(6) NOT NULL',
        'revision' => 'DATETIME(6) NOT NULL',
        'PRIMARY KEY' => 'id',
        'INDEX zone' => 'zone',
        'INDEX owner_agent_id' => 'owner_agent_id',
        'INDEX zone_is_deleted' => ['zone', 'is_deleted'],
        'INDEX revision' => 'revision',
    ];
}
