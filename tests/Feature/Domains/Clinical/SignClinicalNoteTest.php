<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\SignClinicalNote;
use App\Domains\Clinical\Contracts\Event\ClinicalNoteSigned;
use App\Domains\Clinical\Domain\ClinicalNoteRepository;
use App\Domains\Clinical\Domain\Enum\ClinicalNoteStatus;
use App\Domains\Clinical\Domain\Exception\IllegalClinicalNoteTransition;
use App\Domains\Clinical\Domain\Model\ClinicalNote;
use App\Domains\Encounter\Application\Command\ChangeEncounterDisposition;
use App\Domains\Encounter\Application\Command\CompleteEncounter;
use App\Domains\Encounter\Domain\Enum\EncounterDisposition;
use App\Shared\Bus\CommandBus;
use App\Shared\Concurrency\ConcurrencyConflict;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.1) gate — SignClinicalNote (DRAFT -> SIGNED) end-to-end
 | (ADR-134). NOT encounter-state-gated (ADR-135). Auditable (bus).
 */

uses(RefreshDatabase::class);

function seedDraftNote(string $tenantId, string $facilityId, string $authorId): string
{
    return TenantContextScope::run($tenantId, null, fn () => (string) ClinicalNote::factory()
        ->forTenant($tenantId)->atFacility($facilityId)->authoredBy($authorId)->draft()->create()->id);
}

function dispatchSign(string $tenantId, ?string $facilityId, string $noteId, string $actorId): void
{
    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)
        ->dispatch(new SignClinicalNote($noteId, $actorId)));
}

// ── happy path ─────────────────────────────────────────────────────

test('DRAFT -> SIGNED: signed_at / signed_by set once, version bumped, ClinicalNoteSigned + clinical.note_signed audit', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $noteId = seedDraftNote((string) $tenant->id, (string) $facility->id, (string) $user->id);

    dispatchSign((string) $tenant->id, (string) $facility->id, $noteId, (string) $user->id);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_notes')->where('id', $noteId)->first());
    expect($row->status)->toBe('SIGNED')
        ->and($row->signed_at)->not->toBeNull()
        ->and($row->signed_by_user_id)->toBe((string) $user->id)
        ->and((int) $row->version)->toBe(1);

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', ClinicalNoteSigned::class)->first());
    expect($outbox)->not->toBeNull()->and($outbox->event_name)->toBe('clinical.ClinicalNoteSigned');
    $payload = json_decode((string) $outbox->payload, true, 512, JSON_THROW_ON_ERROR);
    expect(array_keys($payload))->toEqualCanonicalizing([
        'eventId', 'tenantId', 'facilityId', 'patientId', 'encounterId',
        'clinicalNoteId', 'occurredAt', 'correlationId', 'causationId',
    ])->and($payload['clinicalNoteId'])->toBe($noteId);

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'clinical.note_signed')->first());
    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe((string) $user->id)
        ->and($audit->target_id)->toBe($noteId)
        ->and(json_decode((string) $audit->metadata, true))->toBe([]);
});

test('signing succeeds regardless of the encounter\'s later state (Invariant 19 — no getEncounterContext call)', function () {
    // Create a real note against an ACTIVE encounter, then push the encounter to COMPLETED,
    // then sign — the note lifecycle is independent of the encounter after origination.
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $noteId = clinicalCreateNote((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $user) {
        $bus = app(CommandBus::class);
        $bus->dispatch(new ChangeEncounterDisposition($encounterId, (string) $user->id, EncounterDisposition::Discharged));
        $bus->dispatch(new CompleteEncounter($encounterId, (string) $user->id));
    });

    dispatchSign((string) $tenant->id, (string) $facility->id, $noteId, (string) $user->id);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_notes')->where('id', $noteId)->value('status')))->toBe('SIGNED');
});

// ── not idempotent / illegal source ────────────────────────────────

