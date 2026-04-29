<?php

declare(strict_types=1);

namespace CakkTransport\data;

use DateTimeImmutable;

abstract class UpdatableTransportDBObject extends TransportDBObject
{
    const REVISION_ONLY = 'revision_only';
    
    protected function beforeInsert($comment, $data)
    {
        $this->updated_at = $data[static::NOW];
        $this->revision = $data[static::NOW];
        parent::beforeInsert($comment, $data);
    }

    protected function beforeUpdate($comment, $data)
    {
        $this->revision = $data[static::NOW];
        if (empty($data[static::REVISION_ONLY])) {
            $this->updated_at = $data[static::NOW];
        }
        parent::beforeUpdate($comment, $data);
    }

}
