<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\AmendClinicalNote;
use App\Domains\Clinical\Application\Command\CreateClinicalNote;
use App\Domains\Clinical\Application\Command\SignClinicalNote;
use App\Domains\Clinical\Application\Query\GetClinicalNote;
use App\Domains\Clinical\Contracts\Dto\ClinicalNoteSummary;
use App\Domains\Clinical\Domain\Exception\EncounterNotEligibleForClinicalRecord;
use App\Domains\Encounter\Application\Command\CancelEncounter;
use App\Domains\Encounter\Application\Command\ChangeEncounterDisposition;
use App\Domains\Encounter\Application\Command\CompleteEncounter;
use App\Domains\Encounter\Application\Command\CreateEncounter;
use App\Domains\Encounter\Application\Command\StartEncounter;
use App\Domains\Encounter\Domain\Enum\EncounterDisposition;
use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.1) cross-domain gate — the canonical Patient -> Encounter ->
 | ClinicalNote composition (blueprint.md; IC §20.2; AGENTS §43). REAL
 | CommandBus / QueryBus / PatientApi / EncounterApi — the cross-domain contract
 | is NOT mocked (ADR-135). Proves Invariant 19: after a note originates, its
 | lifecycle is independent of the encounter's later state.
 */

uses(RefreshDatabase::class);

test('RegisterPatient -> CreateEncounter -> StartEncounter -> CreateClinicalNote -> Sign -> Get -> Complete encounter -> Amend (ungated) -> Get', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();

    // 1. Patient domain
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;

    // Gate: a registered patient alone has no clinical note
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_notes')->where('patient_id', $patientId)->count()))->toBe(0);

    // 2. Encounter domain
    $encounterId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $patientId, $user) {
        $bus = app(CommandBus::class);
        $bus->dispatch(new CreateEncounter($encounterId, $patientId, (string) $user->id, EncounterType::Outpatient));
        $bus->dispatch(new StartEncounter($encounterId, (string) $user->id));
    });

    // 3. Clinical domain — CreateClinicalNote consumes the REAL PatientApi + EncounterApi
    $noteId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(new CreateClinicalNote(
        clinicalNoteId: $noteId, actorId: (string) $user->id, patientId: $patientId, encounterId: $encounterId,
        noteType: 'progress_note', title: 'Initial assessment', body: 'Presenting with cough. Plan: symptomatic care.',
    )));

    $note = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_notes')->where('id', $noteId)->first());
    expect($note->tenant_id)->toBe((string) $tenant->id)
        ->and($note->facility_id)->toBe((string) $facility->id)  // taken from the encounter context
        ->and($note->patient_id)->toBe($patientId)
        ->and($note->encounter_id)->toBe($encounterId)
        ->and($note->status)->toBe('DRAFT');

    // 4. Sign
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)
        ->dispatch(new SignClinicalNote($noteId, (string) $user->id)));

    /** @var ClinicalNoteSummary $summary */
    $summary = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(new GetClinicalNote($noteId)));
    expect($summary->status)->toBe('SIGNED')
        ->and($summary->patientId)->toBe($patientId)
        ->and($summary->encounterId)->toBe($encounterId)
        ->and($summary->body)->toBe('Presenting with cough. Plan: symptomatic care.')
        ->and($summary->amendmentCount)->toBe(0);

    // 5. Drive the encounter to COMPLETED
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $user) {
        $bus = app(CommandBus::class);
        $bus->dispatch(new ChangeEncounterDisposition($encounterId, (string) $user->id, EncounterDisposition::Discharged));
        $bus->dispatch(new CompleteEncounter($encounterId, (string) $user->id));
    });
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('status')))->toBe('COMPLETED');

    // 6. Amend the signed note AFTER the encounter completed — Invariant 19: ungated
    $bodyBeforeAmend = $note->body;
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(new AmendClinicalNote(
        clinicalNoteId: $noteId, actorId: (string) $user->id, amendmentType: 'ADDENDUM',
        body: 'Follow-up call: symptoms resolved.', reason: 'telephone follow-up after discharge',
    )));

    /** @var ClinicalNoteSummary $afterAmend */
    $afterAmend = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(new GetClinicalNote($noteId)));
    expect($afterAmend->amendmentCount)->toBe(1)
        ->and($afterAmend->status)->toBe('SIGNED')                // derived AMENDED
        ->and($afterAmend->body)->toBe($bodyBeforeAmend);         // signed content unchanged

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_note_amendments')->where('clinical_note_id', $noteId)->count()))->toBe(1);
    // exactly one note for this patient
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_notes')->where('patient_id', $patientId)->count()))->toBe(1);
});

// ── cross-domain negative paths (real contract, not mocked) ─────────

test('CreateClinicalNote against an unknown patient / unknown encounter -> NotFound', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    expect(fn () => clinicalCreateNote((string) $tenant->id, (string) $facility->id, (string) Str::uuid7(), $encounterId, (string) $user->id))
        ->toThrow(NotFound::class);
    expect(fn () => clinicalCreateNote((string) $tenant->id, (string) $facility->id, $patientId, (string) Str::uuid7(), (string) $user->id))
        ->toThrow(NotFound::class);
});

test('CreateClinicalNote against a CANCELLED encounter -> EncounterNotEligibleForClinicalRecord', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;

    $encounterId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $patientId, $user) {
        $bus = app(CommandBus::class);
        $bus->dispatch(new CreateEncounter($encounterId, $patientId, (string) $user->id, EncounterType::Outpatient));
        $bus->dispatch(new CancelEncounter($encounterId, (string) $user->id));
    });

    expect(fn () => clinicalCreateNote((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id))
        ->toThrow(EncounterNotEligibleForClinicalRecord::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_notes')->count()))->toBe(0);
});
