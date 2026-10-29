<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Replace an operative note's content while it is still DRAFT or REVIEWED
 * (17-theatre-domain.md §77, §79; ADR-152 D-D/D-F). Frozen once SIGNED —
 * corrections then go through `AmendOperativeNote`. `$content` is ROW-ONLY
 * (ADR-136 deny-list); emits NO event (audit-only — the T.1
 * CompleteChecklistStage precedent).
 */
final readonly class UpdateOperativeNoteContent implements Auditable, AuthorizesAgainst
{
    /**
     * @param  array<string, mixed>  $content
     */
    public function __construct(
        public string $noteId,
        public string $actorId,
        public array $content,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($noteId)) {
            throw new InvalidArgumentException('UpdateOperativeNoteContent::$noteId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('UpdateOperativeNoteContent::$actorId must be a UUID (IC §3).');
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
            action: 'theatre.operative_note_content_updated',
            actorId: $this->actorId,
            targetType: 'operative_note',
            targetId: $this->noteId,
        );
    }
}
