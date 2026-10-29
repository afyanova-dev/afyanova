<?php

declare(strict_types=1);

use App\Domains\BedWard\Domain\Model\Bed;
use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Domains\Theatre\Application\Command\AbortCase;
use App\Domains\Theatre\Application\Command\AssignOperatingRoom;
use App\Domains\Theatre\Application\Command\CompleteCase;
use App\Domains\Theatre\Application\Command\CompleteChecklistStage;
use App\Domains\Theatre\Application\Command\CreateOperatingRoom;
use App\Domains\Theatre\Application\Command\CreateSurgicalCase;
use App\Domains\Theatre\Application\Command\MarkCaseReady;
use App\Domains\Theatre\Application\Command\ScheduleSurgicalCase;
use App\Domains\Theatre\Application\Command\StartCase;
use App\Domains\Theatre\Application\Command\StartPreOp;
use App\Domains\Theatre\Domain\Exception\ChecklistGateNotSatisfied;
use App\Domains\Theatre\Domain\Exception\OperatingRoomNotAvailable;
use App\Domains\Theatre\Domain\Model\OperatingRoom;
use App\Domains\Theatre\Domain\Model\SurgicalCase;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('full happy path: create -> assign room -> pre-op -> checklist -> ready -> start -> complete with recovery bed handoff', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = theatreFixture(capabilities: ['THEATRE', 'OUTPATIENT_CARE', 'INPATIENT_CARE']);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $actorId = (string) $user->id;

    // INPATIENT so the recovery-bed handoff (BedWardApi::assignBed, ADR-150 D-H)
    // passes AssignBed's own eligibility gate (ADR-148 D-F: INPATIENT|MATERNITY only).
    ['encounterId' => $encounterId, 'patientId' => $patientId] =
        seedEligibleSurgicalEncounter($tenantId, $facilityId, $actorId, encounterType: EncounterType::Inpatient);

    $orgRoom = createOrgRoomFixture($tenantId, $facilityId);
    $operatingRoomId = (string) Str::uuid7();
    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(new CreateOperatingRoom(
        operatingRoomId: $operatingRoomId,
        facilityId: $facilityId,
        orgRoomId: (string) $orgRoom->id,
        actorId: $actorId,
        label: 'OR 1',
    )));

    $caseId = (string) Str::uuid7();
    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(new CreateSurgicalCase(
        caseId: $caseId,
        actorId: $actorId,
        patientId: $patientId,
        encounterId: $encounterId,
    )));

    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(new AssignOperatingRoom(
        operatingRoomId: $operatingRoomId,
        caseId: $caseId,
        actorId: $actorId,
    )));

    $room = TenantContextScope::run($tenantId, $facilityId, fn () => OperatingRoom::query()->find($operatingRoomId));
    expect($room->status)->toBe(OperatingRoom::STATUS_RESERVED);

    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(new ScheduleSurgicalCase(
        caseId: $caseId,
        actorId: $actorId,
        scheduledFor: now()->addDay()->toIso8601String(),
    )));

    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(new StartPreOp(
        caseId: $caseId,
        actorId: $actorId,
    )));

    // MarkCaseReady with zero checklist entries fails the ADR-150 D-G floor
    expect(fn () => TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(new MarkCaseReady(
        caseId: $caseId,
        actorId: $actorId,
    ))))->toThrow(ChecklistGateNotSatisfied::class);

    TenantContextScope::run($tenantId, $facilityId, function () use ($caseId, $actorId): void {
        app(CommandBus::class)->dispatch(new CompleteChecklistStage(caseId: $caseId, stage: 'before_anaesthesia', actorId: $actorId));
    });

    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(new MarkCaseReady(
        caseId: $caseId,
        actorId: $actorId,
    )));

    $case = TenantContextScope::run($tenantId, $facilityId, fn () => SurgicalCase::query()->find($caseId));
    expect($case->status)->toBe(SurgicalCase::STATUS_READY);

    // StartCase with only 1 checklist entry fails the >=2 floor
    expect(fn () => TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(new StartCase(
        caseId: $caseId,
        actorId: $actorId,
    ))))->toThrow(ChecklistGateNotSatisfied::class);

    TenantContextScope::run($tenantId, $facilityId, function () use ($caseId, $actorId): void {
        app(CommandBus::class)->dispatch(new CompleteChecklistStage(caseId: $caseId, stage: 'before_incision', actorId: $actorId));
    });

    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(new StartCase(
        caseId: $caseId,
        actorId: $actorId,
    )));

    $case = TenantContextScope::run($tenantId, $facilityId, fn () => SurgicalCase::query()->find($caseId));
    $room = TenantContextScope::run($tenantId, $facilityId, fn () => OperatingRoom::query()->find($operatingRoomId));
    expect($case->status)->toBe(SurgicalCase::STATUS_IN_PROGRESS)
        ->and($room->status)->toBe(OperatingRoom::STATUS_IN_USE)
        ->and($case->safety_checklist)->toHaveCount(2);

    $bed = createBedFixture($tenantId, $facilityId, (string) createWardFixture($tenantId, $facilityId)->id);

    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(new CompleteCase(
        caseId: $caseId,
        actorId: $actorId,
        actualProcedure: 'Open appendectomy',
        outcome: 'SUCCESSFUL',
        recoveryDestinationType: 'WARD',
        recoveryBedId: (string) $bed->id,
    )));

    $case = TenantContextScope::run($tenantId, $facilityId, fn () => SurgicalCase::query()->find($caseId));
    $room = TenantContextScope::run($tenantId, $facilityId, fn () => OperatingRoom::query()->find($operatingRoomId));
    $bedFresh = TenantContextScope::run($tenantId, $facilityId, fn () => Bed::query()->find($bed->id));

    expect($case->status)->toBe(SurgicalCase::STATUS_COMPLETED)
        ->and($room->status)->toBe(OperatingRoom::STATUS_CLEANING)
        ->and($bedFresh->status)->toBe(Bed::STATUS_OCCUPIED);
});

