<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class CreatePatientStickyNote implements Auditable, AuthorizesAgainst
{
    public const MAX_LENGTH = 300;

    public function __construct(
        public string $stickyNoteId,
        public string $patientId,
        public string $actorId,
        public string $body,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['stickyNoteId' => $stickyNoteId, 'patientId' => $patientId, 'actorId' => $actorId] as $name => $id) {
            if (! Str::isUuid($id)) {
                throw new InvalidArgumentException("CreatePatientStickyNote::\${$name} must be a UUID.");
            }
        }

        $trimmed = trim($body);
        if ($trimmed === '' || mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('Sticky note must contain 1 to 300 characters.');
        }
    }

    public function policyAbility(): string
    {
        return 'createStickyNote';
    }

    public function subjectId(): ?string
    {
        return null;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'patient.sticky_note_created',
            actorId: $this->actorId,
            targetType: 'patient_sticky_note',
            targetId: $this->stickyNoteId,
            metadata: ['patient_id' => $this->patientId],
        );
    }
}
