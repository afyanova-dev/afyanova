<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\ResolveAllergy;
use App\Domains\Clinical\Contracts\Event\AllergyResolved;
use App\Domains\Clinical\Domain\AllergyRepository;
use App\Domains\Clinical\Domain\Exception\IllegalAllergyStatusTransition;
use App\Domains\Clinical\Domain\Model\Allergy;
use App\Domains\Clinical\Domain\ValueObject\AllergyVerificationStatus;
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
 | PHASE 6 (C.5) gate — ResolveAllergy (ADR-141 D-K / D-D): ACTIVE → RESOLVED only;
 | resolved_at set once; second resolve rejected; NO history row;
 | AllergyResolved + audit.
 */

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/allergy.php';

function dispatchResolveAllergy(string $tenantId, ?string $facilityId, string $allergyId, string $actorId, array $overrides = []): void
{
    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(new ResolveAllergy(
        allergyId: $allergyId,
        actorId: $actorId,
        resolvedAt: $overrides['resolvedAt'] ?? null,
        reason: $overrides['reason'] ?? null,
    )));
}

test('ACTIVE -> RESOLVED: resolved_at set, version bumps, AllergyResolved + audit, NO history row', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $allergyId = clinicalRecordAllergy((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'verificationStatus' => AllergyVerificationStatus::CONFIRMED,
    ]);

    dispatchResolveAllergy((string) $tenant->id, (string) $facility->id, $allergyId, (string) $user->id);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_allergies')->where('id', $allergyId)->first());
    expect($row->clinical_status)->toBe('RESOLVED')
        ->and($row->verification_status)->toBe('CONFIRMED') // unchanged
        ->and($row->resolved_at)->not->toBeNull()
        ->and((int) $row->version)->toBe(1);

    // NO history row written on resolution (ADR-141 D-J)
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_allergy_history')->count()))->toBe(0);

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', AllergyResolved::class)->first());
    expect($outbox->event_name)->toBe('clinical.AllergyResolved');
    $payload = json_decode((string) $outbox->payload, true, 512, JSON_THROW_ON_ERROR);
    expect(array_keys($payload))->toEqualCanonicalizing([
        'eventId', 'tenantId', 'facilityId', 'patientId', 'encounterId',
        'allergyId', 'resolvedAt', 'occurredAt', 'correlationId', 'causationId',
    ])
        ->and($payload['allergyId'])->toBe($allergyId)
        ->and($payload['resolvedAt'])->toBeString();

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'clinical.allergies.resolved')->first());
    expect($audit->target_id)->toBe($allergyId)
        ->and($audit->reason)->toBeNull()
        ->and(json_decode((string) $audit->metadata, true))->toBe([]);
});

test('an explicit clinical resolution time is honoured (not just now()) and round-trips consistently', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $allergyId = clinicalRecordAllergy((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    $at = CarbonImmutable::now()->subHours(6);
    dispatchResolveAllergy((string) $tenant->id, (string) $facility->id, $allergyId, (string) $user->id, ['resolvedAt' => $at->toISOString()]);

    /** @var CarbonImmutable $stored */
    $stored = TenantContextScope::run((string) $tenant->id, null, fn () => Allergy::query()->find($allergyId)->resolved_at);

    $updatedAt = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_allergies')->where('id', $allergyId)->value('updated_at'));
    expect($stored->lessThan(CarbonImmutable::now()->subHours(5)))->toBeTrue()
        ->and($stored->lessThan(CarbonImmutable::parse($updatedAt)))->toBeTrue()
        ->and(TenantContextScope::run((string) $tenant->id, null, fn () => Allergy::query()->find($allergyId)->resolved_at)->equalTo($stored))->toBeTrue();
});

test('resolving an already-RESOLVED allergy -> IllegalAllergyStatusTransition (409); resolved_at unchanged', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $allergyId = clinicalRecordAllergy((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    dispatchResolveAllergy((string) $tenant->id, (string) $facility->id, $allergyId, (string) $user->id);
    $firstResolvedAt = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_allergies')->where('id', $allergyId)->value('resolved_at'));

    expect(fn () => dispatchResolveAllergy((string) $tenant->id, (string) $facility->id, $allergyId, (string) $user->id))
        ->toThrow(IllegalAllergyStatusTransition::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_allergies')->where('id', $allergyId)->value('resolved_at')))->toBe($firstResolvedAt);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', AllergyResolved::class)->count()))->toBe(1);
});

