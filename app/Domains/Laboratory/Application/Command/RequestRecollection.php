<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Request recollection of a rejected specimen (14-laboratory-domain.md §36;
 * ADR-158 D-F). Operates on the REJECTED specimen — a later `CollectSpecimen`
 * links the replacement via `recollectionOfSpecimenId`. Rejecting a specimen
 * does NOT imply recollection (§36 — sometimes the test is simply cancelled).
 */
final readonly class RequestRecollection implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $specimenId,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($specimenId)) {
            throw new InvalidArgumentException('RequestRecollection::$specimenId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('RequestRecollection::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'recollect';
    }

    public function subjectId(): string
    {
        return $this->specimenId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'laboratory.specimen_recollection_requested',
            actorId: $this->actorId,
            targetType: 'lab_specimen',
            targetId: $this->specimenId,
        );
    }
}
