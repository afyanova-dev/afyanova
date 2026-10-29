<?php

declare(strict_types=1);

use App\Domains\HR\Application\Command\AmendEmployment;
use App\Domains\HR\Application\Command\CreateEmployment;
use App\Domains\HR\Application\Command\CreatePerson;
use App\Domains\HR\Application\Command\CreateStaff;
use App\Domains\HR\Application\Command\DefineCadre;
use App\Domains\HR\Application\Command\DefinePosition;
use App\Domains\HR\Application\Command\EndEmployment;
use App\Domains\HR\Application\Command\OnboardStaff;
use App\Domains\HR\Application\Command\ReinstateEmployment;
use App\Domains\HR\Application\Command\StartEmployment;
use App\Domains\HR\Application\Command\SuspendEmployment;
use App\Domains\HR\Application\Command\SuspendStaff;
use App\Domains\HR\Application\Command\TerminateStaff;
use App\Domains\HR\Application\Query\GetCadre;
use App\Domains\HR\Application\Query\GetCadres;
use App\Domains\HR\Application\Query\GetEmployment;
use App\Domains\HR\Application\Query\GetPerson;
use App\Domains\HR\Application\Query\GetPosition;
use App\Domains\HR\Application\Query\GetPositions;
use App\Domains\HR\Application\Query\GetStaff;
use App\Domains\HR\Application\Query\GetStaffDetail;
use App\Domains\HR\Application\Query\GetStaffEmployments;
use App\Domains\HR\Application\Query\SearchStaff;
use App\Domains\HR\Contracts\Event\EmploymentAmended;
use App\Domains\HR\Contracts\Event\EmploymentEnded;
use App\Domains\HR\Contracts\Event\EmploymentReinstated;
use App\Domains\HR\Contracts\Event\EmploymentStarted;
use App\Domains\HR\Contracts\Event\EmploymentSuspended;
use App\Domains\HR\Contracts\Event\PersonCreated;
use App\Domains\HR\Contracts\Event\StaffOnboarded;
use App\Domains\HR\Contracts\Event\StaffTerminated;
use App\Domains\HR\Contracts\HRApi;
use App\Domains\HR\Domain\Exception\DuplicateActiveEmploymentException;
use App\Domains\HR\Domain\Exception\DuplicateCadreCodeException;
use App\Domains\HR\Domain\Exception\DuplicatePositionCodeException;
use App\Domains\HR\Domain\Exception\StaffHasActiveEmploymentException;
use App\Domains\HR\Domain\Exception\StaffNotEligibleForEmploymentException;
use App\Domains\HR\Domain\Exception\StaffNotFoundException;
use App\Domains\HR\Domain\Model\Employment;
use App\Domains\HR\Domain\Model\Person;
use App\Domains\HR\Domain\Model\Staff;
use App\Domains\HR\Domain\ValueObject\CadreCategory;
use App\Domains\HR\Domain\ValueObject\ContractType;
use App\Domains\HR\Domain\ValueObject\EmploymentStatus;
use App\Domains\HR\Domain\ValueObject\Gender;
use App\Domains\HR\Domain\ValueObject\StaffStatus;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../../tests/Support/hr.php';

