<?php

declare(strict_types=1);

use App\Domains\HR\Application\Command\AddProfessionalRegistration;
use App\Domains\HR\Application\Command\AssignStaff;
use App\Domains\HR\Application\Command\CreateEmployment;
use App\Domains\HR\Application\Command\CreatePerson;
use App\Domains\HR\Application\Command\CreateStaff;
use App\Domains\HR\Application\Command\DefineCadre;
use App\Domains\HR\Application\Command\DefineLeaveType;
use App\Domains\HR\Application\Command\DefinePosition;
use App\Domains\HR\Application\Command\LinkStaffToUser;
use App\Domains\HR\Application\Command\OnboardStaff;
use App\Domains\HR\Application\Command\PostLeaveLedgerEntry;
use App\Domains\HR\Application\Command\RequestLeave;
use App\Domains\HR\Domain\ValueObject\AssignmentScope;
use App\Domains\HR\Domain\ValueObject\CadreCategory;
use App\Domains\HR\Domain\ValueObject\ContractType;
use App\Domains\HR\Domain\ValueObject\Gender;
use App\Domains\HR\Domain\ValueObject\LeaveLedgerKind;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContext;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/security.php';

if (! function_exists('hrFixture')) {
    /**
     * @param  list<string>|null  $permissions
     * @return array{tenant: mixed, organization: mixed, facility: mixed, user: mixed, role_id: string}
     */
    function hrFixture(?array $permissions = null): array
    {
        $permissions ??= [
            'hr.access',
            'hr.person.manage',
            'hr.staff.read',
            'hr.staff.manage',
            'hr.employment.manage',
            'hr.cadre_position.manage',
            'hr.assignment.manage',
            'hr.registration.manage',
            'hr.staff_user_link.manage',
            'hr.shift_pattern.manage',
            'hr.roster.manage',
            'hr.attendance.ingest',
            'hr.attendance.manage',
            'hr.attendance.reconcile',
            'hr.leave_type.manage',
            'hr.leave_ledger.post',
            'hr.leave.request',
            'hr.leave.approve',
        ];

        /** @var array{tenant: mixed, organization: mixed, facility: mixed, user: mixed, role_id: string} $fixture */
        $fixture = makeTenantWithUser($permissions);
        test()->actingAs($fixture['user']);

        return $fixture;
    }
}

if (! function_exists('createPersonHelper')) {
    function createPersonHelper(
        string $tenantId,
        string $firstName = 'Jane',
        ?string $middleName = 'K',
        string $lastName = 'Doe',
        string $dateOfBirth = '1990-05-15',
        Gender|string $gender = Gender::FEMALE,
        ?string $nationalId = null,
        ?string $phone = '+255712345678',
        ?string $email = 'jane.doe@example.com',
        ?string $facilityId = null,
    ): string {
        $facilityId ??= app(TenantContext::class)->facilityId() ?? (string) DB::table('org_facilities')->where('tenant_id', $tenantId)->value('id');
        $nationalId ??= 'NIDA-'.Str::random(10);
        $personId = (string) Str::uuid();

        TenantContextScope::run($tenantId, $facilityId, function () use (
            $personId, $firstName, $middleName, $lastName, $dateOfBirth, $gender, $nationalId, $phone, $email
        ): void {
            app(CommandBus::class)->dispatch(new CreatePerson(
                firstName: $firstName,
                middleName: $middleName,
                lastName: $lastName,
                dateOfBirth: $dateOfBirth,
                gender: $gender,
                personId: $personId,
                nationalId: $nationalId,
                phone: $phone,
                email: $email,
            ));
        });

        return $personId;
    }
}

if (! function_exists('defineCadreHelper')) {
    function defineCadreHelper(
        string $tenantId,
        string $code = 'MED-OFFICER',
        string $name = 'Medical Officer',
        CadreCategory|string $category = CadreCategory::CLINICAL,
        ?string $facilityId = null,
    ): string {
        $facilityId ??= app(TenantContext::class)->facilityId() ?? (string) DB::table('org_facilities')->where('tenant_id', $tenantId)->value('id');
        $cadreId = (string) Str::uuid();

        TenantContextScope::run($tenantId, $facilityId, function () use ($cadreId, $code, $name, $category): void {
            app(CommandBus::class)->dispatch(new DefineCadre(
                code: $code,
                name: $name,
                category: $category,
                cadreId: $cadreId,
            ));
        });

        return $cadreId;
    }
}

