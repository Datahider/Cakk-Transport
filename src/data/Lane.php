<?php

declare(strict_types=1);

namespace CakkTransport\data;

final class Lane extends UpdatableTransportDBObject
{
    public const METADATA = [
        'id' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
        'route_id' => 'BIGINT UNSIGNED NOT NULL',
        'is_default' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'created_by_agent_id' => 'BIGINT UNSIGNED NOT NULL',
        'payload_count' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'last_payload_id' => 'BIGINT UNSIGNED NULL',
        'created_at' => 'DATETIME(6) NOT NULL',
        'updated_at' => 'DATETIME(6) NOT NULL',
        'revision' => 'DATETIME(6) NOT NULL',
        'PRIMARY KEY' => 'id',
        'INDEX route_id' => 'route_id',
        'INDEX route_is_default' => ['route_id', 'is_default'],
        'INDEX created_by_agent_id' => 'created_by_agent_id',
        'INDEX revision' => 'revision',
    ];

    protected function intranInsert($comment, $data)
    {
        $data[static::REVISION_ONLY] = true;
        $route = new Route(['id' => (int) $this->route_id]);
        $route->write($comment, $data);
        parent::intranInsert($comment, $data);
    }

    protected function intranUpdate($comment, $data)
    {
        $data[static::REVISION_ONLY] = true;
        $route = new Route(['id' => (int) $this->route_id]);
        $route->write($comment, $data);
        parent::intranUpdate($comment, $data);
    }

    protected function intranDelete($comment, $data)
    {
        $data[static::REVISION_ONLY] = true;
        $route = new Route(['id' => (int) $this->route_id]);
        $route->write($comment, $data);
        parent::intranDelete($comment, $data);
    }
}
