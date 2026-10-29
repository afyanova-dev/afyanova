<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Domains\Organization\Domain\Model\Facility;
use App\Domains\Organization\Domain\Model\Organization;
use App\Models\User;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BroadcastChannelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $facilityIdA;

    private string $facilityIdB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid7();

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app-id',
        ]);

        require base_path('routes/channels.php');

        TenantContextScope::run($this->tenantId, null, function (): void {
            $org = Organization::factory()->forTenant($this->tenantId)->create();

            $facA = Facility::factory()->forTenant($this->tenantId, $org->id)->create();
            $facB = Facility::factory()->forTenant($this->tenantId, $org->id)->create();

            $this->facilityIdA = (string) $facA->id;
            $this->facilityIdB = (string) $facB->id;
        });
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createUserWithAssignment(string $facilityId, array $permissions = []): User
    {
        $user = User::factory()->forTenant($this->tenantId)->create(['status' => 'ACTIVE']);

        TenantContextScope::run($this->tenantId, null, function () use ($facilityId, $user, $permissions): void {
            $roleId = (string) Str::uuid7();
            DB::table('idn_roles')->insert([
                'id' => $roleId,
                'tenant_id' => $this->tenantId,
                'key' => 'test_role_'.Str::random(6),
                'name' => 'Test Channel Role',
                'status' => 'ACTIVE',
                'scope_type' => 'FACILITY',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($permissions as $key) {
                $permId = DB::table('idn_permissions')->where('key', $key)->value('id')
                    ?? tap((string) Str::uuid7(), fn (string $id) => DB::table('idn_permissions')->insert([
                        'id' => $id,
                        'key' => $key,
                        'layer' => str_ends_with($key, '.access') ? 'ACCESS' : 'ACTION',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]));

                DB::table('idn_permission_role')->insert([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $this->tenantId,
                    'role_id' => $roleId,
                    'permission_id' => $permId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('idn_access_assignments')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $this->tenantId,
                'user_id' => $user->id,
                'role_id' => $roleId,
                'facility_id' => $facilityId,
                'status' => 'ACTIVE',
                'is_primary' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return $user;
    }

    public function test_guest_is_forbidden_on_all_channels(): void
    {
        $response = $this->postJson('/broadcasting/auth', [
            'channel_name' => "private-facility.{$this->facilityIdA}.calling-board",
            'socket_id' => '1234.5678',
        ]);

        $response->assertStatus(403);
    }

    public function test_calling_board_authorizes_assigned_facility_user(): void
    {
        $user = $this->createUserWithAssignment($this->facilityIdA);

        $response = $this->actingAs($user)->postJson('/broadcasting/auth', [
            'channel_name' => "private-facility.{$this->facilityIdA}.calling-board",
            'socket_id' => '1234.5678',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['auth']);
    }

    public function test_calling_board_rejects_user_from_different_facility(): void
    {
        $user = $this->createUserWithAssignment($this->facilityIdA);

        $response = $this->actingAs($user)->postJson('/broadcasting/auth', [
            'channel_name' => "private-facility.{$this->facilityIdB}.calling-board",
            'socket_id' => '1234.5678',
        ]);

        $response->assertStatus(403);
    }

    public function test_reception_channel_rejects_user_without_topic_permissions(): void
    {
        $user = $this->createUserWithAssignment($this->facilityIdA, []);

        $response = $this->actingAs($user)->postJson('/broadcasting/auth', [
            'channel_name' => "private-facility.{$this->facilityIdA}.reception",
            'socket_id' => '1234.5678',
        ]);

        $response->assertStatus(403);
    }

    public function test_reception_channel_authorizes_user_with_encounter_read_permission(): void
    {
        $user = $this->createUserWithAssignment($this->facilityIdA, ['encounter.encounters.read']);

        $response = $this->actingAs($user)->postJson('/broadcasting/auth', [
            'channel_name' => "private-facility.{$this->facilityIdA}.reception",
            'socket_id' => '1234.5678',
        ]);

        $response->assertStatus(200);
    }

    public function test_reception_channel_authorizes_user_with_scheduling_access_permission(): void
    {
        $user = $this->createUserWithAssignment($this->facilityIdA, ['sch.access']);

        $response = $this->actingAs($user)->postJson('/broadcasting/auth', [
            'channel_name' => "private-facility.{$this->facilityIdA}.reception",
            'socket_id' => '1234.5678',
        ]);

        $response->assertStatus(200);
    }

    public function test_queue_channel_authorizes_user_with_queue_permission(): void
    {
        $queueId = (string) Str::uuid7();
        $user = $this->createUserWithAssignment($this->facilityIdA, ['queue.access']);

        $response = $this->actingAs($user)->postJson('/broadcasting/auth', [
            'channel_name' => "private-facility.{$this->facilityIdA}.queue.{$queueId}",
            'socket_id' => '1234.5678',
        ]);

        $response->assertStatus(200);
    }

    public function test_queue_channel_rejects_user_without_queue_permission(): void
    {
        $queueId = (string) Str::uuid7();
        $user = $this->createUserWithAssignment($this->facilityIdA, ['sch.access']);

        $response = $this->actingAs($user)->postJson('/broadcasting/auth', [
            'channel_name' => "private-facility.{$this->facilityIdA}.queue.{$queueId}",
            'socket_id' => '1234.5678',
        ]);

        $response->assertStatus(403);
    }
}
