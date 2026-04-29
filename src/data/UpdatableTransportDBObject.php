<?php

declare(strict_types=1);

namespace CakkTransport\data;

use DateTimeImmutable;

abstract class UpdatableTransportDBObject extends TransportDBObject
{
    protected function beforeInsert($comment, $data)
    {
        $revision = $this->forcedRevision($data) ?? new DateTimeImmutable();
        $this->updated_at = $revision;
        $this->revision = $revision;
        parent::beforeInsert($comment, $data);
    }

    protected function beforeUpdate($comment, $data)
    {
        $revision = $this->forcedRevision($data) ?? new DateTimeImmutable();
        $this->revision = $revision;

        if (!$this->isRevisionOnlyWrite($data)) {
            $this->updated_at = $revision;
        }

        parent::beforeUpdate($comment, $data);
    }

    public function revisionOnlyWrite(mixed $revision): array
    {
        return [
            'revision_only' => true,
            'forced_revision' => $revision,
        ];
    }

    private function isRevisionOnlyWrite(mixed $data): bool
    {
        return is_array($data) && !empty($data['revision_only']);
    }

    private function forcedRevision(mixed $data): mixed
    {
        return is_array($data) && array_key_exists('forced_revision', $data)
            ? $data['forced_revision']
            : null;
    }
}
