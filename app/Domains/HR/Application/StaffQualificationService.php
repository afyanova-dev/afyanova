<?php

declare(strict_types=1);

namespace App\Domains\HR\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class StaffQualificationService
{
    /**
     * @return list<array{
     *     id: string,
     *     award: string,
     *     fieldOfStudy: string,
     *     institution: string,
     *     completionYear: int|null,
     *     verificationStatus: string
     * }>
     */
    public function forStaff(string $tenantId, string $staffId): array
    {
        return array_values(
            DB::table('hr_qualifications')
                ->where('tenant_id', $tenantId)
                ->where('staff_id', $staffId)
                ->orderByDesc('completion_year')
                ->orderBy('award')
                ->get([
                    'id',
                    'award',
                    'field_of_study',
                    'institution',
                    'completion_year',
                    'verification_status',
                ])
                ->map(static fn (object $qualification): array => [
                    'id' => (string) $qualification->id,
                    'award' => (string) $qualification->award,
                    'fieldOfStudy' => (string) $qualification->field_of_study,
                    'institution' => (string) $qualification->institution,
                    'completionYear' => $qualification->completion_year === null
                        ? null
                        : (int) $qualification->completion_year,
                    'verificationStatus' => (string) $qualification->verification_status,
                ])
                ->all(),
        );
    }

    public function staffExists(string $tenantId, string $staffId): bool
    {
        return DB::table('hr_staff')
            ->where('tenant_id', $tenantId)
            ->where('id', $staffId)
            ->exists();
    }

    public function create(
        string $tenantId,
        string $staffId,
        string $actorId,
        string $award,
        string $fieldOfStudy,
        string $institution,
        ?int $completionYear,
        string $verificationStatus,
    ): string {
        $qualificationId = (string) Str::uuid7();
        $now = now();
        $verified = $verificationStatus === 'VERIFIED';

        DB::table('hr_qualifications')->insert([
            'id' => $qualificationId,
            'tenant_id' => $tenantId,
            'staff_id' => $staffId,
            'award' => $award,
            'field_of_study' => $fieldOfStudy,
            'institution' => $institution,
            'completion_year' => $completionYear,
            'verification_status' => $verificationStatus,
            'verified_by' => $verified ? $actorId : null,
            'verified_at' => $verified ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $qualificationId;
    }

    public function update(
        string $tenantId,
        string $staffId,
        string $qualificationId,
        string $actorId,
        string $award,
        string $fieldOfStudy,
        string $institution,
        ?int $completionYear,
        string $verificationStatus,
    ): bool {
        $verified = $verificationStatus === 'VERIFIED';

        return DB::table('hr_qualifications')
            ->where('tenant_id', $tenantId)
            ->where('staff_id', $staffId)
            ->where('id', $qualificationId)
            ->update([
                'award' => $award,
                'field_of_study' => $fieldOfStudy,
                'institution' => $institution,
                'completion_year' => $completionYear,
                'verification_status' => $verificationStatus,
                'verified_by' => $verified ? $actorId : null,
                'verified_at' => $verified ? now() : null,
                'version' => DB::raw('version + 1'),
                'updated_at' => now(),
            ]) === 1;
    }
}
