<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Infrastructure\Sequence;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Allocates the next tenant-scoped, human-readable encounter number (ADR-115) —
 * `ENC-%08d`, tenant-wide unique.
 *
 * The raw counter SQL lives here in Infrastructure/, never in Domain/ or
 * Application/ — IC §9 confines the DB facade / query builder to Infrastructure
 * and the arch rule enforces it. (ADR-115 names the owner
 * `Encounter\Domain\Service\EncounterNumberGenerator`; the shipped Patient
 * precedent — `Infrastructure\Sequence\PatientNumberSequence` — already
 * resolved this ADR-vs-arch-rule wording in favour of Infrastructure. Same
 * class here.)
 *
 * Must run inside the CreateEncounter command transaction (Tenancy::run —
 * IC §11.3), so `app.tenant_id` is set and the counter row's RLS predicate
 * (Rls::enable, tenant-only shape — ADR-115) matches. The UPDATE takes a row
 * lock; concurrent allocations for one tenant serialize on that row (IC §9.1).
 * Written only by this class; no other module reads or writes
 * enc_encounter_number_counters (IC §2.1).
 */
final class EncounterNumberSequence
{
    private const PREFIX = 'ENC-';

    public function next(string $tenantId): string
    {
        DB::table('enc_encounter_number_counters')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'next_value' => 1,
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var object{allocated: int|string}|null $row */
        $row = DB::selectOne(
            'update enc_encounter_number_counters
                set next_value = next_value + 1, version = version + 1, updated_at = now()
              where tenant_id = ?
          returning next_value - 1 as allocated',
            [$tenantId],
        );

        if ($row === null) {
            throw new RuntimeException('enc_encounter_number_counters row missing for tenant after upsert (ADR-115).');
        }

        return self::PREFIX.sprintf('%08d', (int) $row->allocated);
    }
}
