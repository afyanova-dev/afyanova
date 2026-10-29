<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Domains\Laboratory\Domain\Exception\LabOrderHasNoItems;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Originate a laboratory order as DRAFT (14-laboratory-domain.md §21–§24;
 * ADR-157 D-D/D-F/D-I).
 *
 * `$orderId` is client-minted (UUIDv7) — the `CreateClinicalNote` precedent.
 * `facility_id` is taken from the ENCOUNTER context by the handler (ADR-157
 * D-I), never from this command. `priority` is open-coded (normalised
 * uppercase/trim, not validated against a fixed list — §25's "exact vocabulary
 * is configurable", the `ward_type` convention). `$occurredAt` follows the
 * ADR-132 backdate-reason rule. `clinicalIndicationText` is free text (row-only,
 * ADR-136); `clinicalIndicationRef` is an unconstrained provenance pointer.
 *
 * @param  list<string>  $testDefinitionIds
 * @param  list<string>  $panelIds  ADR-162 (Lab.4) — each panel expands to its member tests
 */
final readonly class CreateLabOrder implements Auditable, AuthorizesAgainst
{
    /** ADR-132 — beyond this, backdated origination must carry a `reason`. */
    public const BACKDATE_REASON_THRESHOLD_HOURS = 24;

    /** @var list<string> */
    public array $testDefinitionIds;

    /** @var list<string> */
    public array $panelIds;

    /**
     * @param  list<string>  $testDefinitionIds
     * @param  list<string>  $panelIds
     */
    public function __construct(
        public string $orderId,
        public string $actorId,
        public string $patientId,
        public string $encounterId,
        array $testDefinitionIds,
        array $panelIds = [],
        public string $priority = 'ROUTINE',
        public ?string $clinicalIndicationRef = null,
        public ?string $clinicalIndicationText = null,
        public ?string $occurredAt = null,
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($orderId)) {
            throw new InvalidArgumentException('CreateLabOrder::$orderId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('CreateLabOrder::$actorId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($patientId)) {
            throw new InvalidArgumentException('CreateLabOrder::$patientId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($encounterId)) {
            throw new InvalidArgumentException('CreateLabOrder::$encounterId must be a UUID (IC §3).');
        }

        $ids = array_values(array_unique(array_map('strval', $testDefinitionIds)));
        $panels = array_values(array_unique(array_map('strval', $panelIds)));

        if ($ids === [] && $panels === []) {
            throw LabOrderHasNoItems::forCreate();
        }

        foreach ($ids as $definitionId) {
            if (! Str::isUuid($definitionId)) {
                throw new InvalidArgumentException('CreateLabOrder::$testDefinitionIds must all be UUIDs (IC §3).');
            }
        }

        foreach ($panels as $panelId) {
            if (! Str::isUuid($panelId)) {
                throw new InvalidArgumentException('CreateLabOrder::$panelIds must all be UUIDs (IC §3).');
            }
        }

        $this->testDefinitionIds = $ids;
        $this->panelIds = $panels;

        if (trim($priority) === '') {
            throw new InvalidArgumentException('CreateLabOrder::$priority must not be blank.');
        }

        if ($clinicalIndicationRef !== null && ! Str::isUuid($clinicalIndicationRef)) {
            throw new InvalidArgumentException('CreateLabOrder::$clinicalIndicationRef must be a UUID or null (IC §3).');
        }

        if ($occurredAt !== null) {
            try {
                $occurred = CarbonImmutable::parse($occurredAt);
            } catch (Throwable) {
                throw new InvalidArgumentException('CreateLabOrder::$occurredAt must be an ISO-8601 datetime or null.');
            }

            $now = CarbonImmutable::now();

            if ($occurred->greaterThan($now)) {
                throw new InvalidArgumentException('CreateLabOrder::$occurredAt must not be in the future.');
            }

            if ($occurred->lessThan($now->subHours(self::BACKDATE_REASON_THRESHOLD_HOURS))
                && ($reason === null || trim($reason) === '')) {
                throw new InvalidArgumentException(sprintf(
                    'CreateLabOrder::$reason is required when $occurredAt is more than %d hours in the past.',
                    self::BACKDATE_REASON_THRESHOLD_HOURS,
                ));
            }
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
            action: 'laboratory.order_created',
            actorId: $this->actorId,
            targetType: 'lab_order',
            targetId: $this->orderId,
            metadata: [
                'patient_id' => $this->patientId,
                'encounter_id' => $this->encounterId,
                'priority' => $this->priority,
                'test_count' => count($this->testDefinitionIds),
                'panel_count' => count($this->panelIds),
            ],
            reason: ($this->reason !== null && trim($this->reason) !== '') ? trim($this->reason) : null,
        );
    }
}