test('signing a SIGNED note -> IllegalClinicalNoteTransition (409); signed_at not rewritten, no second event/audit', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $noteId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) ClinicalNote::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->authoredBy((string) $user->id)
        ->signed((string) $user->id)->create()->id);
    $signedAtBefore = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_notes')->where('id', $noteId)->value('signed_at'));

    try {
        dispatchSign((string) $tenant->id, (string) $facility->id, $noteId, (string) $user->id);
        test()->fail('expected IllegalClinicalNoteTransition');
    } catch (IllegalClinicalNoteTransition $e) {
        expect($e->httpStatus())->toBe(409)->and($e->errorCode())->toBe('illegal_clinical_note_transition');
    }

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_notes')->where('id', $noteId)->first());
    expect($row->signed_at)->toBe($signedAtBefore)->and((int) $row->version)->toBe(0);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', ClinicalNoteSigned::class)->count()))->toBe(0);
});

test('signing a CANCELLED note -> IllegalClinicalNoteTransition (409)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $noteId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) ClinicalNote::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->authoredBy((string) $user->id)->cancelled()->create()->id);

    expect(fn () => dispatchSign((string) $tenant->id, (string) $facility->id, $noteId, (string) $user->id))
        ->toThrow(IllegalClinicalNoteTransition::class);
});

// ── authorization ──────────────────────────────────────────────────

test('a caller with clinical.notes.create but not clinical.notes.sign is denied', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture([
        'clinical.access', 'clinical.notes.create', 'clinical.notes.read',
    ]);
    $noteId = seedDraftNote((string) $tenant->id, (string) $facility->id, (string) $user->id);

    expect(fn () => dispatchSign((string) $tenant->id, (string) $facility->id, $noteId, (string) $user->id))
        ->toThrow(AuthorizationException::class);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_notes')->where('id', $noteId)->value('status')))->toBe('DRAFT');
});

test('per-note facility scope: a caller not assigned at the note\'s facility is denied (Invariant 24/25)', function () {
    ['tenant' => $tenant, 'user' => $user] = clinicalFixture();
    $noteId = seedDraftNote((string) $tenant->id, (string) Str::uuid7(), (string) $user->id); // note at a different facility

    expect(fn () => dispatchSign((string) $tenant->id, null, $noteId, (string) $user->id))
        ->toThrow(AuthorizationException::class);
});

// ── isolation ──────────────────────────────────────────────────────

test('a missing / cross-tenant note -> NotFound', function () {
    ['tenant' => $tenantA, 'facility' => $facilityA, 'user' => $userA] = clinicalFixture();
    ['tenant' => $tenantB, 'facility' => $facilityB, 'user' => $userB] = clinicalFixture();
    $noteId = seedDraftNote((string) $tenantA->id, (string) $facilityA->id, (string) $userA->id);

    test()->actingAs($userA);
    expect(fn () => dispatchSign((string) $tenantA->id, (string) $facilityA->id, (string) Str::uuid7(), (string) $userA->id))
        ->toThrow(NotFound::class);

    test()->actingAs($userB);
    expect(fn () => dispatchSign((string) $tenantB->id, (string) $facilityB->id, $noteId, (string) $userB->id))
        ->toThrow(NotFound::class);
});

// ── optimistic concurrency ─────────────────────────────────────────

test('a stale in-memory sign() save throws ConcurrencyConflict — no mutation, no event', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $noteId = seedDraftNote((string) $tenant->id, (string) $facility->id, (string) $user->id);

    try {
        TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($noteId, $user) {
            $stale = ClinicalNote::query()->findOrFail($noteId); // version 0
            ClinicalNote::query()->whereKey($noteId)->update(['version' => 1]);
            $stale->sign((string) $user->id);
            app(ClinicalNoteRepository::class)->save($stale);
        });
        test()->fail('expected ConcurrencyConflict');
    } catch (ConcurrencyConflict) {
        // expected
    }

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_notes')->where('id', $noteId)->value('status')))->toBe('DRAFT');
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', ClinicalNoteSigned::class)->count()))->toBe(0);
});

test('a fresh DRAFT note is at status DRAFT (factory sanity)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $noteId = seedDraftNote((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $note = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => ClinicalNote::query()->find($noteId));
    expect($note->status)->toBe(ClinicalNoteStatus::Draft);
});
