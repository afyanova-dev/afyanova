<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Sign an operative note — content is frozen thereafter
 * (17-theatre-domain.md §80, §303 Invariant 30/31; ADR-152 D-D). Allowed
 * from DRAFT or REVIEWED. Needs the senior key `theatre.operative_notes.sign`.
 */
final readonly class SignOperativeNote implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $noteId,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($noteId)) {
            throw new InvalidArgumentException('SignOperativeNote::$noteId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('SignOperativeNote::$actorId must be a UUID (IC §3).');
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
            action: 'theatre.operative_note_signed',
            actorId: $this->actorId,
            targetType: 'operative_note',
            targetId: $this->noteId,
        );
    }
}
