<?php

declare(strict_types=1);

namespace App\Domains\Organization\Infrastructure\Reader;

use App\Domains\Organization\Domain\Enum\CapabilityState;
use Illuminate\Support\Facades\DB;

/**
 * Read-side over the capability registry (global) and per-facility enablement
 * (docs/00-foundation/06-facility-levels-and-capabilities.md §51, §105–§106).
 * The one place `org_capabilities` / `org_capability_dependencies` are queried
 * outside seeders. Eloquent/DB is permitted here (IC §9).
 */
final class CapabilityReader
{
    /** @return array<string, list<string>> capability code => required codes */
    public function dependencyMap(): array
    {
        $map = [];
        foreach (DB::table('org_capability_dependencies')->get(['capability_code', 'depends_on_code']) as $row) {
            $map[(string) $row->capability_code][] = (string) $row->depends_on_code;
        }

        return $map;
    }

    /** @return list<string> capability codes ENABLED at the facility */
    public function enabledCodes(string $facilityId): array
    {
        return array_values(DB::table('org_facility_capabilities')
            ->where('facility_id', $facilityId)
            ->where('state', CapabilityState::Enabled->value)
            ->pluck('capability_code')
            ->map(static fn ($c): string => (string) $c)
            ->all());
    }

    /**
     * @param  list<string>  $facilityIds
     * @return array<string, list<string>> facility ID => enabled capability codes
     */
    public function enabledCodesForFacilities(array $facilityIds): array
    {
        if ($facilityIds === []) {
            return [];
        }

        $capabilities = [];
        foreach (DB::table('org_facility_capabilities')
            ->whereIn('facility_id', $facilityIds)
            ->where('state', CapabilityState::Enabled->value)
            ->get(['facility_id', 'capability_code']) as $row) {
            $capabilities[(string) $row->facility_id][] = (string) $row->capability_code;
        }

        return $capabilities;
    }

    /** @return list<array{code: string, name: string, category: string}> */
    public function registry(): array
    {
        return array_values(DB::table('org_capabilities')
            ->where('status', 'ACTIVE')
            ->orderBy('category')->orderBy('code')
            ->get(['code', 'name', 'category'])
            ->map(static fn ($r): array => [
                'code' => (string) $r->code,
                'name' => (string) $r->name,
                'category' => (string) $r->category,
            ])
            ->all());
    }

    public function isRegistered(string $capabilityCode): bool
    {
        return DB::table('org_capabilities')->where('code', $capabilityCode)->exists();
    }
}