test('happy path: create person -> staff (STF-%08d) -> cadre & position -> employment -> onboard -> start', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;

    $commandBus = app(CommandBus::class);
    $queryBus = app(QueryBus::class);

    // 1. Create Person
    $personId = (string) Str::uuid();
    TenantContextScope::run($tenantId, $facilityId, function () use ($commandBus, $personId): void {
        $commandBus->dispatch(new CreatePerson(
            firstName: 'Amina',
            middleName: 'Juma',
            lastName: 'Mrema',
            dateOfBirth: '1988-04-12',
            gender: Gender::FEMALE,
            personId: $personId,
            nationalId: '19880412-12345-00001',
            phone: '+255754000111',
            email: 'amina.mrema@hospital.org',
            physicalAddress: 'Dar es Salaam, Tanzania',
            nextOfKinName: 'Rashid Mrema',
            nextOfKinRelationship: 'Spouse',
            nextOfKinPhone: '+255754000222',
        ));
    });

    $personCreatedRow = Tenancy::run($tenantId, fn () => DB::table('shared_outbox_events')
        ->where('event_class', PersonCreated::class)
        ->first());
    expect($personCreatedRow)->not->toBeNull();
    $personCreated = json_decode((string) $personCreatedRow->payload, true);
    expect($personCreated['personId'])->toBe($personId)
        ->and($personCreated['tenantId'])->toBe($tenantId);

    // 2. Define Cadre & Position
    $cadreId = (string) Str::uuid();
    $positionId = (string) Str::uuid();
    TenantContextScope::run($tenantId, $facilityId, function () use ($commandBus, $cadreId, $positionId): void {
        $commandBus->dispatch(new DefineCadre(
            code: 'MED-OFFICER',
            name: 'Medical Officer',
            category: CadreCategory::CLINICAL,
            cadreId: $cadreId,
            description: 'Registered General Practitioner',
        ));

        $commandBus->dispatch(new DefinePosition(
            code: 'RMO-01',
            title: 'Resident Medical Officer - OPD',
            cadreId: $cadreId,
            positionId: $positionId,
            description: 'OPD Resident Doctor',
        ));
    });

    // 3. Create Staff (mints STF-%08d in PROSPECT status)
    $staffId = (string) Str::uuid();
    TenantContextScope::run($tenantId, $facilityId, function () use ($commandBus, $staffId, $personId, $cadreId, $positionId): void {
        $commandBus->dispatch(new CreateStaff(
            personId: $personId,
            staffId: $staffId,
            primaryCadreId: $cadreId,
            primaryPositionId: $positionId,
        ));
    });

    /** @var Staff $staffRecord */
    $staffRecord = TenantContextScope::run($tenantId, $facilityId, fn () => Staff::findOrFail($staffId));
    expect($staffRecord->staff_number)->toBe('STF-00000001')
        ->and($staffRecord->status)->toBe(StaffStatus::PROSPECT)
        ->and($staffRecord->primary_cadre_id)->toBe($cadreId)
        ->and($staffRecord->primary_position_id)->toBe($positionId);

    // 4. Onboard Staff -> ACTIVE
    TenantContextScope::run($tenantId, $facilityId, function () use ($commandBus, $staffId): void {
        $commandBus->dispatch(new OnboardStaff(
            staffId: $staffId,
            joinedDate: '2026-02-01',
        ));
    });

    $staffOnboardedRow = Tenancy::run($tenantId, fn () => DB::table('shared_outbox_events')
        ->where('event_class', StaffOnboarded::class)
        ->first());
    expect($staffOnboardedRow)->not->toBeNull();
    $staffOnboarded = json_decode((string) $staffOnboardedRow->payload, true);
    expect($staffOnboarded['staffId'])->toBe($staffId)
        ->and($staffOnboarded['staffNumber'])->toBe('STF-00000001');

    // 5. Create Employment -> DRAFT (no event emitted)
    $employmentId = (string) Str::uuid();
    TenantContextScope::run($tenantId, $facilityId, function () use ($commandBus, $employmentId, $staffId, $positionId): void {
        $commandBus->dispatch(new CreateEmployment(
            staffId: $staffId,
            positionId: $positionId,
            contractType: ContractType::PERMANENT,
            startDate: '2026-02-01',
            fte: '1.00',
            employmentId: $employmentId,
            noticePeriodDays: 60,
            termsSummary: 'Full-time clinical contract with 6-month probation.',
        ));
    });

    /** @var Employment $draftEmployment */
    $draftEmployment = TenantContextScope::run($tenantId, $facilityId, fn () => Employment::findOrFail($employmentId));
    expect($draftEmployment->status)->toBe(EmploymentStatus::DRAFT)
        ->and((string) $draftEmployment->fte)->toBe('1.00');

    // 6. Start Employment -> ACTIVE (emits EmploymentStarted)
    TenantContextScope::run($tenantId, $facilityId, function () use ($commandBus, $employmentId): void {
        $commandBus->dispatch(new StartEmployment(
            employmentId: $employmentId,
            startDate: '2026-02-01',
        ));
    });

    $empStartedRow = Tenancy::run($tenantId, fn () => DB::table('shared_outbox_events')
        ->where('event_class', EmploymentStarted::class)
        ->first());
    expect($empStartedRow)->not->toBeNull();
    $empStarted = json_decode((string) $empStartedRow->payload, true);
    expect($empStarted['employmentId'])->toBe($employmentId)
        ->and($empStarted['staffId'])->toBe($staffId);

    // 7. Test HRApi Public Contract (strictly data-minimized, zero personal PII)
    $hrApi = app(HRApi::class);
    $exists = TenantContextScope::run($tenantId, $facilityId, fn () => $hrApi->staffExists($staffId));
    $summary = TenantContextScope::run($tenantId, $facilityId, fn () => $hrApi->getStaffSummary($staffId));

    expect($exists)->toBeTrue()
        ->and($summary)->not->toBeNull()
        ->and($summary->staffId)->toBe($staffId)
        ->and($summary->staffNumber)->toBe('STF-00000001')
        ->and($summary->displayName)->toBe('Amina Juma Mrema')
        ->and($summary->cadreCategory)->toBe('CLINICAL')
        ->and($summary->cadreName)->toBe('Medical Officer')
        ->and($summary->positionTitle)->toBe('Resident Medical Officer - OPD')
        ->and($summary->status)->toBe('ACTIVE')
        ->and($summary->isActive)->toBeTrue();
});

