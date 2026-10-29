<?php

declare(strict_types=1);

use App\Domains\Organization\Domain\Model\Organization;
use App\Models\Tenant;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('enforces at most one organization per tenant while allowing another tenant organization', function (): void {
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();

    TenantContextScope::run($tenant->id, null, function () use ($tenant): void {
        Organization::factory()->forTenant($tenant->id)->create();

        expect(fn () => DB::transaction(
            fn () => Organization::factory()->forTenant($tenant->id)->create(),
        ))
            ->toThrow(QueryException::class);
    });

    $otherOrganization = TenantContextScope::run(
        $otherTenant->id,
        null,
        fn (): Organization => Organization::factory()->forTenant($otherTenant->id)->create(),
    );

    expect($otherOrganization->tenant_id)->toBe($otherTenant->id);
});
