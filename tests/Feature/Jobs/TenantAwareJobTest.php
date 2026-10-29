<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Models\Tenant;
use App\Shared\Jobs\TenantAwareJob;
use App\Shared\Support\CorrelationId;
use App\Shared\Tenancy\Rls;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContext;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 | PHASE 4 gate — TenantAwareJob Test (blueprint.md)
 */

uses(RefreshDatabase::class);

final class DummyTenantJob extends TenantAwareJob
{
    public static bool $executed = false;

    public static ?string $capturedTenantId = null;

    protected function execute(): void
    {
        self::$executed = true;
        self::$capturedTenantId = app(TenantContext::class)->tenantId();
    }
}

final class FailingTenantJob extends TenantAwareJob
{
    protected function execute(): void
    {
        throw new \RuntimeException('Job failed intentionally');
    }
}

/** Reads the rows it can see and drops a marker — under whatever context is active. */
final class ProbeTenantJob extends TenantAwareJob
{
    public static ?string $seenTenantId = null;

    public static ?int $seenRowCount = null;

    protected function execute(): void
    {
        self::$seenTenantId = app(TenantContext::class)->tenantId();
        self::$seenRowCount = DB::table('zz_job_probe')->count();
        DB::table('zz_job_probe')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => self::$seenTenantId,
            'label' => 'marker',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

beforeEach(function () {
    DummyTenantJob::$executed = false;
    DummyTenantJob::$capturedTenantId = null;
    ProbeTenantJob::$seenTenantId = null;
    ProbeTenantJob::$seenRowCount = null;
});

test('tenant aware job captures context and re-establishes it on execution', function () {
    $tenant = Tenant::factory()->create();
    $correlationId = (string) Str::uuid7();

    // Fake dispatch within context
    $job = TenantContextScope::run($tenant->id, null, function () use ($correlationId) {
        CorrelationId::set($correlationId);

        return new DummyTenantJob;
    });

    // Asserts job captures tenantId, correlationId
    expect($job->tenantId)->toBe($tenant->id);
    expect($job->correlationId)->toBe($correlationId);

    // Asserts handle() re-establishes TenantContext
    $job->handle();

    expect(DummyTenantJob::$executed)->toBeTrue();
    expect(DummyTenantJob::$capturedTenantId)->toBe($tenant->id);
});

test('failed job records to dead letters and writes audit log', function () {
    $tenant = Tenant::factory()->create();
    $correlationId = (string) Str::uuid7();

    $job = TenantContextScope::run($tenant->id, null, function () use ($correlationId) {
        CorrelationId::set($correlationId);

        return new FailingTenantJob;
    });

    $exception = new \RuntimeException('Job failed intentionally');
    $job->failed($exception);

    // Asserts failed() records to shared_dead_letters
    $this->assertDatabaseHas('shared_dead_letters', [
        'tenant_id' => $tenant->id,
        'source_type' => FailingTenantJob::class,
        'correlation_id' => $correlationId,
    ]);

    // Asserts it writes audit log (via DeadLetterHandler)
    $this->assertDatabaseHas('shared_audit_log', [
        'tenant_id' => $tenant->id,
        'action' => 'dead_letter.created',
    ]);
});

test('a tenant A job runs under tenant A even when tenant B context is active — no cross-tenant read or write', function () {
    Schema::create('zz_job_probe', function (Blueprint $t) {
        $t->uuid('id')->primary();
        $t->uuid('tenant_id')->index();
        $t->uuid('facility_id')->nullable();
        $t->string('label');
        $t->timestampsTz();
    });
    Rls::enable('zz_job_probe');

    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    // seed: A has 1 row, B has 3 rows
    Tenancy::run($tenantA->id, fn () => DB::table('zz_job_probe')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $tenantA->id, 'label' => 'A-seed',
        'created_at' => now(), 'updated_at' => now(),
    ]));
    Tenancy::run($tenantB->id, fn () => collect(range(1, 3))->each(fn ($i) => DB::table('zz_job_probe')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $tenantB->id, 'label' => "B-seed-{$i}",
        'created_at' => now(), 'updated_at' => now(),
    ])));

    // the job is created in tenant A's context (captures tenantId = A)
    $job = TenantContextScope::run($tenantA->id, null, fn () => new ProbeTenantJob);
    expect($job->tenantId)->toBe($tenantA->id);

    // execute it while tenant B's context is the active one
    TenantContextScope::run($tenantB->id, null, function () use ($job, $tenantB) {
        // sanity: B's context really is active here
        expect(app(TenantContext::class)->tenantId())->toBe($tenantB->id);
        $job->handle();
    });

    // the job ran under tenant A, saw only tenant A's row, wrote only to tenant A
    expect(ProbeTenantJob::$seenTenantId)->toBe($tenantA->id)
        ->and(ProbeTenantJob::$seenRowCount)->toBe(1);

    $counts = [
        'A' => Tenancy::run($tenantA->id, fn () => DB::table('zz_job_probe')->count()),
        'B' => Tenancy::run($tenantB->id, fn () => DB::table('zz_job_probe')->count()),
    ];

    expect($counts['A'])->toBe(2)   // A-seed + the job's marker
        ->and($counts['B'])->toBe(3); // B untouched — no marker leaked in

    // and the marker really belongs to tenant A
    expect(Tenancy::run($tenantA->id, fn () => DB::table('zz_job_probe')->where('label', 'marker')->value('tenant_id')))
        ->toBe($tenantA->id);
});
