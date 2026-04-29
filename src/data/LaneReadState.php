<?php

declare(strict_types=1);

namespace CakkTransport\data;

final class LaneReadState extends UpdatableTransportDBObject
{
    public const METADATA = [
        'id' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
        'lane_id' => 'BIGINT UNSIGNED NOT NULL',
        'agent_id' => 'BIGINT UNSIGNED NOT NULL',
        'last_read_payload_id' => 'BIGINT UNSIGNED NULL',
        'created_at' => 'DATETIME(6) NOT NULL',
        'updated_at' => 'DATETIME(6) NOT NULL',
        'revision' => 'DATETIME(6) NOT NULL',
        'read_at' => 'DATETIME(6) NOT NULL',
        'PRIMARY KEY' => 'id',
        'UNIQUE INDEX lane_agent' => ['lane_id', 'agent_id'],
        'INDEX agent_id' => 'agent_id',
        'INDEX revision' => 'revision',
    ];
    
    #[\Override]
    public function write($comment = '', $data = null) {
        $this->isModified() && $this->read_at = new \DateTimeImmutable();
        parent::write($comment, $data);
    }
}
