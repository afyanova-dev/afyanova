<?php

declare(strict_types=1);

use App\Domains\Radiology\Application\Query\GetImagingModalities;
use App\Domains\Radiology\Application\Query\GetImagingModality;
use App\Domains\Radiology\Application\Query\GetImagingServiceDefinition;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('GetImagingModality / GetImagingModalities / GetImagingServiceDefinition return catalog rows for an authorized caller', function () {
    ['tenant' => $tenant, 'facility' => $facility] = radiologyFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    $modality = seedImagingModality($t, ['code' => 'MRI', 'name' => 'MRI']);
    $service = seedImagingService($t, (string) $modality->id, ['code' => 'MRI-BRAIN', 'name' => 'MRI Brain']);

    $one = TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new GetImagingModality(modalityId: (string) $modality->id)));
    expect($one->code)->toBe('MRI');

    $all = TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new GetImagingModalities(active: true)));
    expect($all)->toHaveCount(1)->and($all[0]->code)->toBe('MRI');

    $svc = TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new GetImagingServiceDefinition(serviceId: (string) $service->id)));
    expect($svc->code)->toBe('MRI-BRAIN')->and($svc->modalityCode)->toBe('MRI');
});

test('a caller without radiology.access is denied the catalog', function () {
    ['tenant' => $tenant, 'facility' => $facility] = radiologyFixture(permissions: ['patient.patients.read']);
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    $modality = seedImagingModality($t);

    expect(fn () => TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new GetImagingModality(modalityId: (string) $modality->id))))
        ->toThrow(AuthorizationException::class);
});
