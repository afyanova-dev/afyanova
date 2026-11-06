<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Domains\Inventory\Domain\Quantity;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class RecordLabExecutionMaterialUse implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $useId,
        public string $actorId,
        public string $executionId,
        public string $requirementId,
        public string $locationId,
        public string $quantity,
        public ?string $batchNumber = null,
    ) {
        foreach ([
            'useId' => $useId,
            'actorId' => $actorId,
            'executionId' => $executionId,
            'requirementId' => $requirementId,
            'locationId' => $locationId,
        ] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("RecordLabExecutionMaterialUse::\${$name} must be a UUID.");
            }
        }

        if (! is_numeric($quantity) || ! Quantity::isPositive($quantity)) {
            throw new InvalidArgumentException('RecordLabExecutionMaterialUse::$quantity must be positive.');
        }

        if ($batchNumber !== null && trim($batchNumber) === '') {
            throw new InvalidArgumentException('RecordLabExecutionMaterialUse::$batchNumber must be null or non-blank.');
        }
    }

    public function policyAbility(): string
    {
        return 'execute';
    }

    public function subjectId(): ?string
    {
        return $this->executionId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'laboratory.material_use_prepared',
            actorId: $this->actorId,
            targetType: 'lab_execution_material_use',
            targetId: $this->useId,
            metadata: [
                'execution_id' => $this->executionId,
                'requirement_id' => $this->requirementId,
                'location_id' => $this->locationId,
                'quantity' => $this->quantity,
            ],
        );
    }
}
