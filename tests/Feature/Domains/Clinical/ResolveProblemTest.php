<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\ResolveProblem;
use App\Domains\Clinical\Contracts\Event\ProblemResolved;
use App\Domains\Clinical\Domain\Exception\IllegalProblemStatusTransition;
use App\Domains\Clinical\Domain\Model\Problem;
use App\Domains\Clinical\Domain\ProblemRepository;
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
 | PHASE 6 (C.4) gate — ResolveProblem (ADR-140 D-E): ACTIVE → RESOLVED only;
 | resolved_at set once; second resolve rejected; NO history row;
 | ProblemResolved + audit.
 */

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/problem.php';

function dispatchResolveProblem(string $tenantId, ?string $facilityId, string $problemId, string $actorId, array $overrides = []): void
{
    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(new ResolveProblem(
        problemId: $problemId,
        actorId: $actorId,
        resolvedAt: $overrides['resolvedAt'] ?? null,
        reason: $overrides['reason'] ?? null,
    )));
}

test('ACTIVE -> RESOLVED: resolved_at set, version bumps, ProblemResolved + clinical.problems.resolved audit, NO history row', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = problemFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $problemId = clinicalAddProblem((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    dispatchResolveProblem((string) $tenant->id, (string) $facility->id, $problemId, (string) $user->id);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_problems')->where('id', $problemId)->first());
    expect($row->status)->toBe('RESOLVED')
        ->and($row->resolved_at)->not->toBeNull()
        ->and((int) $row->version)->toBe(1);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_problem_history')->count()))->toBe(0);

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', ProblemResolved::class)->first());
    expect($outbox->event_name)->toBe('clinical.ProblemResolved');
    $payload = json_decode((string) $outbox->payload, true, 512, JSON_THROW_ON_ERROR);
    expect(array_keys($payload))->toEqualCanonicalizing([
        'eventId', 'tenantId', 'facilityId', 'patientId', 'encounterId', 'problemId', 'resolvedAt', 'occurredAt', 'correlationId', 'causationId',
    ])->and($payload['problemId'])->toBe($problemId)->and($payload['resolvedAt'])->toBeString();

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'clinical.problems.resolved')->first());
    expect($audit->target_id)->toBe($problemId)->and($audit->reason)->toBeNull()
        ->and(json_decode((string) $audit->metadata, true))->toBe([]);
});

test('an explicit clinical resolution time is honoured (not just now()) and round-trips consistently', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = problemFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $problemId = clinicalAddProblem((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    $at = CarbonImmutable::now()->subHours(6);
    dispatchResolveProblem((string) $tenant->id, (string) $facility->id, $problemId, (string) $user->id, ['resolvedAt' => $at->toISOString()]);

    /** @var CarbonImmutable $stored */
    $stored = TenantContextScope::run((string) $tenant->id, null, fn () => Problem::query()->find($problemId)->resolved_at);

    // the caller value was used, not server now(): clearly backdated (> 5h) and before updated_at
    $updatedAt = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_problems')->where('id', $problemId)->value('updated_at'));
    expect($stored->lessThan(CarbonImmutable::now()->subHours(5)))->toBeTrue()
        ->and($stored->lessThan(CarbonImmutable::parse($updatedAt)))->toBeTrue()
        // set once — a re-read gives the identical value
        ->and(TenantContextScope::run((string) $tenant->id, null, fn () => Problem::query()->find($problemId)->resolved_at)->equalTo($stored))->toBeTrue();
});

test('resolving an already-RESOLVED problem -> IllegalProblemStatusTransition (409); resolved_at unchanged, no second event', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = problemFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $problemId = clinicalAddProblem((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    dispatchResolveProblem((string) $tenant->id, (string) $facility->id, $problemId, (string) $user->id);
    $firstResolvedAt = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_problems')->where('id', $problemId)->value('resolved_at'));

    try {
        dispatchResolveProblem((string) $tenant->id, (string) $facility->id, $problemId, (string) $user->id);
        test()->fail('expected IllegalProblemStatusTransition');
    } catch (IllegalProblemStatusTransition $e) {
        expect($e->httpStatus())->toBe(409)->and($e->errorCode())->toBe('illegal_problem_status_transition');
    }

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_problems')->where('id', $problemId)->value('resolved_at')))->toBe($firstResolvedAt);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', ProblemResolved::class)->count()))->toBe(1);
});

