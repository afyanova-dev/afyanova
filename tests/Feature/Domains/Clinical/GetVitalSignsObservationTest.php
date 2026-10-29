<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\CorrectVitalSignsObservation;
use App\Domains\Clinical\Application\Query\GetVitalSignsObservation;
use App\Domains\Clinical\Contracts\Dto\VitalSignsObservationSummary;
use App\Domains\Clinical\Domain\Model\VitalSignsObservation;
use App\Domains\Clinical\Domain\ValueObject\VitalSigns;
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
 | PHASE 6 (C.2) gate — GetVitalSignsObservation through the real QueryBus /
 | #[ProtectedQuery] pipeline (IC §6.1, §6.2; ADR-133, ADR-138 D15). Read-only:
 | no event, no audit, no state change. Not encounter-state-gated.
 */

uses(RefreshDatabase::class);

function dispatchGetVitals(string $tenantId, ?string $facilityId, string $observationId): VitalSignsObservationSummary
{
    /** @var VitalSignsObservationSummary */
    return TenantContextScope::run($tenantId, $facilityId, fn () => app(QueryBus::class)->dispatch(new GetVitalSignsObservation($observationId)));
}

// ── happy path ─────────────────────────────────────────────────────

test('returns the ratified VitalSignsObservationSummary for an authorized reader (measurement values included)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $obs = TenantContextScope::run((string) $tenant->id, null, fn () => VitalSignsObservation::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->recordedBy((string) $user->id)
        ->create([
            'temperature' => 38.1, 'heart_rate' => 96, 'respiratory_rate' => 18,
            'systolic_bp' => 132, 'diastolic_bp' => 84, 'oxygen_saturation' => 96,
            'weight' => 68.0, 'height' => 165.0, 'pain_score' => 4,
        ]));

    $s = dispatchGetVitals((string) $tenant->id, (string) $facility->id, (string) $obs->id);

    expect($s)->toBeInstanceOf(VitalSignsObservationSummary::class)
        ->and($s->vitalSignsObservationId)->toBe((string) $obs->id)
        ->and($s->patientId)->toBe((string) $obs->patient_id)
        ->and($s->encounterId)->toBe((string) $obs->encounter_id)
        ->and($s->facilityId)->toBe((string) $facility->id)
        ->and($s->authorUserId)->toBe((string) $user->id)
        ->and($s->authoredContext)->toBe('NURSING')
        ->and($s->temperature)->toBe(38.1)
        ->and($s->heartRate)->toBe(96)
        ->and($s->respiratoryRate)->toBe(18)
        ->and($s->systolicBp)->toBe(132)
        ->and($s->diastolicBp)->toBe(84)
        ->and($s->oxygenSaturation)->toBe(96)
        ->and($s->weight)->toBe(68.0)
        ->and($s->height)->toBe(165.0)
        ->and($s->painScore)->toBe(4)
        ->and($s->occurredAt)->toBeString()
        ->and($s->recordedAt)->toBeString()
        ->and($s->supersedesObservationId)->toBeNull()
        ->and($s->supersededAt)->toBeNull()
        ->and($s->correctionReason)->toBeNull();
});

test('a superseded observation and its correction each report their place in the chain', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $v1 = TenantContextScope::run((string) $tenant->id, null, fn () => (string) VitalSignsObservation::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->recordedBy((string) $user->id)->create()->id);

    $v2 = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(
        new CorrectVitalSignsObservation(
            newObservationId: $v2, supersedesObservationId: $v1, actorId: (string) $user->id,
            measurements: VitalSigns::of(heartRate: 70),
            reason: 'HR corrected',
        ),
    ));

    $original = dispatchGetVitals((string) $tenant->id, (string) $facility->id, $v1);
    expect($original->supersededAt)->not->toBeNull()
        ->and($original->supersededByObservationId)->toBe($v2)
        ->and($original->supersedesObservationId)->toBeNull()
        ->and($original->correctionReason)->toBeNull();

    $correction = dispatchGetVitals((string) $tenant->id, (string) $facility->id, $v2);
    expect($correction->supersedesObservationId)->toBe($v1)
        ->and($correction->correctionReason)->toBe('HR corrected')
        ->and($correction->supersededAt)->toBeNull();
});

// ── authorization ──────────────────────────────────────────────────

test('a caller without clinical.vitals.read is denied (AuthorizationException)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture([
        'clinical.access', 'clinical.vitals.record',
    ]);
    $obsId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) VitalSignsObservation::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->create()->id);

    expect(fn () => dispatchGetVitals((string) $tenant->id, (string) $facility->id, $obsId))->toThrow(AuthorizationException::class);
});

test('facility refinement: a reader holding clinical.vitals.read at facility A cannot read an observation at facility B -> NotFound', function () {
    ['tenant' => $tenant, 'facility' => $facilityA, 'user' => $user] = clinicalFixture();
    $obsId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) VitalSignsObservation::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) Str::uuid7())->create()->id);

    expect(fn () => dispatchGetVitals((string) $tenant->id, (string) $facilityA->id, $obsId))->toThrow(NotFound::class);
});

test('a missing / cross-tenant observation -> NotFound', function () {
    ['tenant' => $tenantA, 'facility' => $facilityA, 'user' => $userA] = clinicalFixture();
    ['tenant' => $tenantB, 'facility' => $facilityB, 'user' => $userB] = clinicalFixture();
    $obsId = TenantContextScope::run((string) $tenantA->id, null, fn () => (string) VitalSignsObservation::factory()
        ->forTenant((string) $tenantA->id)->atFacility((string) $facilityA->id)->create()->id);

    test()->actingAs($userA);
    expect(fn () => dispatchGetVitals((string) $tenantA->id, (string) $facilityA->id, (string) Str::uuid7()))->toThrow(NotFound::class);

    test()->actingAs($userB);
    expect(fn () => dispatchGetVitals((string) $tenantB->id, (string) $facilityB->id, $obsId))->toThrow(NotFound::class);
});

test('the read is side-effect free — no audit, no outbox event', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $obsId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) VitalSignsObservation::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->create()->id);

    dispatchGetVitals((string) $tenant->id, (string) $facility->id, $obsId);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('target_id', $obsId)->count()))->toBe(0)
        ->and(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->count()))->toBe(0);
});
