<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\AddProblem;
use App\Domains\Clinical\Application\Command\AddProblemResult;
use App\Domains\Clinical\Domain\ValueObject\ProblemCode;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 |----------------------------------------------------------------------
 | Clinical Problem (PHASE 6 / C.4 — ADR-140) test helper
 |----------------------------------------------------------------------
 | A NEW support file — NOT a modification of tests/Support/clinical.php.
 | The C.4 authorized existing-file surface is exactly
 | `permissions.php` + `ClinicalServiceProvider.php`; this file therefore
 | carries the C.4 test tooling instead of extending the shared C.1/C.2/C.3
 | helper. It is `require_once`'d at the top of each C.4 feature test
 | (`tests/Support/` is not on the `composer.json` `files` autoload list and
 | not a PHPUnit test suite, so nothing else picks it up).
 |
 | `problemFixture()` = `clinicalFixture()` (the C.1 tenant/user/facility +
 | cross-domain setup keys) with the two `clinical.problems.*` keys granted to
 | the fixture's role — the C.4 clinician key set.
 */

if (! function_exists('problemFixture')) {
    /**
     * `clinicalFixture()` + the C.4 `clinical.problems.{read,record}` keys
     * granted to the fixture's role.
     *
     * @param  list<string>|null  $problemPermissions  which C.4 keys to grant (default: both)
     * @return array{tenant: mixed, organization: mixed, facility: mixed, user: mixed, role_id: string}
     */
    function problemFixture(?array $problemPermissions = null): array
    {
        $problemPermissions ??= ['clinical.problems.read', 'clinical.problems.record'];

        $fixture = clinicalFixture();
        grantPermissionsToRole((string) $fixture['tenant']->id, $fixture['role_id'], $problemPermissions);

        return $fixture;
    }

    /**
     * Grant additional permission keys to an existing role (the
     * `makeTenantWithUser` insert pattern) — registers unknown keys in
     * `idn_permissions` first, then links them to the role.
     *
     * @param  list<string>  $keys
     */
    function grantPermissionsToRole(string $tenantId, string $roleId, array $keys): void
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
     * Dispatch `AddProblem` (C.4 — ADR-140) through the real CommandBus and
     * return the new problem id.
     *
     * @param  array<string, mixed>  $overrides
     */
    function clinicalAddProblem(
        string $tenantId,
        ?string $facilityId,
        string $patientId,
        string $encounterId,
        string $actorId,
        array $overrides = [],
    ): string {
        $problemId = $overrides['problemId'] ?? (string) Str::uuid7();

        /** @var AddProblemResult $result */
        $result = TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(
            new AddProblem(
                problemId: $problemId,
                actorId: $actorId,
                patientId: $patientId,
                encounterId: $encounterId,
                coding: $overrides['coding'] ?? ProblemCode::of(
                    code: $overrides['code'] ?? 'I10',
                    codeSystem: $overrides['codeSystem'] ?? 'ICD-10',
                ),
                description: $overrides['description'] ?? 'Essential (primary) hypertension',
                onsetDate: $overrides['onsetDate'] ?? null,
                sourceDiagnosisId: $overrides['sourceDiagnosisId'] ?? null,
                occurredAt: $overrides['occurredAt'] ?? null,
                reason: $overrides['reason'] ?? null,
            ),
        ));

        return $result->problemId;
    }
}
