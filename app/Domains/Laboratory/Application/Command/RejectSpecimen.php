<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The laboratory rejects a specimen (COLLECTED|RECEIVED → REJECTED)
 * (14-laboratory-domain.md §34–§35; ADR-158 D-F). `$reasonCode` is open-coded
 * (§34's list is illustrative — normalised uppercase in the handler);
 * `$detail` is free text, stored row-only + on the audit row, never in the
 * `SpecimenRejected` event (ADR-136 deny-list).
 */
final readonly class RejectSpecimen implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $specimenId,
        public string $actorId,
        public string $reasonCode,
        public ?string $detail = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($specimenId)) {
            throw new InvalidArgumentException('RejectSpecimen::$specimenId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('RejectSpecimen::$actorId must be a UUID (IC §3).');
        }

        if (trim($reasonCode) === '') {
            throw new InvalidArgumentException('RejectSpecimen::$reasonCode must not be blank (§34).');
        }
    }

    public function policyAbility(): string
    {
        return 'receive';
    }

    public function subjectId(): string
    {
        return $this->specimenId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'laboratory.specimen_rejected',
            actorId: $this->actorId,
            targetType: 'lab_specimen',
            targetId: $this->specimenId,
            reason: ($this->detail !== null && trim($this->detail) !== '') ? trim($this->detail) : null,
            metadata: ['reason_code' => strtoupper(trim($this->reasonCode))],
        );
    }
}
