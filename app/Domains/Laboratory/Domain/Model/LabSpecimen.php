<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain\Model;

use App\Domains\Laboratory\Contracts\Event\SpecimenCollected;
use App\Domains\Laboratory\Contracts\Event\SpecimenReceived;
use App\Domains\Laboratory\Contracts\Event\SpecimenRecollectionRequested;
use App\Domains\Laboratory\Contracts\Event\SpecimenRejected;
use App\Domains\Laboratory\Database\Factories\LabSpecimenFactory;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * Laboratory specimen aggregate root (14-laboratory-domain.md §28–§40, §150,
 * §156; ADR-158 D-D/D-E).
 *
 * Emits SpecimenCollected, SpecimenReceived, SpecimenRejected,
 * SpecimenRecollectionRequested. `SpecimenAccepted` has no event (an internal
 * lab step — the §138 list names none). Every event is PHI-safe (ADR-136
 * deny-list — rejection_detail never carried).
 *
 * @domain Laboratory
 *
 * @authority authoritative
 *
 * @tenant required
 *
 * @facility required
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $facility_id
 * @property string $order_id
 * @property string $patient_id
 * @property string $accession_number
 * @property string $specimen_type
 * @property string|null $container_type
 * @property string|null $collection_location
 * @property string $collected_by_user_id
 * @property CarbonImmutable|null $collected_at
 * @property string|null $received_by_user_id
 * @property CarbonImmutable|null $received_at
 * @property string $status
 * @property string|null $rejection_reason_code
 * @property string|null $rejection_detail
 * @property CarbonImmutable|null $recollection_requested_at
 * @property string|null $recollection_of_specimen_id
 * @property string $created_by_user_id
 * @property CarbonImmutable|null $occurred_at
 * @property CarbonImmutable|null $recorded_at
 * @property CarbonImmutable|null $updated_at
 * @property int $version
 */
final class LabSpecimen extends AggregateRoot
{
    /** @use HasFactory<LabSpecimenFactory> */
    use HasFactory;

    public const STATUS_COLLECTED = 'COLLECTED';

    public const STATUS_RECEIVED = 'RECEIVED';

    public const STATUS_ACCEPTED = 'ACCEPTED';

    public const STATUS_REJECTED = 'REJECTED';

    /** Reserved for Lab.3a (result entry). */
    public const STATUS_PROCESSING = 'PROCESSING';

    /** Reserved for Lab.3a. */
    public const STATUS_COMPLETED = 'COMPLETED';

    protected $table = 'lab_specimens';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'order_id',
        'patient_id',
        'accession_number',
        'specimen_type',
        'container_type',
        'collection_location',
        'collected_by_user_id',
        'collected_at',
        'received_by_user_id',
        'received_at',
        'status',
        'rejection_reason_code',
        'rejection_detail',
        'recollection_requested_at',
        'recollection_of_specimen_id',
        'created_by_user_id',
        'occurred_at',
        'recorded_at',
        'version',
    ];

    protected static function newFactory(): LabSpecimenFactory
    {
        return LabSpecimenFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'collected_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'recollection_requested_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public static function collect(
        string $id,
        string $tenantId,
        string $facilityId,
        string $orderId,
        string $patientId,
        string $accessionNumber,
        string $specimenType,
        ?string $containerType,
        ?string $collectionLocation,
        string $collectedByUserId,
        string $createdByUserId,
        ?CarbonImmutable $collectedAt,
        ?string $recollectionOfSpecimenId,
    ): self {
        $now = CarbonImmutable::now();
        $collected = $collectedAt ?? $now;

        $specimen = new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'order_id' => $orderId,
            'patient_id' => $patientId,
            'accession_number' => $accessionNumber,
            'specimen_type' => $specimenType,
            'container_type' => $containerType,
            'collection_location' => $collectionLocation,
            'collected_by_user_id' => $collectedByUserId,
            'collected_at' => $collected,
            'received_by_user_id' => null,
            'received_at' => null,
            'status' => self::STATUS_COLLECTED,
            'rejection_reason_code' => null,
            'rejection_detail' => null,
            'recollection_requested_at' => null,
            'recollection_of_specimen_id' => $recollectionOfSpecimenId,
            'created_by_user_id' => $createdByUserId,
            'occurred_at' => $collected,
            'recorded_at' => $now,
        ]);

        $specimen->recordThat(new SpecimenCollected(
            eventId: (string) Str::uuid7(),
            tenantId: $tenantId,
            facilityId: $facilityId,
            specimenId: $id,
            orderId: $orderId,
            patientId: $patientId,
            accessionNumber: $accessionNumber,
            specimenType: $specimenType,
            occurredAt: $collected->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $specimen;
    }

    public function receive(string $receivedByUserId): void
    {
        $now = CarbonImmutable::now();
        $this->status = self::STATUS_RECEIVED;
        $this->received_by_user_id = $receivedByUserId;
        $this->received_at = $now;

        $this->recordThat(new SpecimenReceived(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            specimenId: (string) $this->id,
            orderId: (string) $this->order_id,
            accessionNumber: (string) $this->accession_number,
            occurredAt: $now->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function accept(): void
    {
        $this->status = self::STATUS_ACCEPTED;
    }

    public function reject(string $reasonCode, ?string $detail): void
    {
        $now = CarbonImmutable::now();
        $this->status = self::STATUS_REJECTED;
        $this->rejection_reason_code = $reasonCode;
        $this->rejection_detail = ($detail !== null && trim($detail) !== '') ? trim($detail) : null;

        $this->recordThat(new SpecimenRejected(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            specimenId: (string) $this->id,
            orderId: (string) $this->order_id,
            accessionNumber: (string) $this->accession_number,
            reasonCode: $reasonCode,
            occurredAt: $now->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /**
     * ADR-159 (Lab.3a) — the first result has been entered against this
     * ACCEPTED specimen. Eventless internal progression.
     */
    public function startProcessing(): void
    {
        $this->status = self::STATUS_PROCESSING;
    }

    public function requestRecollection(): void
    {
        $now = CarbonImmutable::now();
        $this->recollection_requested_at = $now;

        $this->recordThat(new SpecimenRecollectionRequested(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            specimenId: (string) $this->id,
            orderId: (string) $this->order_id,
            accessionNumber: (string) $this->accession_number,
            occurredAt: $now->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }
}
