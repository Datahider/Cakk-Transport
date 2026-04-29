<?php

declare(strict_types=1);

namespace CakkTransport\data;

final class RouteMeta extends UpdatableTransportDBObject
{
    public const METADATA = [
        'id' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
        'route_id' => 'BIGINT UNSIGNED NOT NULL',
        'meta_key' => 'VARCHAR(191) NOT NULL',
        'meta_value' => 'LONGTEXT NOT NULL',
        'created_at' => 'DATETIME(6) NOT NULL',
        'updated_at' => 'DATETIME(6) NOT NULL',
        'revision' => 'DATETIME(6) NOT NULL',
        'PRIMARY KEY' => 'id',
        'UNIQUE INDEX route_key' => ['route_id', 'meta_key'],
        'INDEX route_id' => 'route_id',
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
