<?php

declare(strict_types=1);

use App\Domains\Encounter\Application\Command\CancelEncounter;
use App\Domains\Patient\Domain\Model\Patient;
use App\Domains\Theatre\Application\Command\CreateSurgicalCase;
use App\Domains\Theatre\Contracts\Event\SurgicalCaseCreated;
use App\Domains\Theatre\Domain\Exception\EncounterNotEligibleForSurgicalCase;
use App\Domains\Theatre\Domain\Exception\SurgicalCaseContextMismatch;
use App\Domains\Theatre\Domain\Model\SurgicalCase;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('it creates a surgical case: REQUESTED, case_number allocated, event on outbox, audit written', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = theatreFixture();

    ['encounterId' => $encounterId, 'patientId' => $patientId] =
        seedEligibleSurgicalEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $caseId = (string) Str::uuid7();
    $command = new CreateSurgicalCase(
        caseId: $caseId,
        actorId: (string) $user->id,
        patientId: $patientId,
        encounterId: $encounterId,
        priority: 'urgent',
        plannedProcedure: 'Appendectomy',
        indication: 'Suspected appendicitis',
    );

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($command));

    $case = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => SurgicalCase::query()->find($caseId));

    expect($case)->not->toBeNull()
        ->and($case->status)->toBe(SurgicalCase::STATUS_REQUESTED)
        ->and($case->priority)->toBe('URGENT')
        ->and($case->case_number)->toStartWith('SURG-')
        ->and((string) $case->facility_id)->toBe((string) $facility->id)
        ->and((int) $case->version)->toBe(0);

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', SurgicalCaseCreated::class)
        ->first());

    expect($outbox)->not->toBeNull();

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')
        ->where('target_id', $caseId)
        ->where('action', 'theatre.case_created')
        ->first());
    expect($audit)->not->toBeNull();
});

test('it accepts CreateSurgicalCase for an ARRIVED (not yet started) encounter (ADR-150 D-H)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = theatreFixture();

    ['encounterId' => $encounterId, 'patientId' => $patientId] =
        seedEligibleSurgicalEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id, started: false);

    $caseId = (string) Str::uuid7();
    $command = new CreateSurgicalCase(
        caseId: $caseId,
        actorId: (string) $user->id,
        patientId: $patientId,
        encounterId: $encounterId,
    );

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($command));

    $case = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => SurgicalCase::query()->find($caseId));
    expect($case)->not->toBeNull();
});

test('it rejects CreateSurgicalCase when the encounter is CANCELLED (ADR-150 D-H)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = theatreFixture();

    ['encounterId' => $encounterId, 'patientId' => $patientId] =
        seedEligibleSurgicalEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id, started: false);

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $user): void {
        app(CommandBus::class)->dispatch(new CancelEncounter(
            encounterId: $encounterId,
            actorId: (string) $user->id,
        ));
    });

    $command = new CreateSurgicalCase(
        caseId: (string) Str::uuid7(),
        actorId: (string) $user->id,
        patientId: $patientId,
        encounterId: $encounterId,
    );

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($command)))
        ->toThrow(EncounterNotEligibleForSurgicalCase::class);
});

test('it rejects CreateSurgicalCase when patientId does not match the encounter (ADR-150 D-H)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = theatreFixture();

    ['encounterId' => $encounterId] =
        seedEligibleSurgicalEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $otherPatientId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Patient::factory()
        ->forTenant((string) $tenant->id)
        ->create()->id);

    $command = new CreateSurgicalCase(
        caseId: (string) Str::uuid7(),
        actorId: (string) $user->id,
        patientId: $otherPatientId, // a real but different patient than the encounter's own
        encounterId: $encounterId,
    );

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($command)))
        ->toThrow(SurgicalCaseContextMismatch::class);
});
