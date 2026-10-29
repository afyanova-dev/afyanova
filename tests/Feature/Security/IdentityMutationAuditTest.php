<?php

declare(strict_types=1);

use App\Models\User;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('audits role creation, permission grants, and status changes with each mutation', function (): void {
    ['tenant' => $tenant] = makeTenantWithUser();

    expect(Artisan::call('afyanova:identity:role', [
        'action' => 'create',
        '--tenant' => $tenant->id,
        '--key' => 'audit_test_role',
        '--name' => 'Audit Test Role',
        '--scope' => 'ORG',
    ]))->toBe(0);

    expect(Artisan::call('afyanova:identity:role', [
        'action' => 'grant',
        '--tenant' => $tenant->id,
        '--key' => 'audit_test_role',
        '--permission' => ['identity.users.read'],
    ]))->toBe(0);

    expect(Artisan::call('afyanova:identity:role', [
        'action' => 'status',
        '--tenant' => $tenant->id,
        '--key' => 'audit_test_role',
        '--to' => 'SUSPENDED',
    ]))->toBe(0);

    $events = TenantContextScope::run($tenant->id, null, fn () => DB::table('shared_audit_log')
        ->where('action', 'like', 'identity.role.%')
        ->orderBy('created_at')
        ->get());

    expect($events->pluck('action')->all())->toContain(
        'identity.role.created',
        'identity.role.permissions_granted',
        'identity.role.status_changed',
    );
    $grant = $events->firstWhere('action', 'identity.role.permissions_granted');
    expect(json_decode($grant->metadata, true, 512, JSON_THROW_ON_ERROR))->toMatchArray([
        'role' => 'audit_test_role',
        'permissions' => ['identity.users.read'],
        'permissions_count' => 1,
        'permissions_truncated' => false,
    ]);
});

it('audits assignment grants and revocations against the assigned facility', function (): void {
    ['tenant' => $tenant, 'facility' => $facility] = makeTenantWithUser();
    $targetUser = User::factory()->forTenant($tenant->id)->create();

    expect(Artisan::call('afyanova:identity:assign', [
        'action' => 'grant',
        '--tenant' => $tenant->id,
        '--user' => $targetUser->id,
        '--role' => 'test_role',
        '--facility' => $facility->id,
    ]))->toBe(0);

    $assignment = TenantContextScope::run($tenant->id, null, fn () => DB::table('idn_access_assignments')
        ->where('user_id', $targetUser->id)
        ->where('facility_id', $facility->id)
        ->first());

    expect($assignment)->not->toBeNull();
    expect(Artisan::call('afyanova:identity:assign', [
        'action' => 'revoke',
        '--tenant' => $tenant->id,
        '--assignment' => $assignment->id,
    ]))->toBe(0);

    $events = TenantContextScope::run($tenant->id, null, fn () => DB::table('shared_audit_log')
        ->whereIn('action', ['identity.assignment.grant', 'identity.assignment.revoke'])
        ->orderBy('action')
        ->get());

    expect($events)->toHaveCount(2)
        ->and($events->pluck('facility_id')->unique()->all())->toBe([$facility->id])
        ->and($events->pluck('actor_id')->filter()->all())->toBe([])
        ->and($events->pluck('target_id')->unique()->all())->toBe([$assignment->id]);
});

it('audits staff identity changes without copying staff numbers into audit metadata', function (): void {
    ['tenant' => $tenant, 'user' => $user] = makeTenantWithUser();

    expect(Artisan::call('afyanova:identity:staff', [
        '--tenant' => $tenant->id,
        '--user' => $user->id,
        '--staff-number' => 'PRIVATE-STAFF-NUMBER',
    ]))->toBe(0);

    expect(Artisan::call('afyanova:identity:staff', [
        '--tenant' => $tenant->id,
        '--user' => $user->id,
        '--not-staff' => true,
    ]))->toBe(0);

    $events = TenantContextScope::run($tenant->id, null, fn () => DB::table('shared_audit_log')
        ->where('action', 'identity.staff_identity.updated')
        ->orderBy('created_at')
        ->get());

    expect($events)->toHaveCount(2)
        ->and(json_decode($events[0]->metadata, true, 512, JSON_THROW_ON_ERROR))->toBe(['is_staff' => true])
        ->and(json_decode($events[1]->metadata, true, 512, JSON_THROW_ON_ERROR))->toBe(['is_staff' => false])
        ->and($events->pluck('target_id')->unique()->all())->toBe([$user->id])
        ->and($events->pluck('facility_id')->unique()->all())->toBe([null]);
});
