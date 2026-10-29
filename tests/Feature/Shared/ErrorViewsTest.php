<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Branded error views (resources/views/errors/*). Direct navigation into a
 * denied, missing, expired, or broken page renders product copy — never a
 * framework debug page or a blank screen.
 */
final class ErrorViewsTest extends TestCase
{
    use RefreshDatabase;

    public function test_forbidden_renders_branded_403(): void
    {
        ['facility' => $facility, 'user' => $user] = makeTenantWithUser([]);

        $this->actingAs($user)
            ->withSession(['active_facility_id' => (string) $facility->id])
            ->get('/workspaces/billing')
            ->assertForbidden()
            ->assertSee('Access denied', false);
    }

    public function test_unknown_url_renders_branded_404(): void
    {
        $this->get('/no-such-page-anywhere')
            ->assertNotFound()
            ->assertSee('Not found', false);
    }

    public function test_cashier_workspace_renders_instead_of_the_missing_page_error(): void
    {
        config(['app.debug' => false]);

        ['facility' => $facility, 'user' => $user] = makeTenantWithUser(['billing.access']);

        $this->actingAs($user)
            ->withSession(['active_facility_id' => (string) $facility->id])
            ->get('/workspaces/billing')
            ->assertOk()
            ->assertSee('Billing', false);
    }
}