test('dedicated employment lifecycle: draft -> active -> suspended -> active (reinstated) -> ended', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $commandBus = app(CommandBus::class);

    $personId = createPersonHelper($tenantId);
    $cadreId = defineCadreHelper($tenantId);
    $positionId = definePositionHelper($tenantId, $cadreId);
    $staffId = createStaffHelper($tenantId, $personId, $cadreId, $positionId);

    // Onboard staff first
    TenantContextScope::run($tenantId, $facilityId, fn () => $commandBus->dispatch(new OnboardStaff($staffId, '2026-01-01')));

    // 1. Create Employment in DRAFT
    $employmentId = createEmploymentHelper($tenantId, $staffId, $positionId, ContractType::FIXED_TERM, '2026-01-01');

    // 2. Start Employment -> ACTIVE
    TenantContextScope::run($tenantId, $facilityId, fn () => $commandBus->dispatch(new StartEmployment($employmentId, '2026-01-01')));
    expect(Tenancy::run($tenantId, fn () => DB::table('shared_outbox_events')->where('event_class', EmploymentStarted::class)->exists()))->toBeTrue();

    // 3. Suspend Employment -> SUSPENDED
    TenantContextScope::run($tenantId, $facilityId, fn () => $commandBus->dispatch(new SuspendEmployment($employmentId, 'Disciplinary review')));
    $empSuspendedRow = Tenancy::run($tenantId, fn () => DB::table('shared_outbox_events')->where('event_class', EmploymentSuspended::class)->first());
    expect($empSuspendedRow)->not->toBeNull();
    expect(json_decode((string) $empSuspendedRow->payload, true)['reason'])->toBe('Disciplinary review');

    /** @var Employment $suspended */
    $suspended = TenantContextScope::run($tenantId, $facilityId, fn () => Employment::findOrFail($employmentId));
    expect($suspended->status)->toBe(EmploymentStatus::SUSPENDED);

    // 4. Reinstate Employment -> ACTIVE
    TenantContextScope::run($tenantId, $facilityId, fn () => $commandBus->dispatch(new ReinstateEmployment($employmentId)));
    $empReinstatedRow = Tenancy::run($tenantId, fn () => DB::table('shared_outbox_events')->where('event_class', EmploymentReinstated::class)->first());
    expect($empReinstatedRow)->not->toBeNull();
    expect(json_decode((string) $empReinstatedRow->payload, true)['employmentId'])->toBe($employmentId);

    /** @var Employment $reinstated */
    $reinstated = TenantContextScope::run($tenantId, $facilityId, fn () => Employment::findOrFail($employmentId));
    expect($reinstated->status)->toBe(EmploymentStatus::ACTIVE)
        ->and($reinstated->suspension_reason)->toBeNull();

    // 5. Amend Employment terms (does not change lifecycle status)
    TenantContextScope::run($tenantId, $facilityId, fn () => $commandBus->dispatch(new AmendEmployment(
        employmentId: $employmentId,
        positionId: $positionId,
        contractType: ContractType::PERMANENT,
        fte: '0.80',
        termsSummary: 'Amended to 0.8 FTE permanent',
    )));
    $empAmendedRow = Tenancy::run($tenantId, fn () => DB::table('shared_outbox_events')->where('event_class', EmploymentAmended::class)->first());
    expect($empAmendedRow)->not->toBeNull();
    expect(json_decode((string) $empAmendedRow->payload, true)['fte'])->toBe('0.80');

    /** @var Employment $amended */
    $amended = TenantContextScope::run($tenantId, $facilityId, fn () => Employment::findOrFail($employmentId));
    expect($amended->status)->toBe(EmploymentStatus::ACTIVE)
        ->and((string) $amended->fte)->toBe('0.80')
        ->and($amended->contract_type)->toBe(ContractType::PERMANENT);

    // 6. End Employment -> ENDED
    TenantContextScope::run($tenantId, $facilityId, fn () => $commandBus->dispatch(new EndEmployment($employmentId, '2026-12-31', 'Contract concluded')));
    $empEndedRow = Tenancy::run($tenantId, fn () => DB::table('shared_outbox_events')->where('event_class', EmploymentEnded::class)->first());
    expect($empEndedRow)->not->toBeNull();
    expect(json_decode((string) $empEndedRow->payload, true)['reason'])->toBe('Contract concluded');

    /** @var Employment $ended */
    $ended = TenantContextScope::run($tenantId, $facilityId, fn () => Employment::findOrFail($employmentId));
    expect($ended->status)->toBe(EmploymentStatus::ENDED);
});

