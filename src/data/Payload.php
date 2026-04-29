<?php

declare(strict_types=1);

namespace CakkTransport\data;

final class Payload extends UpdatableTransportDBObject
{
    public const METADATA = [
        'id' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
        'lane_id' => 'BIGINT UNSIGNED NOT NULL',
        'author_agent_id' => 'BIGINT UNSIGNED NOT NULL',
        'payload' => 'LONGBLOB NOT NULL',
        'payload_sha256' => 'CHAR(64) NOT NULL',
        'payload_size' => 'INT UNSIGNED NOT NULL',
        'created_at' => 'DATETIME(6) NOT NULL',
        'updated_at' => 'DATETIME(6) NOT NULL',
        'revision' => 'DATETIME(6) NOT NULL',
        'PRIMARY KEY' => 'id',
        'INDEX lane_id' => 'lane_id',
        'INDEX author_agent_id' => 'author_agent_id',
        'INDEX revision' => 'revision',
    ];

    protected function intranInsert($comment, $data)
    {
        $lane = new Lane(['id' => (int) $this->lane_id]);
        $lane->write('', [Lane::NOW => $this->revision, Lane::REVISION_ONLY => true]);
        parent::intranInsert($comment, $data);
    }

    protected function intranUpdate($comment, $data)
    {
        $lane = new Lane(['id' => (int) $this->lane_id]);
        $lane->write('', [Lane::NOW => $this->revision, Lane::REVISION_ONLY => true]);
        parent::intranUpdate($comment, $data);
    }

    protected function intranDelete($comment, $data)
    {
        $now = new \DateTimeImmutable();
        $lane = new Lane(['id' => (int) $this->lane_id]);
        $lane->write('', [Lane::NOW => $now, Lane::REVISION_ONLY => true]);
        parent::intranDelete($comment, $data);
    }
}
