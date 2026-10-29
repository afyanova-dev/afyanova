<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\AddCareTeamMember;
use App\Domains\Clinical\Application\Command\AddCareTeamMemberResult;
use App\Domains\Clinical\Domain\ValueObject\TeamRole;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 |----------------------------------------------------------------------
 | Clinical Care Team (PHASE 6 / C.6 — ADR-142) test helper
 |----------------------------------------------------------------------
 | A NEW support file — NOT a modification of tests/Support/clinical.php.
 | The C.6 authorized existing-file surface is exactly
 | `permissions.php` + `ClinicalServiceProvider.php`; this file therefore
 | carries the C.6 test tooling. It is `require_once`'d at the top of each
 | C.6 test (`tests/Support/` is not on the composer `files` autoload list
 | and is not a PHPUnit suite, so nothing else picks it up).
 |
 | `careTeamFixture()` = `clinicalFixture()` + the two
 | `clinical.care_team.{read,record}` keys granted to the fixture's role.
 */

if (! function_exists('careTeamFixture')) {
    /**
     * `clinicalFixture()` + the C.6 `clinical.care_team.{read,record}` keys
     * granted to the fixture's role.
     *
     * @param  list<string>|null  $careTeamPermissions  which C.6 keys to grant (default: both)
     * @return array{tenant: mixed, organization: mixed, facility: mixed, user: mixed, role_id: string}
     */
    function careTeamFixture(?array $careTeamPermissions = null): array
    {
        $careTeamPermissions ??= ['clinical.care_team.read', 'clinical.care_team.record'];

        $fixture = clinicalFixture();
        grantCareTeamPermissionsToRole((string) $fixture['tenant']->id, $fixture['role_id'], $careTeamPermissions);

        return $fixture;
    }

    /**
     * Grant additional permission keys to an existing role.
     *
     * @param  list<string>  $keys
     */
    function grantCareTeamPermissionsToRole(string $tenantId, string $roleId, array $keys): void
    {
        TenantContextScope::run($tenantId, null, function () use ($tenantId, $roleId, $keys): void {
            foreach ($keys as $key) {
                $permId = DB::table('idn_permissions')->where('key', $key)->value('id')
                    ?? tap((string) Str::uuid7(), fn (string $id) => DB::table('idn_permissions')->insert([
                        'id' => $id,
                        'key' => $key,
                        'layer' => str_ends_with($key, '.access') ? 'ACCESS' : 'ACTION',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]));

                $alreadyLinked = DB::table('idn_permission_role')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $permId)
                    ->exists();

                if (! $alreadyLinked) {
                    DB::table('idn_permission_role')->insert([
                        'id' => (string) Str::uuid7(),
                        'tenant_id' => $tenantId,
                        'role_id' => $roleId,
                        'permission_id' => $permId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    /**
     * Register a staff identity for a fresh user id in the tenant (so
     * `IdentityApi::isStaff()` returns true for it — ADR-142 D-E). Returns the
     * new user id.
     */
    function makeStaffUser(string $tenantId): string
    {
        $userId = (string) Str::uuid7();

        TenantContextScope::run($tenantId, null, fn () => DB::table('idn_staff_identities')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'is_staff' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return $userId;
    }

    /**
     * A user id that is NOT a staff identity (nothing seeded).
     */
    function makeNonStaffUser(): string
    {
        return (string) Str::uuid7();
    }

    /**
     * Dispatch `AddCareTeamMember` (C.6 — ADR-142) through the real CommandBus
     * and return the new membership id.
     *
     * @param  array<string, mixed>  $overrides
     */
    function clinicalAddCareTeamMember(
        string $tenantId,
        ?string $facilityId,
        string $encounterId,
        string $staffUserId,
        string $actorId,
        array $overrides = [],
    ): string {
        $membershipId = $overrides['careTeamMembershipId'] ?? (string) Str::uuid7();

        /** @var AddCareTeamMemberResult $result */
        $result = TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(
            new AddCareTeamMember(
                careTeamMembershipId: $membershipId,
                actorId: $actorId,
                encounterId: $encounterId,
                staffUserId: $staffUserId,
                teamRole: $overrides['teamRole'] ?? TeamRole::ATTENDING,
                isProviderOfRecord: $overrides['isProviderOfRecord'] ?? false,
            ),
        ));

        return $result->careTeamMembershipId;
    }
}
