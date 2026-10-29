<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Create a bed within a ward (24-bed-and-ward-management-domain.md §9; ADR-148 D-G).
 */
final readonly class CreateBed implements Auditable, AuthorizesAgainst
{
    /**
     * @param  list<string>|array<string, mixed>  $features
     */
    public function __construct(
        public string $bedId,
        public string $facilityId,
        public string $wardId,
        public ?string $roomId,
        public string $actorId,
        public string $label,
        public string $bedClass,
        public array $features = [],
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($bedId)) {
            throw new InvalidArgumentException('CreateBed::$bedId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('CreateBed::$facilityId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($wardId)) {
            throw new InvalidArgumentException('CreateBed::$wardId must be a UUID (IC §3).');
        }

        if ($roomId !== null && ! Str::isUuid($roomId)) {
            throw new InvalidArgumentException('CreateBed::$roomId must be a UUID or null (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('CreateBed::$actorId must be a UUID (IC §3).');
        }

        if (trim($label) === '') {
            throw new InvalidArgumentException('CreateBed::$label must not be empty.');
        }

        if (trim($bedClass) === '') {
            throw new InvalidArgumentException('CreateBed::$bedClass must not be empty.');
        }
    }

    public function policyAbility(): string
    {
        return 'create';
    }

    public function subjectId(): ?string
    {
        return null;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'bed_ward.bed_created',
            actorId: $this->actorId,
            targetType: 'bed',
            targetId: $this->bedId,
            metadata: [
                'facility_id' => $this->facilityId,
                'ward_id' => $this->wardId,
                'room_id' => $this->roomId,
                'label' => $this->label,
                'bed_class' => $this->bedClass,
            ],
        );
    }
}
