<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\ResolveAllergy;
use App\Domains\Clinical\Application\Command\UpdateAllergy;
use App\Domains\Clinical\Contracts\Event\AllergyUpdated;
use App\Domains\Clinical\Domain\AllergyRepository;
use App\Domains\Clinical\Domain\Model\Allergy;
use App\Domains\Clinical\Domain\ValueObject\AllergyChangeType;
use App\Domains\Clinical\Domain\ValueObject\AllergySubstance;
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
 | PHASE 6 (C.5) gate — UpdateAllergy (ADR-141 D-K / D-J): content-only mutation;
 | REVISION | CORRECTION; the COMPLETE pre-change state is preserved in exactly
 | one append-only clin_allergy_history row; version bumps; AllergyUpdated + audit.
 */

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/allergy.php';

function dispatchUpdateAllergy(string $tenantId, ?string $facilityId, string $allergyId, string $actorId, array $overrides = []): void
{
    $substance = $overrides['substance'] ?? AllergySubstance::of(
        name: $overrides['substanceName'] ?? 'Penicillin G',
        code: array_key_exists('substanceCode', $overrides) ? $overrides['substanceCode'] : '764146007',
        codeSystem: array_key_exists('substanceCodeSystem', $overrides) ? $overrides['substanceCodeSystem'] : 'SNOMED-CT',
    );

    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(new UpdateAllergy(
        allergyId: $allergyId,
        actorId: $actorId,
        changeType: $overrides['changeType'] ?? AllergyChangeType::REVISION,
        category: $overrides['category'] ?? 'DRUG',
        substance: $substance,
        reactionDescription: array_key_exists('reactionDescription', $overrides) ? $overrides['reactionDescription'] : 'Anaphylaxis',
        severity: array_key_exists('severity', $overrides) ? $overrides['severity'] : 'SEVERE',
        onsetDate: $overrides['onsetDate'] ?? '2022-01-01',
        reason: $overrides['reason'] ?? null,
    )));
}

// ── happy path ─────────────────────────────────────────────────────

test('a REVISION applies the new values, writes one pre-change history row, bumps version, emits AllergyUpdated + audit', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $allergyId = clinicalRecordAllergy((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'category' => 'DRUG',
        'substanceName' => 'Penicillin',
        'substanceCode' => '764146007',
        'substanceCodeSystem' => 'SNOMED-CT',
        'reactionDescription' => 'Mild rash',
        'severity' => 'MILD',
        'onsetDate' => '2023-01-01',
    ]);

    dispatchUpdateAllergy((string) $tenant->id, (string) $facility->id, $allergyId, (string) $user->id, [
        'changeType' => AllergyChangeType::REVISION,
        'category' => 'DRUG',
        'substanceName' => 'Penicillin V',
        'substanceCode' => '372687004',
        'substanceCodeSystem' => 'SNOMED-CT',
        'reactionDescription' => 'Severe rash',
        'severity' => 'MODERATE',
        'onsetDate' => '2022-06-01',
    ]);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_allergies')->where('id', $allergyId)->first());
    expect($row->substance_name)->toBe('Penicillin V')
        ->and($row->substance_code)->toBe('372687004')
        ->and($row->reaction_description)->toBe('Severe rash')
        ->and($row->severity)->toBe('MODERATE')
        ->and($row->onset_date)->toBe('2022-06-01')
        ->and($row->clinical_status)->toBe('ACTIVE')      // unchanged
        ->and($row->verification_status)->toBe('SUSPECTED') // unchanged
        ->and((int) $row->version)->toBe(1);

    $history = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_allergy_history')->where('allergy_id', $allergyId)->get());
    expect($history)->toHaveCount(1);
    $h = $history->first();
    expect($h->change_kind)->toBe('CONTENT')
        ->and($h->change_type)->toBe('REVISION')
        ->and($h->substance_name)->toBe('Penicillin') // PRE-CHANGE
        ->and($h->reaction_description)->toBe('Mild rash') // PRE-CHANGE
        ->and($h->severity)->toBe('MILD') // PRE-CHANGE
        ->and($h->onset_date)->toBe('2023-01-01') // PRE-CHANGE
        ->and($h->clinical_status)->toBe('ACTIVE')
        ->and($h->verification_status)->toBe('SUSPECTED')
        ->and($h->reason)->toBeNull()
        ->and($h->author_user_id)->toBe((string) $user->id)
        ->and((int) $h->version)->toBe(0);

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', AllergyUpdated::class)->first());
    expect($outbox->event_name)->toBe('clinical.AllergyUpdated');
    $payload = json_decode((string) $outbox->payload, true, 512, JSON_THROW_ON_ERROR);
    expect(array_keys($payload))->toEqualCanonicalizing([
        'eventId', 'tenantId', 'facilityId', 'patientId', 'encounterId', 'allergyId',
        'changeType', 'category', 'clinicalStatus', 'verificationStatus',
        'substanceCodeSystem', 'occurredAt', 'correlationId', 'causationId',
    ])->and($payload['changeType'])->toBe('REVISION');

    // Deny-listed fields absent from event
    foreach (['Penicillin', 'Mild rash', 'Severe rash', 'MODERATE', '2022-06-01'] as $needle) {
        expect((string) $outbox->payload)->not->toContain($needle);
    }

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'clinical.allergies.updated')->first());
    expect($audit->target_id)->toBe($allergyId)
        ->and($audit->reason)->toBeNull()
        ->and(json_decode((string) $audit->metadata, true))->toBe([]);
});

