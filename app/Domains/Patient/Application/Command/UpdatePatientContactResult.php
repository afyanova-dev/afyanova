<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Command;

final readonly class UpdatePatientContactResult
{
    public function __construct(
        public string $contactId,
        public bool $wasCreated,
    ) {}
}
