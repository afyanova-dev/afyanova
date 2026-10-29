<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Model;

use App\Domains\Billing\Database\Factories\BillableEventFactory;
use App\Domains\Billing\Domain\ValueObject\BillableEventStatus;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * A captured billable fact (20-billing-and-payments-domain.md §10 / §126 / §128;
 * ADR-189 D-B).
 *
 * ADR-189 INV-4 — a `pharmacy.MedicationDispensed` Bill.3 cannot turn into a
 * charge is captured here as PENDING (with a `pending_reason`), never dropped.
 * No events — the `Charge` / `ChargeReversal` aggregates emit.
 *
 * @domain      Billing
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    required
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $facility_id
 * @property string $source_domain
 * @property string $source_event_type
 * @property string $source_event_id
 * @property string $source_ref
 * @property string $patient_id
 * @property string $item_id
 * @property string $quantity
 * @property CarbonImmutable $occurred_at
 * @property string $status
 * @property string|null $pending_reason
 * @property string|null $charge_id
 * @property CarbonImmutable $recorded_at
 * @property int $version
 */
final class BillableEvent extends AggregateRoot
{
    /** @use HasFactory<BillableEventFactory> */
    use HasFactory;

    public const STATUS_PENDING = BillableEventStatus::PENDING;

    public const STATUS_CHARGED = BillableEventStatus::CHARGED;

    public const STATUS_REVERSED = BillableEventStatus::REVERSED;

    public const STATUS_CANCELLED = BillableEventStatus::CANCELLED;

    protected $table = 'bill_billable_events';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id',
        'source_domain', 'source_event_type', 'source_event_id', 'source_ref',
        'patient_id', 'item_id', 'quantity',
        'occurred_at',
        'status', 'pending_reason', 'charge_id',
        'recorded_at', 'version',
    ];

    protected static function newFactory(): BillableEventFactory
    {
        return BillableEventFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public static function capture(
        string $id,
        string $tenantId,
        string $facilityId,
        string $sourceDomain,
        string $sourceEventType,
        string $sourceEventId,
        string $sourceRef,
        string $patientId,
        string $itemId,
        string $quantity,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): self {
        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'source_domain' => $sourceDomain,
            'source_event_type' => $sourceEventType,
            'source_event_id' => $sourceEventId,
            'source_ref' => $sourceRef,
            'patient_id' => $patientId,
            'item_id' => $itemId,
            'quantity' => $quantity,
            'occurred_at' => $occurredAt,
            'status' => self::STATUS_PENDING,
            'pending_reason' => null,
            'recorded_at' => $recordedAt,
        ]);
    }

    public function holdPending(string $reason): void
    {
        $this->status = self::STATUS_PENDING;
        $this->pending_reason = $reason;
    }

    public function markCharged(string $chargeId): void
    {
        $this->status = self::STATUS_CHARGED;
        $this->pending_reason = null;
        $this->charge_id = $chargeId;
    }

    public function markReversed(): void
    {
        $this->status = self::STATUS_REVERSED;
    }

    public function markCancelled(): void
    {
        $this->status = self::STATUS_CANCELLED;
        $this->pending_reason = null;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isCharged(): bool
    {
        return $this->status === self::STATUS_CHARGED;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, BillableEventStatus::TERMINAL, true);
    }
}
