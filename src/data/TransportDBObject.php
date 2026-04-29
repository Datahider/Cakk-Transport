<?php

declare(strict_types=1);

namespace CakkTransport\data;

use DateTimeImmutable;
use losthost\DB\DBObject;

abstract class TransportDBObject extends DBObject
{
    protected function beforeInsert($comment, $data)
    {
        if (!isset($this->created_at) || $this->created_at === null) {
            $this->created_at = new DateTimeImmutable();
        }
        $this->assertInvariants();
        parent::beforeInsert($comment, $data);
    }

    protected function beforeUpdate($comment, $data)
    {
        $this->assertInvariants();
        parent::beforeUpdate($comment, $data);
    }

    protected function assertInvariants(): void
    {
    }
}
