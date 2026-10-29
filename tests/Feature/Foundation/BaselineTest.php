<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

// Slice pages unbuilt: disable missing-page enforcement so response shapes
// stay testable until the .tsx lands (then delete this block).
beforeEach(function (): void {
    config([
        'inertia.pages.ensure_pages_exist' => false,
        'inertia.testing.ensure_pages_exist' => false,
    ]);
});

it('boots and serves the health screen', function () {
    $this->get('/system/health')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('System/Health'));
});

it('redirects guests from / to the sign-in screen', function () {
    $this->get('/')->assertRedirect('/login');
});

it('exposes the bare health endpoint', function () {
    $this->get('/up')->assertOk();
});

it('serves the versioned machine API', function () {
    $this->getJson('/api/v1/ping')->assertOk()->assertJsonPath('data.pong', true);
});

it('assigns and echoes a correlation id', function () {
    $this->get('/system/health')->assertHeader('X-Correlation-Id');
});

it('runs on PostgreSQL, not SQLite', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');
});

it('reaches Redis', function () {
    expect((string) Redis::connection()->ping())->not->toBe('');
});

it('uses a NOSUPERUSER db role so RLS FORCE is meaningful', function () {
    $row = DB::selectOne('select rolsuper from pg_roles where rolname = current_user');
    expect($row->rolsuper)->toBeFalse();
});

/*
 | The RLS policy uses nullif(current_setting('app.tenant_id', true), '') because
 | on a reused connection the GUC reverts to '' (not NULL) — without nullif the
 | cast '' ::uuid throws instead of returning zero rows (§11.2, verified on PG 18).
 | Runs on the raw connection so RefreshDatabase's outer transaction doesn't turn
 | our inner transactions into savepoints (which would leak the GUC).
 */
it('fail-closes RLS with no context and with an empty-string context', function () {
    $raw = DB::connection('pgsql_raw');
    $tenant = '11111111-1111-1111-1111-111111111111';

    $raw->statement('drop table if exists _rls_probe');
    $raw->statement('create table _rls_probe (id uuid primary key, tenant_id uuid not null)');
    $raw->statement('alter table _rls_probe enable row level security');
    $raw->statement('alter table _rls_probe force row level security');
    $raw->statement("create policy p on _rls_probe using (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid) with check (tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid)");

    try {
        $raw->transaction(function () use ($raw, $tenant) {
            $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenant]);
            $raw->table('_rls_probe')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenant]);
            expect($raw->table('_rls_probe')->count())->toBe(1);      // own tenant sees it
        });

        $raw->transaction(function () use ($raw) {
            expect($raw->table('_rls_probe')->count())->toBe(0);      // no context → 0, no error
            $raw->statement("select set_config('app.tenant_id', '', true)");
            expect($raw->table('_rls_probe')->count())->toBe(0);      // '' → 0, no error
        });

        $raw->transaction(function () use ($raw) {
            $raw->statement("select set_config('app.tenant_id', ?, true)", ['22222222-2222-2222-2222-222222222222']);
            expect($raw->table('_rls_probe')->count())->toBe(0);      // other tenant → 0
        });
    } finally {
        $raw->statement('drop table if exists _rls_probe');
    }
});
