<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Domains\Identity\Contracts\FacilityAdminRoleDefinition;
use App\Domains\Identity\Contracts\NurseRoleDefinition;
use App\Domains\Identity\Contracts\ReceptionistRoleDefinition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Post-login landing: /dashboard renders Dashboard/Index for every
 * authenticated user; the route itself never redirects and never 403s.
 */
final class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_admin_dashboard_renders_dashboard_index(): void
    {
        $fixture = makeTenantWithUser(FacilityAdminRoleDefinition::PERMISSIONS);
        $admin = $fixture['user'];
        $facilityId = (string) $fixture['facility']->id;

        $this->actingAs($admin)->withSession(['active_facility_id' => $facilityId])
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard/Index'));
    }

    public function test_dashboard_ignores_query_and_renders(): void
    {
        $fixture = makeTenantWithUser(FacilityAdminRoleDefinition::PERMISSIONS);
        $admin = $fixture['user'];
        $facilityId = (string) $fixture['facility']->id;

        $this->actingAs($admin)->withSession(['active_facility_id' => $facilityId])->get('/dashboard?date=2026-09-25')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard/Index'));
    }

    public function test_receptionist_dashboard_renders_dashboard_index(): void
    {
        $receptionist = makeTenantWithUser(ReceptionistRoleDefinition::PERMISSIONS)['user'];

        $this->actingAs($receptionist)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard/Index'));
    }

    public function test_nurse_dashboard_renders_dashboard_index(): void
    {
        $nurse = makeTenantWithUser(NurseRoleDefinition::PERMISSIONS)['user'];

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
        return makeTenantWithUser($permissionKeys)['user'];
    }
}
