<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Domain\Model;

use App\Domains\Radiology\Contracts\Event\ImagingOrderAccepted;
use App\Domains\Radiology\Contracts\Event\ImagingOrderCancelled;
use App\Domains\Radiology\Contracts\Event\ImagingOrderCreated;
use App\Domains\Radiology\Contracts\Event\ImagingOrderRejected;
use App\Domains\Radiology\Contracts\Event\ImagingOrderSubmitted;
use App\Domains\Radiology\Database\Factories\ImagingOrderFactory;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * Imaging order aggregate root (15-radiology-domain.md §18–§21, §104–§105;
 * ADR-165 D-E/D-F).
 *
 * Emits ImagingOrderCreated (on create → DRAFT), ImagingOrderSubmitted (DRAFT →
 * ORDERED), ImagingOrderAccepted (ORDERED → ACCEPTED), ImagingOrderRejected
 * (ORDERED → REJECTED), ImagingOrderCancelled (DRAFT|ORDERED → CANCELLED).
 * Every event is PHI-safe (ADR-136 deny-list — clinical_indication_text /
 * rejection_reason / cancellation_reason never carried).
 *
 * The ordered services live on `rad_imaging_order_items` (a plain child, written
 * by `CreateImagingOrderHandler`), not on this aggregate (§181).
 *
 * @domain Radiology
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
final class ImagingOrder extends AggregateRoot
{
    /** @use HasFactory<ImagingOrderFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_ORDERED = 'ORDERED';

    public const STATUS_ACCEPTED = 'ACCEPTED';

    /** ADR-166 (Rad.2) — reserved by ADR-165 D-F; entered when the first study is scheduled. */
    public const STATUS_SCHEDULED = 'SCHEDULED';

    /** ADR-166 (Rad.2) — reserved by ADR-165 D-F; entered when the first study starts. */
    public const STATUS_IN_PROGRESS = 'IN_PROGRESS';

    /** ADR-166 (Rad.2) — reserved by ADR-165 D-F; entered when the first study completes. */
    public const STATUS_COMPLETED = 'COMPLETED';

    /** ADR-167 (Rad.3a) — reserved by §20 + ADR-165 D-B; entered when the first report is created. */
    public const STATUS_REPORTED = 'REPORTED';

    /** ADR-167 (Rad.3a) — reserved by §20; entered when the first report is verified. */
    public const STATUS_VERIFIED = 'VERIFIED';

    /** ADR-167 (Rad.3a) — reserved by §20; entered when the first report is released. */
    public const STATUS_RELEASED = 'RELEASED';

    public const STATUS_REJECTED = 'REJECTED';

    public const STATUS_CANCELLED = 'CANCELLED';

    protected $table = 'rad_imaging_orders';

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

    protected static function newFactory(): ImagingOrderFactory
    {
        return ImagingOrderFactory::new();
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

        $order->recordThat(new ImagingOrderCreated(
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

        $this->recordThat(new ImagingOrderSubmitted(
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

        $this->recordThat(new ImagingOrderAccepted(
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

        $this->recordThat(new ImagingOrderRejected(
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
     * ADR-166 (Rad.2) — the first study for this order has been scheduled.
     * Eventless forward-only progression (the §177 event list names no
     * order-side study event; the study's own `ImagingStudyScheduled` carries
     * the fact). Reserved by ADR-165 D-F.
     */
    public function markScheduled(): void
    {
        $this->status = self::STATUS_SCHEDULED;
    }

    /** ADR-166 (Rad.2) — the first study has started. Eventless. */
    public function markInProgress(): void
    {
        $this->status = self::STATUS_IN_PROGRESS;
    }

    /** ADR-166 (Rad.2) — the first study has completed. Eventless. */
    public function markCompleted(): void
    {
        $this->status = self::STATUS_COMPLETED;
    }

    /**
     * ADR-167 (Rad.3a) — the first report for this order has been created.
     * Eventless forward-only progression (the §177 event list names no
     * order-side report event; the report's own `RadiologyReportCreated`
     * carries the fact). Reserved by §20 + ADR-165 D-B.
     */
    public function markReported(): void
    {
        $this->status = self::STATUS_REPORTED;
    }

    /** ADR-167 (Rad.3a) — the first report has been verified. Eventless. */
    public function markReportVerified(): void
    {
        $this->status = self::STATUS_VERIFIED;
    }

    /** ADR-167 (Rad.3a) — the first report has been released. Eventless. */
    public function markReleased(): void
    {
        $this->status = self::STATUS_RELEASED;
    }

    public function cancel(?string $reason): void
    {
        $this->status = self::STATUS_CANCELLED;
        $this->cancellation_reason = ($reason !== null && trim($reason) !== '') ? trim($reason) : null;

        $this->recordThat(new ImagingOrderCancelled(
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
