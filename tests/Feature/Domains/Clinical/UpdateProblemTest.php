<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\ResolveProblem;
use App\Domains\Clinical\Application\Command\UpdateProblem;
use App\Domains\Clinical\Contracts\Event\ProblemUpdated;
use App\Domains\Clinical\Domain\Model\Problem;
use App\Domains\Clinical\Domain\ProblemRepository;
use App\Domains\Clinical\Domain\ValueObject\ProblemChangeType;
use App\Domains\Clinical\Domain\ValueObject\ProblemCode;
use App\Domains\Encounter\Application\Command\TransferEncounter;
use App\Models\User;
use App\Shared\Authorization\PermissionRegistry;
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
 | PHASE 6 (C.4) gate — UpdateProblem (ADR-140 D-J): status-agnostic in-place
 | revision / correction of description / code / code_system / onset_date; the
 | COMPLETE pre-change state is preserved in exactly one append-only
 | clin_problem_history row; version bumps; ProblemUpdated + audit.
 */

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/problem.php';

function dispatchUpdateProblem(string $tenantId, ?string $facilityId, string $problemId, string $actorId, array $overrides = []): void
{
    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(new UpdateProblem(
        problemId: $problemId,
        actorId: $actorId,
        changeType: $overrides['changeType'] ?? ProblemChangeType::REVISION,
        coding: $overrides['coding'] ?? ProblemCode::of(code: 'I11.9', codeSystem: 'ICD-10'),
        description: $overrides['description'] ?? 'Hypertensive heart disease without heart failure',
        onsetDate: $overrides['onsetDate'] ?? null,
        reason: $overrides['reason'] ?? null,
    )));
}

// ── happy path ─────────────────────────────────────────────────────

test('a REVISION applies the new values, writes one pre-change history row, bumps version, emits ProblemUpdated + audit', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = problemFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $problemId = clinicalAddProblem((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'code' => 'I10', 'codeSystem' => 'ICD-10', 'description' => 'Essential hypertension', 'onsetDate' => '2019-01-01',
    ]);

    dispatchUpdateProblem((string) $tenant->id, (string) $facility->id, $problemId, (string) $user->id, [
        'changeType' => ProblemChangeType::REVISION,
        'coding' => ProblemCode::of(code: 'I11.9', codeSystem: 'ICD-10'),
        'description' => 'Hypertensive heart disease without heart failure',
        'onsetDate' => '2018-11-15',
    ]);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_problems')->where('id', $problemId)->first());
    expect($row->code)->toBe('I11.9')
        ->and($row->description)->toBe('Hypertensive heart disease without heart failure')
        ->and($row->onset_date)->toBe('2018-11-15')
        ->and($row->status)->toBe('ACTIVE')      // unchanged
        ->and((int) $row->version)->toBe(1);

    $history = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_problem_history')->where('problem_id', $problemId)->get());
    expect($history)->toHaveCount(1);
    $h = $history->first();
    expect($h->change_type)->toBe('REVISION')
        ->and($h->code)->toBe('I10')                          // PRE-CHANGE
        ->and($h->description)->toBe('Essential hypertension')
        ->and($h->onset_date)->toBe('2019-01-01')
        ->and($h->status)->toBe('ACTIVE')
        ->and($h->resolved_at)->toBeNull()
        ->and($h->reason)->toBeNull()
        ->and($h->author_user_id)->toBe((string) $user->id)
        ->and((int) $h->version)->toBe(0);

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', ProblemUpdated::class)->first());
    expect($outbox->event_name)->toBe('clinical.ProblemUpdated');
    $payload = json_decode((string) $outbox->payload, true, 512, JSON_THROW_ON_ERROR);
    expect(array_keys($payload))->toEqualCanonicalizing([
        'eventId', 'tenantId', 'facilityId', 'patientId', 'encounterId', 'problemId', 'changeType', 'occurredAt', 'correlationId', 'causationId',
    ])->and($payload['changeType'])->toBe('REVISION');
    foreach (['I10', 'I11.9', 'Hypertensive', 'Essential'] as $needle) {
        expect((string) $outbox->payload)->not->toContain($needle);
    }

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'clinical.problems.updated')->first());
    expect($audit->target_id)->toBe($problemId)
        ->and($audit->reason)->toBeNull()
        ->and(json_decode((string) $audit->metadata, true))->toBe([]);
});

