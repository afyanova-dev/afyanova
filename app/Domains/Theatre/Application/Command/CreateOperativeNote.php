<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Originate an operative note as DRAFT for a surgical case
 * (17-theatre-domain.md §77–§79; ADR-152 D-C/D-D/D-F).
 *
 * `$noteId` is client-minted (UUIDv7) — the `CreateClinicalNote` precedent.
 * `$content` is a governance-configurable map (§77 "clinically governed");
 * it is ROW-ONLY — never carried on an event or in audit metadata
 * (ADR-136 deny-list). `facility_id` / `patient_id` / `encounter_id` are
 * copied from the SurgicalCase by the handler, never from this command.
 */
final readonly class CreateOperativeNote implements Auditable, AuthorizesAgainst
{
    /**
     * @param  array<string, mixed>  $content
     */
    public function __construct(
        public string $noteId,
        public string $caseId,
        public string $actorId,
        public array $content = [],
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($noteId)) {
            throw new InvalidArgumentException('CreateOperativeNote::$noteId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($caseId)) {
            throw new InvalidArgumentException('CreateOperativeNote::$caseId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('CreateOperativeNote::$actorId must be a UUID (IC §3).');
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
            action: 'theatre.operative_note_created',
            actorId: $this->actorId,
            targetType: 'operative_note',
            targetId: $this->noteId,
            metadata: [
                'case_id' => $this->caseId,
            ],
        );
    }
}
