<?php

declare(strict_types=1);

namespace App\Domains\HR\Infrastructure\Reader;

use App\Domains\HR\Contracts\Dto\StaffDetail;
use App\Domains\HR\Contracts\Dto\StaffNotificationContactDto;
use App\Domains\HR\Contracts\Dto\StaffSummary;
use App\Domains\HR\Domain\Exception\StaffNotFoundException;
use App\Domains\HR\Domain\Model\Staff;
use App\Domains\HR\Domain\Model\StaffUserLink;
use App\Domains\HR\Domain\StaffReader;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Eloquent implementation of StaffReader (25-hr-and-workforce-domain.md §6; ADR-206).
 */
final class EloquentStaffReader implements StaffReader
{
    public function exists(string $staffId): bool
    {
        return Staff::query()->whereKey($staffId)->exists();
    }

    public function summary(string $staffId): StaffSummary
    {
        /** @var Staff|null $staff */
        $staff = Staff::query()
            ->with(['person', 'primaryCadre', 'primaryPosition'])
            ->whereKey($staffId)
            ->first();

        if ($staff === null) {
            throw StaffNotFoundException::forId($staffId);
        }

        return $this->toSummary($staff);
    }

    public function detail(string $staffId): StaffDetail
    {
        /** @var Staff|null $staff */
        $staff = Staff::query()
            ->with(['primaryCadre', 'primaryPosition'])
            ->whereKey($staffId)
            ->first();

        if ($staff === null) {
            throw StaffNotFoundException::forId($staffId);
        }

        return new StaffDetail(
            staffId: $staff->id,
            tenantId: $staff->tenant_id,
            personId: $staff->person_id,
            staffNumber: $staff->staff_number,
            status: $staff->status->value,
            primaryCadreId: $staff->primary_cadre_id,
            primaryCadreName: $staff->primaryCadre?->name,
            primaryPositionId: $staff->primary_position_id,
            primaryPositionTitle: $staff->primaryPosition?->title,
            joinedDate: $staff->joined_date?->toDateString(),
            terminatedDate: $staff->terminated_date?->toDateString(),
            terminationReason: $staff->termination_reason,
            suspensionReason: $staff->suspension_reason,
            version: $staff->version,
        );
    }

    public function notificationContact(string $staffId): ?StaffNotificationContactDto
    {
        /** @var Staff|null $staff */
        $staff = Staff::query()->with('person')->whereKey($staffId)->first();
        if ($staff === null || $staff->person === null) {
            return null;
        }

        $userLink = StaffUserLink::query()->where('staff_id', $staffId)->first();

        return new StaffNotificationContactDto(
            staffId: $staff->id,
            phone: $staff->person->phone,
            email: $staff->person->email,
            userId: $userLink?->user_id,
        );
    }

    public function photoDocumentIdForActiveFacilityAssignment(
        string $tenantId,
        string $facilityId,
        string $staffId,
        string $date,
    ): ?string {
        $documentId = DB::table('hr_assignments as assignment')
            ->join('hr_staff as staff', 'staff.id', '=', 'assignment.staff_id')
            ->join('hr_persons as person', 'person.id', '=', 'staff.person_id')
            ->where('assignment.tenant_id', $tenantId)
            ->where('assignment.facility_id', $facilityId)
            ->where('assignment.staff_id', $staffId)
            ->where('assignment.status', 'ACTIVE')
            ->where('assignment.effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('assignment.effective_to')->orWhere('assignment.effective_to', '>=', $date))
            ->value('person.photo_document_id');

        return is_string($documentId) ? $documentId : null;
    }

    public function search(?string $search = null, ?string $status = null, ?string $cadreId = null): array
    {
        $query = Staff::query()->with(['person', 'primaryCadre', 'primaryPosition']);

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        if ($cadreId !== null && $cadreId !== '') {
            $query->where('primary_cadre_id', $cadreId);
        }

        if ($search !== null && trim($search) !== '') {
            $term = trim($search);
            $query->where(function (Builder $builder) use ($term): void {
                $builder->where('staff_number', 'ilike', "%{$term}%")
                    ->orWhereHas('person', function (Builder $personQuery) use ($term): void {
                        $personQuery->where('first_name', 'ilike', "%{$term}%")
                            ->orWhere('last_name', 'ilike', "%{$term}%")
                            ->orWhere('national_id', 'ilike', "%{$term}%");
                    });
            });
        }

        return array_values($query->orderBy('staff_number')->get()->map(fn (Staff $s) => $this->toSummary($s))->all());
    }

    private function toSummary(Staff $staff): StaffSummary
    {
        return new StaffSummary(
            staffId: $staff->id,
            staffNumber: $staff->staff_number,
            displayName: $staff->person?->fullName() ?? '',
            cadreCategory: $staff->primaryCadre?->category->value,
            cadreName: $staff->primaryCadre?->name,
            positionTitle: $staff->primaryPosition?->title,
            status: $staff->status->value,
            isActive: $staff->status->isActive(),
        );
    }
}
