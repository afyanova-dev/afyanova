<?php

declare(strict_types=1);

use App\Domains\Clinical\Domain\Model\PrescribingDecision;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/plan_prescribing.php';

test('application layer: a PrescribingDecision created under tenant A is invisible under tenant B', function () {
    $tenantA = (string) Str::uuid7();
    $tenantB = (string) Str::uuid7();

    $id = TenantContextScope::run($tenantA, null, fn () => (string) PrescribingDecision::factory()->forTenant($tenantA)->create()->id);

    expect(TenantContextScope::run($tenantA, null, fn () => PrescribingDecision::query()->find($id)))->not->toBeNull();
    expect(TenantContextScope::run($tenantB, null, fn () => PrescribingDecision::query()->find($id)))->toBeNull();
});
