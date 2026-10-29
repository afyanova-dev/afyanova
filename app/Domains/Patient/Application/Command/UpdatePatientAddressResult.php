<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Command;

final readonly class UpdatePatientAddressResult
{
    public function __construct(
        public string $addressId,
        public bool $wasCreated,
    ) {}
}
