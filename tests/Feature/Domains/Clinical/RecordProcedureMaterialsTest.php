<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\AuthorizeProcedure;
use App\Domains\Clinical\Application\Command\CompleteProcedure;
use App\Domains\Clinical\Application\Command\RecordProcedureMaterials;
use App\Domains\Clinical\Application\Command\StartProcedure;
use App\Domains\Clinical\Contracts\Event\ProcedureMaterialsConsumed;
use App\Domains\Clinical\Domain\Exception\IllegalProcedureTransition;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
 | PHASE 6 (CP.1) gate — RecordProcedureMaterials (ADR-137, ADR-146 D-N):
 | append-only clin_procedure_materials rows + ONE PHI-free
 | ProcedureMaterialsConsumed event carrying every line.
 */

uses(RefreshDatabase::class);

function dispatchMaterialsCommand(string $tenantId, string $facilityId, object $command): mixed
{
    return TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch($command));
}

test('records one row per material line and ONE event carrying every line, callable while IN_PROGRESS', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $id = clinicalRequestProcedure((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
    dispatchMaterialsCommand((string) $tenant->id, (string) $facility->id, new AuthorizeProcedure($id, (string) $user->id));
    dispatchMaterialsCommand((string) $tenant->id, (string) $facility->id, new StartProcedure($id, (string) $user->id));

    dispatchMaterialsCommand((string) $tenant->id, (string) $facility->id, new RecordProcedureMaterials($id, (string) $user->id, [
        ['materialRef' => 'GAUZE', 'quantity' => 2, 'unit' => 'pack'],
        ['materialRef' => 'BANDAGE', 'quantity' => 1, 'batchRef' => 'B123'],
    ]));

    $rows = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_procedure_materials')->where('procedure_id', $id)->orderBy('material_ref')->get());
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->material_ref)->toBe('BANDAGE')
        ->and($rows[0]->batch_ref)->toBe('B123');

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', ProcedureMaterialsConsumed::class)->first());
    expect($outbox->event_name)->toBe('clinical.ProcedureMaterialsConsumed');
    $payload = json_decode((string) $outbox->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($payload['materials'])->toHaveCount(2);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'procedures.materials_recorded')->where('target_id', $id)->exists()))->toBeTrue();
});

test('callable "during or after" — also legal once COMPLETED', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $id = clinicalRequestProcedure((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
    dispatchMaterialsCommand((string) $tenant->id, (string) $facility->id, new AuthorizeProcedure($id, (string) $user->id));
    dispatchMaterialsCommand((string) $tenant->id, (string) $facility->id, new StartProcedure($id, (string) $user->id));
    dispatchMaterialsCommand((string) $tenant->id, (string) $facility->id, new CompleteProcedure($id, (string) $user->id));

    dispatchMaterialsCommand((string) $tenant->id, (string) $facility->id, new RecordProcedureMaterials($id, (string) $user->id, [
        ['materialRef' => 'GAUZE', 'quantity' => 1],
    ]));

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_procedure_materials')->where('procedure_id', $id)->count()))->toBe(1);
});

test('rejected before execution starts (REQUESTED / AUTHORIZED)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $id = clinicalRequestProcedure((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    expect(fn () => dispatchMaterialsCommand((string) $tenant->id, (string) $facility->id, new RecordProcedureMaterials($id, (string) $user->id, [
        ['materialRef' => 'GAUZE', 'quantity' => 1],
    ])))->toThrow(IllegalProcedureTransition::class);
});

test('the table is append-only — a RESTRICTIVE no-UPDATE / no-DELETE policy', function () {
    $policies = DB::table('pg_policies')->where('tablename', 'clin_procedure_materials')->pluck('policyname')->all();
    expect($policies)->toContain('clin_procedure_materials_no_update')
        ->and($policies)->toContain('clin_procedure_materials_no_delete');
});
