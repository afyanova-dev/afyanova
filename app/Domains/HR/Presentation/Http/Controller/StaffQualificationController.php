<?php

declare(strict_types=1);

namespace App\Domains\HR\Presentation\Http\Controller;

use App\Domains\HR\Application\FacilityStaffDirectoryService;
use App\Models\User;
use App\Shared\Audit\AuditLogger;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class StaffQualificationController
{
    public function store(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        FacilityStaffDirectoryService $directory,
        AuditLogger $audit,
        string $staff,
    ): RedirectResponse {
        $actor = $this->authorizeStaffQualificationManagement($request, $context, $scopeChecker, $directory, $staff);
        $validated = $request->validate($this->rules());
        $verified = $validated['verification_status'] === 'VERIFIED';
        $qualificationId = (string) Str::uuid7();

        DB::table('hr_qualifications')->insert([
            'id' => $qualificationId,
            'tenant_id' => $context->tenantId(),
            'staff_id' => $staff,
            'award' => $validated['award'],
            'field_of_study' => $validated['field_of_study'],
            'institution' => $validated['institution'],
            'completion_year' => $validated['completion_year'] ?? null,
            'verification_status' => $validated['verification_status'],
            'verified_by' => $verified ? (string) $actor->getKey() : null,
            'verified_at' => $verified ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $audit->recordFor(
            tenantId: $context->tenantId(),
            action: 'hr.staff_qualification.created',
            actorId: (string) $actor->getKey(),
            facilityId: $context->facilityId(),
            metadata: ['qualification_id' => $qualificationId, 'verification_status' => $validated['verification_status']],
            targetType: 'staff',
            targetId: $staff,
        );

        return back()->with('status', 'Staff qualification added.');
    }

    public function update(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        FacilityStaffDirectoryService $directory,
        AuditLogger $audit,
        string $staff,
        string $qualification,
    ): RedirectResponse {
        $actor = $this->authorizeStaffQualificationManagement($request, $context, $scopeChecker, $directory, $staff);
        $validated = $request->validate($this->rules());
        $verified = $validated['verification_status'] === 'VERIFIED';

        $updated = DB::table('hr_qualifications')
            ->where('tenant_id', $context->tenantId())
            ->where('staff_id', $staff)
            ->where('id', $qualification)
            ->update([
                'award' => $validated['award'],
                'field_of_study' => $validated['field_of_study'],
                'institution' => $validated['institution'],
                'completion_year' => $validated['completion_year'] ?? null,
                'verification_status' => $validated['verification_status'],
                'verified_by' => $verified ? (string) $actor->getKey() : null,
                'verified_at' => $verified ? now() : null,
                'version' => DB::raw('version + 1'),
                'updated_at' => now(),
            ]);
        abort_unless($updated === 1, 404);

        $audit->recordFor(
            tenantId: $context->tenantId(),
            action: 'hr.staff_qualification.updated',
            actorId: (string) $actor->getKey(),
            facilityId: $context->facilityId(),
            metadata: ['qualification_id' => $qualification, 'verification_status' => $validated['verification_status']],
            targetType: 'staff',
            targetId: $staff,
        );

        return back()->with('status', 'Staff qualification updated.');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(): array
    {
        return [
            'award' => ['required', 'string', 'max:150'],
            'field_of_study' => ['required', 'string', 'max:150'],
            'institution' => ['required', 'string', 'max:200'],
            'completion_year' => ['nullable', 'integer', 'between:1900,'.now()->year],
            'verification_status' => ['required', Rule::in(['UNVERIFIED', 'VERIFIED'])],
        ];
    }

    private function authorizeStaffQualificationManagement(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        FacilityStaffDirectoryService $directory,
        string $staff,
    ): User {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $facilityId = $context->facilityId();
        abort_unless($facilityId !== null, 403);
        abort_unless($scopeChecker->withinFacilityScope($actor, $facilityId), 403);
        abort_unless(
            in_array('hr.staff.manage', $scopeChecker->effectivePermissions($actor, $facilityId), true),
            403,
        );
        abort_unless(
            DB::table('hr_staff')
                ->where('tenant_id', $context->tenantId())
                ->where('id', $staff)
                ->exists(),
            404,
        );
        abort_unless(
            $directory->hasCurrentFacilityAssignment(
                $context->tenantId(),
                $facilityId,
                $staff,
                now()->toDateString(),
            ),
            404,
        );

        return $actor;
    }
}
