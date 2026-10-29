<?php

declare(strict_types=1);

namespace App\Platform\Documents\Application\Command;

use App\Platform\Documents\Domain\Enum\ConfidentialityLevel;
use App\Platform\Documents\Domain\Enum\DocumentType;

final readonly class UploadDocumentCommand
{
    public function __construct(
        public string $tenantId,
        public string $facilityId,
        public DocumentType $docType,
        public ConfidentialityLevel $confidentialityLevel,
        public string $sourceDomain,
        public string $sourceEntityRef,
        public string $fileContents,
        public string $mimeType,
        public string $fileName,
        public string $actorId,
        public ?string $encounterId = null,
        public ?string $patientId = null,
    ) {}
}
