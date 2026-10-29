<?php

declare(strict_types=1);

namespace App\Platform\Integration\Contracts\Dto;

final readonly class FileImportIngestDto
{
    /**
     * @param  list<array<string, mixed>>  $records
     */
    public function __construct(
        public string $tenantId,
        public string $connectorId,
        public string $filename,
        public int $byteSize,
        public array $records,
        public ?string $externalRef = null,
    ) {}
}
