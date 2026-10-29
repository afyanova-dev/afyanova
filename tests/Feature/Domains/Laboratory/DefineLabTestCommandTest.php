<?php

declare(strict_types=1);

use App\Domains\Laboratory\Application\Query\GetLabTestDefinitions;
use App\Domains\Laboratory\Domain\Exception\LabTestDefinitionAlreadyExists;
use App\Models\Tenant;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 | PHASE 8 (Lab.1) gate — `laboratory:define-test` (ADR-157 D-C): the ONLY
 | catalog-authoring path in Lab.1 — no HTTP command, no permission key. Every
 | change is audited.
 */

uses(RefreshDatabase::class);

test('define creates a new catalog entry at version 1, audited', function () {
    $tenant = Tenant::factory()->create();

    $exitCode = Artisan::call('laboratory:define-test', [
        'action' => 'define',
        '--tenant' => (string) $tenant->id,
        '--code' => 'FBC',
        '--name' => 'Full Blood Count',
        '--category' => 'HAEMATOLOGY',
        '--specimen-type' => 'whole_blood',
        '--tat' => '45',
    ]);

    expect($exitCode)->toBe(0);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('lab_test_definitions')->where('code', 'FBC')->first());
    expect($row)->not->toBeNull()
        ->and($row->name)->toBe('Full Blood Count')
        ->and($row->specimen_type)->toBe('WHOLE_BLOOD')
        ->and((int) $row->turnaround_target_minutes)->toBe(45)
        ->and((int) $row->version)->toBe(1)
        ->and((bool) $row->active)->toBeTrue();

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'laboratory.test_defined')->first());
    expect($audit)->not->toBeNull()->and($audit->target_id)->toBe($row->id);
});

test('define rejects a duplicate active code', function () {
    $tenant = Tenant::factory()->create();
    Artisan::call('laboratory:define-test', [
        'action' => 'define', '--tenant' => (string) $tenant->id, '--code' => 'GLUC', '--name' => 'Glucose',
    ]);

    expect(fn () => Artisan::call('laboratory:define-test', [
        'action' => 'define', '--tenant' => (string) $tenant->id, '--code' => 'GLUC', '--name' => 'Glucose (fasting)',
    ]))->toThrow(LabTestDefinitionAlreadyExists::class);
});

test('define fails without --code/--name', function () {
    $tenant = Tenant::factory()->create();

    $exitCode = Artisan::call('laboratory:define-test', ['action' => 'define', '--tenant' => (string) $tenant->id]);

    expect($exitCode)->toBe(1);
});

test('update bumps the version and applies changes, audited', function () {
    $tenant = Tenant::factory()->create();
    Artisan::call('laboratory:define-test', [
        'action' => 'define', '--tenant' => (string) $tenant->id, '--code' => 'CRP', '--name' => 'C-Reactive Protein',
    ]);
    $id = Tenancy::run((string) $tenant->id, fn () => DB::table('lab_test_definitions')->where('code', 'CRP')->value('id'));

    $exitCode = Artisan::call('laboratory:define-test', [
        'action' => 'update', '--tenant' => (string) $tenant->id, '--id' => $id,
        '--availability' => 'referral_only', '--active' => '0',
    ]);

    expect($exitCode)->toBe(0);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('lab_test_definitions')->where('id', $id)->first());
    expect($row->availability_status)->toBe('REFERRAL_ONLY')
        ->and((bool) $row->active)->toBeFalse()
        ->and((int) $row->version)->toBe(2);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')
        ->where('action', 'laboratory.test_updated')->where('target_id', $id)->exists()))->toBeTrue();
});

test('GetLabTestDefinitions returns the tenant catalog filtered by active', function () {
    ['tenant' => $tenant, 'facility' => $facility] = laboratoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    seedLabTestDefinition($t, ['code' => 'A1', 'name' => 'Alpha', 'active' => true]);
    seedLabTestDefinition($t, ['code' => 'B2', 'name' => 'Beta', 'active' => false]);

    $active = TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new GetLabTestDefinitions(active: true)));
    expect($active)->toHaveCount(1)->and($active[0]->code)->toBe('A1');
});