if (! function_exists('definePositionHelper')) {
    function definePositionHelper(
        string $tenantId,
        string $cadreId,
        string $code = 'POS-REG-MO',
        string $title = 'Resident Medical Officer',
        ?string $facilityId = null,
    ): string {
        $facilityId ??= app(TenantContext::class)->facilityId() ?? (string) DB::table('org_facilities')->where('tenant_id', $tenantId)->value('id');
        $positionId = (string) Str::uuid();

        TenantContextScope::run($tenantId, $facilityId, function () use ($positionId, $code, $title, $cadreId): void {
            app(CommandBus::class)->dispatch(new DefinePosition(
                code: $code,
                title: $title,
                cadreId: $cadreId,
                positionId: $positionId,
            ));
        });

        return $positionId;
    }
}

if (! function_exists('createStaffHelper')) {
    function createStaffHelper(
        string $tenantId,
        string $personId,
        ?string $cadreId = null,
        ?string $positionId = null,
        ?string $facilityId = null,
    ): string {
        $facilityId ??= app(TenantContext::class)->facilityId() ?? (string) DB::table('org_facilities')->where('tenant_id', $tenantId)->value('id');
        $staffId = (string) Str::uuid();

        TenantContextScope::run($tenantId, $facilityId, function () use ($staffId, $personId, $cadreId, $positionId): void {
            app(CommandBus::class)->dispatch(new CreateStaff(
                personId: $personId,
                staffId: $staffId,
                primaryCadreId: $cadreId,
                primaryPositionId: $positionId,
            ));
        });

        return $staffId;
    }
}

if (! function_exists('createEmploymentHelper')) {
    function createEmploymentHelper(
        string $tenantId,
        string $staffId,
        string $positionId,
        ContractType|string $contractType = ContractType::PERMANENT,
        string $startDate = '2026-01-01',
        string $fte = '1.00',
        ?string $facilityId = null,
    ): string {
        $facilityId ??= app(TenantContext::class)->facilityId() ?? (string) DB::table('org_facilities')->where('tenant_id', $tenantId)->value('id');
        $employmentId = (string) Str::uuid();

        TenantContextScope::run($tenantId, $facilityId, function () use (
            $employmentId, $staffId, $positionId, $contractType, $startDate, $fte
        ): void {
            app(CommandBus::class)->dispatch(new CreateEmployment(
                staffId: $staffId,
                positionId: $positionId,
                contractType: $contractType,
                startDate: $startDate,
                fte: $fte,
                employmentId: $employmentId,
            ));
        });

        return $employmentId;
    }
}

if (! function_exists('assignStaffHelper')) {
    function assignStaffHelper(
        string $tenantId,
        string $staffId,
        string $facilityId,
        AssignmentScope|string $scope = AssignmentScope::FACILITY,
        string $effectiveFrom = '2026-01-01',
        ?string $departmentId = null,
        ?string $unitId = null,
        ?string $effectiveTo = null,
        bool $isPrimary = false,
    ): string {
        $assignmentId = (string) Str::uuid();

        TenantContextScope::run($tenantId, $facilityId, function () use (
            $assignmentId, $staffId, $facilityId, $scope, $effectiveFrom, $departmentId, $unitId, $effectiveTo, $isPrimary
        ): void {
            app(CommandBus::class)->dispatch(new AssignStaff(
                staffId: $staffId,
                facilityId: $facilityId,
                scope: $scope,
                effectiveFrom: $effectiveFrom,
                departmentId: $departmentId,
                unitId: $unitId,
                effectiveTo: $effectiveTo,
                isPrimary: $isPrimary,
                assignmentId: $assignmentId,
            ));
        });

        return $assignmentId;
    }
}

if (! function_exists('addRegistrationHelper')) {
    function addRegistrationHelper(
        string $tenantId,
        string $staffId,
        string $council = 'MCT',
        string $category = 'Medical Practitioner',
        ?string $licenceNumber = null,
        string $issuedOn = '2025-01-01',
        string $expiresOn = '2027-12-31',
        ?string $notes = null,
        ?string $facilityId = null,
    ): string {
        $facilityId ??= app(TenantContext::class)->facilityId() ?? (string) DB::table('org_facilities')->where('tenant_id', $tenantId)->value('id');
        $licenceNumber ??= 'LIC-'.Str::random(8);
        $registrationId = (string) Str::uuid();

        TenantContextScope::run($tenantId, $facilityId, function () use (
            $registrationId, $staffId, $council, $category, $licenceNumber, $issuedOn, $expiresOn, $notes
        ): void {
            app(CommandBus::class)->dispatch(new AddProfessionalRegistration(
                staffId: $staffId,
                council: $council,
                category: $category,
                licenceNumber: $licenceNumber,
                issuedOn: $issuedOn,
                expiresOn: $expiresOn,
                notes: $notes,
                registrationId: $registrationId,
            ));
        });

        return $registrationId;
    }
}