test('invariant 7.4: cannot start a second active employment for the same staff member', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $commandBus = app(CommandBus::class);

    $personId = createPersonHelper($tenantId);
    $cadreId = defineCadreHelper($tenantId);
    $positionId = definePositionHelper($tenantId, $cadreId);
    $staffId = createStaffHelper($tenantId, $personId, $cadreId, $positionId);

    TenantContextScope::run($tenantId, $facilityId, fn () => $commandBus->dispatch(new OnboardStaff($staffId, '2026-01-01')));

    $emp1 = createEmploymentHelper($tenantId, $staffId, $positionId, ContractType::PERMANENT, '2026-01-01');
    $emp2 = createEmploymentHelper($tenantId, $staffId, $positionId, ContractType::CASUAL, '2026-02-01');

    // Start first employment -> ACTIVE
    TenantContextScope::run($tenantId, $facilityId, fn () => $commandBus->dispatch(new StartEmployment($emp1, '2026-01-01')));

    // Attempt to start second employment -> throws DuplicateActiveEmploymentException
    expect(function () use ($tenantId, $facilityId, $commandBus, $emp2): void {
        TenantContextScope::run($tenantId, $facilityId, fn () => $commandBus->dispatch(new StartEmployment($emp2, '2026-02-01')));
    })->toThrow(DuplicateActiveEmploymentException::class);
});

test('staff eligibility: cannot start or reinstate employment for suspended or terminated staff', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $commandBus = app(CommandBus::class);

    $personId = createPersonHelper($tenantId);
    $cadreId = defineCadreHelper($tenantId);
    $positionId = definePositionHelper($tenantId, $cadreId);
    $staffId = createStaffHelper($tenantId, $personId, $cadreId, $positionId);

    TenantContextScope::run($tenantId, $facilityId, fn () => $commandBus->dispatch(new OnboardStaff($staffId, '2026-01-01')));
    $empId = createEmploymentHelper($tenantId, $staffId, $positionId, ContractType::PERMANENT, '2026-01-01');

    // Suspend staff member
    TenantContextScope::run($tenantId, $facilityId, fn () => $commandBus->dispatch(new SuspendStaff($staffId, 'Under investigation')));

    // Attempt to start employment while staff is suspended -> fails
    expect(function () use ($tenantId, $facilityId, $commandBus, $empId): void {
        TenantContextScope::run($tenantId, $facilityId, fn () => $commandBus->dispatch(new StartEmployment($empId, '2026-01-01')));
    })->toThrow(StaffNotEligibleForEmploymentException::class);
});

