<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Model;

use App\Domains\HR\Contracts\Event\EmploymentAmended;
use App\Domains\HR\Contracts\Event\EmploymentEnded;
use App\Domains\HR\Contracts\Event\EmploymentReinstated;
use App\Domains\HR\Contracts\Event\EmploymentStarted;
use App\Domains\HR\Contracts\Event\EmploymentSuspended;
use App\Domains\HR\Domain\Exception\InvalidEmploymentStatusTransitionException;
use App\Domains\HR\Domain\ValueObject\ContractType;
use App\Domains\HR\Domain\ValueObject\EmploymentStatus;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Employment contract aggregate root (25-hr-and-workforce-domain.md §3, §6, §8; ADR-206).
 *
 * Lifecycle: DRAFT → ACTIVE ⇄ SUSPENDED → ENDED.
 *
 * Invariant 7.4: At most one ACTIVE employment contract per staff member.
 *
 * @domain      HR
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    not-applicable
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $staff_id
 * @property string $position_id
 * @property ContractType $contract_type
 * @property EmploymentStatus $status
 * @property string $fte
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable|null $end_date
 * @property CarbonImmutable|null $probation_end_date
 * @property int $notice_period_days
 * @property string|null $terms_summary
 * @property string|null $suspension_reason
 * @property string|null $end_reason
 * @property int $version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Employment extends AggregateRoot
{
    protected $table = 'hr_employments';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'staff_id',
        'position_id',
        'contract_type',
        'status',
        'fte',
        'start_date',
        'end_date',
        'probation_end_date',
        'notice_period_days',
        'terms_summary',
        'suspension_reason',
        'end_reason',
        'version',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'contract_type' => ContractType::class,
        'status' => EmploymentStatus::class,
        'fte' => 'decimal:2',
        'start_date' => 'immutable_date',
        'end_date' => 'immutable_date',
        'probation_end_date' => 'immutable_date',
        'notice_period_days' => 'integer',
        'version' => 'integer',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    public static function createDraft(
        string $tenantId,
        string $staffId,
        string $positionId,
        ContractType $contractType,
        CarbonImmutable $startDate,
        string $fte = '1.00',
        ?CarbonImmutable $endDate = null,
        ?CarbonImmutable $probationEndDate = null,
        int $noticePeriodDays = 30,
        ?string $termsSummary = null,
    ): self {
        $employment = new self;
        $employment->tenant_id = $tenantId;
        $employment->staff_id = $staffId;
        $employment->position_id = $positionId;
        $employment->contract_type = $contractType;
        $employment->status = EmploymentStatus::DRAFT;
        $employment->start_date = $startDate;
        $employment->fte = $fte;
        $employment->end_date = $endDate;
        $employment->probation_end_date = $probationEndDate;
        $employment->notice_period_days = $noticePeriodDays;
        $employment->terms_summary = $termsSummary;

        return $employment;
    }

    public function start(CarbonImmutable $startDate): void
    {
        if (! $this->status->canTransitionTo(EmploymentStatus::ACTIVE)) {
            throw InvalidEmploymentStatusTransitionException::transition($this->status, EmploymentStatus::ACTIVE);
        }

        $this->status = EmploymentStatus::ACTIVE;
        $this->start_date = $startDate;

        $this->recordThat(new EmploymentStarted(
            eventId: (string) Str::uuid(),
            tenantId: $this->tenant_id,
            employmentId: $this->id,
            staffId: $this->staff_id,
            positionId: $this->position_id,
            contractType: $this->contract_type->value,
            startDate: $startDate->toDateString(),
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        ));
    }

    public function suspend(?string $reason = null): void
    {
        if (! $this->status->canTransitionTo(EmploymentStatus::SUSPENDED)) {
            throw InvalidEmploymentStatusTransitionException::transition($this->status, EmploymentStatus::SUSPENDED);
        }

        $this->status = EmploymentStatus::SUSPENDED;
        $this->suspension_reason = $reason;

        $this->recordThat(new EmploymentSuspended(
            eventId: (string) Str::uuid(),
            tenantId: $this->tenant_id,
            employmentId: $this->id,
            staffId: $this->staff_id,
            reason: $reason,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        ));
    }

    public function reinstate(): void
    {
        if (! $this->status->canTransitionTo(EmploymentStatus::ACTIVE)) {
            throw InvalidEmploymentStatusTransitionException::transition($this->status, EmploymentStatus::ACTIVE);
        }

        $this->status = EmploymentStatus::ACTIVE;
        $this->suspension_reason = null;

        $this->recordThat(new EmploymentReinstated(
            eventId: (string) Str::uuid(),
            tenantId: $this->tenant_id,
            employmentId: $this->id,
            staffId: $this->staff_id,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        ));
    }

    public function amend(
        string $positionId,
        ContractType $contractType,
        string $fte,
        ?CarbonImmutable $endDate = null,
        ?CarbonImmutable $probationEndDate = null,
        int $noticePeriodDays = 30,
        ?string $termsSummary = null,
    ): void {
        if ($this->status->isTerminal()) {
            throw InvalidEmploymentStatusTransitionException::transition($this->status, $this->status);
        }

        $this->position_id = $positionId;
        $this->contract_type = $contractType;
        $this->fte = $fte;
        $this->end_date = $endDate;
        $this->probation_end_date = $probationEndDate;
        $this->notice_period_days = $noticePeriodDays;
        $this->terms_summary = $termsSummary;

        $this->recordThat(new EmploymentAmended(
            eventId: (string) Str::uuid(),
            tenantId: $this->tenant_id,
            employmentId: $this->id,
            staffId: $this->staff_id,
            positionId: $this->position_id,
            contractType: $this->contract_type->value,
            fte: $this->fte,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        ));
    }

    public function end(CarbonImmutable $endDate, ?string $reason = null): void
    {
        if (! $this->status->canTransitionTo(EmploymentStatus::ENDED)) {
            throw InvalidEmploymentStatusTransitionException::transition($this->status, EmploymentStatus::ENDED);
        }

        $this->status = EmploymentStatus::ENDED;
        $this->end_date = $endDate;
        $this->end_reason = $reason;

        $this->recordThat(new EmploymentEnded(
            eventId: (string) Str::uuid(),
            tenantId: $this->tenant_id,
            employmentId: $this->id,
            staffId: $this->staff_id,
            endDate: $endDate->toDateString(),
            reason: $reason,
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
     * @return BelongsTo<Position, $this>
     */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'position_id');
    }
}
