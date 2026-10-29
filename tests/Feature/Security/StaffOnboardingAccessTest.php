<?php

declare(strict_types=1);

use App\Models\User;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../tests/Support/hr.php';

it('keeps linked staff pending onboarding out of operational routes and workspaces', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $personId = createPersonHelper($tenantId, 'Neema', 'A', 'Pending', facilityId: $facilityId);
    $staffId = createStaffHelper($tenantId, $personId, facilityId: $facilityId);
    assignStaffHelper($tenantId, $staffId, $facilityId);

    $prospect = User::factory()->forTenant($tenantId)->create([
        'email' => 'neema.pending@example.test',
        'email_verified_at' => now(),
        'status' => 'ACTIVE',
    ]);
    linkStaffUserHelper($tenantId, $staffId, (string) $prospect->getKey(), $facilityId);

    TenantContextScope::run($tenantId, null, function () use ($fixture, $tenantId, $facilityId, $prospect): void {
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'user_id' => $prospect->getKey(),
            'role_id' => $fixture['role_id'],
            'facility_id' => $facilityId,
            'status' => 'ACTIVE',
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $this->actingAs($prospect)
        ->get(route('dashboard'))
        ->assertRedirect(route('staff.onboarding-pending'));

    $this->actingAs($prospect)
        ->get(route('hr.staff.index'))
        ->assertRedirect(route('staff.onboarding-pending'));

    $this->actingAs($prospect)
        ->get('/patient/'.Str::uuid7().'/chart')
        ->assertRedirect(route('staff.onboarding-pending'));

    $this->actingAs($prospect)
        ->post('/broadcasting/auth')
        ->assertRedirect(route('staff.onboarding-pending'));

    $this->actingAs($prospect)
        ->get(route('staff.onboarding-pending'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Auth/StaffOnboardingPending')
            ->where('email', 'neema.pending@example.test')
            ->where('staffStatus', 'PROSPECT'));

    $this->actingAs($prospect)
        ->get(route('sessions.index'))
        ->assertOk();

    $this->actingAs($fixture['user'])
        ->post(route('hr.staff.onboard', $staffId), [
            'joined_date' => now()->toDateString(),
        ])
        ->assertRedirect(route('hr.staff.show', $staffId));

    $this->actingAs($prospect)
        ->get(route('hr.staff.index'))
        ->assertOk();

    $this->actingAs($prospect)
        ->get(route('staff.onboarding-pending'))
        ->assertRedirect(route('dashboard'));
});