test('single-aggregate boundary (F1): terminate staff is rejected if active or suspended employments exist', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $commandBus = app(CommandBus::class);

    $personId = createPersonHelper($tenantId);
    $cadreId = defineCadreHelper($tenantId);
    $positionId = definePositionHelper($tenantId, $cadreId);
    $staffId = createStaffHelper($tenantId, $personId, $cadreId, $positionId);

    TenantContextScope::run($tenantId, $facilityId, fn () => $commandBus->dispatch(new OnboardStaff($staffId, '2026-01-01')));
    $empId = createEmploymentHelper($tenantId, $staffId, $positionId, ContractType::PERMANENT, '2026-01-01');
    TenantContextScope::run($tenantId, $facilityId, fn () => $commandBus->dispatch(new StartEmployment($empId, '2026-01-01')));

    // Attempting to terminate staff while employment is ACTIVE throws StaffHasActiveEmploymentException
    expect(function () use ($tenantId, $facilityId, $commandBus, $staffId): void {
        TenantContextScope::run($tenantId, $facilityId, fn () => $commandBus->dispatch(new TerminateStaff($staffId, '2026-06-01', 'Resignation')));
    })->toThrow(StaffHasActiveEmploymentException::class);

    // Conclude employment first via EndEmployment
    TenantContextScope::run($tenantId, $facilityId, fn () => $commandBus->dispatch(new EndEmployment($empId, '2026-06-01', 'Resigned')));

    // Now terminate staff succeeds
    TenantContextScope::run($tenantId, $facilityId, fn () => $commandBus->dispatch(new TerminateStaff($staffId, '2026-06-01', 'Resignation')));

    $staffTerminatedRow = Tenancy::run($tenantId, fn () => DB::table('shared_outbox_events')->where('event_class', StaffTerminated::class)->first());
    expect($staffTerminatedRow)->not->toBeNull();
    expect(json_decode((string) $staffTerminatedRow->payload, true)['staffId'])->toBe($staffId);

    /** @var Staff $staff */
    $staff = TenantContextScope::run($tenantId, $facilityId, fn () => Staff::findOrFail($staffId));
    expect($staff->status)->toBe(StaffStatus::TERMINATED)
        ->and($staff->termination_reason)->toBe('Resignation');
});

test('cadre and position uniqueness per tenant', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $commandBus = app(CommandBus::class);

    $cadreId = defineCadreHelper($tenantId, 'CADRE-NURSE', 'Registered Nurse', CadreCategory::NURSING);

    // Duplicate cadre code throws DuplicateCadreCodeException
    expect(function () use ($tenantId, $facilityId, $commandBus): void {
        TenantContextScope::run($tenantId, $facilityId, fn () => $commandBus->dispatch(new DefineCadre(
            code: 'CADRE-NURSE',
            name: 'Another Nurse',
            category: CadreCategory::NURSING,
        )));
    })->toThrow(DuplicateCadreCodeException::class);

    definePositionHelper($tenantId, $cadreId, 'POS-ICU-01', 'ICU Nurse Lead');

    // Duplicate position code throws DuplicatePositionCodeException
    expect(function () use ($tenantId, $facilityId, $commandBus, $cadreId): void {
        TenantContextScope::run($tenantId, $facilityId, fn () => $commandBus->dispatch(new DefinePosition(
            code: 'POS-ICU-01',
            title: 'Duplicate ICU Lead',
            cadreId: $cadreId,
        )));
    })->toThrow(DuplicatePositionCodeException::class);
});