test('a CORRECTION stores its clinical reason on the history row ONLY — never the audit / event', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = problemFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $problemId = clinicalAddProblem((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    dispatchUpdateProblem((string) $tenant->id, (string) $facility->id, $problemId, (string) $user->id, [
        'changeType' => ProblemChangeType::CORRECTION,
        'description' => 'corrected: recorded against the wrong problem concept',
        'reason' => 'entered against wrong ICD concept on the ward round',
    ]);

    $h = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_problem_history')->where('problem_id', $problemId)->first());
    expect($h->change_type)->toBe('CORRECTION')->and($h->reason)->toBe('entered against wrong ICD concept on the ward round');

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'clinical.problems.updated')->first());
    expect($audit->reason)->toBeNull()->and(json_decode((string) $audit->metadata, true))->toBe([]);
    expect((string) $audit->metadata)->not->toContain('wrong ICD concept');

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', ProblemUpdated::class)->value('payload'));
    expect((string) $outbox)->not->toContain('wrong ICD concept')->not->toContain('corrected: recorded');
});

test('UpdateProblem MAY correct a RESOLVED problem (Decision 1) — status / resolved_at untouched, history snapshot shows RESOLVED', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = problemFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $problemId = clinicalAddProblem((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(
        new ResolveProblem($problemId, (string) $user->id)));
    $resolvedAt = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_problems')->where('id', $problemId)->value('resolved_at'));

    dispatchUpdateProblem((string) $tenant->id, (string) $facility->id, $problemId, (string) $user->id, [
        'changeType' => ProblemChangeType::CORRECTION,
        'description' => 'fixed a typo on a resolved entry',
        'reason' => 'spelling',
    ]);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_problems')->where('id', $problemId)->first());
    expect($row->status)->toBe('RESOLVED')
        ->and($row->resolved_at)->toBe($resolvedAt)
        ->and($row->description)->toBe('fixed a typo on a resolved entry');

    $h = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_problem_history')->where('problem_id', $problemId)->first());
    expect($h->status)->toBe('RESOLVED')->and($h->resolved_at)->toBe($resolvedAt);
});

// ── immutable fields ──────────────────────────────────────────────

