<?php

declare(strict_types=1);

namespace App\Platform\Documents\Domain\Repository;

use App\Platform\Documents\Domain\Model\DocumentSignature;

interface SignatureRepositoryInterface
{
    public function save(DocumentSignature $signature): void;

    public function findById(string $id): ?DocumentSignature;

    /**
     * @return list<DocumentSignature>
     */
    public function listByVersion(string $versionId): array;
}