if (! function_exists('linkStaffUserHelper')) {
    function linkStaffUserHelper(
        string $tenantId,
        string $staffId,
        string $userId,
        ?string $facilityId = null,
    ): string {
        $facilityId ??= app(TenantContext::class)->facilityId() ?? (string) DB::table('org_facilities')->where('tenant_id', $tenantId)->value('id');
        $linkId = (string) Str::uuid();

        TenantContextScope::run($tenantId, $facilityId, function () use ($linkId, $staffId, $userId): void {
            app(CommandBus::class)->dispatch(new LinkStaffToUser(
                staffId: $staffId,
                userId: $userId,
                linkId: $linkId,
            ));
        });

        return $linkId;
    }
}

if (! function_exists('onboardStaffHelper')) {
    function onboardStaffHelper(
        string $tenantId,
        string $firstName = 'Jane',
        string $lastName = 'Doe',
        ?string $facilityId = null,
        string $joinedDate = '2026-01-01',
    ): string {
        $facilityId ??= app(TenantContext::class)->facilityId() ?? (string) DB::table('org_facilities')->where('tenant_id', $tenantId)->value('id');
        $personId = createPersonHelper($tenantId, $firstName, 'K', $lastName, facilityId: $facilityId);
        $staffId = createStaffHelper($tenantId, $personId, facilityId: $facilityId);

        TenantContextScope::run($tenantId, $facilityId, function () use ($staffId, $joinedDate): void {
            app(CommandBus::class)->dispatch(new OnboardStaff($staffId, $joinedDate));
        });

        return $staffId;
    }
}

if (! function_exists('defineLeaveTypeHelper')) {
    function defineLeaveTypeHelper(
        string $tenantId,
        string $code = 'ANNUAL',
        string $name = 'Annual Leave',
        bool $isPaid = true,
        float $accrualRate = 21.0,
        ?float $maxBalance = 30.0,
        bool $allowNegative = false,
        ?float $carryOverLimit = 10.0,
        ?string $facilityId = null,
    ): string {
        $facilityId ??= app(TenantContext::class)->facilityId() ?? (string) DB::table('org_facilities')->where('tenant_id', $tenantId)->value('id');
        $typeId = (string) Str::uuid();

        TenantContextScope::run($tenantId, $facilityId, function () use (
            $typeId, $code, $name, $isPaid, $accrualRate, $maxBalance, $allowNegative, $carryOverLimit
        ): void {
            app(CommandBus::class)->dispatch(new DefineLeaveType(
                code: $code,
                name: $name,
                isPaid: $isPaid,
                accrualRatePerYear: $accrualRate,
                maxBalance: $maxBalance,
                allowNegative: $allowNegative,
                carryOverLimit: $carryOverLimit,
                leaveTypeId: $typeId,
            ));
        });

        return $typeId;
    }
}

if (! function_exists('postLeaveLedgerEntryHelper')) {
    function postLeaveLedgerEntryHelper(
        string $tenantId,
        string $staffId,
        string $leaveTypeId,
        LeaveLedgerKind $kind,
        float $delta,
        string $effectiveDate,
        string $postedBy,
        ?string $sourceRef = null,
        ?string $reason = null,
        ?string $facilityId = null,
    ): string {
        $facilityId ??= app(TenantContext::class)->facilityId() ?? (string) DB::table('org_facilities')->where('tenant_id', $tenantId)->value('id');
        $entryId = (string) Str::uuid();

        TenantContextScope::run($tenantId, $facilityId, function () use (
            $entryId, $staffId, $leaveTypeId, $kind, $delta, $effectiveDate, $postedBy, $sourceRef, $reason
        ): void {
            app(CommandBus::class)->dispatch(new PostLeaveLedgerEntry(
                staffId: $staffId,
                leaveTypeId: $leaveTypeId,
                kind: $kind,
                delta: $delta,
                effectiveDate: CarbonImmutable::parse($effectiveDate),
                postedBy: $postedBy,
                sourceRef: $sourceRef,
                reason: $reason,
                ledgerEntryId: $entryId,
            ));
        });

        return $entryId;
    }
}

if (! function_exists('requestLeaveHelper')) {
    function requestLeaveHelper(
        string $tenantId,
        string $facilityId,
        string $staffId,
        string $leaveTypeId,
        string $startDate,
        string $endDate,
        float $days,
        ?string $reason = null,
    ): string {
        $requestId = (string) Str::uuid();

        TenantContextScope::run($tenantId, $facilityId, function () use (
            $requestId, $facilityId, $staffId, $leaveTypeId, $startDate, $endDate, $days, $reason
        ): void {
            app(CommandBus::class)->dispatch(new RequestLeave(
                facilityId: $facilityId,
                staffId: $staffId,
                leaveTypeId: $leaveTypeId,
                startDate: CarbonImmutable::parse($startDate),
                endDate: CarbonImmutable::parse($endDate),
                days: $days,
                reason: $reason,
                leaveRequestId: $requestId,
            ));
        });

        return $requestId;
    }
}