test('backdating resolved_at > 24h with a reason -> the reason is on the audit row, never a clin_ column', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = problemFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $problemId = clinicalAddProblem((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    dispatchResolveProblem((string) $tenant->id, (string) $facility->id, $problemId, (string) $user->id, [
        'resolvedAt' => CarbonImmutable::now()->subDays(5)->toISOString(),
        'reason' => 'resolution noted retrospectively from the discharge summary',
    ]);

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'clinical.problems.resolved')->first());
    expect($audit->reason)->toBe('resolution noted retrospectively from the discharge summary');
});

// ── concurrency / authorization / not found ──────────────────────

test('a stale in-memory resolve save throws ConcurrencyConflict', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = problemFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $problemId = clinicalAddProblem((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    try {
        TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($problemId) {
            $problem = Problem::query()->findOrFail($problemId);
            Problem::query()->whereKey($problemId)->update(['version' => 9]);
            $problem->resolve(CarbonImmutable::now());
            app(ProblemRepository::class)->save($problem);
        });
        test()->fail('expected ConcurrencyConflict');
    } catch (ConcurrencyConflict) {
        // expected
    }

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_problems')->where('id', $problemId)->value('status')))->toBe('ACTIVE');
});

test('ResolveProblem uses clinical.problems.record — a read-only caller cannot resolve; there is no clinical.problems.resolve key', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $author] = problemFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $author->id);
    $problemId = clinicalAddProblem((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $author->id);

    $readerId = (string) Str::uuid7();
    $roleId = (string) Str::uuid7();
    Tenancy::run((string) $tenant->id, function () use ($tenant, $facility, $roleId, $readerId) {
        DB::table('users')->insert(['id' => $readerId, 'tenant_id' => $tenant->id, 'name' => 'Reader', 'email' => 'r'.Str::random(6).'@x.test', 'password' => bcrypt('x'), 'status' => 'ACTIVE', 'email_verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('idn_roles')->insert(['id' => $roleId, 'tenant_id' => $tenant->id, 'key' => 'ro', 'name' => 'RO', 'status' => 'ACTIVE', 'scope_type' => 'FACILITY', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('idn_permission_role')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'role_id' => $roleId, 'permission_id' => DB::table('idn_permissions')->where('key', 'clinical.problems.read')->value('id'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('idn_staff_identities')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'user_id' => $readerId, 'is_staff' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('idn_access_assignments')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'user_id' => $readerId, 'role_id' => $roleId, 'facility_id' => $facility->id, 'status' => 'ACTIVE', 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
    });
    test()->actingAs(User::find($readerId));

    expect(fn () => dispatchResolveProblem((string) $tenant->id, (string) $facility->id, $problemId, $readerId))
        ->toThrow(AuthorizationException::class);
    expect(PermissionRegistry::all())->not->toContain('clinical.problems.resolve');
});

test('a missing / cross-tenant problem -> NotFound', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = problemFixture();
    expect(fn () => dispatchResolveProblem((string) $tenant->id, (string) $facility->id, (string) Str::uuid7(), (string) $user->id))
        ->toThrow(NotFound::class);
});

test('resolution is not encounter-state-gated and makes NO cross-domain call', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = problemFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $problemId = clinicalAddProblem((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)
        ->dispatch(new TransferEncounter($encounterId, (string) $user->id)));

    dispatchResolveProblem((string) $tenant->id, (string) $facility->id, $problemId, (string) $user->id);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_problems')->where('id', $problemId)->value('status')))->toBe('RESOLVED');
});
