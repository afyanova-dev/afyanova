<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\AmendClinicalNote;
use App\Domains\Clinical\Application\Command\SignClinicalNote;
use App\Domains\Clinical\Contracts\Event\ClinicalNoteAmended;
use App\Domains\Clinical\Domain\ClinicalNoteRepository;
use App\Domains\Clinical\Domain\Enum\ClinicalNoteAmendmentType;
use App\Domains\Clinical\Domain\Exception\IllegalClinicalNoteTransition;
use App\Domains\Clinical\Domain\Model\ClinicalNote;
use App\Domains\Encounter\Application\Command\TransferEncounter;
use App\Shared\Bus\CommandBus;
use App\Shared\Concurrency\ConcurrencyConflict;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.1) gate — AmendClinicalNote (SIGNED -> SIGNED): appends an
 | append-only clin_note_amendments row, preserves the signed body, bumps the
 | note version, emits ClinicalNoteAmended + clinical.note_amended audit. NOT
 | encounter-state-gated (ADR-134, ADR-135).
 */

uses(RefreshDatabase::class);

function seedSignedNote(string $tenantId, string $facilityId, string $authorId): string
{
    return TenantContextScope::run($tenantId, null, fn () => (string) ClinicalNote::factory()
        ->forTenant($tenantId)->atFacility($facilityId)->authoredBy($authorId)->signed($authorId)->create()->id);
}

function dispatchAmend(string $tenantId, ?string $facilityId, string $noteId, string $actorId, array $overrides = []): void
{
    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(new AmendClinicalNote(
        clinicalNoteId: $noteId,
        actorId: $actorId,
        amendmentType: $overrides['amendmentType'] ?? 'ADDENDUM',
        body: $overrides['body'] ?? 'Result now available: WBC 11.2.',
        reason: $overrides['reason'] ?? 'lab result returned after signing',
        occurredAt: $overrides['occurredAt'] ?? null,
    )));
}

// ── happy path ─────────────────────────────────────────────────────

test('SIGNED -> amendment: append-only row, signed body untouched, note version bumped, ClinicalNoteAmended + clinical.note_amended audit', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $noteId = seedSignedNote((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $bodyBefore = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_notes')->where('id', $noteId)->value('body'));

    dispatchAmend((string) $tenant->id, (string) $facility->id, $noteId, (string) $user->id, [
        'amendmentType' => 'CORRECTION', 'body' => 'BP corrected to 128/82.', 'reason' => 'transcription error',
    ]);

    $note = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_notes')->where('id', $noteId)->first());
    expect($note->status)->toBe('SIGNED')
        ->and($note->body)->toBe($bodyBefore)               // signed content is immutable
        ->and((int) $note->amendment_count)->toBe(1)
        ->and((int) $note->version)->toBe(1);

    $amendment = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_note_amendments')->where('clinical_note_id', $noteId)->first());
    expect($amendment)->not->toBeNull()
        ->and($amendment->amendment_type)->toBe('CORRECTION')
        ->and($amendment->body)->toBe('BP corrected to 128/82.')
        ->and($amendment->reason)->toBe('transcription error')       // reason lives on the row
        ->and($amendment->author_user_id)->toBe((string) $user->id)
        ->and($amendment->facility_id)->toBe((string) $facility->id)
        ->and($amendment->patient_id)->toBe($note->patient_id)
        ->and($amendment->occurred_at)->not->toBeNull()
        ->and($amendment->recorded_at)->not->toBeNull();

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', ClinicalNoteAmended::class)->first());
    expect($outbox)->not->toBeNull()->and($outbox->event_name)->toBe('clinical.ClinicalNoteAmended');
    $payload = json_decode((string) $outbox->payload, true, 512, JSON_THROW_ON_ERROR);
    expect(array_keys($payload))->toEqualCanonicalizing([
        'eventId', 'tenantId', 'facilityId', 'patientId', 'encounterId',
        'clinicalNoteId', 'amendmentType', 'occurredAt', 'correlationId', 'causationId',
    ])->and($payload['amendmentType'])->toBe('CORRECTION');
    // PHI-free — the amendment body / reason must not be on the event
    expect((string) $outbox->payload)->not->toContain('128/82')->and((string) $outbox->payload)->not->toContain('transcription error');

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'clinical.note_amended')->first());
    expect($audit)->not->toBeNull()
        ->and($audit->target_id)->toBe($noteId)
        ->and($audit->reason)->toBeNull()                    // NOT the amendment reason
        ->and(json_decode((string) $audit->metadata, true))->toBe([]);
});

