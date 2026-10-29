<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Command;

final readonly class UpdatePatientResult
{
    /**
     * @param  list<string>  $changedFields  coded column names that actually changed
     */
    public function __construct(
        public string $patientId,
        public array $changedFields,
        public bool $provisionalCleared,
    ) {}
}
