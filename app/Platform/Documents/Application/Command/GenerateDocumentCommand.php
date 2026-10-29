<?php

declare(strict_types=1);

namespace App\Platform\Documents\Application\Command;

use App\Platform\Documents\Domain\Enum\ConfidentialityLevel;
use App\Platform\Documents\Domain\Enum\DocumentType;

final readonly class GenerateDocumentCommand
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $tenantId,
        public string $facilityId,
        public DocumentType $docType,
        public ConfidentialityLevel $confidentialityLevel,
        public string $sourceDomain,
        public string $sourceEntityRef,
        public string $templateId,
        public string $templateVersion,
        public array $payload,
        public string $actorId,
        public ?string $encounterId = null,
        public ?string $patientId = null,
        public ?string $format = 'html',
    ) {}
}
