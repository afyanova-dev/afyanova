<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Model;

use App\Domains\HR\Contracts\Event\AssignmentAmended;
use App\Domains\HR\Contracts\Event\AssignmentEnded;
use App\Domains\HR\Contracts\Event\StaffAssigned;
use App\Domains\HR\Domain\Exception\InvalidAssignmentDatesException;
use App\Domains\HR\Domain\Exception\InvalidAssignmentScopeException;
use App\Domains\HR\Domain\ValueObject\AssignmentScope;
use App\Domains\HR\Domain\ValueObject\AssignmentStatus;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Organizational assignment aggregate root (25-hr-and-workforce-domain.md §3, §6, §7, §8; ADR-206).
 *
 * Scope: FACILITY, DEPARTMENT, UNIT.
 * Lifecycle: ACTIVE -> ENDED.
 *
 * Invariant 7.5: Carries ZERO RBAC permissions or roles.
 * Invariant 7.7: References facility/department/unit owned by Organization; HR never creates org structure.
 * At most one ACTIVE primary assignment per staff member.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $staff_id
 * @property string $facility_id
 * @property string|null $department_id
 * @property string|null $unit_id
 * @property AssignmentScope $scope
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property bool $is_primary
 * @property AssignmentStatus $status
 * @property string|null $end_reason
 * @property int $version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Assignment extends AggregateRoot
{
    protected $table = 'hr_assignments';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'staff_id',
        'facility_id',
        'department_id',
        'unit_id',
        'scope',
        'effective_from',
        'effective_to',
        'is_primary',
        'status',
        'end_reason',
        'version',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'scope' => AssignmentScope::class,
        'status' => AssignmentStatus::class,
        'effective_from' => 'immutable_date',
        'effective_to' => 'immutable_date',
        'is_primary' => 'boolean',
        'version' => 'integer',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    public static function create(
        string $tenantId,
        string $staffId,
        string $facilityId,
        ?string $departmentId,
        ?string $unitId,
        AssignmentScope $scope,
        CarbonImmutable $effectiveFrom,
        ?CarbonImmutable $effectiveTo = null,
        bool $isPrimary = false,
        ?string $assignmentId = null,
    ): self {
        self::validateScope($scope, $departmentId, $unitId);

        if ($effectiveTo !== null && $effectiveTo->isBefore($effectiveFrom)) {
            throw InvalidAssignmentDatesException::endBeforeStart(
                $effectiveFrom->toDateString(),
                $effectiveTo->toDateString()
            );
        }

        $id = $assignmentId ?? (string) Str::uuid();

        $assignment = new self([
            'tenant_id' => $tenantId,
            'staff_id' => $staffId,
            'facility_id' => $facilityId,
            'department_id' => $departmentId,
            'unit_id' => $unitId,
            'scope' => $scope,
            'effective_from' => $effectiveFrom,
            'effective_to' => $effectiveTo,
            'is_primary' => $isPrimary,
            'status' => AssignmentStatus::ACTIVE,
            'end_reason' => null,
            'version' => 0,
        ]);

        $assignment->id = $id;

        $assignment->recordThat(new StaffAssigned(
            eventId: (string) Str::uuid(),
            tenantId: $tenantId,
            assignmentId: $id,
            staffId: $staffId,
            facilityId: $facilityId,
            departmentId: $departmentId,
            unitId: $unitId,
            scope: $scope->value,
            effectiveFrom: $effectiveFrom->toDateString(),
            effectiveTo: $effectiveTo?->toDateString(),
            isPrimary: $isPrimary,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        ));

        return $assignment;
    }

    public function amend(
        ?string $departmentId,
        ?string $unitId,
        ?AssignmentScope $scope,
        ?CarbonImmutable $effectiveFrom,
        ?CarbonImmutable $effectiveTo,
        ?bool $isPrimary,
    ): void {
        $targetScope = $scope ?? $this->scope;
        $targetDept = $departmentId !== null ? $departmentId : $this->department_id;
        $targetUnit = $unitId !== null ? $unitId : $this->unit_id;

        self::validateScope($targetScope, $targetDept, $targetUnit);

        $from = $effectiveFrom ?? $this->effective_from;
        $to = $effectiveTo ?? $this->effective_to;

        if ($to !== null && $to->isBefore($from)) {
            throw InvalidAssignmentDatesException::endBeforeStart(
                $from->toDateString(),
                $to->toDateString()
            );
        }

        if ($departmentId !== null) {
            $this->department_id = $departmentId;
        }
        if ($unitId !== null) {
            $this->unit_id = $unitId;
        }
        if ($scope !== null) {
            $this->scope = $scope;
        }
        if ($effectiveFrom !== null) {
            $this->effective_from = $effectiveFrom;
        }
        if ($effectiveTo !== null) {
            $this->effective_to = $effectiveTo;
        }
        if ($isPrimary !== null) {
            $this->is_primary = $isPrimary;
        }

        $this->version++;

        $this->recordThat(new AssignmentAmended(
            eventId: (string) Str::uuid(),
            tenantId: $this->tenant_id,
            assignmentId: $this->id,
            staffId: $this->staff_id,
            facilityId: $this->facility_id,
            departmentId: $this->department_id,
            unitId: $this->unit_id,
            scope: $this->scope->value,
            effectiveFrom: $this->effective_from->toDateString(),
            effectiveTo: $this->effective_to?->toDateString(),
            isPrimary: $this->is_primary,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        ));
    }

    public function end(CarbonImmutable $endedDate, ?string $reason = null): void
    {
        $this->status = AssignmentStatus::ENDED;
        $this->effective_to = $endedDate;
        $this->end_reason = $reason;
        $this->is_primary = false;
        $this->version++;

        $this->recordThat(new AssignmentEnded(
            eventId: (string) Str::uuid(),
            tenantId: $this->tenant_id,
            assignmentId: $this->id,
            staffId: $this->staff_id,
            facilityId: $this->facility_id,
            endedDate: $endedDate->toDateString(),
            endReason: $reason,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        ));
    }

    public function demotePrimary(): void
    {
        $this->is_primary = false;
        $this->version++;
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }

    private static function validateScope(AssignmentScope $scope, ?string $departmentId, ?string $unitId): void
    {
        if ($scope === AssignmentScope::DEPARTMENT && ($departmentId === null || trim($departmentId) === '')) {
            throw InvalidAssignmentScopeException::missingDepartment();
        }

        if ($scope === AssignmentScope::UNIT) {
            if ($departmentId === null || trim($departmentId) === '') {
                throw InvalidAssignmentScopeException::missingDepartment();
            }
            if ($unitId === null || trim($unitId) === '') {
                throw InvalidAssignmentScopeException::missingUnit();
            }
        }
    }
}
