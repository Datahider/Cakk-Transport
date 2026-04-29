<?php

declare(strict_types=1);

namespace CakkTransport\data;

final class PayloadMeta extends UpdatableTransportDBObject
{
    public const METADATA = [
        'id' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
        'payload_id' => 'BIGINT UNSIGNED NOT NULL',
        'agent_id' => 'BIGINT UNSIGNED NOT NULL',
        'meta_key' => 'VARCHAR(191) NOT NULL',
        'meta_value' => 'LONGTEXT NOT NULL',
        'created_at' => 'DATETIME(6) NOT NULL',
        'updated_at' => 'DATETIME(6) NOT NULL',
        'revision' => 'DATETIME(6) NOT NULL',
        'PRIMARY KEY' => 'id',
        'UNIQUE INDEX payload_agent_key' => ['payload_id', 'agent_id', 'meta_key'],
        'INDEX payload_id' => 'payload_id',
        'INDEX agent_id' => 'agent_id',
        'INDEX revision' => 'revision',
    ];

    protected function intranInsert($comment, $data)
    {
        $payload = new Payload(['id' => (int) $this->payload_id]);
        $payload->write('', $payload->revisionOnlyWrite($this->revision));
        parent::intranInsert($comment, $data);
    }

    protected function intranUpdate($comment, $data)
    {
        $payload = new Payload(['id' => (int) $this->payload_id]);
        $payload->write('', $payload->revisionOnlyWrite($this->revision));
        parent::intranUpdate($comment, $data);
    }

    protected function intranDelete($comment, $data)
    {
        $payload = new Payload(['id' => (int) $this->payload_id]);
        $payload->write('', $payload->revisionOnlyWrite(new \DateTimeImmutable()));
        parent::intranDelete($comment, $data);
    }
}