test('a second amendment increments amendment_count to 2 and appends a second row (derived AMENDED)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $noteId = seedSignedNote((string) $tenant->id, (string) $facility->id, (string) $user->id);

    dispatchAmend((string) $tenant->id, (string) $facility->id, $noteId, (string) $user->id, ['amendmentType' => 'ADDENDUM']);
    dispatchAmend((string) $tenant->id, (string) $facility->id, $noteId, (string) $user->id, ['amendmentType' => 'AMENDMENT']);

    $note = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_notes')->where('id', $noteId)->first());
    expect((int) $note->amendment_count)->toBe(2)
        ->and((int) $note->version)->toBe(2)
        ->and($note->status)->toBe('SIGNED');
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_note_amendments')->where('clinical_note_id', $noteId)->count()))->toBe(2);
});

test('amendment succeeds regardless of the encounter\'s later state (Invariant 19)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $noteId = clinicalCreateNote((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)
        ->dispatch(new SignClinicalNote($noteId, (string) $user->id)));

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $user) {
        $bus = app(CommandBus::class);
        $bus->dispatch(new TransferEncounter($encounterId, (string) $user->id));
    });

    dispatchAmend((string) $tenant->id, (string) $facility->id, $noteId, (string) $user->id);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_note_amendments')->where('clinical_note_id', $noteId)->count()))->toBe(1);
});

// ── illegal source states ──────────────────────────────────────────

test('amending a DRAFT note -> IllegalClinicalNoteTransition (409); no amendment row, no event', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $noteId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) ClinicalNote::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->authoredBy((string) $user->id)->draft()->create()->id);

    expect(fn () => dispatchAmend((string) $tenant->id, (string) $facility->id, $noteId, (string) $user->id))
        ->toThrow(IllegalClinicalNoteTransition::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_note_amendments')->count()))->toBe(0);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', ClinicalNoteAmended::class)->count()))->toBe(0);
});

test('amending a CANCELLED note -> IllegalClinicalNoteTransition (409)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $noteId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) ClinicalNote::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->authoredBy((string) $user->id)->cancelled()->create()->id);

    expect(fn () => dispatchAmend((string) $tenant->id, (string) $facility->id, $noteId, (string) $user->id))
        ->toThrow(IllegalClinicalNoteTransition::class);
});

// ── append-only enforcement (DB layer) ─────────────────────────────

test('clin_note_amendments rows cannot be updated or deleted by a tenant (append-only RESTRICTIVE RLS)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $noteId = seedSignedNote((string) $tenant->id, (string) $facility->id, (string) $user->id);
    dispatchAmend((string) $tenant->id, (string) $facility->id, $noteId, (string) $user->id);

    $raw = DB::connection('pgsql_raw');
    $amendmentId = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_note_amendments')->where('clinical_note_id', $noteId)->value('id'));

    // append-only RESTRICTIVE policies (the shared_audit_log precedent): UPDATE / DELETE
    // affect zero rows and the row is untouched — no error, no mutation.
    $raw->transaction(function () use ($raw, $tenant, $amendmentId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [(string) $tenant->id]);
        expect($raw->table('clin_note_amendments')->where('id', $amendmentId)->update(['reason' => 'tampered']))->toBe(0);
        expect($raw->table('clin_note_amendments')->where('id', $amendmentId)->delete())->toBe(0);
    });

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_note_amendments')->where('id', $amendmentId)->value('reason')))
        ->not->toBe('tampered');
});

// ── authorization ──────────────────────────────────────────────────

test('a caller with clinical.notes.sign but not clinical.notes.amend cannot amend', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture([
        'clinical.access', 'clinical.notes.sign', 'clinical.notes.read',
    ]);
    $noteId = seedSignedNote((string) $tenant->id, (string) $facility->id, (string) $user->id);

    expect(fn () => dispatchAmend((string) $tenant->id, (string) $facility->id, $noteId, (string) $user->id))
        ->toThrow(AuthorizationException::class);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_note_amendments')->count()))->toBe(0);
});

// ── isolation / concurrency ────────────────────────────────────────

test('a missing / cross-tenant note -> NotFound', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    expect(fn () => dispatchAmend((string) $tenant->id, (string) $facility->id, (string) Str::uuid7(), (string) $user->id))
        ->toThrow(NotFound::class);
});

test('a stale in-memory amend() save throws ConcurrencyConflict — no amendment row, no event', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $noteId = seedSignedNote((string) $tenant->id, (string) $facility->id, (string) $user->id);

    try {
        TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($noteId, $user) {
            $stale = ClinicalNote::query()->findOrFail($noteId);
            ClinicalNote::query()->whereKey($noteId)->update(['version' => 5]);
            $stale->amend(
                ClinicalNoteAmendmentType::Addendum,
                'x', 'r', (string) $user->id,
                CarbonImmutable::now(), CarbonImmutable::now(),
            );
            app(ClinicalNoteRepository::class)->save($stale);
        });
        test()->fail('expected ConcurrencyConflict');
    } catch (ConcurrencyConflict) {
        // expected
    }

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_note_amendments')->count()))->toBe(0);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', ClinicalNoteAmended::class)->count()))->toBe(0);
});
