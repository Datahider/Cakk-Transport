<?php

declare(strict_types=1);

namespace CakkTransport\data;

final class Agent extends UpdatableTransportDBObject
{
    public const ZERO_ZONE = '00000000-0000-0000-0000-000000000000';

    public const METADATA = [
        'id' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
        'zone' => 'CHAR(36) NOT NULL DEFAULT "00000000-0000-0000-0000-000000000000"',
        'is_system' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'password_hash' => 'VARCHAR(255) NOT NULL',
        'created_at' => 'DATETIME(6) NOT NULL',
        'updated_at' => 'DATETIME(6) NOT NULL',
        'revision' => 'DATETIME(6) NOT NULL',
        'PRIMARY KEY' => 'id',
        'INDEX zone' => 'zone',
        'INDEX revision' => 'revision',
    ];
}
