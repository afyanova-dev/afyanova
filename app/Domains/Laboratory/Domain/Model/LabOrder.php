<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain\Model;

use App\Domains\Laboratory\Contracts\Event\LabOrderAccepted;
use App\Domains\Laboratory\Contracts\Event\LabOrderCancelled;
use App\Domains\Laboratory\Contracts\Event\LabOrderCreated;
use App\Domains\Laboratory\Contracts\Event\LabOrderRejected;
use App\Domains\Laboratory\Contracts\Event\LabOrderSubmitted;
use App\Domains\Laboratory\Database\Factories\LabOrderFactory;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * Laboratory order aggregate root (14-laboratory-domain.md §21–§24; ADR-157
 * D-D/D-E).
 *
 * Emits LabOrderCreated (on create → DRAFT), LabOrderSubmitted (DRAFT →
 * ORDERED), LabOrderAccepted (ORDERED → ACCEPTED), LabOrderRejected (ORDERED →
 * REJECTED), LabOrderCancelled (DRAFT|ORDERED → CANCELLED). Every event is
 * PHI-safe (ADR-136 deny-list — clinical_indication_text / rejection_reason /
 * cancellation_reason never carried).
 *
 * The ordered tests live on `lab_order_items` (a plain child, written by
 * `CreateLabOrderHandler`), not on this aggregate (§149 — "should not contain
 * all specimen/result internals").
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
 * @property string $patient_id
 * @property string $encounter_id
 * @property string $order_number
 * @property string $priority
 * @property string|null $clinical_indication_ref
 * @property string|null $clinical_indication_text
 * @property string|null $rejection_reason
 * @property string|null $cancellation_reason
 * @property string $status
 * @property string $created_by_user_id
 * @property CarbonImmutable|null $occurred_at
 * @property CarbonImmutable|null $recorded_at
 * @property CarbonImmutable|null $updated_at
 * @property int $version
 */
final class LabOrder extends AggregateRoot
{
    /** @use HasFactory<LabOrderFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_ORDERED = 'ORDERED';

    public const STATUS_ACCEPTED = 'ACCEPTED';

    /** ADR-158 (Lab.2) — reserved by ADR-157 D-E; entered when a specimen is collected. */
    public const STATUS_SPECIMEN_PENDING = 'SPECIMEN_PENDING';

    /** ADR-158 (Lab.2) — reserved by ADR-157 D-E; entered when a specimen is received/accepted. */
    public const STATUS_PROCESSING = 'PROCESSING';

    /** ADR-159 (Lab.3a) — reserved by ADR-157 D-E; at least one result entered. */
    public const STATUS_RESULTED = 'RESULTED';

    /** ADR-159 (Lab.3a) — at least one result verified. */
    public const STATUS_VERIFIED = 'VERIFIED';

    /** ADR-159 (Lab.3a) — at least one result released. */
    public const STATUS_RELEASED = 'RELEASED';

    public const STATUS_REJECTED = 'REJECTED';

    public const STATUS_CANCELLED = 'CANCELLED';

    protected $table = 'lab_orders';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'patient_id',
        'encounter_id',
        'order_number',
        'priority',
        'clinical_indication_ref',
        'clinical_indication_text',
        'rejection_reason',
        'cancellation_reason',
        'status',
        'created_by_user_id',
        'occurred_at',
        'recorded_at',
        'version',
    ];

    protected static function newFactory(): LabOrderFactory
    {
        return LabOrderFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public static function create(
        string $id,
        string $tenantId,
        string $facilityId,
        string $patientId,
        string $encounterId,
        string $orderNumber,
        string $priority,
        ?string $clinicalIndicationRef,
        ?string $clinicalIndicationText,
        string $createdByUserId,
        ?CarbonImmutable $occurredAt = null,
    ): self {
        $occurred = $occurredAt ?? CarbonImmutable::now();
        $recorded = CarbonImmutable::now();

        $order = new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'patient_id' => $patientId,
            'encounter_id' => $encounterId,
            'order_number' => $orderNumber,
            'priority' => $priority,
            'clinical_indication_ref' => $clinicalIndicationRef,
            'clinical_indication_text' => $clinicalIndicationText,
            'rejection_reason' => null,
            'cancellation_reason' => null,
            'status' => self::STATUS_DRAFT,
            'created_by_user_id' => $createdByUserId,
            'occurred_at' => $occurred,
            'recorded_at' => $recorded,
        ]);

        $order->recordThat(new LabOrderCreated(
            eventId: (string) Str::uuid7(),
            tenantId: $tenantId,
            facilityId: $facilityId,
            orderId: $id,
            patientId: $patientId,
            encounterId: $encounterId,
            orderNumber: $orderNumber,
            priority: $priority,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $order;
    }

    public function submit(): void
    {
        $this->status = self::STATUS_ORDERED;

        $this->recordThat(new LabOrderSubmitted(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            orderId: (string) $this->id,
            patientId: (string) $this->patient_id,
            encounterId: (string) $this->encounter_id,
            orderNumber: (string) $this->order_number,
            priority: (string) $this->priority,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function accept(): void
    {
        $this->status = self::STATUS_ACCEPTED;

        $this->recordThat(new LabOrderAccepted(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            orderId: (string) $this->id,
            orderNumber: (string) $this->order_number,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function reject(?string $reason): void
    {
        $this->status = self::STATUS_REJECTED;
        $this->rejection_reason = ($reason !== null && trim($reason) !== '') ? trim($reason) : null;

        $this->recordThat(new LabOrderRejected(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            orderId: (string) $this->id,
            orderNumber: (string) $this->order_number,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /**
     * ADR-158 (Lab.2) — a specimen has been collected for this ACCEPTED order.
     * Eventless internal progression (the §138 event list names no order-side
     * specimen event); the specimen's own `SpecimenCollected` carries the fact.
     */
    public function markSpecimenPending(): void
    {
        $this->status = self::STATUS_SPECIMEN_PENDING;
    }

    /**
     * ADR-158 (Lab.2) — a specimen has been received/accepted at the laboratory.
     * Eventless internal progression.
     */
    public function markProcessing(): void
    {
        $this->status = self::STATUS_PROCESSING;
    }

    /** ADR-159 (Lab.3a) — first result entered. Eventless ('at least one' rollup). */
    public function markResulted(): void
    {
        $this->status = self::STATUS_RESULTED;
    }

    /** ADR-159 (Lab.3a) — first result verified. Eventless. */
    public function markVerified(): void
    {
        $this->status = self::STATUS_VERIFIED;
    }

    /** ADR-159 (Lab.3a) — first result released. Eventless. */
    public function markReleased(): void
    {
        $this->status = self::STATUS_RELEASED;
    }

    public function cancel(?string $reason): void
    {
        $this->status = self::STATUS_CANCELLED;
        $this->cancellation_reason = ($reason !== null && trim($reason) !== '') ? trim($reason) : null;

        $this->recordThat(new LabOrderCancelled(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            orderId: (string) $this->id,
            orderNumber: (string) $this->order_number,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }
}
