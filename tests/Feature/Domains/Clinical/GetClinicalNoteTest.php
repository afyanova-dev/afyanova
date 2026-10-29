<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\AmendClinicalNote;
use App\Domains\Clinical\Application\Query\GetClinicalNote;
use App\Domains\Clinical\Contracts\Dto\ClinicalNoteSummary;
use App\Domains\Clinical\Domain\Model\ClinicalNote;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.1) gate — GetClinicalNote through the real QueryBus /
 | #[ProtectedQuery] pipeline (IC §6.1, §6.2; ADR-133, ADR-134). Read-only: no
 | event, no audit, no state change. NOT encounter-state-gated.
 */

uses(RefreshDatabase::class);

function dispatchGetNote(string $tenantId, ?string $facilityId, string $noteId): ClinicalNoteSummary
{
    /** @var ClinicalNoteSummary */
    return TenantContextScope::run($tenantId, $facilityId, fn () => app(QueryBus::class)->dispatch(new GetClinicalNote($noteId)));
}

// ── happy path ─────────────────────────────────────────────────────

test('returns the ratified ClinicalNoteSummary for an authorized reader (body included)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $note = TenantContextScope::run((string) $tenant->id, null, fn () => ClinicalNote::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->authoredBy((string) $user->id)
        ->signed((string) $user->id)->create(['title' => 'Discharge summary', 'body' => 'Home with follow-up in 2 weeks.', 'note_type' => 'discharge_summary']));

    $summary = dispatchGetNote((string) $tenant->id, (string) $facility->id, (string) $note->id);

    expect($summary)->toBeInstanceOf(ClinicalNoteSummary::class)
        ->and($summary->clinicalNoteId)->toBe((string) $note->id)
        ->and($summary->patientId)->toBe((string) $note->patient_id)
        ->and($summary->encounterId)->toBe((string) $note->encounter_id)
        ->and($summary->facilityId)->toBe((string) $facility->id)
        ->and($summary->authorUserId)->toBe((string) $user->id)
        ->and($summary->authoredContext)->toBe('CLINICIAN')
        ->and($summary->noteType)->toBe('discharge_summary')
        ->and($summary->title)->toBe('Discharge summary')
        ->and($summary->body)->toBe('Home with follow-up in 2 weeks.')
        ->and($summary->status)->toBe('SIGNED')
        ->and($summary->signedAt)->not->toBeNull()
        ->and($summary->signedByUserId)->toBe((string) $user->id)
        ->and($summary->amendmentCount)->toBe(0)
        ->and($summary->occurredAt)->toBeString()
        ->and($summary->recordedAt)->toBeString();
});

test('GetClinicalNote returns a NURSING-authored note with authoredContext = NURSING (ADR-144 — no reader change)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $note = TenantContextScope::run((string) $tenant->id, null, fn () => ClinicalNote::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->authoredBy((string) $user->id)
        ->nursing()->create(['note_type' => 'nursing_note', 'title' => 'Nursing note', 'body' => 'Obs stable overnight.']));

    $summary = dispatchGetNote((string) $tenant->id, (string) $facility->id, (string) $note->id);

    expect($summary->authoredContext)->toBe('NURSING')
        ->and($summary->noteType)->toBe('nursing_note')
        ->and($summary->body)->toBe('Obs stable overnight.')
        ->and($summary->status)->toBe('DRAFT');
});

test('a SIGNED note with amendments reports status SIGNED + amendmentCount > 0 (derived AMENDED)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $noteId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) ClinicalNote::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->authoredBy((string) $user->id)->signed((string) $user->id)->create()->id);

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(
        new AmendClinicalNote($noteId, (string) $user->id, 'ADDENDUM', 'extra detail', 'clarification'),
    ));

    $summary = dispatchGetNote((string) $tenant->id, (string) $facility->id, $noteId);
    expect($summary->status)->toBe('SIGNED')->and($summary->amendmentCount)->toBe(1);
});

test('a DRAFT and a CANCELLED note are both readable (no encounter-state gate, no status gate on reads)', function (string $state) {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $noteId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) ClinicalNote::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->authoredBy((string) $user->id)->{$state}()->create()->id);

    expect(dispatchGetNote((string) $tenant->id, (string) $facility->id, $noteId)->status)->toBe(strtoupper($state));
})->with(['draft', 'cancelled']);

// ── authorization ──────────────────────────────────────────────────

test('a caller without clinical.notes.read is denied (AuthorizationException)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture([
        'clinical.access', 'clinical.notes.create', 'clinical.notes.sign', 'clinical.notes.amend',
    ]);
    $noteId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) ClinicalNote::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->authoredBy((string) $user->id)->signed((string) $user->id)->create()->id);

    expect(fn () => dispatchGetNote((string) $tenant->id, (string) $facility->id, $noteId))->toThrow(AuthorizationException::class);
});

test('facility refinement: a reader holding clinical.notes.read at facility A cannot read a note at facility B -> NotFound', function () {
    ['tenant' => $tenant, 'facility' => $facilityA, 'user' => $user] = clinicalFixture();
    $noteId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) ClinicalNote::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) Str::uuid7())->authoredBy((string) $user->id)->signed((string) $user->id)->create()->id);

    expect(fn () => dispatchGetNote((string) $tenant->id, (string) $facilityA->id, $noteId))->toThrow(NotFound::class);
});

test('a missing / cross-tenant note -> NotFound', function () {
    ['tenant' => $tenantA, 'facility' => $facilityA, 'user' => $userA] = clinicalFixture();
    ['tenant' => $tenantB, 'facility' => $facilityB, 'user' => $userB] = clinicalFixture();
    $noteId = TenantContextScope::run((string) $tenantA->id, null, fn () => (string) ClinicalNote::factory()
        ->forTenant((string) $tenantA->id)->atFacility((string) $facilityA->id)->authoredBy((string) $userA->id)->signed((string) $userA->id)->create()->id);

    test()->actingAs($userA);
    expect(fn () => dispatchGetNote((string) $tenantA->id, (string) $facilityA->id, (string) Str::uuid7()))->toThrow(NotFound::class);

    test()->actingAs($userB);
    expect(fn () => dispatchGetNote((string) $tenantB->id, (string) $facilityB->id, $noteId))->toThrow(NotFound::class);
});

test('the read is side-effect free — no audit, no outbox event', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $noteId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) ClinicalNote::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->authoredBy((string) $user->id)->signed((string) $user->id)->create()->id);

    dispatchGetNote((string) $tenant->id, (string) $facility->id, $noteId);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('target_id', $noteId)->count()))->toBe(0);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->count()))->toBe(0);
});
