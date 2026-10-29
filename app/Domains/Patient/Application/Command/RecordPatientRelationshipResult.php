<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Command;

final readonly class RecordPatientRelationshipResult
{
    public function __construct(
        public string $relationshipId,
    ) {}
}
