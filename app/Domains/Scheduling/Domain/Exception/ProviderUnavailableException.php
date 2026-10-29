<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Domain\Exception;

use DomainException;

final class ProviderUnavailableException extends DomainException
{
    public static function onLeave(string $providerRef, string $date): self
    {
        return new self("Provider [{$providerRef}] is on approved leave on [{$date}].");
    }

    public static function notFound(string $providerRef): self
    {
        return new self("Provider [{$providerRef}] does not exist in HR & Workforce.");
    }
}
