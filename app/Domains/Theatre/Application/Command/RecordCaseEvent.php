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
 * Record one intra-operative case event (17-theatre-domain.md §84–§87;
 * ADR-156 D-E). `$caseEventId` is client-minted. `$eventType` is open-coded,
 * normalised uppercase by the handler. `$note` is ROW-ONLY (ADR-136).
 */
final readonly class RecordCaseEvent implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $caseEventId,
        public string $caseId,
        public string $actorId,
        public string $eventType,
        public string $eventAt,
        public ?string $note = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['caseEventId' => $caseEventId, 'caseId' => $caseId, 'actorId' => $actorId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("RecordCaseEvent::\${$name} must be a UUID (IC §3).");
            }
        }

        if (trim($eventType) === '') {
            throw new InvalidArgumentException('RecordCaseEvent::$eventType must not be blank.');
        }

        try {
            CarbonImmutable::parse($eventAt);
        } catch (Throwable) {
            throw new InvalidArgumentException('RecordCaseEvent::$eventAt must be an ISO-8601 datetime.');
        }

        if ($note !== null && trim($note) === '') {
            throw new InvalidArgumentException('RecordCaseEvent::$note must be a non-blank string or null.');
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
            action: 'theatre.case_event_recorded',
            actorId: $this->actorId,
            targetType: 'case_event',
            targetId: $this->caseEventId,
            metadata: [
                'case_id' => $this->caseId,
                'event_type' => strtoupper(trim($this->eventType)),
            ],
        );
    }
}
