<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Replace an anaesthesia record's content / timing / outcome while it is
 * still DRAFT (17-theatre-domain.md §36–§40; ADR-155 D-E). Frozen once
 * SIGNED. `$content` is ROW-ONLY (ADR-136 deny-list); emits NO event
 * (audit-only — the T.2 UpdateOperativeNoteContent precedent).
 */
final readonly class UpdateAnaesthesiaContent implements Auditable, AuthorizesAgainst
{
    /**
     * @param  array<string, mixed>|null  $content
     */
    public function __construct(
        public string $recordId,
        public string $actorId,
        public ?array $content = null,
        public ?string $startedAt = null,
        public ?string $endedAt = null,
        public ?string $outcome = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($recordId)) {
            throw new InvalidArgumentException('UpdateAnaesthesiaContent::$recordId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('UpdateAnaesthesiaContent::$actorId must be a UUID (IC §3).');
        }

        foreach (['startedAt' => $startedAt, 'endedAt' => $endedAt] as $name => $value) {
            if ($value !== null) {
                try {
                    CarbonImmutable::parse($value);
                } catch (Throwable) {
                    throw new InvalidArgumentException("UpdateAnaesthesiaContent::\${$name} must be an ISO-8601 datetime or null.");
                }
            }
        }

        if ($outcome !== null && trim($outcome) === '') {
            throw new InvalidArgumentException('UpdateAnaesthesiaContent::$outcome must be a non-blank string or null.');
        }

        if ($content === null && $startedAt === null && $endedAt === null && $outcome === null) {
            throw new InvalidArgumentException('UpdateAnaesthesiaContent needs at least one field to update.');
        }
    }

    public function policyAbility(): string
    {
        return 'record';
    }

    public function subjectId(): string
    {
        return $this->recordId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'theatre.anaesthesia_record_content_updated',
            actorId: $this->actorId,
            targetType: 'anaesthesia_record',
            targetId: $this->recordId,
        );
    }
}
