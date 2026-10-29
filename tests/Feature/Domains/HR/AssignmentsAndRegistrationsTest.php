<?php

declare(strict_types=1);

use App\Domains\HR\Application\Command\AddProfessionalRegistration;
use App\Domains\HR\Application\Command\AmendAssignment;
use App\Domains\HR\Application\Command\AssignStaff;
use App\Domains\HR\Application\Command\EndAssignment;
use App\Domains\HR\Application\Command\LinkStaffToUser;
use App\Domains\HR\Application\Command\OnboardStaff;
use App\Domains\HR\Application\Command\RecordRegistrationVerification;
use App\Domains\HR\Application\Command\TerminateStaff;
use App\Domains\HR\Application\Command\UnlinkStaffFromUser;
use App\Domains\HR\Application\Command\UpdateRegistrationStatus;
use App\Domains\HR\Application\Query\GetActiveAssignments;
use App\Domains\HR\Application\Query\GetRegistrations;
use App\Domains\HR\Application\Query\GetStaffAssignments;
use App\Domains\HR\Application\Query\GetStaffByUser;
use App\Domains\HR\Application\Query\GetUserByStaff;
use App\Domains\HR\Contracts\Event\AssignmentAmended;
use App\Domains\HR\Contracts\Event\AssignmentEnded;
use App\Domains\HR\Contracts\Event\RegistrationAdded;
use App\Domains\HR\Contracts\Event\RegistrationStatusChanged;
use App\Domains\HR\Contracts\Event\RegistrationVerified;
use App\Domains\HR\Contracts\Event\StaffAssigned;
use App\Domains\HR\Contracts\Event\StaffLinkedToUser;
use App\Domains\HR\Contracts\Event\StaffUnlinkedFromUser;
use App\Domains\HR\Domain\AssignmentReader;
use App\Domains\HR\Domain\Exception\DuplicateRegistrationLicenceException;
use App\Domains\HR\Domain\Exception\InvalidOrganizationStructureException;
use App\Domains\HR\Domain\Exception\StaffAlreadyLinkedException;
use App\Domains\HR\Domain\Exception\StaffHasActiveAssignmentsException;
use App\Domains\HR\Domain\Exception\UserAlreadyLinkedException;
use App\Domains\HR\Domain\ProfessionalRegistrationReader;
use App\Domains\HR\Domain\StaffUserLinkReader;
use App\Domains\HR\Domain\ValueObject\AssignmentScope;
use App\Domains\HR\Domain\ValueObject\RegistrationStatus;
use App\Domains\HR\Domain\ValueObject\VerificationSource;
use App\Models\User;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../../tests/Support/hr.php';

beforeEach(function (): void {
    $this->bus = app(CommandBus::class);
    $this->queryBus = app(QueryBus::class);
});

