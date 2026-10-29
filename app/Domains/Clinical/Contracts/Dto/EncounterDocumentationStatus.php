<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Contracts\Dto;

final readonly class EncounterDocumentationStatus
{
    public function __construct(
        public string $encounterId,
        public ?bool $vitalsRecorded,
        public ?bool $triageRecorded,
    ) {}
}
