<?php

declare(strict_types=1);

namespace App\Platform\Documents\Infrastructure\Repository;

use App\Platform\Documents\Domain\Enum\DocumentType;
use App\Platform\Documents\Domain\Model\DocumentTemplate;
use App\Platform\Documents\Domain\Repository\DocumentTemplateRepositoryInterface;

final class EloquentDocumentTemplateRepository implements DocumentTemplateRepositoryInterface
{
    public function save(DocumentTemplate $template): void
    {
        $template->save();
    }

    public function findByIdAndVersion(string $id, string $version): ?DocumentTemplate
    {
        return DocumentTemplate::query()
            ->where('id', $id)
            ->where('version', $version)
            ->first();
    }

    public function findActiveById(string $id): ?DocumentTemplate
    {
        return DocumentTemplate::query()
            ->where('id', $id)
            ->where('is_active', true)
            ->latest('version')
            ->first();
    }

    /**
     * @return list<DocumentTemplate>
     */
    public function listByDocType(DocumentType $docType): array
    {
        /** @var list<DocumentTemplate> */
        return DocumentTemplate::query()
            ->where('doc_type', $docType->value)
            ->where('is_active', true)
            ->get()
            ->all();
    }
}
