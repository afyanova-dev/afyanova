<?php

declare(strict_types=1);

use App\Domains\Clinical\Authorization\ClinicalRolePermissions;
use App\Domains\Identity\Domain\Model\Role;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../../../Support/clinical.php';

uses(RefreshDatabase::class);

test('clinical role sync grants nurse permissions to existing seeded roles', function (): void {
    $fixture = clinicalFixture();
    $tenantId = (string) $fixture['tenant']->id;

    TenantContextScope::run($tenantId, null, fn () => Role::query()->firstOrCreate(
        ['tenant_id' => $tenantId, 'key' => 'nurse'],
        ['name' => 'Staff Nurse', 'status' => 'ACTIVE', 'scope_type' => 'FACILITY'],
    ));

    $this->artisan('clinical:sync-role-permissions', [
        '--tenant' => $tenantId,
        '--dry-run' => true,
    ])->assertSuccessful();

    $role = TenantContextScope::run(
        $tenantId,
        null,
        fn (): Role => Role::query()->where('key', 'nurse')->firstOrFail(),
    );
    expect(TenantContextScope::run(
        $tenantId,
        null,
        fn (): array => $role->permissions()->pluck('idn_permissions.key')->all(),
    ))->toBe([]);

    $this->artisan('clinical:sync-role-permissions', ['--tenant' => $tenantId])
        ->assertSuccessful();

    $keys = TenantContextScope::run(
        $tenantId,
        null,
        fn (): array => $role->refresh()->permissions()->pluck('idn_permissions.key')->all(),
    );
    sort($keys);

    $expected = ClinicalRolePermissions::defaults()['nurse'];
    sort($expected);
    expect($keys)->toBe($expected);

    $this->artisan('clinical:sync-role-permissions', ['--tenant' => $tenantId])
        ->expectsOutputToContain('Attached 0 missing clinical permission grant(s).')
        ->assertSuccessful();
});
