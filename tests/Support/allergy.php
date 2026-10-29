<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\RecordAllergy;
use App\Domains\Clinical\Application\Command\RecordAllergyResult;
use App\Domains\Clinical\Domain\Model\Allergy;
use App\Domains\Clinical\Domain\ValueObject\AllergySubstance;
use App\Domains\Clinical\Domain\ValueObject\AllergyVerificationStatus;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 |----------------------------------------------------------------------
 | Clinical Allergy (PHASE 6 / C.5 — ADR-141) test helper
 |----------------------------------------------------------------------
 | A NEW support file — NOT a modification of tests/Support/clinical.php.
 | The C.5 authorized existing-file surface is exactly
 | `permissions.php` + `ClinicalServiceProvider.php`; this file therefore
 | carries the C.5 test tooling instead of extending the shared C.1/C.2/C.3
 | helper. It is `require_once`'d at the top of each C.5 feature test.
 |
 | `allergyFixture()` = `clinicalFixture()` with the two
 | `clinical.allergies.*` keys granted to the fixture's role.
 */

if (! function_exists('allergyFixture')) {
    /**
     * `clinicalFixture()` + the C.5 `clinical.allergies.{read,record}` keys
     * granted to the fixture's role.
     *
     * @param  list<string>|null  $allergyPermissions  which C.5 keys to grant (default: both)
     * @return array{tenant: mixed, organization: mixed, facility: mixed, user: mixed, role_id: string}
     */
    function allergyFixture(?array $allergyPermissions = null): array
    {
        $allergyPermissions ??= ['clinical.allergies.read', 'clinical.allergies.record'];

        $fixture = clinicalFixture();
        grantAllergyPermissionsToRole((string) $fixture['tenant']->id, $fixture['role_id'], $allergyPermissions);

        return $fixture;
    }

    /**
     * Grant additional permission keys to an existing role.
     *
     * @param  list<string>  $keys
     */
    function grantAllergyPermissionsToRole(string $tenantId, string $roleId, array $keys): void
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
     * Dispatch `RecordAllergy` (C.5 — ADR-141) through the real CommandBus and
     * return the new allergy id.
     *
     * @param  array<string, mixed>  $overrides
     */
    function clinicalRecordAllergy(
        string $tenantId,
        ?string $facilityId,
        string $patientId,
        string $encounterId,
        string $actorId,
        array $overrides = [],
    ): string {
        $allergyId = $overrides['allergyId'] ?? (string) Str::uuid7();

        $substance = $overrides['substance'] ?? AllergySubstance::of(
            name: $overrides['substanceName'] ?? 'Penicillin',
            code: array_key_exists('substanceCode', $overrides) ? $overrides['substanceCode'] : '764146007',
            codeSystem: array_key_exists('substanceCodeSystem', $overrides) ? $overrides['substanceCodeSystem'] : 'SNOMED-CT',
        );

        /** @var RecordAllergyResult $result */
        $result = TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(
            new RecordAllergy(
                allergyId: $allergyId,
                actorId: $actorId,
                patientId: $patientId,
                encounterId: $encounterId,
                category: $overrides['category'] ?? 'DRUG',
                substance: $substance,
                reactionDescription: array_key_exists('reactionDescription', $overrides) ? $overrides['reactionDescription'] : 'Urticaria',
                severity: array_key_exists('severity', $overrides) ? $overrides['severity'] : 'MODERATE',
                verificationStatus: $overrides['verificationStatus'] ?? AllergyVerificationStatus::SUSPECTED,
                authoredContext: $overrides['authoredContext'] ?? Allergy::AUTHORED_CONTEXT_DEFAULT,
                onsetDate: $overrides['onsetDate'] ?? null,
                occurredAt: $overrides['occurredAt'] ?? null,
                reason: $overrides['reason'] ?? null,
            ),
        ));

        return $result->allergyId;
    }
}
