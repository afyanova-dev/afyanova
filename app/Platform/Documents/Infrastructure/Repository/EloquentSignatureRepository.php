<?php

declare(strict_types=1);

namespace App\Platform\Documents\Infrastructure\Repository;

use App\Platform\Documents\Domain\Model\DocumentSignature;
use App\Platform\Documents\Domain\Repository\SignatureRepositoryInterface;

final class EloquentSignatureRepository implements SignatureRepositoryInterface
{
    public function save(DocumentSignature $signature): void
    {
        $signature->save();
    }

    public function findById(string $id): ?DocumentSignature
    {
        return DocumentSignature::query()->whereKey($id)->first();
    }

    /**
     * @return list<DocumentSignature>
     */
    public function listByVersion(string $versionId): array
    {
        /** @var list<DocumentSignature> */
        return DocumentSignature::query()
            ->where('document_version_id', $versionId)
            ->orderBy('signed_at', 'asc')
            ->get()
            ->all();
    }
}
