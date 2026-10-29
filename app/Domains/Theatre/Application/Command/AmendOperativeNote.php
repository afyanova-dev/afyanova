<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Append an amendment to a signed operative note (17-theatre-domain.md §81,
 * §303 Invariant 32; ADR-152 D-D). The signed original is never touched;
 * the first amendment flips the note SIGNED → AMENDED. `$amendmentText` and
 * `$reason` are ROW-ONLY (ADR-136 deny-list — never on the event; `$reason`
 * IS carried on the audit row per §81's "reason" requirement, the
 * `CorrectOccupancy` precedent). Needs the senior key
 * `theatre.operative_notes.sign`.
 */
final readonly class AmendOperativeNote implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $amendmentId,
        public string $noteId,
        public string $actorId,
        public string $amendmentText,
        public string $reason,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($amendmentId)) {
            throw new InvalidArgumentException('AmendOperativeNote::$amendmentId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($noteId)) {
            throw new InvalidArgumentException('AmendOperativeNote::$noteId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('AmendOperativeNote::$actorId must be a UUID (IC §3).');
        }

        if (trim($amendmentText) === '') {
            throw new InvalidArgumentException('AmendOperativeNote::$amendmentText must not be blank (§81).');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('AmendOperativeNote::$reason must not be blank (§81).');
        }
    }

    public function policyAbility(): string
    {
        return 'sign';
    }

    public function subjectId(): string
    {
        return $this->noteId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'theatre.operative_note_amended',
            actorId: $this->actorId,
            targetType: 'operative_note',
            targetId: $this->noteId,
            metadata: [
                'amendment_id' => $this->amendmentId,
            ],
            reason: trim($this->reason),
        );
    }
}
