<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain\Exception;

use App\Domains\Patient\Contracts\Exception\SearchCriteriaTooBroad as SearchCriteriaTooBroadContract;

/**
 * A `SearchPatients` query supplied no usable criterion, only a name fragment
 * shorter than the minimum, or only a date of birth (10-patient-domain.md §52,
 * §128; ADR-123, ADR-129). Paginated enumeration is still enumeration — the
 * query needs a specificity floor. Maps to HTTP 422 (the first 422 in the
 * codebase — ADR-129).
 */
final class SearchCriteriaTooBroad extends SearchCriteriaTooBroadContract
{
    public static function noUsableCriterion(): self
    {
        return new self('Provide at least one of: exact patient number, exact national/insurance identifier, phone, or a name fragment.');
    }

    public static function nameFragmentTooShort(int $minimum): self
    {
        return new self(sprintf('A name-fragment search needs at least %d characters.', $minimum));
    }
}
