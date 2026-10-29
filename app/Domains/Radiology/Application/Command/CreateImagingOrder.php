<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Command;

use App\Domains\Radiology\Domain\Exception\ImagingOrderHasNoItems;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Originate an imaging order as DRAFT (15-radiology-domain.md §18–§21;
 * ADR-165 D-E/D-G/D-J).
 *
 * `$orderId` is client-minted (UUIDv7) — the `CreateLabOrder` precedent.
 * `facility_id` is taken from the ENCOUNTER context by the handler (ADR-165
 * D-J), never from this command. `priority` is open-coded (normalised
 * uppercase/trim — §21, the Lab.1 convention). `$occurredAt` follows the
 * ADR-132 backdate-reason rule. `clinicalIndicationText` is free text (row-only,
 * ADR-136); `clinicalIndicationRef` is an unconstrained provenance pointer.
 *
 * @param  list<string>  $serviceIds
 */
final readonly class CreateImagingOrder implements Auditable, AuthorizesAgainst
{
    /** ADR-132 — beyond this, backdated origination must carry a `reason`. */
    public const BACKDATE_REASON_THRESHOLD_HOURS = 24;

    /** @var list<string> */
    public array $serviceIds;

    /**
     * @param  list<string>  $serviceIds
     */
    public function __construct(
        public string $orderId,
        public string $actorId,
        public string $patientId,
        public string $encounterId,
        array $serviceIds,
        public string $priority = 'ROUTINE',
        public ?string $clinicalIndicationRef = null,
        public ?string $clinicalIndicationText = null,
        public ?string $occurredAt = null,
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['orderId' => $orderId, 'actorId' => $actorId, 'patientId' => $patientId, 'encounterId' => $encounterId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("CreateImagingOrder::\${$name} must be a UUID (IC §3).");
            }
        }

        $ids = array_values(array_unique(array_map('strval', $serviceIds)));

        if ($ids === []) {
            throw ImagingOrderHasNoItems::forCreate();
        }

        foreach ($ids as $serviceId) {
            if (! Str::isUuid($serviceId)) {
                throw new InvalidArgumentException('CreateImagingOrder::$serviceIds must all be UUIDs (IC §3).');
            }
        }

        $this->serviceIds = $ids;

        if (trim($priority) === '') {
            throw new InvalidArgumentException('CreateImagingOrder::$priority must not be blank.');
        }

        if ($clinicalIndicationRef !== null && ! Str::isUuid($clinicalIndicationRef)) {
            throw new InvalidArgumentException('CreateImagingOrder::$clinicalIndicationRef must be a UUID or null (IC §3).');
        }

        if ($clinicalIndicationText !== null && trim($clinicalIndicationText) === '') {
            throw new InvalidArgumentException('CreateImagingOrder::$clinicalIndicationText must be a non-blank string or null.');
        }

        if ($occurredAt !== null) {
            try {
                $occurred = CarbonImmutable::parse($occurredAt);
            } catch (Throwable) {
                throw new InvalidArgumentException('CreateImagingOrder::$occurredAt must be an ISO-8601 datetime or null.');
            }

            $now = CarbonImmutable::now();

            if ($occurred->greaterThan($now)) {
                throw new InvalidArgumentException('CreateImagingOrder::$occurredAt must not be in the future.');
            }

            if ($occurred->lessThan($now->subHours(self::BACKDATE_REASON_THRESHOLD_HOURS))
                && ($reason === null || trim($reason) === '')) {
                throw new InvalidArgumentException(sprintf(
                    'CreateImagingOrder::$reason is required when $occurredAt is more than %d hours in the past.',
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
            action: 'radiology.order_created',
            actorId: $this->actorId,
            targetType: 'imaging_order',
            targetId: $this->orderId,
            metadata: [
                'patient_id' => $this->patientId,
                'encounter_id' => $this->encounterId,
                'priority' => strtoupper(trim($this->priority)),
                'service_count' => count($this->serviceIds),
            ],
            reason: ($this->reason !== null && trim($this->reason) !== '') ? trim($this->reason) : null,
        );
    }
}
