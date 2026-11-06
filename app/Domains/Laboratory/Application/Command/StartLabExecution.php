<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class StartLabExecution implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $executionId,
        public string $actorId,
        public string $orderId,
        public string $orderItemId,
        public string $specimenId,
        public string $methodId,
        public ?string $instrumentId = null,
    ) {
        foreach ([
            'executionId' => $executionId,
            'actorId' => $actorId,
            'orderId' => $orderId,
            'orderItemId' => $orderItemId,
            'specimenId' => $specimenId,
            'methodId' => $methodId,
        ] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("StartLabExecution::\${$name} must be a UUID.");
            }
        }

        if ($instrumentId !== null && ! Str::isUuid($instrumentId)) {
            throw new InvalidArgumentException('StartLabExecution::$instrumentId must be a UUID or null.');
        }
    }

    public function policyAbility(): string
    {
        return 'execute';
    }

    public function subjectId(): ?string
    {
        return $this->orderId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'laboratory.execution_started',
            actorId: $this->actorId,
            targetType: 'lab_execution',
            targetId: $this->executionId,
            metadata: [
                'order_id' => $this->orderId,
                'order_item_id' => $this->orderItemId,
                'specimen_id' => $this->specimenId,
                'method_id' => $this->methodId,
                'instrument_id' => $this->instrumentId,
            ],
        );
    }
}
