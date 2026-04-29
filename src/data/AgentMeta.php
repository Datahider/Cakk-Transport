<?php

declare(strict_types=1);

namespace CakkTransport\data;

final class AgentMeta extends UpdatableTransportDBObject
{
    public const METADATA = [
        'id' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
        'agent_id' => 'BIGINT UNSIGNED NOT NULL',
        'meta_key' => 'VARCHAR(191) NOT NULL',
        'meta_value' => 'LONGTEXT NOT NULL',
        'created_at' => 'DATETIME(6) NOT NULL',
        'updated_at' => 'DATETIME(6) NOT NULL',
        'revision' => 'DATETIME(6) NOT NULL',
        'PRIMARY KEY' => 'id',
        'UNIQUE INDEX agent_key' => ['agent_id', 'meta_key'],
        'INDEX agent_id' => 'agent_id',
        'INDEX revision' => 'revision',
    ];

    protected function intranInsert($comment, $data)
    {
        $agent = new Agent(['id' => (int) $this->agent_id]);
        $agent->write('', $agent->revisionOnlyWrite($this->revision));
        parent::intranInsert($comment, $data);
    }

    protected function intranUpdate($comment, $data)
    {
        $agent = new Agent(['id' => (int) $this->agent_id]);
        $agent->write('', $agent->revisionOnlyWrite($this->revision));
        parent::intranUpdate($comment, $data);
    }

    protected function intranDelete($comment, $data)
    {
        $agent = new Agent(['id' => (int) $this->agent_id]);
        $agent->write('', $agent->revisionOnlyWrite(new \DateTimeImmutable()));
        parent::intranDelete($comment, $data);
    }
}
