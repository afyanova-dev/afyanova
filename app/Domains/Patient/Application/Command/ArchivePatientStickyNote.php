<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class ArchivePatientStickyNote implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $stickyNoteId,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['stickyNoteId' => $stickyNoteId, 'actorId' => $actorId] as $name => $id) {
            if (! Str::isUuid($id)) {
                throw new InvalidArgumentException("ArchivePatientStickyNote::\${$name} must be a UUID.");
            }
        }
    }

    public function policyAbility(): string
    {
        return 'archiveStickyNote';
    }

    public function subjectId(): string
    {
        return $this->stickyNoteId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'patient.sticky_note_archived',
            actorId: $this->actorId,
            targetType: 'patient_sticky_note',
            targetId: $this->stickyNoteId,
        );
    }
}