test('a CORRECTION stores its clinical reason on the history row ONLY — never the audit / event', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $allergyId = clinicalRecordAllergy((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    dispatchUpdateAllergy((string) $tenant->id, (string) $facility->id, $allergyId, (string) $user->id, [
        'changeType' => AllergyChangeType::CORRECTION,
        'substanceName' => 'Cephalexin',
        'reason' => 'Misidentified allergen during intake interview',
    ]);

    $h = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_allergy_history')->where('allergy_id', $allergyId)->first());
    expect($h->change_type)->toBe('CORRECTION')
        ->and($h->reason)->toBe('Misidentified allergen during intake interview');

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'clinical.allergies.updated')->first());
    expect($audit->reason)->toBeNull()
        ->and(json_decode((string) $audit->metadata, true))->toBe([]);
    expect((string) $audit->metadata)->not->toContain('Misidentified allergen');

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', AllergyUpdated::class)->value('payload'));
    expect((string) $outbox)->not->toContain('Misidentified allergen');
});

test('UpdateAllergy MAY correct a RESOLVED allergy — status / resolved_at untouched, history snapshot shows RESOLVED', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $allergyId = clinicalRecordAllergy((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(
        new ResolveAllergy($allergyId, (string) $user->id)));
    $resolvedAt = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_allergies')->where('id', $allergyId)->value('resolved_at'));

    dispatchUpdateAllergy((string) $tenant->id, (string) $facility->id, $allergyId, (string) $user->id, [
        'changeType' => AllergyChangeType::CORRECTION,
        'substanceName' => 'Penicillin VK',
        'reason' => 'corrected specific salt form',
    ]);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_allergies')->where('id', $allergyId)->first());
    expect($row->clinical_status)->toBe('RESOLVED')
        ->and($row->resolved_at)->toBe($resolvedAt) // untouched
        ->and($row->substance_name)->toBe('Penicillin VK');

    $h = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_allergy_history')->where('allergy_id', $allergyId)->first());
    expect($h->clinical_status)->toBe('RESOLVED');
});

