<?php

declare(strict_types=1);

namespace App\Domains\HR\Infrastructure\Reader;

use Illuminate\Support\Facades\DB;

final class FacilityPeopleSearchReader
{
    /** @return list<string> */
    public function facilityRelatedAccountIds(string $tenantId, string $facilityId): array
    {
        return array_values(DB::table('hr_staff_user_links as staff_link')
            ->join('hr_assignments as assignment', 'assignment.staff_id', '=', 'staff_link.staff_id')
            ->where('staff_link.tenant_id', $tenantId)
            ->where('assignment.tenant_id', $tenantId)
            ->where('assignment.facility_id', $facilityId)
            ->pluck('staff_link.user_id')
            ->map(static fn (mixed $userId): string => (string) $userId)
            ->all());
    }

    /** @return list<string> */
    public function activeFacilityAccountIds(string $tenantId, string $facilityId): array
    {
        return array_values(DB::table('hr_staff_user_links as staff_link')
            ->join('hr_assignments as assignment', 'assignment.staff_id', '=', 'staff_link.staff_id')
            ->where('staff_link.tenant_id', $tenantId)
            ->where('assignment.tenant_id', $tenantId)
            ->where('assignment.facility_id', $facilityId)
            ->where('assignment.status', 'ACTIVE')
            ->pluck('staff_link.user_id')
            ->map(static fn (mixed $userId): string => (string) $userId)
            ->all());
    }

    /** @return array{id: string, staffNumber: string, firstName: string, middleName: ?string, lastName: string}|null */
    public function linkedStaffForAccount(string $tenantId, string $facilityId, string $userId): ?array
    {
        $row = DB::table('hr_staff_user_links as staff_link')
            ->join('hr_staff as staff', 'staff.id', '=', 'staff_link.staff_id')
            ->join('hr_persons as person', 'person.id', '=', 'staff.person_id')
            ->where('staff_link.tenant_id', $tenantId)
            ->where('staff_link.user_id', $userId)
            ->where(function ($relationship) use ($tenantId, $facilityId): void {
                $relationship->whereExists(function ($assignments) use ($tenantId, $facilityId): void {
                    $assignments->selectRaw('1')
                        ->from('hr_assignments')
                        ->whereColumn('hr_assignments.staff_id', 'staff.id')
                        ->where('hr_assignments.tenant_id', $tenantId)
                        ->where('hr_assignments.facility_id', $facilityId);
                })->orWhereExists(function ($requests) use ($tenantId, $facilityId): void {
                    $requests->selectRaw('1')
                        ->from('hr_assignment_requests')
                        ->whereColumn('hr_assignment_requests.staff_id', 'staff.id')
                        ->where('hr_assignment_requests.tenant_id', $tenantId)
                        ->where('hr_assignment_requests.facility_id', $facilityId);
                });
            })
            ->first([
                'staff.id',
                'staff.staff_number',
                'person.first_name',
                'person.middle_name',
                'person.last_name',
            ]);

        return $row === null ? null : [
            'id' => (string) $row->id,
            'staffNumber' => (string) $row->staff_number,
            'firstName' => (string) $row->first_name,
            'middleName' => $row->middle_name === null ? null : (string) $row->middle_name,
            'lastName' => (string) $row->last_name,
        ];
    }

    /**
     * @return list<array{
     *     id: string,
     *     staffNumber: string,
     *     status: string,
     *     firstName: string,
     *     middleName: ?string,
     *     lastName: string,
     *     photoDocumentId: ?string,
     *     accountEmail: ?string
     * }>
     */
    public function activeStaffMatches(
        string $tenantId,
        string $facilityId,
        string $pattern,
        string $today,
        bool $includeAccountEmail,
    ): array {
        $query = DB::table('hr_assignments as assignment')
            ->join('hr_staff as staff', 'staff.id', '=', 'assignment.staff_id')
            ->join('hr_persons as person', 'person.id', '=', 'staff.person_id')
            ->where('assignment.tenant_id', $tenantId)
            ->where('assignment.facility_id', $facilityId)
            ->where('assignment.status', 'ACTIVE')
            ->where('assignment.effective_from', '<=', $today)
            ->where(fn ($query) => $query->whereNull('assignment.effective_to')
                ->orWhere('assignment.effective_to', '>=', $today))
            ->where(function ($query) use ($pattern): void {
                $query->whereRaw("LOWER(CONCAT_WS(' ', person.first_name, person.middle_name, person.last_name)) LIKE ?", [$pattern])
                    ->orWhereRaw('LOWER(staff.staff_number) LIKE ?', [$pattern])
                    ->orWhereRaw('LOWER(COALESCE(person.email, \'\')) LIKE ?', [$pattern])
                    ->orWhereRaw('LOWER(COALESCE(person.phone, \'\')) LIKE ?', [$pattern]);
            })
            ->orderBy('person.last_name')
            ->orderBy('person.first_name')
            ->limit(5);

        if ($includeAccountEmail) {
            $query
                ->leftJoin('hr_staff_user_links as staff_link', function ($join) use ($tenantId): void {
                    $join->on('staff_link.staff_id', '=', 'staff.id')
                        ->where('staff_link.tenant_id', '=', $tenantId);
                })
                ->leftJoin('users as account', function ($join) use ($tenantId): void {
                    $join->on('account.id', '=', 'staff_link.user_id')
                        ->where('account.tenant_id', '=', $tenantId);
                });
        }

        $columns = [
            'staff.id',
            'staff.staff_number',
            'staff.status as staff_status',
            'person.first_name',
            'person.middle_name',
            'person.last_name',
            'person.photo_document_id',
        ];
        if ($includeAccountEmail) {
            $columns[] = 'account.email as account_email';
        }

        return array_values($query->get($columns)
            ->map(static fn (object $row): array => [
                'id' => (string) $row->id,
                'staffNumber' => (string) $row->staff_number,
                'status' => (string) $row->staff_status,
                'firstName' => (string) $row->first_name,
                'middleName' => $row->middle_name === null ? null : (string) $row->middle_name,
                'lastName' => (string) $row->last_name,
                'photoDocumentId' => $row->photo_document_id === null ? null : (string) $row->photo_document_id,
                'accountEmail' => ! $includeAccountEmail || $row->account_email === null
                    ? null
                    : (string) $row->account_email,
            ])->all());
    }
}
