<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\AddProcedureNote;
use App\Domains\Clinical\Application\Command\AddProcedureNoteResult;
use App\Domains\Clinical\Application\Command\AuthorizeProcedure;
use App\Domains\Clinical\Application\Command\StartProcedure;
use App\Domains\Clinical\Application\Query\GetClinicalNote;
use App\Domains\Clinical\Contracts\Dto\ClinicalNoteSummary;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
 | PHASE 6 (CP.1) gate — AddProcedureNote (ADR-146 D-L): dispatches the
 | UNCHANGED C.1 CreateClinicalNote with note_type = 'procedure_note' — no
 | change to the C.1 write path, dual-permission requirement enforced
 | automatically by the nested dispatch.
 */

uses(RefreshDatabase::class);

function dispatchNoteCommand(string $tenantId, string $facilityId, object $command): mixed
{
    return TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch($command));
}

test('creates a linked ClinicalNote with note_type = procedure_note, GetClinicalNote returns it, TWO audit rows', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $id = clinicalRequestProcedure((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
    dispatchNoteCommand((string) $tenant->id, (string) $facility->id, new AuthorizeProcedure($id, (string) $user->id));
    dispatchNoteCommand((string) $tenant->id, (string) $facility->id, new StartProcedure($id, (string) $user->id));

    /** @var AddProcedureNoteResult $result */
    $result = dispatchNoteCommand((string) $tenant->id, (string) $facility->id, new AddProcedureNote(
        procedureId: $id, actorId: (string) $user->id, body: 'Wound cleaned, dressed with gauze.', title: 'Procedure note',
    ));

    $procedure = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_procedures')->where('id', $id)->first());
    expect($procedure->clinical_note_id)->toBe($result->clinicalNoteId);

    $noteRow = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_notes')->where('id', $result->clinicalNoteId)->first());
    expect($noteRow->note_type)->toBe('procedure_note')
        ->and($noteRow->patient_id)->toBe($patientId)
        ->and($noteRow->encounter_id)->toBe($encounterId)
        ->and($noteRow->authored_context)->toBe('CLINICIAN');

    /** @var ClinicalNoteSummary $summary */
    $summary = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(new GetClinicalNote($result->clinicalNoteId)));
    expect($summary->noteType)->toBe('procedure_note');

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'procedures.note_added')->where('target_id', $id)->exists()))->toBeTrue();
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'clinical.note_created')->where('target_id', $result->clinicalNoteId)->exists()))->toBeTrue();
});

test('the actor also needs clinical.notes.create — the dual-permission requirement (ADR-146 D-L / R-5)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture([
        'clinical.access', 'clinical.procedures.read', 'clinical.procedures.request', 'clinical.procedures.perform',
        'patient.patients.read', 'patient.patients.create',
        'encounter.encounters.read', 'encounter.encounters.create', 'encounter.encounters.update',
        // deliberately NO clinical.notes.create
    ]);
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $id = clinicalRequestProcedure((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
    dispatchNoteCommand((string) $tenant->id, (string) $facility->id, new AuthorizeProcedure($id, (string) $user->id));
    dispatchNoteCommand((string) $tenant->id, (string) $facility->id, new StartProcedure($id, (string) $user->id));

    expect(fn () => dispatchNoteCommand((string) $tenant->id, (string) $facility->id, new AddProcedureNote($id, (string) $user->id, 'note body')))
        ->toThrow(AuthorizationException::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_notes')->count()))->toBe(0);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_procedures')->where('id', $id)->value('clinical_note_id')))->toBeNull();
});
