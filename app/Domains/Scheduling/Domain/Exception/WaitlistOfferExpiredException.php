<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Domain\Exception;

use DomainException;

final class WaitlistOfferExpiredException extends DomainException
{
    public static function forId(string $id): self
    {
        return new self("The slot offer for waitlist entry [{$id}] has expired.");
    }
}
