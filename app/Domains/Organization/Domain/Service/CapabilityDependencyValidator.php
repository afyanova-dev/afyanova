<?php

declare(strict_types=1);

namespace App\Domains\Organization\Domain\Service;

/**
 * Validates capability dependencies on enablement
 * (docs/00-foundation/06-facility-levels-and-capabilities.md §16–§17, ADR-013).
 *
 * A pure function — the dependency map and the facility's currently-enabled set
 * are passed in (a `CapabilityReader` fetches them). Dependencies are explicit
 * data, never controller logic, and never "HOSPITAL → enable everything".
 */
final class CapabilityDependencyValidator
{
    /**
     * The dependency codes that `$candidate` requires but which are not yet
     * enabled at the facility. Empty ⇒ the candidate may be enabled.
     *
     * @param  list<string>  $currentlyEnabled  capability codes in state ENABLED
     * @param  array<string, list<string>>  $dependencyMap  code => [required codes]
     * @return list<string>
     */
    public function missingDependencies(string $candidate, array $currentlyEnabled, array $dependencyMap): array
    {
        $required = $dependencyMap[$candidate] ?? [];

        return array_values(array_diff($required, $currentlyEnabled));
    }
}
