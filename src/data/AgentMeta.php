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
        'is_private' => 'TINYINT(1) NULL',
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
        $data[static::REVISION_ONLY] = true;
        $agent = new Agent(['id' => (int) $this->agent_id]);
        $agent->write($comment, $data);
        parent::intranInsert($comment, $data);
    }

    protected function intranUpdate($comment, $data)
    {
        $data[static::REVISION_ONLY] = true;
        $agent = new Agent(['id' => (int) $this->agent_id]);
        $agent->write($comment, $data);
        parent::intranUpdate($comment, $data);
    }

    protected function intranDelete($comment, $data)
    {
        $data[static::REVISION_ONLY] = true;
        $agent = new Agent(['id' => (int) $this->agent_id]);
        $agent->write($comment, $data);
        parent::intranDelete($comment, $data);
    }
}