test('queries: person, staff, employment, cadre, position readers return accurate DTOs', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $commandBus = app(CommandBus::class);
    $queryBus = app(QueryBus::class);

    $personId = createPersonHelper($tenantId, firstName: 'Baraka', middleName: null, lastName: 'Said');
    $cadreId = defineCadreHelper($tenantId, code: 'CADRE-PHARM', name: 'Pharmacist', category: CadreCategory::ALLIED);
    $positionId = definePositionHelper($tenantId, $cadreId, code: 'POS-CHIEF-PHARM', title: 'Chief Pharmacist');
    $staffId = createStaffHelper($tenantId, $personId, $cadreId, $positionId);
    TenantContextScope::run($tenantId, $facilityId, fn () => $commandBus->dispatch(new OnboardStaff($staffId, '2026-03-01')));
    $empId = createEmploymentHelper($tenantId, $staffId, $positionId, ContractType::PERMANENT, '2026-03-01');

    TenantContextScope::run($tenantId, $facilityId, function () use ($queryBus, $personId, $staffId, $empId, $cadreId, $positionId): void {
        // Person query
        $person = $queryBus->dispatch(new GetPerson($personId));
        expect($person->firstName)->toBe('Baraka')
            ->and($person->lastName)->toBe('Said');

        // Staff queries
        $staffSummary = $queryBus->dispatch(new GetStaff($staffId));
        expect($staffSummary->displayName)->toBe('Baraka Said')
            ->and($staffSummary->cadreCategory)->toBe('ALLIED');

        $staffDetail = $queryBus->dispatch(new GetStaffDetail($staffId));
        expect($staffDetail->primaryCadreName)->toBe('Pharmacist')
            ->and($staffDetail->primaryPositionTitle)->toBe('Chief Pharmacist');

        $searchResults = $queryBus->dispatch(new SearchStaff(search: 'Baraka'));
        expect($searchResults)->toHaveCount(1)
            ->and($searchResults[0]->staffId)->toBe($staffId);

        // Employment queries
        $empSummary = $queryBus->dispatch(new GetEmployment($empId));
        expect($empSummary->contractType)->toBe('PERMANENT')
            ->and($empSummary->status)->toBe('DRAFT');

        $staffEmployments = $queryBus->dispatch(new GetStaffEmployments($staffId));
        expect($staffEmployments)->toHaveCount(1);

        // Cadre & Position queries
        $cadre = $queryBus->dispatch(new GetCadre($cadreId));
        expect($cadre->code)->toBe('CADRE-PHARM');

        $cadres = $queryBus->dispatch(new GetCadres);
        expect($cadres)->not->toBeEmpty();

        $position = $queryBus->dispatch(new GetPosition($positionId));
        expect($position->code)->toBe('POS-CHIEF-PHARM');

        $positions = $queryBus->dispatch(new GetPositions);
        expect($positions)->not->toBeEmpty();
    });
});

test('multi-tenant isolation: staff and person in tenant A are not accessible in tenant B', function (): void {
    $tenantA = hrFixture();
    $tenantB = hrFixture();

    $tenantAId = (string) $tenantA['tenant']->id;
    $tenantBId = (string) $tenantB['tenant']->id;
    $tenantAFacilityId = (string) $tenantA['facility']->id;
    $tenantBFacilityId = (string) $tenantB['facility']->id;

    // Act as Tenant A user to create Tenant A's records
    test()->actingAs($tenantA['user']);

    $personAId = createPersonHelper($tenantAId, firstName: 'Tenant A Person', facilityId: $tenantAFacilityId);
    $cadreAId = defineCadreHelper($tenantAId, 'CADRE-A', 'Cadre A', facilityId: $tenantAFacilityId);
    $posAId = definePositionHelper($tenantAId, $cadreAId, 'POS-A', 'Position A', facilityId: $tenantAFacilityId);
    $staffAId = createStaffHelper($tenantAId, $personAId, $cadreAId, $posAId, facilityId: $tenantAFacilityId);

    // Switch actor to Tenant B user
    test()->actingAs($tenantB['user']);

    $queryBus = app(QueryBus::class);

    // Attempting to query Tenant A's staff from Tenant B throws StaffNotFoundException
    expect(function () use ($tenantBId, $tenantBFacilityId, $queryBus, $staffAId): void {
        TenantContextScope::run($tenantBId, $tenantBFacilityId, fn () => $queryBus->dispatch(new GetStaff($staffAId)));
    })->toThrow(StaffNotFoundException::class);
});

test('RBAC authorization: commands fail without required permission', function (): void {
    // User with only hr.access but missing hr.staff.manage
    $fixture = hrFixture(['hr.access', 'hr.person.manage', 'hr.cadre_position.manage']);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $commandBus = app(CommandBus::class);

    $personId = createPersonHelper($tenantId);

    expect(function () use ($tenantId, $facilityId, $commandBus, $personId): void {
        TenantContextScope::run($tenantId, $facilityId, fn () => $commandBus->dispatch(new CreateStaff($personId)));
    })->toThrow(AuthorizationException::class);
});
