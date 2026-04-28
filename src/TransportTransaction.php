<?php

declare(strict_types=1);

namespace CakkTransport;

use CakkTransport\data\UpdateLog;
use DateTimeImmutable;
use RuntimeException;
use Throwable;
use losthost\DB\DB;
use losthost\DB\DBObject;

final class TransportTransaction
{
    public const OBJECT_AGENT = 'agent';
    public const OBJECT_AGENT_META = 'agent_meta';
    public const OBJECT_PRESENCE = 'presence';
    public const OBJECT_ROUTE = 'route';
    public const OBJECT_ROUTE_META = 'route_meta';
    public const OBJECT_SUBSCRIPTION = 'subscription';
    public const OBJECT_LANE = 'lane';
    public const OBJECT_LANE_META = 'lane_meta';
    public const OBJECT_LANE_READ_STATE = 'lane_read_state';
    public const OBJECT_PAYLOAD = 'payload';
    public const OBJECT_PAYLOAD_META = 'payload_meta';

    /** @var array<string, true> */
    private array $allowedKinds = [];

    /** @var array<string, true> */
    private array $touchedKinds = [];

    /** @var array<string, mixed>|null */
    private ?array $updateRecord = null;

    private bool $finished = false;

    /**
     * @param list<string> $allowedKinds
     */
    private function __construct(
        private readonly string $zone,
        private readonly ?int $agentId,
        array $allowedKinds,
    ) {
        foreach ($allowedKinds as $kind) {
            $this->allowedKinds[$kind] = true;
        }
    }

    /**
     * @param list<string> $allowedKinds
     */
    public static function begin(string $zone, ?int $agentId, array $allowedKinds): self
    {
        if ($allowedKinds === []) {
            throw new RuntimeException('Transport transaction must declare at least one allowed object kind');
        }

        DB::beginTransaction();

        return new self($zone, $agentId, array_values(array_unique($allowedKinds)));
    }

    public function write(string $kind, DBObject $object): void
    {
        $this->touch($kind);
        $object->write();
    }

    public function delete(string $kind, DBObject $object): void
    {
        $this->touch($kind);
        $object->delete();
    }

    public function touch(string $kind): void
    {
        $this->assertMutable();
        if (!isset($this->allowedKinds[$kind])) {
            throw new RuntimeException(sprintf('Object kind "%s" was changed outside declared beginTran scope', $kind));
        }
        $this->touchedKinds[$kind] = true;
    }

    /**
     * @param list<string> $coveredKinds
     * @param array<string, mixed> $data
     * @param array<string, int|null> $refs
     */
    public function updateLog(
        string $kind,
        array $coveredKinds,
        array $data,
        array $refs = [],
    ): void
    {
        $this->assertMutable();
        if ($this->updateRecord !== null) {
            throw new RuntimeException('Transport transaction may produce only one update_log record');
        }

        $normalizedCovered = array_values(array_unique($coveredKinds));
        foreach ($normalizedCovered as $coveredKind) {
            if (!isset($this->allowedKinds[$coveredKind])) {
                throw new RuntimeException(sprintf(
                    'Covered object kind "%s" is outside declared beginTran scope',
                    $coveredKind
                ));
            }
        }

        $this->updateRecord = [
            'kind' => $kind,
            'covered' => $normalizedCovered,
            'data' => $data,
            'refs' => $refs,
        ];
    }

    public function commit(): void
    {
        $this->assertMutable();

        try {
            if ($this->updateRecord === null) {
                throw new RuntimeException('Transport transaction must write exactly one update_log record');
            }

            $coveredKinds = [];
            foreach ($this->updateRecord['covered'] as $coveredKind) {
                $coveredKinds[$coveredKind] = true;
            }

            foreach (array_keys($this->touchedKinds) as $touchedKind) {
                if (!isset($coveredKinds[$touchedKind])) {
                    throw new RuntimeException(sprintf(
                        'Touched object kind "%s" is not covered by update_log',
                        $touchedKind
                    ));
                }
            }

            $update = new UpdateLog();
            $update->zone = $this->zone;
            $update->agent_id = $this->agentId;
            $update->kind = (string) $this->updateRecord['kind'];
            $update->route_id = $this->normalizeRef('route_id');
            $update->lane_id = $this->normalizeRef('lane_id');
            $update->payload_id = $this->normalizeRef('payload_id');
            $update->payload_meta_id = $this->normalizeRef('payload_meta_id');
            $update->covered_json = $this->encodeJson($this->updateRecord['covered']);
            $update->data_json = $this->encodeJson($this->updateRecord['data']);
            $update->created_at = new DateTimeImmutable();
            $update->write();

            DB::commit();
            $this->finished = true;
        } catch (Throwable $error) {
            if (DB::inTransaction()) {
                DB::rollBack();
            }
            $this->finished = true;
            throw $error;
        }
    }

    public function rollBack(): void
    {
        if (!$this->finished && DB::inTransaction()) {
            DB::rollBack();
        }
        $this->finished = true;
    }

    private function assertMutable(): void
    {
        if ($this->finished) {
            throw new RuntimeException('Transport transaction is already finished');
        }
    }

    private function normalizeRef(string $name): ?int
    {
        $value = $this->updateRecord['refs'][$name] ?? null;

        return is_int($value) ? $value : null;
    }

    private function encodeJson(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('Failed to encode transport transaction JSON payload');
        }

        return $json;
    }
}
