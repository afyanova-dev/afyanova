<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Domain\Exception;

use App\Shared\Domain\DomainException;

final class WardNotActive extends DomainException
{
    public static function forWard(string $wardId): self
    {
        return new self(sprintf(
            'Ward [%s] is not active; new bed setup, reservations, and assignments are unavailable.',
            $wardId,
        ));
    }

    public function errorCode(): string
    {
        return 'ward_not_active';
    }
}
