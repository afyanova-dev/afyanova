<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 | PHASE 6 (CP.1) gate — `clinical:define-procedure` (ADR-146 D-C): the ONLY
 | catalog-authoring path in CP.1 — no HTTP command, no permission key. Every
 | change is audited.
 */

uses(RefreshDatabase::class);

test('define creates a new catalog entry at version 1, audited', function () {
    $tenant = Tenant::factory()->create();

    $exitCode = Artisan::call('clinical:define-procedure', [
        'action' => 'define',
        '--tenant' => (string) $tenant->id,
        '--code' => 'SUTURE-1',
        '--name' => 'Suturing',
        '--category' => 'SUTURING',
        '--requires-site' => true,
        '--requires-laterality' => true,
    ]);

    expect($exitCode)->toBe(0);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_procedure_definitions')->where('code', 'SUTURE-1')->first());
    expect($row)->not->toBeNull()
        ->and($row->name)->toBe('Suturing')
        ->and((bool) $row->requires_site)->toBeTrue()
        ->and((bool) $row->requires_laterality)->toBeTrue()
        ->and((int) $row->version)->toBe(1)
        ->and((bool) $row->active)->toBeTrue();

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'procedures.definition_defined')->first());
    expect($audit)->not->toBeNull()->and($audit->target_id)->toBe($row->id);
});

test('define fails without --code/--name', function () {
    $tenant = Tenant::factory()->create();

    $exitCode = Artisan::call('clinical:define-procedure', ['action' => 'define', '--tenant' => (string) $tenant->id]);

    expect($exitCode)->toBe(1);
});

test('update bumps the version and applies changes, audited', function () {
    $tenant = Tenant::factory()->create();
    Artisan::call('clinical:define-procedure', [
        'action' => 'define', '--tenant' => (string) $tenant->id, '--code' => 'DRESS-1', '--name' => 'Dressing',
    ]);
    $id = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_procedure_definitions')->where('code', 'DRESS-1')->value('id'));

    $exitCode = Artisan::call('clinical:define-procedure', [
        'action' => 'update', '--tenant' => (string) $tenant->id, '--id' => $id, '--category' => 'DRESSING_WOUND_CARE', '--active' => '0',
    ]);

    expect($exitCode)->toBe(0);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_procedure_definitions')->where('id', $id)->first());
    expect($row->category)->toBe('DRESSING_WOUND_CARE')
        ->and((bool) $row->active)->toBeFalse()
        ->and((int) $row->version)->toBe(2);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'procedures.definition_updated')->where('target_id', $id)->exists()))->toBeTrue();
});

test('update fails without --id', function () {
    $tenant = Tenant::factory()->create();

    $exitCode = Artisan::call('clinical:define-procedure', ['action' => 'update', '--tenant' => (string) $tenant->id]);

    expect($exitCode)->toBe(1);
});

test('protocol approval can be explicitly enabled and removed through audited catalog maintenance', function () {
    $tenant = Tenant::factory()->create();
    Artisan::call('clinical:define-procedure', [
        'action' => 'define',
        '--tenant' => (string) $tenant->id,
        '--code' => 'DIRECT-DOSE',
        '--name' => 'Direct dose',
        '--protocol-reference' => 'APPROVED-IM-01',
    ]);
    $id = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_procedure_definitions')
        ->where('code', 'DIRECT-DOSE')
        ->value('id'));
    $approved = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_procedure_definitions')
        ->where('id', $id)
        ->value('protocol_reference'));
    expect($approved)->toBe('APPROVED-IM-01');

    Artisan::call('clinical:define-procedure', [
        'action' => 'update',
        '--tenant' => (string) $tenant->id,
        '--id' => $id,
        '--clear-protocol-approval' => true,
    ]);
    $cleared = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_procedure_definitions')
        ->where('id', $id)
        ->value('protocol_reference'));
    expect($cleared)->toBeNull();

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')
        ->where('action', 'procedures.definition_updated')
        ->where('target_id', $id)
        ->orderByDesc('created_at')
        ->first());
    expect(json_decode((string) $audit->metadata, true)['protocol_approval_cleared'])->toBeTrue();
});
