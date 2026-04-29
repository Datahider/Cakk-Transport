<?php

declare(strict_types=1);

namespace CakkTransport\data;

use losthost\DB\DBObject;

abstract class TransportDBObject extends DBObject
{
    const NOW = 'write_moment';
    
    #[\Override]
    public function write($comment = '', $data = null)
    {
        $this->setNowData($data);
        return parent::write($comment, $data);
    }

    protected function beforeInsert($comment, $data)
    {
        $this->created_at = $data[static::NOW];
        parent::beforeInsert($comment, $data);
    }

    #[\Override]
    public function delete($comment = '', $data = null) {
        $this->setNowData($data);
        parent::delete($comment, $data);
    }
    
    protected function setNowData(&$data) {

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
        
    }
}
