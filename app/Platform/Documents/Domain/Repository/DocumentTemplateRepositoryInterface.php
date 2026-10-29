<?php

declare(strict_types=1);

namespace App\Platform\Documents\Domain\Repository;

use App\Platform\Documents\Domain\Enum\DocumentType;
use App\Platform\Documents\Domain\Model\DocumentTemplate;

interface DocumentTemplateRepositoryInterface
{
    public function save(DocumentTemplate $template): void;

    public function findByIdAndVersion(string $id, string $version): ?DocumentTemplate;

    public function findActiveById(string $id): ?DocumentTemplate;

    /**
     * @return list<DocumentTemplate>
     */
    public function listByDocType(DocumentType $docType): array;
}
