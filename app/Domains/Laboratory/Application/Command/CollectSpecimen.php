<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Record the collection of a specimen for a laboratory order
 * (14-laboratory-domain.md §31–§33; ADR-158 D-F).
 *
 * `$specimenId` is client-minted (UUIDv7). `facility_id` + `patient_id` are
 * SNAPSHOT from the order by the handler (wrong-patient safety, §224/§227) —
 * never from this command. `specimenType` / `containerType` / `collectionLocation`
 * are open-coded (normalised uppercase in the handler). `collectedByUserId`
 * defaults to the actor. `$occurredAt` (the collection time) follows the
 * ADR-132 backdate-reason rule.
 */
final readonly class CollectSpecimen implements Auditable, AuthorizesAgainst
{
    /** ADR-132 — beyond this, a backdated collection time must carry a `reason`. */
    public const BACKDATE_REASON_THRESHOLD_HOURS = 24;

    public function __construct(
        public string $specimenId,
        public string $actorId,
        public string $orderId,
        public string $specimenType,
        public ?string $containerType = null,
        public ?string $collectionLocation = null,
        public ?string $collectedByUserId = null,
        public ?string $recollectionOfSpecimenId = null,
        public ?string $occurredAt = null,
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['specimenId' => $specimenId, 'actorId' => $actorId, 'orderId' => $orderId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("CollectSpecimen::\${$name} must be a UUID (IC §3).");
            }
        }

        if (trim($specimenType) === '') {
            throw new InvalidArgumentException('CollectSpecimen::$specimenType must not be blank.');
        }

        if ($collectedByUserId !== null && ! Str::isUuid($collectedByUserId)) {
            throw new InvalidArgumentException('CollectSpecimen::$collectedByUserId must be a UUID or null (IC §3).');
        }

        if ($recollectionOfSpecimenId !== null && ! Str::isUuid($recollectionOfSpecimenId)) {
            throw new InvalidArgumentException('CollectSpecimen::$recollectionOfSpecimenId must be a UUID or null (IC §3).');
        }

        if ($occurredAt !== null) {
            try {
                $occurred = CarbonImmutable::parse($occurredAt);
            } catch (Throwable) {
                throw new InvalidArgumentException('CollectSpecimen::$occurredAt must be an ISO-8601 datetime or null.');
            }

            $now = CarbonImmutable::now();

            if ($occurred->greaterThan($now)) {
                throw new InvalidArgumentException('CollectSpecimen::$occurredAt must not be in the future.');
            }

            if ($occurred->lessThan($now->subHours(self::BACKDATE_REASON_THRESHOLD_HOURS))
                && ($reason === null || trim($reason) === '')) {
                throw new InvalidArgumentException(sprintf(
                    'CollectSpecimen::$reason is required when $occurredAt is more than %d hours in the past.',
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
            action: 'laboratory.specimen_collected',
            actorId: $this->actorId,
            targetType: 'lab_specimen',
            targetId: $this->specimenId,
            metadata: [
                'order_id' => $this->orderId,
                'specimen_type' => strtoupper(trim($this->specimenType)),
            ],
            reason: ($this->reason !== null && trim($this->reason) !== '') ? trim($this->reason) : null,
        );
    }
}