test('UpdateAllergy never changes status / facility / encounter / authored_context / occurred_at / recorded_at', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $allergyId = clinicalRecordAllergy((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    $before = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_allergies')->where('id', $allergyId)->first());

    dispatchUpdateAllergy((string) $tenant->id, (string) $facility->id, $allergyId, (string) $user->id, [
        'substanceName' => 'Modified Name',
    ]);

    $after = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_allergies')->where('id', $allergyId)->first());
    expect($after->facility_id)->toBe($before->facility_id)
        ->and($after->encounter_id)->toBe($before->encounter_id)
        ->and($after->patient_id)->toBe($before->patient_id)
        ->and($after->author_user_id)->toBe($before->author_user_id)
        ->and($after->authored_context)->toBe($before->authored_context)
        ->and($after->clinical_status)->toBe($before->clinical_status)
        ->and($after->verification_status)->toBe($before->verification_status)
        ->and($after->occurred_at)->toBe($before->occurred_at)
        ->and($after->recorded_at)->toBe($before->recorded_at);
});

test('a stale in-memory revise save throws ConcurrencyConflict — no history row written', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $allergyId = clinicalRecordAllergy((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    // Stale model with old version
    $stale = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(AllergyRepository::class)->getById($allergyId));

    // First update succeeds
    dispatchUpdateAllergy((string) $tenant->id, (string) $facility->id, $allergyId, (string) $user->id, [
        'substanceName' => 'First Update',
    ]);

    // Second update with stale in-memory model throws
    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($stale, $user): void {
        $now = CarbonImmutable::now();
        $stale->revise(
            changeType: AllergyChangeType::REVISION,
            substance: AllergySubstance::of('Stale Update'),
            category: 'DRUG',
            reactionDescription: null,
            severity: null,
            onsetDate: null,
            reason: null,
            authorUserId: (string) $user->id,
            changeOccurredAt: $now,
            changeRecordedAt: $now,
        );
        app(AllergyRepository::class)->save($stale);
    }))->toThrow(ConcurrencyConflict::class);

    // Exactly one history row persisted from the successful first update
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_allergy_history')->where('allergy_id', $allergyId)->count()))->toBe(1);
});

test('UpdateAllergy uses clinical.allergies.record — a caller with only read is denied', function () {
    $fixture = allergyFixture(['clinical.allergies.read']);
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $fixture['tenant']->id, (string) $fixture['facility']->id, (string) $fixture['user']->id);

    // Grant record temporarily to create the allergy
    grantAllergyPermissionsToRole((string) $fixture['tenant']->id, $fixture['role_id'], ['clinical.allergies.record']);
    $allergyId = clinicalRecordAllergy((string) $fixture['tenant']->id, (string) $fixture['facility']->id, $patientId, $encounterId, (string) $fixture['user']->id);

    // Revoke record key
    Tenancy::run((string) $fixture['tenant']->id, fn () => DB::table('idn_permission_role')
        ->where('role_id', $fixture['role_id'])
        ->where('permission_id', DB::table('idn_permissions')->where('key', 'clinical.allergies.record')->value('id'))
        ->delete());

    expect(fn () => dispatchUpdateAllergy((string) $fixture['tenant']->id, (string) $fixture['facility']->id, $allergyId, (string) $fixture['user']->id))
        ->toThrow(AuthorizationException::class);
});

test('per-allergy originating-facility scope: a caller not assigned at the allergy facility is denied', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $allergyId = clinicalRecordAllergy((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    // move the allergy's originating facility to one the user is not assigned to
    Tenancy::run((string) $tenant->id, fn () => DB::table('clin_allergies')->where('id', $allergyId)->update(['facility_id' => (string) Str::uuid7()]));

    expect(fn () => dispatchUpdateAllergy((string) $tenant->id, (string) $facility->id, $allergyId, (string) $user->id))
        ->toThrow(AuthorizationException::class);
});

test('there is no clinical.allergies.manage / .update / .verify / .resolve key (ADR-141 D-L)', function () {
    $permissions = require dirname(__DIR__, 4).'/app/Domains/Clinical/Authorization/permissions.php';
    expect($permissions)
        ->not->toContain('clinical.allergies.manage')
        ->not->toContain('clinical.allergies.update')
        ->not->toContain('clinical.allergies.verify')
        ->not->toContain('clinical.allergies.resolve')
        ->not->toContain('clinical.allergies.correct');
});

test('a missing / cross-tenant allergy -> NotFound', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();

    expect(fn () => dispatchUpdateAllergy((string) $tenant->id, (string) $facility->id, (string) Str::uuid7(), (string) $user->id))
        ->toThrow(NotFound::class);
});

test('update succeeds regardless of the encounter later state and makes NO cross-domain call', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $allergyId = clinicalRecordAllergy((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    // Cancel the originating encounter after the allergy was recorded
    Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->update([
        'status' => 'CANCELLED',
    ]));

    // UpdateAllergy still succeeds!
    dispatchUpdateAllergy((string) $tenant->id, (string) $facility->id, $allergyId, (string) $user->id, [
        'substanceName' => 'Post-Encounter Update',
    ]);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_allergies')->where('id', $allergyId)->value('substance_name')))
        ->toBe('Post-Encounter Update');
});
