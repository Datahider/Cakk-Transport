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
        $route = new Route(['id' => (int) $this->route_id]);
        $route->write('', [Route::NOW => $this->revision, Route::REVISION_ONLY => true]);
        parent::intranInsert($comment, $data);
    }

    protected function intranUpdate($comment, $data)
    {
        $route = new Route(['id' => (int) $this->route_id]);
        $route->write('', [Route::NOW => $this->revision, Route::REVISION_ONLY => true]);
        parent::intranUpdate($comment, $data);
    }

    protected function intranDelete($comment, $data)
    {
        $now = new \DateTimeImmutable();
        $route = new Route(['id' => (int) $this->route_id]);
        $route->write('', [Route::NOW => $now, Route::REVISION_ONLY => true]);
        parent::intranDelete($comment, $data);
    }
}