test('UpdateProblem never changes status / facility / encounter / source_diagnosis_id / occurred_at', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = problemFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $diagnosisId = clinicalRecordDiagnosis((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
    $problemId = clinicalAddProblem((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, ['sourceDiagnosisId' => $diagnosisId]);
    $before = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_problems')->where('id', $problemId)->first());

    dispatchUpdateProblem((string) $tenant->id, (string) $facility->id, $problemId, (string) $user->id);

    $after = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_problems')->where('id', $problemId)->first());
    expect($after->status)->toBe($before->status)
        ->and($after->facility_id)->toBe($before->facility_id)
        ->and($after->encounter_id)->toBe($before->encounter_id)
        ->and($after->source_diagnosis_id)->toBe($before->source_diagnosis_id)
        ->and($after->occurred_at)->toBe($before->occurred_at)
        ->and($after->recorded_at)->toBe($before->recorded_at)
        ->and($after->authored_context)->toBe('CLINICIAN');
});

// ── concurrency ──────────────────────────────────────────────────

test('a stale in-memory revise save throws ConcurrencyConflict — no history row, no partial state', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = problemFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $problemId = clinicalAddProblem((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    try {
        TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($problemId, $user) {
            $problem = Problem::query()->findOrFail($problemId);
            Problem::query()->whereKey($problemId)->update(['version' => 9]);
            $problem->revise(
                changeType: ProblemChangeType::REVISION, coding: ProblemCode::none(), description: 'x',
                onsetDate: null, reason: null, authorUserId: (string) $user->id,
                changeOccurredAt: CarbonImmutable::now(), changeRecordedAt: CarbonImmutable::now(),
            );
            app(ProblemRepository::class)->save($problem);
        });
        test()->fail('expected ConcurrencyConflict');
    } catch (ConcurrencyConflict) {
        // expected
    }

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_problem_history')->count()))->toBe(0);
});

// ── authorization ────────────────────────────────────────────────

test('UpdateProblem uses clinical.problems.record — a caller with only clinical.problems.read cannot update', function () {
    // author with the record key adds a problem
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $author] = problemFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $author->id);
    $problemId = clinicalAddProblem((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $author->id);

    // a read-only user, assigned at the same facility, cannot update
    $roleId = (string) Str::uuid7();
    $readerId = (string) Str::uuid7();
    Tenancy::run((string) $tenant->id, function () use ($tenant, $facility, $roleId, $readerId) {
        DB::table('users')->insert(['id' => $readerId, 'tenant_id' => $tenant->id, 'name' => 'Reader', 'email' => 'r'.Str::random(6).'@x.test', 'password' => bcrypt('x'), 'status' => 'ACTIVE', 'email_verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('idn_roles')->insert(['id' => $roleId, 'tenant_id' => $tenant->id, 'key' => 'ro', 'name' => 'RO', 'status' => 'ACTIVE', 'scope_type' => 'FACILITY', 'created_at' => now(), 'updated_at' => now()]);
        $permId = DB::table('idn_permissions')->where('key', 'clinical.problems.read')->value('id');
        DB::table('idn_permission_role')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'role_id' => $roleId, 'permission_id' => $permId, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('idn_staff_identities')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'user_id' => $readerId, 'is_staff' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('idn_access_assignments')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'user_id' => $readerId, 'role_id' => $roleId, 'facility_id' => $facility->id, 'status' => 'ACTIVE', 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
    });
    test()->actingAs(User::find($readerId));

    expect(fn () => dispatchUpdateProblem((string) $tenant->id, (string) $facility->id, $problemId, $readerId))
        ->toThrow(AuthorizationException::class);
});

test('per-problem originating-facility scope: a caller not assigned at the problem\'s facility is denied', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = problemFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $problemId = clinicalAddProblem((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    // move the problem's originating facility to one the user is not assigned to
    Tenancy::run((string) $tenant->id, fn () => DB::table('clin_problems')->where('id', $problemId)->update(['facility_id' => (string) Str::uuid7()]));

    expect(fn () => dispatchUpdateProblem((string) $tenant->id, (string) $facility->id, $problemId, (string) $user->id))
        ->toThrow(AuthorizationException::class);
});

test('there is no clinical.problems.update / .correct / .manage key', function () {
    expect(PermissionRegistry::all())
        ->not->toContain('clinical.problems.update')
        ->not->toContain('clinical.problems.correct')
        ->not->toContain('clinical.problems.manage')
        ->not->toContain('clinical.problems.amend');
});

// ── not found / no cross-domain call ─────────────────────────────

test('a missing / cross-tenant problem -> NotFound', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = problemFixture();
    expect(fn () => dispatchUpdateProblem((string) $tenant->id, (string) $facility->id, (string) Str::uuid7(), (string) $user->id))
        ->toThrow(NotFound::class);
});

test('update succeeds regardless of the encounter\'s later state and makes NO cross-domain call', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = problemFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $problemId = clinicalAddProblem((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)
        ->dispatch(new TransferEncounter($encounterId, (string) $user->id)));

    dispatchUpdateProblem((string) $tenant->id, (string) $facility->id, $problemId, (string) $user->id, ['description' => 'corrected after transfer']);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_problems')->where('id', $problemId)->value('description')))->toBe('corrected after transfer');
});
