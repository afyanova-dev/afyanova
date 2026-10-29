<?php

declare(strict_types=1);

namespace App\Shared\Capability;

use Illuminate\Support\Facades\DB;

/**
 * The `FacilityCapabilityService` (docs/00-foundation/06-facility-levels-and-capabilities.md
 * §118) — the ONE coherent abstraction for "does this facility operate this?".
 * Stays in the shared kernel (IC §12 names it here); it only *reads* the `org_`
 * tables (no code import of `App\Domains`), so the arch boundary holds.
 *
 * Capability, never facility type (ADR-005 — `facility_type ===` is grep-forbidden).
 * Capability is not authorization (ADR-006): a workflow needs an enabled
 * capability AND an authorized user.
 *
 * Effective operational availability (§107–§108):
 *   facility ACTIVE ∧ capability ENABLED ∧ (service available, if named)
 * — the user/policy factors are applied by the authorization layer, not here.
 */
final class FacilityCapabilities
{
    /** @var array<string, array<string, string>>  facilityId => (capabilityCode => state) */
    private array $stateCache = [];

    /** @var array<string, bool>  facilityId => is ACTIVE */
    private array $facilityActive = [];

    public function has(string $facilityId, string $capability): bool
    {
        return $this->state($facilityId, $capability) === 'ENABLED';
    }

    public function state(string $facilityId, string $capability): ?string
    {
        return $this->states($facilityId)[$capability] ?? null;
    }

    /** @return list<string>  capability codes in state ENABLED */
    public function enabledFor(string $facilityId): array
    {
        return array_keys(array_filter($this->states($facilityId), static fn (string $s): bool => $s === 'ENABLED'));
    }

    /** @return array<string, list<string>>  category => enabled capability codes (§70) */
    public function groupsFor(string $facilityId): array
    {
        $enabled = $this->enabledFor($facilityId);
        if ($enabled === []) {
            return [];
        }

        $groups = [];
        foreach (DB::table('org_capabilities')->whereIn('code', $enabled)->get(['code', 'category']) as $row) {
            $groups[(string) $row->category][] = (string) $row->code;
        }

        return $groups;
    }

    public function serviceAvailable(string $facilityId, string $serviceCode): bool
    {
        return DB::table('org_facility_services')
            ->where('facility_id', $facilityId)
            ->where('service_code', $serviceCode)
            ->where('available', true)
            ->exists();
    }

    /**
     * The §107 chain minus the user/policy factors.
     */
    public function canOperate(string $facilityId, string $capability, ?string $serviceCode = null): bool
    {
        if (! $this->facilityIsActive($facilityId)) {
            return false;
        }

        if (! $this->has($facilityId, $capability)) {
            return false;
        }

        return $serviceCode === null || $this->serviceAvailable($facilityId, $serviceCode);
    }

    /** Called after an enable/suspend/disable so a subsequent read is fresh (§119). */
    public function flush(?string $facilityId = null): void
    {
        if ($facilityId === null) {
            $this->stateCache = [];
            $this->facilityActive = [];

            return;
        }

        unset($this->stateCache[$facilityId], $this->facilityActive[$facilityId]);
    }

    /** @return array<string, string> capabilityCode => state */
    private function states(string $facilityId): array
    {
        return $this->stateCache[$facilityId] ??= DB::table('org_facility_capabilities')
            ->where('facility_id', $facilityId)
            ->pluck('state', 'capability_code')
            ->map(static fn ($s): string => (string) $s)
            ->all();
    }

    private function facilityIsActive(string $facilityId): bool
    {
        return $this->facilityActive[$facilityId] ??= DB::table('org_facilities')
            ->where('id', $facilityId)
            ->where('status', 'ACTIVE')
            ->exists();
    }
}
