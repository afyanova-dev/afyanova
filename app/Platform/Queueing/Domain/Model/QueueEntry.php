<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Domain\Model;

use App\Platform\Queueing\Contracts\Event\TicketCalled;
use App\Platform\Queueing\Contracts\Event\TicketCancelled;
use App\Platform\Queueing\Contracts\Event\TicketCompleted;
use App\Platform\Queueing\Contracts\Event\TicketHoldPlaced;
use App\Platform\Queueing\Contracts\Event\TicketHoldReleased;
use App\Platform\Queueing\Contracts\Event\TicketLeftWithoutBeingSeen;
use App\Platform\Queueing\Contracts\Event\TicketNoResponse;
use App\Platform\Queueing\Contracts\Event\TicketPlaced;
use App\Platform\Queueing\Contracts\Event\TicketServiceStarted;
use App\Platform\Queueing\Contracts\Event\TicketSkipped;
use App\Platform\Queueing\Contracts\Event\TicketTransferred;
use App\Platform\Queueing\Domain\Enum\HoldReason;
use App\Platform\Queueing\Domain\Enum\OrderType;
use App\Platform\Queueing\Domain\Enum\QueuePriority;
use App\Platform\Queueing\Domain\Enum\QueueStatus;
use App\Platform\Queueing\Domain\Exception\InvalidQueueStateTransitionException;
use App\Platform\Queueing\Domain\Exception\MissingClearanceProvenanceException;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Queue Entry (Ticket) aggregate root.
 *
 * @authority coordination (PLATFORM SERVICE)
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $facility_id
 * @property string $queue_id
 * @property string $encounter_id
 * @property string $patient_id
 * @property string $ticket_number
 * @property QueuePriority $priority
 * @property QueueStatus $status
 * @property HoldReason|null $hold_reason
 * @property string|null $hold_provenance
 * @property string|null $clearance_provenance
 * @property string|null $source_order_ref
 * @property OrderType|null $order_type
 * @property CarbonImmutable|null $called_at
 * @property string|null $called_room_id
 * @property string|null $called_staff_id
 * @property CarbonImmutable|null $service_started_at
 * @property CarbonImmutable|null $completed_at
 * @property int $call_attempts
 * @property int $version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class QueueEntry extends AggregateRoot
{
    protected $table = 'qms_queue_entries';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'queue_id',
        'encounter_id',
        'patient_id',
        'ticket_number',
        'priority',
        'status',
        'hold_reason',
        'hold_provenance',
        'clearance_provenance',
        'source_order_ref',
        'order_type',
        'called_at',
        'called_room_id',
        'called_staff_id',
        'service_started_at',
        'completed_at',
        'call_attempts',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'priority' => QueuePriority::class,
        'status' => QueueStatus::class,
        'hold_reason' => HoldReason::class,
        'order_type' => OrderType::class,
        'called_at' => 'immutable_datetime',
        'service_started_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
        'call_attempts' => 'integer',
        'version' => 'integer',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<Queue, $this>
     */
    public function queue(): BelongsTo
    {
        return $this->belongsTo(Queue::class, 'queue_id');
    }

    public static function place(
        string $tenantId,
        string $facilityId,
        string $queueId,
        string $encounterId,
        string $patientId,
        string $ticketNumber,
        QueuePriority $priority,
        ?string $sourceOrderRef = null,
        ?OrderType $orderType = null,
    ): self {
        $entry = new self([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'queue_id' => $queueId,
            'encounter_id' => $encounterId,
            'patient_id' => $patientId,
            'ticket_number' => $ticketNumber,
            'priority' => $priority,
            'status' => QueueStatus::WAITING,
            'source_order_ref' => $sourceOrderRef,
            'order_type' => $orderType,
            'call_attempts' => 0,
        ]);
        $entry->id = (string) Str::uuid7();

        $entry->recordThat(new TicketPlaced(
            eventId: (string) Str::uuid7(),
            tenantId: $tenantId,
            facilityId: $facilityId,
            entryId: $entry->id,
            queueId: $queueId,
            encounterId: $encounterId,
            patientId: $patientId,
            ticketNumber: $ticketNumber,
            priority: $priority->value,
            sourceOrderRef: $sourceOrderRef,
            orderType: $orderType?->value,
            occurredAt: (string) now()->toISOString(),
        ));

        return $entry;
    }

    public function call(string $roomId, ?string $staffId = null): void
    {
        if (! $this->status->isCallable()) {
            throw new InvalidQueueStateTransitionException(
                "Cannot call ticket in '{$this->status->value}' state."
            );
        }

        $this->status = QueueStatus::CALLED;
        $this->called_at = CarbonImmutable::now();
        $this->called_room_id = $roomId;
        $this->called_staff_id = $staffId;
        $this->call_attempts++;

        $this->recordThat(new TicketCalled(
            eventId: (string) Str::uuid7(),
            tenantId: $this->tenant_id,
            facilityId: $this->facility_id,
            entryId: $this->id,
            queueId: $this->queue_id,
            encounterId: $this->encounter_id,
            patientId: $this->patient_id,
            ticketNumber: $this->ticket_number,
            calledRoomId: $roomId,
            calledStaffId: $staffId,
            callAttempts: $this->call_attempts,
            occurredAt: (string) now()->toISOString(),
        ));
    }

    public function recall(?string $roomId = null, ?string $staffId = null): void
    {
        if ($this->status !== QueueStatus::CALLED && $this->status !== QueueStatus::NO_RESPONSE && $this->status !== QueueStatus::SKIPPED) {
            throw new InvalidQueueStateTransitionException(
                "Cannot recall ticket in '{$this->status->value}' state."
            );
        }

        $this->status = QueueStatus::CALLED;
        $this->called_at = CarbonImmutable::now();
        if ($roomId !== null) {
            $this->called_room_id = $roomId;
        }
        if ($staffId !== null) {
            $this->called_staff_id = $staffId;
        }
        $this->call_attempts++;

        $this->recordThat(new TicketCalled(
            eventId: (string) Str::uuid7(),
            tenantId: $this->tenant_id,
            facilityId: $this->facility_id,
            entryId: $this->id,
            queueId: $this->queue_id,
            encounterId: $this->encounter_id,
            patientId: $this->patient_id,
            ticketNumber: $this->ticket_number,
            calledRoomId: $this->called_room_id,
            calledStaffId: $this->called_staff_id,
            callAttempts: $this->call_attempts,
            occurredAt: (string) now()->toISOString(),
        ));
    }

    public function startService(): void
    {
        if ($this->status !== QueueStatus::CALLED) {
            throw new InvalidQueueStateTransitionException(
                "Cannot start service for ticket in '{$this->status->value}' state. Must be CALLED."
            );
        }

        $this->status = QueueStatus::IN_SERVICE;
        $this->service_started_at = CarbonImmutable::now();

        $this->recordThat(new TicketServiceStarted(
            eventId: (string) Str::uuid7(),
            tenantId: $this->tenant_id,
            facilityId: $this->facility_id,
            entryId: $this->id,
            queueId: $this->queue_id,
            encounterId: $this->encounter_id,
            ticketNumber: $this->ticket_number,
            serviceStartedAt: (string) $this->service_started_at->toISOString(),
            occurredAt: (string) now()->toISOString(),
        ));
    }

    public function completeService(): void
    {
        if ($this->status !== QueueStatus::IN_SERVICE && $this->status !== QueueStatus::CALLED) {
            throw new InvalidQueueStateTransitionException(
                "Cannot complete ticket in '{$this->status->value}' state. Must be IN_SERVICE or CALLED."
            );
        }

        $this->status = QueueStatus::COMPLETED;
        $this->completed_at = CarbonImmutable::now();

        $this->recordThat(new TicketCompleted(
            eventId: (string) Str::uuid7(),
            tenantId: $this->tenant_id,
            facilityId: $this->facility_id,
            entryId: $this->id,
            queueId: $this->queue_id,
            encounterId: $this->encounter_id,
            ticketNumber: $this->ticket_number,
            completedAt: (string) $this->completed_at->toISOString(),
            occurredAt: (string) now()->toISOString(),
        ));
    }

    public function placeHold(HoldReason $reason, string $provenance): void
    {
        if ($this->status->isTerminal()) {
            throw new InvalidQueueStateTransitionException(
                "Cannot place hold on ticket in terminal '{$this->status->value}' state."
            );
        }

        $this->status = QueueStatus::HOLD;
        $this->hold_reason = $reason;
        $this->hold_provenance = $provenance;

        $this->recordThat(new TicketHoldPlaced(
            eventId: (string) Str::uuid7(),
            tenantId: $this->tenant_id,
            facilityId: $this->facility_id,
            entryId: $this->id,
            queueId: $this->queue_id,
            encounterId: $this->encounter_id,
            ticketNumber: $this->ticket_number,
            holdReason: $reason->value,
            holdProvenance: $provenance,
            occurredAt: (string) now()->toISOString(),
        ));
    }

    public function releaseHold(string $clearanceProvenance): void
    {
        if ($this->status !== QueueStatus::HOLD) {
            throw new InvalidQueueStateTransitionException(
                "Cannot release hold on ticket in '{$this->status->value}' state. Must be HOLD."
            );
        }

        if (trim($clearanceProvenance) === '') {
            throw new MissingClearanceProvenanceException(
                'Hold release requires an authoritative clearance provenance reference.'
            );
        }

        $this->status = QueueStatus::WAITING;
        $this->clearance_provenance = $clearanceProvenance;
        $this->hold_reason = null;

        $this->recordThat(new TicketHoldReleased(
            eventId: (string) Str::uuid7(),
            tenantId: $this->tenant_id,
            facilityId: $this->facility_id,
            entryId: $this->id,
            queueId: $this->queue_id,
            encounterId: $this->encounter_id,
            ticketNumber: $this->ticket_number,
            clearanceProvenance: $clearanceProvenance,
            occurredAt: (string) now()->toISOString(),
        ));
    }

    public function recordNoResponse(): void
    {
        if ($this->status !== QueueStatus::CALLED) {
            throw new InvalidQueueStateTransitionException(
                "Cannot record no-response for ticket in '{$this->status->value}' state. Must be CALLED."
            );
        }

        $this->status = QueueStatus::NO_RESPONSE;

        $this->recordThat(new TicketNoResponse(
            eventId: (string) Str::uuid7(),
            tenantId: $this->tenant_id,
            facilityId: $this->facility_id,
            entryId: $this->id,
            queueId: $this->queue_id,
            encounterId: $this->encounter_id,
            ticketNumber: $this->ticket_number,
            callAttempts: $this->call_attempts,
            occurredAt: (string) now()->toISOString(),
        ));
    }

    public function recordTransferTo(string $newEntryId, string $destinationQueueId, ?string $reason): void
    {
        $this->status = QueueStatus::COMPLETED;
        $this->completed_at = CarbonImmutable::now();

        $this->recordThat(new TicketTransferred(
            eventId: (string) Str::uuid7(),
            tenantId: $this->tenant_id,
            facilityId: $this->facility_id,
            sourceEntryId: $this->id,
            newEntryId: $newEntryId,
            sourceQueueId: $this->queue_id,
            destinationQueueId: $destinationQueueId,
            encounterId: $this->encounter_id,
            ticketNumber: $this->ticket_number,
            reason: $reason,
            occurredAt: (string) now()->toISOString(),
        ));
    }

    public function cancel(string $reason): void
    {
        if ($this->status->isTerminal()) {
            return;
        }

        $this->status = QueueStatus::EXPIRED;
        $this->completed_at = CarbonImmutable::now();

        $this->recordThat(new TicketCancelled(
            eventId: (string) Str::uuid7(),
            tenantId: $this->tenant_id,
            facilityId: $this->facility_id,
            entryId: $this->id,
            queueId: $this->queue_id,
            encounterId: $this->encounter_id,
            ticketNumber: $this->ticket_number,
            reason: $reason,
            occurredAt: (string) now()->toISOString(),
        ));
    }

    public function skip(?string $reason = null): void
    {
        if ($this->status !== QueueStatus::CALLED && $this->status !== QueueStatus::WAITING) {
            throw new InvalidQueueStateTransitionException(
                "Cannot skip ticket in '{$this->status->value}' state. Must be CALLED or WAITING."
            );
        }

        $this->status = QueueStatus::SKIPPED;

        $this->recordThat(new TicketSkipped(
            eventId: (string) Str::uuid7(),
            tenantId: $this->tenant_id,
            facilityId: $this->facility_id,
            entryId: $this->id,
            queueId: $this->queue_id,
            encounterId: $this->encounter_id,
            patientId: $this->patient_id,
            ticketNumber: $this->ticket_number,
            reason: $reason,
            occurredAt: (string) now()->toISOString(),
        ));
    }

    public function recordLeftWithoutBeingSeen(?string $reason = null): void
    {
        if ($this->status->isTerminal()) {
            throw new InvalidQueueStateTransitionException(
                "Cannot mark ticket as left-without-being-seen in terminal '{$this->status->value}' state."
            );
        }

        $this->status = QueueStatus::LEFT_WITHOUT_BEING_SEEN;
        $this->completed_at = CarbonImmutable::now();

        $this->recordThat(new TicketLeftWithoutBeingSeen(
            eventId: (string) Str::uuid7(),
            tenantId: $this->tenant_id,
            facilityId: $this->facility_id,
            entryId: $this->id,
            queueId: $this->queue_id,
            encounterId: $this->encounter_id,
            patientId: $this->patient_id,
            ticketNumber: $this->ticket_number,
            reason: $reason,
            occurredAt: (string) now()->toISOString(),
        ));
    }

    public function updatePriority(QueuePriority $priority): void
    {
        $this->priority = $priority;
    }
}
