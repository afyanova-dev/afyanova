<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Model;

use App\Domains\HR\Contracts\Event\LeaveApproved;
use App\Domains\HR\Contracts\Event\LeaveCancelled;
use App\Domains\HR\Contracts\Event\LeaveRejected;
use App\Domains\HR\Contracts\Event\LeaveRequested;
use App\Domains\HR\Contracts\Event\LeaveTaken;
use App\Domains\HR\Domain\Exception\InvalidLeaveRequestStatusTransitionException;
use App\Domains\HR\Domain\ValueObject\LeaveRequestStatus;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Leave request aggregate root (25-hr-and-workforce-domain.md §6, §8; ADR-206).
 *
 * Lifecycle: REQUESTED -> APPROVED -> TAKEN (or REJECTED / CANCELLED).
 *
 * @domain      HR
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
 * @property string $staff_id
 * @property string $leave_type_id
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable $end_date
 * @property float $days
 * @property LeaveRequestStatus $status
 * @property string|null $reason
 * @property string|null $reviewer_id
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $rejection_reason
 * @property string|null $cancellation_reason
 * @property int $version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property Staff|null $staff
 * @property LeaveType|null $leaveType
 */
final class LeaveRequest extends AggregateRoot
{
    protected $table = 'hr_leave_requests';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'staff_id',
        'leave_type_id',
        'start_date',
        'end_date',
        'days',
        'status',
        'reason',
        'reviewer_id',
        'reviewed_at',
        'rejection_reason',
        'cancellation_reason',
        'version',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'start_date' => 'immutable_date',
        'end_date' => 'immutable_date',
        'days' => 'float',
        'status' => LeaveRequestStatus::class,
        'reviewed_at' => 'immutable_datetime',
        'version' => 'integer',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    public static function request(
        string $tenantId,
        string $facilityId,
        string $staffId,
        string $leaveTypeId,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate,
        float $days,
        ?string $reason = null,
        ?string $leaveRequestId = null,
    ): self {
        $id = $leaveRequestId ?? (string) Str::uuid();

        $request = new self([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'staff_id' => $staffId,
            'leave_type_id' => $leaveTypeId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'days' => $days,
            'status' => LeaveRequestStatus::REQUESTED,
            'reason' => $reason,
            'reviewer_id' => null,
            'reviewed_at' => null,
            'rejection_reason' => null,
            'cancellation_reason' => null,
            'version' => 0,
        ]);

        $request->id = $id;

        $request->recordThat(new LeaveRequested(
            eventId: (string) Str::uuid(),
            tenantId: $tenantId,
            leaveRequestId: $id,
            facilityId: $facilityId,
            staffId: $staffId,
            leaveTypeId: $leaveTypeId,
            startDate: $startDate->toDateString(),
            endDate: $endDate->toDateString(),
            days: $days,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        ));

        return $request;
    }

    public function approve(string $reviewerId): void
    {
        if (! $this->status->canTransitionTo(LeaveRequestStatus::APPROVED)) {
            throw InvalidLeaveRequestStatusTransitionException::forTransition(
                $this->status,
                LeaveRequestStatus::APPROVED
            );
        }

        $this->status = LeaveRequestStatus::APPROVED;
        $this->reviewer_id = $reviewerId;
        $this->reviewed_at = CarbonImmutable::now();

        $this->recordThat(new LeaveApproved(
            eventId: (string) Str::uuid(),
            tenantId: $this->tenant_id,
            leaveRequestId: $this->id,
            facilityId: $this->facility_id,
            staffId: $this->staff_id,
            leaveTypeId: $this->leave_type_id,
            startDate: $this->start_date->toDateString(),
            endDate: $this->end_date->toDateString(),
            days: $this->days,
            reviewerId: $reviewerId,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        ));
    }

    public function reject(string $reviewerId, string $rejectionReason): void
    {
        if (! $this->status->canTransitionTo(LeaveRequestStatus::REJECTED)) {
            throw InvalidLeaveRequestStatusTransitionException::forTransition(
                $this->status,
                LeaveRequestStatus::REJECTED
            );
        }

        $this->status = LeaveRequestStatus::REJECTED;
        $this->reviewer_id = $reviewerId;
        $this->reviewed_at = CarbonImmutable::now();
        $this->rejection_reason = trim($rejectionReason);

        $this->recordThat(new LeaveRejected(
            eventId: (string) Str::uuid(),
            tenantId: $this->tenant_id,
            leaveRequestId: $this->id,
            staffId: $this->staff_id,
            reviewerId: $reviewerId,
            rejectionReason: $this->rejection_reason,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        ));
    }

    public function cancel(string $cancellationReason): void
    {
        if (! $this->status->canTransitionTo(LeaveRequestStatus::CANCELLED)) {
            throw InvalidLeaveRequestStatusTransitionException::forTransition(
                $this->status,
                LeaveRequestStatus::CANCELLED
            );
        }

        $this->status = LeaveRequestStatus::CANCELLED;
        $this->cancellation_reason = trim($cancellationReason);

        $this->recordThat(new LeaveCancelled(
            eventId: (string) Str::uuid(),
            tenantId: $this->tenant_id,
            leaveRequestId: $this->id,
            staffId: $this->staff_id,
            cancellationReason: $this->cancellation_reason,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        ));
    }

    public function confirmTaken(): void
    {
        if (! $this->status->canTransitionTo(LeaveRequestStatus::TAKEN)) {
            throw InvalidLeaveRequestStatusTransitionException::forTransition(
                $this->status,
                LeaveRequestStatus::TAKEN
            );
        }

        $this->status = LeaveRequestStatus::TAKEN;

        $this->recordThat(new LeaveTaken(
            eventId: (string) Str::uuid(),
            tenantId: $this->tenant_id,
            leaveRequestId: $this->id,
            staffId: $this->staff_id,
            startDate: $this->start_date->toDateString(),
            endDate: $this->end_date->toDateString(),
            days: $this->days,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        ));
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }

    /**
     * @return BelongsTo<LeaveType, $this>
     */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }
}
