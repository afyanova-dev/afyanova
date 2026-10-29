<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Mark a DRAFT operative note REVIEWED (17-theatre-domain.md §79; ADR-152
 * D-D). Optional checkpoint — a note may also be signed directly from DRAFT.
 * Author-level permission (a registrar may review a junior's draft; only
 * signing needs the senior key — §80).
 */
final readonly class ReviewOperativeNote implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $noteId,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($noteId)) {
            throw new InvalidArgumentException('ReviewOperativeNote::$noteId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('ReviewOperativeNote::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'author';
    }

    public function subjectId(): string
    {
        return $this->noteId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'theatre.operative_note_reviewed',
            actorId: $this->actorId,
            targetType: 'operative_note',
            targetId: $this->noteId,
        );
    }
}
