<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\CancelClinicalNote;
use App\Domains\Clinical\Domain\Exception\IllegalClinicalNoteTransition;
use App\Domains\Clinical\Domain\Model\ClinicalNote;
use App\Domains\Encounter\Application\Command\ChangeEncounterDisposition;
use App\Domains\Encounter\Application\Command\CompleteEncounter;
use App\Domains\Encounter\Domain\Enum\EncounterDisposition;
use App\Shared\Authorization\PermissionRegistry;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.1) gate — CancelClinicalNote (DRAFT -> CANCELLED). LOCKED
 | behaviour (spec §2): AuthorizesAgainst only, gated by clinical.notes.amend,
 | NO event, NO audit, NO cross-domain call, NO encounter-state gate. Version
 | still increments.
 */

uses(RefreshDatabase::class);

function dispatchCancel(string $tenantId, ?string $facilityId, string $noteId, string $actorId): void
{
    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)
        ->dispatch(new CancelClinicalNote($noteId, $actorId)));
}

// ── happy path ─────────────────────────────────────────────────────

test('DRAFT -> CANCELLED: status only, version bumped, NO event, NO audit row', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $noteId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) ClinicalNote::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->authoredBy((string) $user->id)->draft()->create()->id);
    $before = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_notes')->where('id', $noteId)->first());

    dispatchCancel((string) $tenant->id, (string) $facility->id, $noteId, (string) $user->id);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_notes')->where('id', $noteId)->first());
    expect($row->status)->toBe('CANCELLED')
        ->and((int) $row->version)->toBe(1)
        ->and($row->body)->toBe($before->body)               // unchanged
        ->and($row->title)->toBe($before->title)
        ->and($row->note_type)->toBe($before->note_type)
        ->and($row->signed_at)->toBeNull();

    // NO ClinicalNoteCancelled anywhere on the outbox
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_name', 'like', '%Cancel%')->count()))->toBe(0);
    // NO cancellation audit action
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'like', '%cancel%')->count()))->toBe(0);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('target_id', $noteId)->count()))->toBe(0);
});

test('cancelling succeeds regardless of the encounter\'s later state (no encounter-state gate, no getEncounterContext call)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $noteId = clinicalCreateNote((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $user) {
        $bus = app(CommandBus::class);
        $bus->dispatch(new ChangeEncounterDisposition($encounterId, (string) $user->id, EncounterDisposition::Discharged));
        $bus->dispatch(new CompleteEncounter($encounterId, (string) $user->id));
    });

    dispatchCancel((string) $tenant->id, (string) $facility->id, $noteId, (string) $user->id);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_notes')->where('id', $noteId)->value('status')))->toBe('CANCELLED');
});

// ── illegal source states ──────────────────────────────────────────

test('cancelling a SIGNED note -> IllegalClinicalNoteTransition (409); no mutation', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $noteId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) ClinicalNote::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->authoredBy((string) $user->id)->signed((string) $user->id)->create()->id);

    try {
        dispatchCancel((string) $tenant->id, (string) $facility->id, $noteId, (string) $user->id);
        test()->fail('expected IllegalClinicalNoteTransition');
    } catch (IllegalClinicalNoteTransition $e) {
        expect($e->httpStatus())->toBe(409);
    }

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_notes')->where('id', $noteId)->first()))
        ->status->toBe('SIGNED')
        ->version->toBe(0);
});

test('cancelling a CANCELLED note -> IllegalClinicalNoteTransition (409, not idempotent)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $noteId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) ClinicalNote::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->authoredBy((string) $user->id)->cancelled()->create()->id);

    expect(fn () => dispatchCancel((string) $tenant->id, (string) $facility->id, $noteId, (string) $user->id))
        ->toThrow(IllegalClinicalNoteTransition::class);
});

// ── authorization — gated by clinical.notes.amend, NO clinical.notes.cancel key ──

test('clinical.notes.amend authorizes cancellation', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture([
        'clinical.access', 'clinical.notes.amend',
    ]);
    $noteId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) ClinicalNote::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->authoredBy((string) $user->id)->draft()->create()->id);

    dispatchCancel((string) $tenant->id, (string) $facility->id, $noteId, (string) $user->id);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_notes')->where('id', $noteId)->value('status')))->toBe('CANCELLED');
});

test('a caller with clinical.notes.create but not clinical.notes.amend cannot cancel', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture([
        'clinical.access', 'clinical.notes.create', 'clinical.notes.sign', 'clinical.notes.read',
    ]);
    $noteId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) ClinicalNote::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->authoredBy((string) $user->id)->draft()->create()->id);

    expect(fn () => dispatchCancel((string) $tenant->id, (string) $facility->id, $noteId, (string) $user->id))
        ->toThrow(AuthorizationException::class);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_notes')->where('id', $noteId)->value('status')))->toBe('DRAFT');
});

test('there is no clinical.notes.cancel permission key registered', function () {
    expect(PermissionRegistry::all())->not->toContain('clinical.notes.cancel');
});

// ── isolation ──────────────────────────────────────────────────────

test('a missing / cross-tenant note -> NotFound', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    expect(fn () => dispatchCancel((string) $tenant->id, (string) $facility->id, (string) Str::uuid7(), (string) $user->id))
        ->toThrow(NotFound::class);
});
