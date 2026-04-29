<?php

declare(strict_types=1);

namespace CakkTransport\data;

final class Subscription extends UpdatableTransportDBObject
{
    public const ROLE_OWNER = 'owner';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_EDITOR = 'editor';
    public const ROLE_PUBLISHER = 'publisher';
    public const ROLE_GUEST = 'guest';

    public const METADATA = [
        'id' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
        'route_id' => 'BIGINT UNSIGNED NOT NULL',
        'agent_id' => 'BIGINT UNSIGNED NOT NULL',
        'role' => 'ENUM("owner","admin","editor","publisher","guest") NOT NULL',
        'created_at' => 'DATETIME(6) NOT NULL',
        'updated_at' => 'DATETIME(6) NOT NULL',
        'revision' => 'DATETIME(6) NOT NULL',
        'PRIMARY KEY' => 'id',
        'UNIQUE INDEX route_agent' => ['route_id', 'agent_id'],
        'INDEX agent_id' => 'agent_id',
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
