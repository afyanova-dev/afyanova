<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Queueing;

use App\Models\Tenant;
use App\Shared\Tenancy\TenantContextScope;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('the application seeder does not create demo hospital operations', function (): void {
    $this->seed(DatabaseSeeder::class);

    $tenant = Tenant::query()->where('slug', 'afyanova-demo')->firstOrFail();
    $operationalData = TenantContextScope::run((string) $tenant->id, null, function () use ($tenant): array {
        return [
            'demo_hospital_exists' => DB::table('org_facilities')
                ->where('tenant_id', $tenant->id)
                ->where('code', 'DEMO_HOSP')
                ->exists(),
            'rooms' => DB::table('org_rooms')
                ->where('tenant_id', $tenant->id)
                ->count(),
            'queues' => DB::table('qms_queues')
                ->where('tenant_id', $tenant->id)
                ->count(),
        ];
    });

    expect($operationalData)->toBe([
        'demo_hospital_exists' => false,
        'rooms' => 0,
        'queues' => 0,
    ]);
});
