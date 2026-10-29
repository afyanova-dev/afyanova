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
 * Originate an anaesthesia record as DRAFT for a surgical case
 * (17-theatre-domain.md §36–§40; ADR-155 D-C/D-E).
 *
 * `$recordId` is client-minted (UUIDv7 — the T.2 CreateOperativeNote
 * precedent). `$anaesthesiaType` is normalised uppercase by the handler
 * (open-coded — §38, rajani ruling). `$content` is ROW-ONLY (ADR-136
 * deny-list). `facility_id` / `patient_id` / `encounter_id` are copied from
 * the SurgicalCase by the handler, never from this command.
 */
final readonly class RecordAnaesthesia implements Auditable, AuthorizesAgainst
{
    /**
     * @param  array<string, mixed>  $content
     */
    public function __construct(
        public string $recordId,
        public string $caseId,
        public string $actorId,
        public string $anaesthesiaType,
        public ?string $providerUserId = null,
        public ?string $startedAt = null,
        public ?string $endedAt = null,
        public array $content = [],
        public ?string $outcome = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['recordId' => $recordId, 'caseId' => $caseId, 'actorId' => $actorId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("RecordAnaesthesia::\${$name} must be a UUID (IC §3).");
            }
        }

        if (trim($anaesthesiaType) === '') {
            throw new InvalidArgumentException('RecordAnaesthesia::$anaesthesiaType must not be blank.');
        }

        if ($providerUserId !== null && ! Str::isUuid($providerUserId)) {
            throw new InvalidArgumentException('RecordAnaesthesia::$providerUserId must be a UUID or null (IC §3).');
        }

        foreach (['startedAt' => $startedAt, 'endedAt' => $endedAt] as $name => $value) {
            if ($value !== null) {
                try {
                    CarbonImmutable::parse($value);
                } catch (Throwable) {
                    throw new InvalidArgumentException("RecordAnaesthesia::\${$name} must be an ISO-8601 datetime or null.");
                }
            }
        }

        if ($outcome !== null && trim($outcome) === '') {
            throw new InvalidArgumentException('RecordAnaesthesia::$outcome must be a non-blank string or null.');
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
            action: 'theatre.anaesthesia_record_created',
            actorId: $this->actorId,
            targetType: 'anaesthesia_record',
            targetId: $this->recordId,
            metadata: [
                'case_id' => $this->caseId,
                'anaesthesia_type' => strtoupper(trim($this->anaesthesiaType)),
            ],
        );
    }
}
