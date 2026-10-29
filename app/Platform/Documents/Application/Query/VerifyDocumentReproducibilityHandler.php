<?php

declare(strict_types=1);

namespace App\Platform\Documents\Application\Query;

use App\Platform\Documents\Contracts\DTO\ReproducibilityReportDto;
use App\Platform\Documents\Domain\Exception\DocumentNotFoundException;
use App\Platform\Documents\Domain\Port\DocumentTemplateRendererPort;
use App\Platform\Documents\Domain\Repository\DocumentTemplateRepositoryInterface;
use App\Platform\Documents\Domain\Repository\DocumentVersionRepositoryInterface;
use Carbon\CarbonImmutable;
use RuntimeException;

final class VerifyDocumentReproducibilityHandler
{
    public function __construct(
        private readonly DocumentVersionRepositoryInterface $versionRepository,
        private readonly DocumentTemplateRepositoryInterface $templateRepository,
        private readonly DocumentTemplateRendererPort $renderer,
    ) {}

    public function __invoke(VerifyDocumentReproducibilityQuery $query): ReproducibilityReportDto
    {
        $version = $this->versionRepository->findById($query->versionId);
        if ($version === null) {
            throw new DocumentNotFoundException("Document version '{$query->versionId}' not found.");
        }

        if ($version->template_id === null || $version->template_version === null || $version->generation_payload === null) {
            throw new RuntimeException("Version '{$version->id}' is not a generated document and cannot be checked for reproducibility.");
        }

        $template = $this->templateRepository->findByIdAndVersion($version->template_id, $version->template_version);
        if ($template === null) {
            throw new DocumentNotFoundException("Template '{$version->template_id}' version '{$version->template_version}' not found.");
        }

        // F10: Strictly read-only re-render
        $renderedBytes = $this->renderer->render($template, $version->generation_payload);
        $recomputedHash = hash('sha256', $renderedBytes);
        $isReproducible = ($recomputedHash === $version->content_hash);

        return new ReproducibilityReportDto(
            versionId: $version->id,
            documentId: $version->document_id,
            isReproducible: $isReproducible,
            storedHash: $version->content_hash,
            recomputedHash: $recomputedHash,
            generationContextHash: $version->generation_context_hash ?? '',
            evaluatedAt: CarbonImmutable::now(),
        );
    }
}
