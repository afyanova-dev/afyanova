<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain\Exception;

use App\Shared\Domain\DomainException;

final class DispensingPaymentNotCleared extends DomainException
{
    public function __construct(string $dispensingId)
    {
        parent::__construct(sprintf(
            'Medication for dispensing [%s] cannot be handed over until its bill is paid or confirmed insurance coverage is in place.',
            $dispensingId,
        ));
    }

    public function errorCode(): string
    {
        return 'dispensing_payment_not_cleared';
    }
}
