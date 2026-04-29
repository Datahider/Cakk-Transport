<?php

declare(strict_types=1);

namespace CakkTransport\data;

use DateTimeImmutable;
use losthost\DB\DBObject;

abstract class TransportDBObject extends DBObject
{
    const NOW = 'write_moment';
    
    public function write($comment = '', $data = null)
    {
        $now = new \DateTimeImmutable();
        
        if ($data === null) {
            $data = [static::NOW => $now];
        } elseif (is_array($data)) {
            if (empty($data[static::NOW])) {
                $data[static::NOW] = $now;
            }
        } else {    
            throw new \InvalidArgumentException('Transport write data must be an array or null.');
        }

        return parent::write($comment, $data);
    }

    protected function beforeInsert($comment, $data)
    {
        $this->created_at = $data[static::NOW];
        parent::beforeInsert($comment, $data);
    }


}
