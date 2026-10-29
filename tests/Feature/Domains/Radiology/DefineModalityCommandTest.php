<?php

declare(strict_types=1);

use App\Domains\Radiology\Domain\Exception\ImagingModalityAlreadyExists;
use App\Models\Tenant;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 | PHASE 8 (Rad.1) gate — `radiology:define-modality` (ADR-165 D-C): the ONLY
 | modality-catalog-authoring path in Rad.1 — no HTTP command, no permission
 | key. Every change is audited.
 */

uses(RefreshDatabase::class);

test('define creates a new modality at version 1, audited', function () {
    $tenant = Tenant::factory()->create();

    $exitCode = Artisan::call('radiology:define-modality', [
        'action' => 'define',
        '--tenant' => (string) $tenant->id,
        '--code' => 'ct',
        '--name' => 'Computed Tomography',
        '--category' => 'CROSS_SECTIONAL',
    ]);

    expect($exitCode)->toBe(0);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('rad_modalities')->where('code', 'CT')->first());
    expect($row)->not->toBeNull()
        ->and($row->name)->toBe('Computed Tomography')
        ->and($row->operational_status)->toBe('ACTIVE')
        ->and((int) $row->version)->toBe(1)
        ->and((bool) $row->active)->toBeTrue();

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'radiology.modality_defined')->first());
    expect($audit)->not->toBeNull()->and($audit->target_id)->toBe($row->id);
});

test('define rejects a duplicate active code', function () {
    $tenant = Tenant::factory()->create();
    Artisan::call('radiology:define-modality', [
        'action' => 'define', '--tenant' => (string) $tenant->id, '--code' => 'MRI', '--name' => 'MRI',
    ]);

    expect(fn () => Artisan::call('radiology:define-modality', [
        'action' => 'define', '--tenant' => (string) $tenant->id, '--code' => 'mri', '--name' => 'Magnetic Resonance',
    ]))->toThrow(ImagingModalityAlreadyExists::class);
});

test('update bumps the version, suspends the modality, audited', function () {
    $tenant = Tenant::factory()->create();
    Artisan::call('radiology:define-modality', [
        'action' => 'define', '--tenant' => (string) $tenant->id, '--code' => 'US', '--name' => 'Ultrasound',
    ]);
    $id = Tenancy::run((string) $tenant->id, fn () => DB::table('rad_modalities')->where('code', 'US')->value('id'));

    $exitCode = Artisan::call('radiology:define-modality', [
        'action' => 'update', '--tenant' => (string) $tenant->id, '--id' => $id, '--status' => 'suspended',
    ]);

    expect($exitCode)->toBe(0);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('rad_modalities')->where('id', $id)->first());
    expect($row->operational_status)->toBe('SUSPENDED')->and((int) $row->version)->toBe(2);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')
        ->where('action', 'radiology.modality_updated')->where('target_id', $id)->exists()))->toBeTrue();
});

test('define fails without --code/--name', function () {
    $tenant = Tenant::factory()->create();

    expect(Artisan::call('radiology:define-modality', ['action' => 'define', '--tenant' => (string) $tenant->id]))->toBe(1);
});