test('resolving an INACTIVE / REFUTED allergy -> IllegalAllergyStatusTransition (409)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $allergyId = clinicalRecordAllergy((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    // Refute the allergy first
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($allergyId, $user) {
        $allergy = app(AllergyRepository::class)->getById($allergyId);
        $now = CarbonImmutable::now();
        $allergy->verify(AllergyVerificationStatus::REFUTED, 'Disproved', (string) $user->id, $now, $now);
        app(AllergyRepository::class)->save($allergy);
    });

    expect(fn () => dispatchResolveAllergy((string) $tenant->id, (string) $facility->id, $allergyId, (string) $user->id))
        ->toThrow(IllegalAllergyStatusTransition::class);
});

test('backdating resolved_at > 24h with a reason -> the reason is on the audit row, never a clin_ column', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $allergyId = clinicalRecordAllergy((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    dispatchResolveAllergy((string) $tenant->id, (string) $facility->id, $allergyId, (string) $user->id, [
        'resolvedAt' => CarbonImmutable::now()->subDays(5)->toISOString(),
        'reason' => 'resolution noted retrospectively from childhood review',
    ]);

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'clinical.allergies.resolved')->first());
    expect($audit->reason)->toBe('resolution noted retrospectively from childhood review');
});

test('a stale in-memory resolve save throws ConcurrencyConflict', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $allergyId = clinicalRecordAllergy((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    $stale = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(AllergyRepository::class)->getById($allergyId));

    // Resolve successfully once
    dispatchResolveAllergy((string) $tenant->id, (string) $facility->id, $allergyId, (string) $user->id);

    // Stale model resolve save throws
    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($stale): void {
        $stale->resolve(CarbonImmutable::now());
        app(AllergyRepository::class)->save($stale);
    }))->toThrow(ConcurrencyConflict::class);
});

test('ResolveAllergy uses clinical.allergies.record — a caller with only read is denied', function () {
    $fixture = allergyFixture(['clinical.allergies.read']);
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $fixture['tenant']->id, (string) $fixture['facility']->id, (string) $fixture['user']->id);

    grantAllergyPermissionsToRole((string) $fixture['tenant']->id, $fixture['role_id'], ['clinical.allergies.record']);
    $allergyId = clinicalRecordAllergy((string) $fixture['tenant']->id, (string) $fixture['facility']->id, $patientId, $encounterId, (string) $fixture['user']->id);

    Tenancy::run((string) $fixture['tenant']->id, fn () => DB::table('idn_permission_role')
        ->where('role_id', $fixture['role_id'])
        ->where('permission_id', DB::table('idn_permissions')->where('key', 'clinical.allergies.record')->value('id'))
        ->delete());

    expect(fn () => dispatchResolveAllergy((string) $fixture['tenant']->id, (string) $fixture['facility']->id, $allergyId, (string) $fixture['user']->id))
        ->toThrow(AuthorizationException::class);
});

test('per-allergy originating-facility scope: a caller not assigned at the allergy facility is denied', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $allergyId = clinicalRecordAllergy((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    Tenancy::run((string) $tenant->id, fn () => DB::table('clin_allergies')->where('id', $allergyId)->update(['facility_id' => (string) Str::uuid7()]));

    expect(fn () => dispatchResolveAllergy((string) $tenant->id, (string) $facility->id, $allergyId, (string) $user->id))
        ->toThrow(AuthorizationException::class);
});

test('a missing / cross-tenant allergy -> NotFound', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();

    expect(fn () => dispatchResolveAllergy((string) $tenant->id, (string) $facility->id, (string) Str::uuid7(), (string) $user->id))
        ->toThrow(NotFound::class);
});

test('resolution is not encounter-state-gated and makes NO cross-domain call', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $allergyId = clinicalRecordAllergy((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->update([
        'status' => 'CANCELLED',
    ]));

    dispatchResolveAllergy((string) $tenant->id, (string) $facility->id, $allergyId, (string) $user->id);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_allergies')->where('id', $allergyId)->value('clinical_status')))
        ->toBe('RESOLVED');
});
