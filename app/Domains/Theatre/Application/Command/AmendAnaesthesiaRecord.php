<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Append an amendment to a signed anaesthesia record (17-theatre-domain.md;
 * ADR-155 D-E — the T.2 AmendOperativeNote precedent). The signed original is
 * never touched; the first amendment flips SIGNED → AMENDED. `$amendmentText`
 * / `$reason` are ROW-ONLY (ADR-136 deny-list — never on the event; `$reason`
 * IS on the audit row). Needs the senior key `theatre.anaesthesia.sign`.
 */
final readonly class AmendAnaesthesiaRecord implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $amendmentId,
        public string $recordId,
        public string $actorId,
        public string $amendmentText,
        public string $reason,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['amendmentId' => $amendmentId, 'recordId' => $recordId, 'actorId' => $actorId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("AmendAnaesthesiaRecord::\${$name} must be a UUID (IC §3).");
            }
        }

        if (trim($amendmentText) === '') {
            throw new InvalidArgumentException('AmendAnaesthesiaRecord::$amendmentText must not be blank.');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('AmendAnaesthesiaRecord::$reason must not be blank.');
        }
    }

    public function policyAbility(): string
    {
        return 'sign';
    }

    public function subjectId(): string
    {
        return $this->recordId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'theatre.anaesthesia_record_amended',
            actorId: $this->actorId,
            targetType: 'anaesthesia_record',
            targetId: $this->recordId,
            reason: trim($this->reason),
            metadata: [
                'amendment_id' => $this->amendmentId,
            ],
        );
    }
}
