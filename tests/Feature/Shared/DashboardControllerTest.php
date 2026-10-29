<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Domains\Identity\Domain\Model\AccessAssignment;
use App\Domains\Identity\Domain\Model\Permission;
use App\Domains\Identity\Domain\Model\Role;
use App\Domains\Organization\Domain\Model\Facility;
use App\Models\User;
use App\Shared\Tenancy\TenantContextScope;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Post-login landing: /dashboard renders Dashboard/Index for every
 * authenticated user; the route itself never redirects and never 403s.
 */
final class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_admin_dashboard_renders_dashboard_index(): void
    {
        $admin = User::where('email', 'admin@afyanova.local')->firstOrFail();
        $facilityId = TenantContextScope::run($admin->tenant_id, null, fn (): string => (string) Facility::where('code', 'DEMO_HOSP')->firstOrFail()->id);

        $this->actingAs($admin)->withSession(['active_facility_id' => $facilityId])
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard/Index'));
    }

    public function test_dashboard_ignores_query_and_renders(): void
    {
        $admin = User::where('email', 'admin@afyanova.local')->firstOrFail();
        $facilityId = TenantContextScope::run($admin->tenant_id, null, fn (): string => (string) Facility::where('code', 'DEMO_HOSP')->firstOrFail()->id);

        $this->actingAs($admin)->withSession(['active_facility_id' => $facilityId])->get('/dashboard?date=2026-09-25')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard/Index'));
    }

    public function test_receptionist_dashboard_renders_dashboard_index(): void
    {
        $receptionist = User::where('email', 'reception@afyanova.local')->firstOrFail();

        $this->actingAs($receptionist)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard/Index'));
    }

    public function test_nurse_dashboard_renders_dashboard_index(): void
    {
        $nurse = User::where('email', 'nurse@afyanova.local')->firstOrFail();

        $this->actingAs($nurse)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard/Index'));
    }

    public function test_billing_only_dashboard_renders_dashboard_index(): void
    {
        $cashier = $this->makeUserWithPermissions(['billing.access']);

        $this->actingAs($cashier)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard/Index'));
    }

    public function test_dashboard_renders_for_a_user_without_workspace_access(): void
    {
        $viewer = $this->makeUserWithPermissions([]);

        // Dashboard is an authenticated landing page, independent of workspace permissions.
        $this->actingAs($viewer)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard/Index'));
    }

    /**
     * @param  list<string>  $permissionKeys
     */
    private function makeUserWithPermissions(array $permissionKeys): User
    {
        $admin = User::where('email', 'admin@afyanova.local')->firstOrFail();

        return TenantContextScope::run($admin->tenant_id, null, function () use ($admin, $permissionKeys): User {
            $user = User::factory()->create([
                'tenant_id' => $admin->tenant_id,
                'status' => 'ACTIVE',
            ]);
            $facility = Facility::where('code', 'DEMO_HOSP')->firstOrFail();
            $role = Role::query()->firstOrCreate(
                ['tenant_id' => $admin->tenant_id, 'key' => 'dashboard-test-'.Str::uuid7()],
                ['name' => 'Dashboard Test', 'status' => 'ACTIVE', 'scope_type' => 'FACILITY'],
            );
            foreach ($permissionKeys as $key) {
                $perm = Permission::query()->firstOrCreate(['key' => $key]);
                $role->permissions()->syncWithoutDetaching([
                    $perm->id => ['id' => (string) Str::uuid7(), 'tenant_id' => $admin->tenant_id],
                ]);
            }
            AccessAssignment::query()->create([
                'tenant_id' => $admin->tenant_id,
                'user_id' => $user->id,
                'role_id' => $role->id,
                'facility_id' => $facility->id,
                'status' => 'ACTIVE',
                'is_primary' => true,
            ]);

            return $user;
        });
    }
}
