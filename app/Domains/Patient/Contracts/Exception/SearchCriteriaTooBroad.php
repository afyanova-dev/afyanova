<?php

declare(strict_types=1);

namespace App\Domains\Patient\Contracts\Exception;

use App\Shared\Domain\DomainException;

class SearchCriteriaTooBroad extends DomainException
{
    public static function noUsableCriterion(): self
    {
        return new self('Provide at least one of: exact patient number, exact national/insurance identifier, phone, or a name fragment.');
    }

    public static function nameFragmentTooShort(int $minimum): self
    {
        return new self(sprintf('A name-fragment search needs at least %d characters.', $minimum));
    }

    public function errorCode(): string
    {
        return 'search_criteria_too_broad';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
