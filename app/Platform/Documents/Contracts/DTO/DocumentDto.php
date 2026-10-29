<?php

declare(strict_types=1);

namespace App\Platform\Documents\Contracts\DTO;

use App\Platform\Documents\Domain\Enum\ConfidentialityLevel;
use App\Platform\Documents\Domain\Enum\DocumentKind;
use App\Platform\Documents\Domain\Enum\DocumentStatus;
use App\Platform\Documents\Domain\Enum\DocumentType;
use Carbon\CarbonImmutable;

final readonly class DocumentDto
{
    /**
     * @param  list<DocumentVersionDto>  $versions
     */
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $facilityId,
        public DocumentKind $kind,
        public DocumentType $docType,
        public ConfidentialityLevel $confidentialityLevel,
        public string $sourceDomain,
        public string $sourceEntityRef,
        public DocumentStatus $status,
        public CarbonImmutable $createdAt,
        public CarbonImmutable $updatedAt,
        public ?string $encounterId = null,
        public ?string $patientId = null,
        public ?string $currentVersionId = null,
        public ?string $supersededBy = null,
        public array $versions = [],
    ) {}
}
