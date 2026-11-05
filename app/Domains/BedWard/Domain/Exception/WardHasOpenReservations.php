<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Domain\Exception;

use App\Shared\Domain\DomainException;

final class WardHasOpenReservations extends DomainException
{
    public static function forWard(string $wardId): self
    {
        return new self(sprintf(
            'Ward [%s] cannot be decommissioned while it has open bed reservations.',
            $wardId,
        ));
    }

    public function errorCode(): string
    {
        return 'ward_has_open_reservations';
    }
}
