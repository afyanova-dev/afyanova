<?php

declare(strict_types=1);

use App\Domains\Radiology\Application\Query\GetImagingServices;
use App\Domains\Radiology\Domain\Exception\ImagingServiceAlreadyExists;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('define resolves --modality by code and creates a service at version 1, audited', function () {
    ['tenant' => $tenant] = radiologyFixture();
    $t = (string) $tenant->id;

    Artisan::call('radiology:define-modality', ['action' => 'define', '--tenant' => $t, '--code' => 'XR', '--name' => 'X-Ray']);

    $exitCode = Artisan::call('radiology:define-imaging-service', [
        'action' => 'define', '--tenant' => $t,
        '--code' => 'xr-chest', '--name' => 'Chest X-Ray', '--modality' => 'xr',
        '--body-site' => 'chest', '--contrast' => 'none', '--tat' => '30',
    ]);

    expect($exitCode)->toBe(0);

    $row = Tenancy::run($t, fn () => DB::table('rad_imaging_services')->where('code', 'XR-CHEST')->first());
    expect($row)->not->toBeNull()
        ->and($row->name)->toBe('Chest X-Ray')
        ->and($row->body_site)->toBe('CHEST')
        ->and($row->contrast_requirement)->toBe('NONE')
        ->and((int) $row->turnaround_target_minutes)->toBe(30)
        ->and((int) $row->version)->toBe(1);

    $modalityId = Tenancy::run($t, fn () => DB::table('rad_modalities')->where('code', 'XR')->value('id'));
    expect($row->modality_id)->toBe($modalityId);

    expect(Tenancy::run($t, fn () => DB::table('shared_audit_log')->where('action', 'radiology.imaging_service_defined')->exists()))->toBeTrue();
});

test('define fails on an unknown modality code', function () {
    ['tenant' => $tenant] = radiologyFixture();
    $t = (string) $tenant->id;

    expect(Artisan::call('radiology:define-imaging-service', [
        'action' => 'define', '--tenant' => $t, '--code' => 'CT-HEAD', '--name' => 'CT Head', '--modality' => 'NOPE',
    ]))->toBe(1);
});

test('define rejects a duplicate active service code', function () {
    ['tenant' => $tenant] = radiologyFixture();
    $t = (string) $tenant->id;
    Artisan::call('radiology:define-modality', ['action' => 'define', '--tenant' => $t, '--code' => 'CT', '--name' => 'CT']);
    Artisan::call('radiology:define-imaging-service', ['action' => 'define', '--tenant' => $t, '--code' => 'CT-ABDO', '--name' => 'CT Abdomen', '--modality' => 'CT']);

    expect(fn () => Artisan::call('radiology:define-imaging-service', [
        'action' => 'define', '--tenant' => $t, '--code' => 'ct-abdo', '--name' => 'CT Abdomen (repeat)', '--modality' => 'CT',
    ]))->toThrow(ImagingServiceAlreadyExists::class);
});

test('GetImagingServices returns the tenant catalog filtered by active', function () {
    ['tenant' => $tenant, 'facility' => $facility] = radiologyFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    $modality = seedImagingModality($t, ['code' => 'XR', 'name' => 'X-Ray']);
    seedImagingService($t, (string) $modality->id, ['code' => 'A1', 'name' => 'Alpha', 'active' => true]);
    seedImagingService($t, (string) $modality->id, ['code' => 'B2', 'name' => 'Beta', 'active' => false]);

    $active = TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new GetImagingServices(active: true)));
    expect($active)->toHaveCount(1)
        ->and($active[0]->code)->toBe('A1')
        ->and($active[0]->modalityCode)->toBe('XR');
});
