<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Domain\Model;

use App\Domains\Scheduling\Contracts\Event\WaitlistEntryAdded;
use App\Domains\Scheduling\Contracts\Event\WaitlistEntryCancelled;
use App\Domains\Scheduling\Contracts\Event\WaitlistOfferAccepted;
use App\Domains\Scheduling\Contracts\Event\WaitlistOfferDeclined;
use App\Domains\Scheduling\Contracts\Event\WaitlistOfferExpired;
use App\Domains\Scheduling\Contracts\Event\WaitlistSlotOffered;
use App\Domains\Scheduling\Domain\Exception\InvalidWaitlistTransitionException;
use App\Domains\Scheduling\Domain\Exception\WaitlistOfferExpiredException;
use App\Domains\Scheduling\Domain\ValueObject\WaitlistPriority;
use App\Domains\Scheduling\Domain\ValueObject\WaitlistStatus;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Waitlist Entry aggregate root (22-scheduling-and-appointments-domain.md §23–§27, §258).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $facility_id
 * @property string $waitlist_number
 * @property string $patient_id
 * @property string $appointment_type_id
 * @property string|null $resource_id
 * @property WaitlistPriority $priority
 * @property WaitlistStatus $status
 * @property CarbonImmutable|null $preferred_date_start
 * @property CarbonImmutable|null $preferred_date_end
 * @property string|null $notes
 * @property string|null $offered_slot_id
 * @property CarbonImmutable|null $offered_at
 * @property CarbonImmutable|null $offered_until
 * @property string|null $booked_appointment_id
 * @property string|null $cancellation_reason
 * @property CarbonImmutable|null $cancelled_at
 * @property int $version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class WaitlistEntry extends AggregateRoot
{
    protected $table = 'sch_waitlist_entries';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'waitlist_number',
        'patient_id',
        'appointment_type_id',
        'resource_id',
        'priority',
        'status',
        'preferred_date_start',
        'preferred_date_end',
        'notes',
        'offered_slot_id',
        'offered_at',
        'offered_until',
        'booked_appointment_id',
        'cancellation_reason',
        'cancelled_at',
        'version',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'priority' => WaitlistPriority::class,
        'status' => WaitlistStatus::class,
        'preferred_date_start' => 'immutable_date',
        'preferred_date_end' => 'immutable_date',
        'offered_at' => 'immutable_datetime',
        'offered_until' => 'immutable_datetime',
        'cancelled_at' => 'immutable_datetime',
        'version' => 'integer',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    public static function add(
        string $tenantId,
        string $facilityId,
        string $waitlistNumber,
        string $patientId,
        string $appointmentTypeId,
        ?string $resourceId = null,
        WaitlistPriority $priority = WaitlistPriority::ROUTINE,
        ?CarbonImmutable $preferredDateStart = null,
        ?CarbonImmutable $preferredDateEnd = null,
        ?string $notes = null,
        ?string $id = null,
        ?string $correlationId = null,
        ?string $causationId = null,
    ): self {
        $entry = new self;
        $entry->id = $id ?? (string) Str::uuid();
        $entry->tenant_id = $tenantId;
        $entry->facility_id = $facilityId;
        $entry->waitlist_number = $waitlistNumber;
        $entry->patient_id = $patientId;
        $entry->appointment_type_id = $appointmentTypeId;
        $entry->resource_id = $resourceId;
        $entry->priority = $priority;
        $entry->status = WaitlistStatus::ACTIVE;
        $entry->preferred_date_start = $preferredDateStart;
        $entry->preferred_date_end = $preferredDateEnd;
        $entry->notes = $notes;
        $entry->version = 0;

        $entry->recordThat(new WaitlistEntryAdded(
            eventId: (string) Str::uuid(),
            tenantId: $tenantId,
            facilityId: $facilityId,
            waitlistEntryId: (string) $entry->id,
            waitlistNumber: $waitlistNumber,
            patientId: $patientId,
            appointmentTypeId: $appointmentTypeId,
            resourceId: $resourceId,
            priority: $priority->value,
            preferredDateStart: $preferredDateStart?->format('Y-m-d'),
            preferredDateEnd: $preferredDateEnd?->format('Y-m-d'),
            occurredAt: CarbonImmutable::now()->toIso8601String(),
            correlationId: $correlationId,
            causationId: $causationId,
        ));

        return $entry;
    }

    public function offerSlot(
        string $slotId,
        CarbonImmutable $offeredUntil,
        ?string $correlationId = null,
        ?string $causationId = null,
    ): void {
        if (! $this->status->canOffer()) {
            throw InvalidWaitlistTransitionException::from($this->status->value, 'offerSlot');
        }

        $now = CarbonImmutable::now();
        $this->offered_slot_id = $slotId;
        $this->offered_at = $now;
        $this->offered_until = $offeredUntil;
        $this->status = WaitlistStatus::OFFERED;

        $this->recordThat(new WaitlistSlotOffered(
            eventId: (string) Str::uuid(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            waitlistEntryId: (string) $this->id,
            slotId: $slotId,
            offeredUntil: $offeredUntil->toIso8601String(),
            occurredAt: $now->toIso8601String(),
            correlationId: $correlationId,
            causationId: $causationId,
        ));
    }

    public function acceptOffer(
        string $appointmentId,
        ?string $correlationId = null,
        ?string $causationId = null,
    ): void {
        if (! $this->status->canAcceptOrDecline()) {
            throw InvalidWaitlistTransitionException::from($this->status->value, 'acceptOffer');
        }

        $now = CarbonImmutable::now();
        if ($this->offered_until !== null && $now->isAfter($this->offered_until)) {
            throw WaitlistOfferExpiredException::forId((string) $this->id);
        }

        $slotId = (string) $this->offered_slot_id;
        $this->booked_appointment_id = $appointmentId;
        $this->status = WaitlistStatus::BOOKED;

        $this->recordThat(new WaitlistOfferAccepted(
            eventId: (string) Str::uuid(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            waitlistEntryId: (string) $this->id,
            slotId: $slotId,
            appointmentId: $appointmentId,
            occurredAt: $now->toIso8601String(),
            correlationId: $correlationId,
            causationId: $causationId,
        ));
    }

    public function declineOffer(
        ?string $reason = null,
        ?string $correlationId = null,
        ?string $causationId = null,
    ): void {
        if (! $this->status->canAcceptOrDecline()) {
            throw InvalidWaitlistTransitionException::from($this->status->value, 'declineOffer');
        }

        $slotId = (string) $this->offered_slot_id;
        $now = CarbonImmutable::now();

        $this->offered_slot_id = null;
        $this->offered_at = null;
        $this->offered_until = null;
        $this->status = WaitlistStatus::ACTIVE;

        $this->recordThat(new WaitlistOfferDeclined(
            eventId: (string) Str::uuid(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            waitlistEntryId: (string) $this->id,
            slotId: $slotId,
            reason: $reason,
            occurredAt: $now->toIso8601String(),
            correlationId: $correlationId,
            causationId: $causationId,
        ));
    }

    public function expireOffer(
        ?string $correlationId = null,
        ?string $causationId = null,
    ): void {
        if ($this->status !== WaitlistStatus::OFFERED) {
            return;
        }

        $slotId = (string) $this->offered_slot_id;
        $now = CarbonImmutable::now();

        $this->offered_slot_id = null;
        $this->offered_at = null;
        $this->offered_until = null;

        // If preferred end date was set and has passed, expire entry; otherwise revert to ACTIVE pool
        if ($this->preferred_date_end !== null && $now->toDateString() > $this->preferred_date_end->toDateString()) {
            $this->status = WaitlistStatus::EXPIRED;
        } else {
            $this->status = WaitlistStatus::ACTIVE;
        }

        $this->recordThat(new WaitlistOfferExpired(
            eventId: (string) Str::uuid(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            waitlistEntryId: (string) $this->id,
            slotId: $slotId,
            occurredAt: $now->toIso8601String(),
            correlationId: $correlationId,
            causationId: $causationId,
        ));
    }

    public function cancel(
        ?string $reason = null,
        ?string $correlationId = null,
        ?string $causationId = null,
    ): void {
        if (! $this->status->canCancel()) {
            throw InvalidWaitlistTransitionException::from($this->status->value, 'cancel');
        }

        $now = CarbonImmutable::now();
        $this->status = WaitlistStatus::CANCELLED;
        $this->cancellation_reason = $reason;
        $this->cancelled_at = $now;

        $this->recordThat(new WaitlistEntryCancelled(
            eventId: (string) Str::uuid(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            waitlistEntryId: (string) $this->id,
            reason: $reason,
            occurredAt: $now->toIso8601String(),
            correlationId: $correlationId,
            causationId: $causationId,
        ));
    }
}
