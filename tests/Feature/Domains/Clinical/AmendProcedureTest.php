<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\AbortProcedure;
use App\Domains\Clinical\Application\Command\AmendProcedure;
use App\Domains\Clinical\Application\Command\AuthorizeProcedure;
use App\Domains\Clinical\Application\Command\CompleteProcedure;
use App\Domains\Clinical\Application\Command\StartProcedure;
use App\Domains\Clinical\Domain\Exception\IllegalProcedureTransition;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
 | PHASE 6 (CP.1) gate — AmendProcedure (ADR-137, ADR-146 D-M): applies the
 | supplied field changes onto clin_procedures AND appends an append-only
 | clin_procedure_amendments row capturing the correction narrative + which
 | fields changed. Legal only from COMPLETED / ABORTED, gated by the
 | privileged `clinical.procedures.amend` key.
 */

uses(RefreshDatabase::class);

function dispatchAmendCommand(string $tenantId, string $facilityId, object $command): mixed
{
    return TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch($command));
}

function completedProcedure(string $tenantId, string $facilityId, string $patientId, string $encounterId, string $actorId): string
{
    $id = clinicalRequestProcedure($tenantId, $facilityId, $patientId, $encounterId, $actorId);
    dispatchAmendCommand($tenantId, $facilityId, new AuthorizeProcedure($id, $actorId));
    dispatchAmendCommand($tenantId, $facilityId, new StartProcedure($id, $actorId));
    dispatchAmendCommand($tenantId, $facilityId, new CompleteProcedure($id, $actorId, 'UNSUCCESSFUL'));

    return $id;
}

test('applies the supplied fields onto clin_procedures and appends one amendment row with the correct changed_fields, reason required + never on audit', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $id = completedProcedure((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    dispatchAmendCommand((string) $tenant->id, (string) $facility->id, new AmendProcedure(
        procedureId: $id, actorId: (string) $user->id, amendmentType: 'CORRECTION',
        body: 'Outcome was mis-recorded at completion; procedure actually succeeded.',
        reason: 'documentation error caught on review',
        outcome: 'SUCCESSFUL',
    ));

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_procedures')->where('id', $id)->first());
    expect($row->outcome)->toBe('SUCCESSFUL')->and((int) $row->version)->toBeGreaterThan(0);

    $amendment = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_procedure_amendments')->where('procedure_id', $id)->first());
    expect($amendment->amendment_type)->toBe('CORRECTION')
        ->and($amendment->reason)->toBe('documentation error caught on review')
        ->and(json_decode((string) $amendment->changed_fields, true))->toBe(['outcome']);

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'procedures.amended')->where('target_id', $id)->first());
    expect($audit->reason)->toBeNull(); // the rationale lives on the amendment row (ADR-146 D-M)
});

test('rejected before the procedure reaches a terminal execution state', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $id = clinicalRequestProcedure((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    expect(fn () => dispatchAmendCommand((string) $tenant->id, (string) $facility->id, new AmendProcedure(
        procedureId: $id, actorId: (string) $user->id, amendmentType: 'CORRECTION', body: 'x', reason: 'y', outcome: 'SUCCESSFUL',
    )))->toThrow(IllegalProcedureTransition::class);
});

test('legal from ABORTED as well as COMPLETED', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $id = clinicalRequestProcedure((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
    dispatchAmendCommand((string) $tenant->id, (string) $facility->id, new AuthorizeProcedure($id, (string) $user->id));
    dispatchAmendCommand((string) $tenant->id, (string) $facility->id, new StartProcedure($id, (string) $user->id));
    dispatchAmendCommand((string) $tenant->id, (string) $facility->id, new AbortProcedure($id, (string) $user->id, 'complication'));

    dispatchAmendCommand((string) $tenant->id, (string) $facility->id, new AmendProcedure(
        procedureId: $id, actorId: (string) $user->id, amendmentType: 'ADDENDUM', body: 'clarifying note', reason: 'clarification',
        statusReason: 'complication — patient reaction to local anaesthetic',
    ));

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_procedure_amendments')->where('procedure_id', $id)->count()))->toBe(1);
});

test('a caller holding only clinical.procedures.perform (not .amend) is denied', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture([
        'clinical.access', 'clinical.procedures.read', 'clinical.procedures.request', 'clinical.procedures.perform', 'clinical.procedures.complete',
        'patient.patients.read', 'patient.patients.create',
        'encounter.encounters.read', 'encounter.encounters.create', 'encounter.encounters.update',
        // deliberately NO clinical.procedures.amend
    ]);
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $id = completedProcedure((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    expect(fn () => dispatchAmendCommand((string) $tenant->id, (string) $facility->id, new AmendProcedure(
        procedureId: $id, actorId: (string) $user->id, amendmentType: 'CORRECTION', body: 'x', reason: 'y', outcome: 'SUCCESSFUL',
    )))->toThrow(AuthorizationException::class);
});

test('the amendments table is append-only', function () {
    $policies = DB::table('pg_policies')->where('tablename', 'clin_procedure_amendments')->pluck('policyname')->all();
    expect($policies)->toContain('clin_procedure_amendments_no_update')
        ->and($policies)->toContain('clin_procedure_amendments_no_delete');
});