it('executes organizational assignment lifecycle with outbox events', function (): void {
    $fx = hrFixture();
    $tenantId = $fx['tenant']->id;
    $facilityId = $fx['facility']->id;

    $personId = createPersonHelper($tenantId, 'Alice', 'M', 'Walker');
    $staffId = createStaffHelper($tenantId, $personId);

    // Create department and unit in Organization structure
    $deptId = (string) Str::uuid();
    $unitId = (string) Str::uuid();

    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $deptId, $unitId): void {
        DB::table('org_departments')->insert([
            'id' => $deptId,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'name' => 'Pediatrics',
            'code' => 'PED',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('org_units')->insert([
            'id' => $unitId,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'department_id' => $deptId,
            'name' => 'NICU',
            'code' => 'NICU',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    // 1. AssignStaff to unit
    $assignmentId = (string) Str::uuid();
    TenantContextScope::run($tenantId, $facilityId, function () use (
        $assignmentId, $staffId, $facilityId, $deptId, $unitId
    ): void {
        $this->bus->dispatch(new AssignStaff(
            staffId: $staffId,
            facilityId: $facilityId,
            scope: AssignmentScope::UNIT,
            effectiveFrom: '2026-02-01',
            departmentId: $deptId,
            unitId: $unitId,
            isPrimary: true,
            assignmentId: $assignmentId,
        ));
    });

    Tenancy::run($tenantId, function () use ($assignmentId, $staffId, $facilityId, $deptId, $unitId): void {
        $row = DB::table('hr_assignments')->where('id', $assignmentId)->first();
        expect($row)->not->toBeNull()
            ->and($row->staff_id)->toBe($staffId)
            ->and($row->facility_id)->toBe($facilityId)
            ->and($row->department_id)->toBe($deptId)
            ->and($row->unit_id)->toBe($unitId)
            ->and($row->scope)->toBe('UNIT')
            ->and($row->is_primary)->toBeTrue()
            ->and($row->status)->toBe('ACTIVE')
            ->and($row->version)->toBe(0);

        $outbox = DB::table('shared_outbox_events')
            ->where('event_class', StaffAssigned::class)
            ->first();
        expect($outbox)->not->toBeNull();
        $payload = json_decode((string) $outbox->payload, true);
        expect($payload['assignmentId'])->toBe($assignmentId);
    });

    // 2. AmendAssignment
    TenantContextScope::run($tenantId, $facilityId, function () use ($assignmentId): void {
        $this->bus->dispatch(new AmendAssignment(
            assignmentId: $assignmentId,
            effectiveTo: '2026-12-31',
        ));
    });

    Tenancy::run($tenantId, function () use ($assignmentId): void {
        $row = DB::table('hr_assignments')->where('id', $assignmentId)->first();
        expect($row->effective_to)->toBe('2026-12-31')
            ->and($row->version)->toBe(1);

        $outbox = DB::table('shared_outbox_events')
            ->where('event_class', AssignmentAmended::class)
            ->first();
        expect($outbox)->not->toBeNull();
        $payload = json_decode((string) $outbox->payload, true);
        expect($payload['assignmentId'])->toBe($assignmentId);
    });

    // 3. EndAssignment
    TenantContextScope::run($tenantId, $facilityId, function () use ($assignmentId): void {
        $this->bus->dispatch(new EndAssignment(
            assignmentId: $assignmentId,
            endedDate: '2026-06-30',
            reason: 'Transferred to another facility',
        ));
    });

    Tenancy::run($tenantId, function () use ($assignmentId): void {
        $row = DB::table('hr_assignments')->where('id', $assignmentId)->first();
        expect($row->status)->toBe('ENDED')
            ->and($row->is_primary)->toBeFalse()
            ->and($row->end_reason)->toBe('Transferred to another facility')
            ->and($row->version)->toBe(2);

        $outbox = DB::table('shared_outbox_events')
            ->where('event_class', AssignmentEnded::class)
            ->first();
        expect($outbox)->not->toBeNull();
        $payload = json_decode((string) $outbox->payload, true);
        expect($payload['assignmentId'])->toBe($assignmentId);
    });
});

it('enforces single active primary assignment per staff atomically', function (): void {
    $fx = hrFixture();
    $tenantId = $fx['tenant']->id;
    $facilityId = $fx['facility']->id;

    $personId = createPersonHelper($tenantId, 'Bob', 'A', 'Johnson');
    $staffId = createStaffHelper($tenantId, $personId);

    // Assign primary assignment 1
    $assign1Id = assignStaffHelper(
        tenantId: $tenantId,
        staffId: $staffId,
        facilityId: $facilityId,
        effectiveFrom: '2026-01-01',
        isPrimary: true,
    );

    Tenancy::run($tenantId, function () use ($assign1Id): void {
        $row = DB::table('hr_assignments')->where('id', $assign1Id)->first();
        expect($row->is_primary)->toBeTrue();
    });

    // Assign primary assignment 2 for same staff
    $assign2Id = assignStaffHelper(
        tenantId: $tenantId,
        staffId: $staffId,
        facilityId: $facilityId,
        effectiveFrom: '2026-04-01',
        isPrimary: true,
    );

    Tenancy::run($tenantId, function () use ($assign1Id, $assign2Id): void {
        $row1 = DB::table('hr_assignments')->where('id', $assign1Id)->first();
        $row2 = DB::table('hr_assignments')->where('id', $assign2Id)->first();

        // Previous primary was demoted atomically, new primary is true
        expect($row1->is_primary)->toBeFalse()
            ->and($row2->is_primary)->toBeTrue();
    });
});

it('validates organizational structure existence (Invariant 7.7)', function (): void {
    $fx = hrFixture();
    $tenantId = $fx['tenant']->id;
    $facilityId = $fx['facility']->id;

    $personId = createPersonHelper($tenantId, 'Chris', null, 'Brown');
    $staffId = createStaffHelper($tenantId, $personId);

    $fakeFacilityId = (string) Str::uuid();

    // Facility does not exist
    TenantContextScope::run($tenantId, $facilityId, function () use ($staffId, $fakeFacilityId): void {
        $this->bus->dispatch(new AssignStaff(
            staffId: $staffId,
            facilityId: $fakeFacilityId,
            scope: AssignmentScope::FACILITY,
            effectiveFrom: '2026-01-01',
        ));
    });
})->throws(InvalidOrganizationStructureException::class);

it('confirms assignment carries zero RBAC roles or permissions (Invariant 7.5)', function (): void {
    $fx = hrFixture();
    $tenantId = $fx['tenant']->id;
    $facilityId = $fx['facility']->id;

    $personId = createPersonHelper($tenantId, 'Diana', null, 'Prince');
    $staffId = createStaffHelper($tenantId, $personId);

    // Initial access assignments count for tenant
    $initialAssignmentsCount = DB::table('idn_access_assignments')->where('tenant_id', $tenantId)->count();

    // Perform assignment
    assignStaffHelper(
        tenantId: $tenantId,
        staffId: $staffId,
        facilityId: $facilityId,
        scope: AssignmentScope::FACILITY,
        effectiveFrom: '2026-01-01',
        isPrimary: true,
    );

    // Confirm idn_access_assignments remains unchanged
    $afterAssignmentsCount = DB::table('idn_access_assignments')->where('tenant_id', $tenantId)->count();
    expect($afterAssignmentsCount)->toBe($initialAssignmentsCount);
});

it('executes professional registration lifecycle and enforces unique licence', function (): void {
    $fx = hrFixture();
    $tenantId = $fx['tenant']->id;
    $facilityId = $fx['facility']->id;

    $personId = createPersonHelper($tenantId, 'Edward', 'E', 'Norton');
    $staffId = createStaffHelper($tenantId, $personId);

    $licenceNo = 'MCT-'.Str::random(6);

    // 1. Add professional registration
    $regId = (string) Str::uuid();
    TenantContextScope::run($tenantId, $facilityId, function () use (
        $regId, $staffId, $licenceNo
    ): void {
        $this->bus->dispatch(new AddProfessionalRegistration(
            staffId: $staffId,
            council: 'MCT',
            category: 'Medical Practitioner',
            licenceNumber: $licenceNo,
            issuedOn: '2025-01-01',
            expiresOn: '2027-12-31',
            notes: 'Initial registration',
            registrationId: $regId,
        ));
    });

    Tenancy::run($tenantId, function () use ($regId, $staffId, $licenceNo): void {
        $row = DB::table('hr_professional_registrations')->where('id', $regId)->first();
        expect($row)->not->toBeNull()
            ->and($row->staff_id)->toBe($staffId)
            ->and($row->council)->toBe('MCT')
            ->and($row->licence_number)->toBe($licenceNo)
            ->and($row->status)->toBe('ACTIVE')
            ->and($row->verification_source)->toBe('MANUAL')
            ->and($row->version)->toBe(0);

        $outbox = DB::table('shared_outbox_events')
            ->where('event_class', RegistrationAdded::class)
            ->first();
        expect($outbox)->not->toBeNull();
        $payload = json_decode((string) $outbox->payload, true);
        expect($payload['registrationId'])->toBe($regId);
    });

    // 2. Duplicate licence for same council throws DuplicateRegistrationLicenceException
    expect(function () use ($tenantId, $staffId, $licenceNo, $facilityId): void {
        addRegistrationHelper(
            tenantId: $tenantId,
            staffId: $staffId,
            council: 'MCT',
            licenceNumber: $licenceNo,
            facilityId: $facilityId,
        );
    })->toThrow(DuplicateRegistrationLicenceException::class);

    // 3. Record verification
    TenantContextScope::run($tenantId, $facilityId, function () use ($regId): void {
        $this->bus->dispatch(new RecordRegistrationVerification(
            registrationId: $regId,
            verifiedAt: CarbonImmutable::parse('2026-03-01 10:00:00'),
            verificationSource: VerificationSource::COUNCIL_API,
            notes: 'Verified via MCT API gateway',
        ));
    });

    Tenancy::run($tenantId, function () use ($regId): void {
        $row = DB::table('hr_professional_registrations')->where('id', $regId)->first();
        expect($row->verification_source)->toBe('COUNCIL_API')
            ->and($row->verified_at)->not->toBeNull()
            ->and($row->version)->toBe(1);

        $outbox = DB::table('shared_outbox_events')
            ->where('event_class', RegistrationVerified::class)
            ->first();
        expect($outbox)->not->toBeNull();
        $payload = json_decode((string) $outbox->payload, true);
        expect($payload['registrationId'])->toBe($regId);
    });

    // 4. Update registration status (e.g. SUSPENDED_BY_COUNCIL)
    TenantContextScope::run($tenantId, $facilityId, function () use ($regId): void {
        $this->bus->dispatch(new UpdateRegistrationStatus(
            registrationId: $regId,
            status: RegistrationStatus::SUSPENDED_BY_COUNCIL,
            reason: 'Disciplinary review pending',
        ));
    });

    Tenancy::run($tenantId, function () use ($regId): void {
        $row = DB::table('hr_professional_registrations')->where('id', $regId)->first();
        expect($row->status)->toBe('SUSPENDED_BY_COUNCIL')
            ->and($row->version)->toBe(2);

        $outbox = DB::table('shared_outbox_events')
            ->where('event_class', RegistrationStatusChanged::class)
            ->first();
        expect($outbox)->not->toBeNull();
        $payload = json_decode((string) $outbox->payload, true);
        expect($payload['registrationId'])->toBe($regId);
    });
});

it('executes staff-user link lifecycle and ensures non-deletion invariant (Invariant 7.3)', function (): void {
    $fx = hrFixture();
    $tenantId = $fx['tenant']->id;
    $facilityId = $fx['facility']->id;
    $user = $fx['user'];

    $personId = createPersonHelper($tenantId, 'Fiona', 'G', 'Gallagher');
    $staffId = createStaffHelper($tenantId, $personId);

    // 1. Link staff to user
    $linkId = (string) Str::uuid();
    TenantContextScope::run($tenantId, $facilityId, function () use ($linkId, $staffId, $user): void {
        $this->bus->dispatch(new LinkStaffToUser(
            staffId: $staffId,
            userId: (string) $user->id,
            linkId: $linkId,
        ));
    });

    Tenancy::run($tenantId, function () use ($linkId, $staffId, $user): void {
        $row = DB::table('hr_staff_user_links')->where('id', $linkId)->first();
        expect($row)->not->toBeNull()
            ->and($row->staff_id)->toBe($staffId)
            ->and($row->user_id)->toBe((string) $user->id);

        $outbox = DB::table('shared_outbox_events')
            ->where('event_class', StaffLinkedToUser::class)
            ->first();
        expect($outbox)->not->toBeNull();
        $payload = json_decode((string) $outbox->payload, true);
        expect($payload['linkId'])->toBe($linkId);
    });

    // 2. Duplicate link rejected
    $person2 = createPersonHelper($tenantId, 'George', null, 'Clark');
    $staff2 = createStaffHelper($tenantId, $person2);

    expect(function () use ($tenantId, $staff2, $user, $facilityId): void {
        linkStaffUserHelper($tenantId, $staff2, (string) $user->id, $facilityId);
    })->toThrow(UserAlreadyLinkedException::class);

    expect(function () use ($tenantId, $staffId, $facilityId): void {
        $user2 = User::factory()->forTenant($tenantId)->create();
        linkStaffUserHelper($tenantId, $staffId, (string) $user2->id, $facilityId);
    })->toThrow(StaffAlreadyLinkedException::class);

    // 3. Unlink staff from user
    TenantContextScope::run($tenantId, $facilityId, function () use ($staffId): void {
        $this->bus->dispatch(new UnlinkStaffFromUser(
            staffId: $staffId,
            reason: 'User account transitioned to external contractor',
        ));
    });

    Tenancy::run($tenantId, function () use ($linkId, $staffId, $user): void {
        $row = DB::table('hr_staff_user_links')->where('id', $linkId)->first();
        expect($row)->toBeNull();

        // Invariant 7.3: Neither Staff nor User is deleted
        $staffRow = DB::table('hr_staff')->where('id', $staffId)->first();
        $userRow = DB::table('users')->where('id', $user->id)->first();
        expect($staffRow)->not->toBeNull()
            ->and($userRow)->not->toBeNull();

        $outbox = DB::table('shared_outbox_events')
            ->where('event_class', StaffUnlinkedFromUser::class)
            ->first();
        expect($outbox)->not->toBeNull();
        $payload = json_decode((string) $outbox->payload, true);
        expect($payload['linkId'])->toBe($linkId);
    });
});

it('enforces fail-closed precondition: terminate staff rejected if active assignment exists', function (): void {
    $fx = hrFixture();
    $tenantId = $fx['tenant']->id;
    $facilityId = $fx['facility']->id;

    $personId = createPersonHelper($tenantId, 'Helen', null, 'Hunt');
    $staffId = createStaffHelper($tenantId, $personId);

    // Onboard staff to active status
    TenantContextScope::run($tenantId, $facilityId, function () use ($staffId): void {
        $this->bus->dispatch(new OnboardStaff($staffId, '2026-01-01'));
    });

    // Create active assignment
    $assignId = assignStaffHelper(
        tenantId: $tenantId,
        staffId: $staffId,
        facilityId: $facilityId,
        effectiveFrom: '2026-01-01',
    );

    // Attempting TerminateStaff must throw StaffHasActiveAssignmentsException
    expect(function () use ($tenantId, $facilityId, $staffId): void {
        TenantContextScope::run($tenantId, $facilityId, function () use ($staffId): void {
            $this->bus->dispatch(new TerminateStaff(
                staffId: $staffId,
                terminatedDate: '2026-05-01',
                reason: 'Resigned',
            ));
        });
    })->toThrow(StaffHasActiveAssignmentsException::class);

    // End assignment first
    TenantContextScope::run($tenantId, $facilityId, function () use ($assignId): void {
        $this->bus->dispatch(new EndAssignment(
            assignmentId: $assignId,
            endedDate: '2026-04-30',
            reason: 'Departing organization',
        ));
    });

    // Now terminate staff succeeds
    TenantContextScope::run($tenantId, $facilityId, function () use ($staffId): void {
        $this->bus->dispatch(new TerminateStaff(
            staffId: $staffId,
            terminatedDate: '2026-05-01',
            reason: 'Resigned',
        ));
    });

    Tenancy::run($tenantId, function () use ($staffId): void {
        $row = DB::table('hr_staff')->where('id', $staffId)->first();
        expect($row->status)->toBe('TERMINATED');
    });
});

it('verifies queries: assignments, registrations, and staff-user link readers', function (): void {
    $fx = hrFixture();
    $tenantId = $fx['tenant']->id;
    $facilityId = $fx['facility']->id;
    $user = $fx['user'];

    $personId = createPersonHelper($tenantId, 'Isaac', 'Newton', 'Scientist');
    $staffId = createStaffHelper($tenantId, $personId);

    $assignId = assignStaffHelper($tenantId, $staffId, $facilityId, isPrimary: true);
    $regId = addRegistrationHelper($tenantId, $staffId, 'MCT', 'Specialist');
    $linkId = linkStaffUserHelper($tenantId, $staffId, (string) $user->id, $facilityId);

    TenantContextScope::run($tenantId, $facilityId, function () use (
        $staffId, $facilityId, $assignId, $regId, $user
    ): void {
        // Query Active Assignments
        $activeAssignments = $this->queryBus->dispatch(new GetActiveAssignments($facilityId));
        expect($activeAssignments)->toBeArray()
            ->and(count($activeAssignments))->toBeGreaterThanOrEqual(1)
            ->and($activeAssignments[0]->assignmentId)->toBe($assignId);

        // Query Staff Assignments
        $staffAssignments = $this->queryBus->dispatch(new GetStaffAssignments($staffId));
        expect($staffAssignments)->toBeArray()
            ->and(count($staffAssignments))->toBe(1)
            ->and($staffAssignments[0]->assignmentId)->toBe($assignId);

        // Query Registrations
        $regs = $this->queryBus->dispatch(new GetRegistrations($staffId));
        expect($regs)->toBeArray()
            ->and(count($regs))->toBe(1)
            ->and($regs[0]->registrationId)->toBe($regId);

        // Query Staff By User
        $linkByUser = $this->queryBus->dispatch(new GetStaffByUser((string) $user->id));
        expect($linkByUser)->not->toBeNull()
            ->and($linkByUser->staffId)->toBe($staffId);

        // Query User By Staff
        $linkByStaff = $this->queryBus->dispatch(new GetUserByStaff($staffId));
        expect($linkByStaff)->not->toBeNull()
            ->and($linkByStaff->userId)->toBe((string) $user->id);
    });
});

it('enforces multi-tenant RLS isolation across assignments, registrations, and links', function (): void {
    $fxA = hrFixture();
    $tenantA = $fxA['tenant']->id;
    $facA = $fxA['facility']->id;

    $fxB = hrFixture(['hr.access', 'hr.staff.read', 'hr.assignment.manage']);
    $tenantB = $fxB['tenant']->id;
    $facB = $fxB['facility']->id;

    // Act as Tenant A user to create Tenant A's records
    test()->actingAs($fxA['user']);

    // Create in Tenant A
    $personA = createPersonHelper($tenantA, 'TenantA', null, 'Staff', facilityId: $facA);
    $staffA = createStaffHelper($tenantA, $personA, facilityId: $facA);
    $assignA = assignStaffHelper($tenantA, $staffA, $facA);
    $regA = addRegistrationHelper($tenantA, $staffA, facilityId: $facA);
    $linkA = linkStaffUserHelper($tenantA, $staffA, (string) $fxA['user']->id, $facA);

    // Switch actor to Tenant B user
    test()->actingAs($fxB['user']);

    // Queries in Tenant B cannot see Tenant A records
    TenantContextScope::run($tenantB, $facB, function () use ($staffA): void {
        $reader = app(AssignmentReader::class);
        expect($reader->forStaff($staffA))->toBe([]);

        $regReader = app(ProfessionalRegistrationReader::class);
        expect($regReader->forStaff($staffA))->toBe([]);

        $linkReader = app(StaffUserLinkReader::class);
        expect($linkReader->findByStaff($staffA))->toBeNull();
    });
});

it('enforces RBAC gates on HR.2 commands without permissions', function (): void {
    // User with only base access
    $fx = makeTenantWithUser(['hr.access']);
    test()->actingAs($fx['user']);
    $tenantId = $fx['tenant']->id;
    $facilityId = $fx['facility']->id;

    expect(function () use ($tenantId, $facilityId): void {
        TenantContextScope::run($tenantId, $facilityId, function () use ($facilityId): void {
            $this->bus->dispatch(new AssignStaff(
                staffId: (string) Str::uuid(),
                facilityId: $facilityId,
                scope: AssignmentScope::FACILITY,
                effectiveFrom: '2026-01-01',
            ));
        });
    })->toThrow(AuthorizationException::class);
});