test('AssignOperatingRoom rejects a room that is not AVAILABLE', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = theatreFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $actorId = (string) $user->id;

    ['encounterId' => $encounterId, 'patientId' => $patientId] =
        seedEligibleSurgicalEncounter($tenantId, $facilityId, $actorId);

    $room = createOperatingRoomFixture($tenantId, $facilityId, ['status' => OperatingRoom::STATUS_MAINTENANCE]);

    $caseId = (string) Str::uuid7();
    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(new CreateSurgicalCase(
        caseId: $caseId,
        actorId: $actorId,
        patientId: $patientId,
        encounterId: $encounterId,
    )));

    expect(fn () => TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(new AssignOperatingRoom(
        operatingRoomId: (string) $room->id,
        caseId: $caseId,
        actorId: $actorId,
    ))))->toThrow(OperatingRoomNotAvailable::class);
});

test('AbortCase transitions IN_PROGRESS -> ABORTED (distinct from cancel) and sends the room for cleaning', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = theatreFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $actorId = (string) $user->id;

    ['encounterId' => $encounterId, 'patientId' => $patientId] = seedEligibleSurgicalEncounter($tenantId, $facilityId, $actorId);

    $orgRoom = createOrgRoomFixture($tenantId, $facilityId);
    $operatingRoomId = (string) Str::uuid7();
    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(new CreateOperatingRoom(
        operatingRoomId: $operatingRoomId, facilityId: $facilityId, orgRoomId: (string) $orgRoom->id, actorId: $actorId, label: 'OR 2',
    )));

    $caseId = (string) Str::uuid7();
    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(new CreateSurgicalCase(
        caseId: $caseId, actorId: $actorId, patientId: $patientId, encounterId: $encounterId,
    )));

    TenantContextScope::run($tenantId, $facilityId, function () use ($operatingRoomId, $caseId, $actorId): void {
        app(CommandBus::class)->dispatch(new AssignOperatingRoom(operatingRoomId: $operatingRoomId, caseId: $caseId, actorId: $actorId));
        app(CommandBus::class)->dispatch(new ScheduleSurgicalCase(caseId: $caseId, actorId: $actorId, scheduledFor: now()->addDay()->toIso8601String()));
        app(CommandBus::class)->dispatch(new StartPreOp(caseId: $caseId, actorId: $actorId));
        app(CommandBus::class)->dispatch(new CompleteChecklistStage(caseId: $caseId, stage: 'a', actorId: $actorId));
        app(CommandBus::class)->dispatch(new MarkCaseReady(caseId: $caseId, actorId: $actorId));
        app(CommandBus::class)->dispatch(new CompleteChecklistStage(caseId: $caseId, stage: 'b', actorId: $actorId));
        app(CommandBus::class)->dispatch(new StartCase(caseId: $caseId, actorId: $actorId));
        app(CommandBus::class)->dispatch(new AbortCase(caseId: $caseId, actorId: $actorId, reason: 'haemorrhage'));
    });

    $case = TenantContextScope::run($tenantId, $facilityId, fn () => SurgicalCase::query()->find($caseId));
    $room = TenantContextScope::run($tenantId, $facilityId, fn () => OperatingRoom::query()->find($operatingRoomId));

    expect($case->status)->toBe(SurgicalCase::STATUS_ABORTED)
        ->and($room->status)->toBe(OperatingRoom::STATUS_CLEANING);
});
