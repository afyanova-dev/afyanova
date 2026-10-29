<?php

declare(strict_types=1);

namespace App\Platform\Documents\Application\Query;

use App\Platform\Documents\Contracts\DTO\RetentionStatusDto;
use App\Platform\Documents\Domain\Enum\RetentionImposedBy;
use App\Platform\Documents\Domain\Exception\DocumentNotFoundException;
use App\Platform\Documents\Domain\Repository\DocumentRepositoryInterface;
use App\Platform\Documents\Domain\Repository\RetentionRequirementRepositoryInterface;

final class GetRetentionStatusHandler
{
    public function __construct(
        private readonly DocumentRepositoryInterface $documentRepository,
        private readonly RetentionRequirementRepositoryInterface $retentionRepository,
    ) {}

    public function __invoke(GetRetentionStatusQuery $query): RetentionStatusDto
    {
        $document = $this->documentRepository->findById($query->documentId);
        if ($document === null) {
            throw new DocumentNotFoundException("Document '{$query->documentId}' not found.");
        }

        $activeRequirements = $this->retentionRepository->listActiveByDocument($query->documentId);

        $isUnderLegalHold = false;
        $isRetained = count($activeRequirements) > 0;
        $hasIndefinite = false;
        $maxRetainUntil = null;

        foreach ($activeRequirements as $req) {
            if ($req->imposed_by === RetentionImposedBy::LEGAL_HOLD) {
                $isUnderLegalHold = true;
            }
            if ($req->indefinite) {
                $hasIndefinite = true;
            }
            if ($req->retain_until !== null) {
                if ($maxRetainUntil === null || $req->retain_until->isAfter($maxRetainUntil)) {
                    $maxRetainUntil = $req->retain_until;
                }
            }
        }

        $canBeDisposed = (! $isRetained && ! $isUnderLegalHold);
        $earliestDisposalDate = $hasIndefinite ? null : $maxRetainUntil;

        return new RetentionStatusDto(
            documentId: $document->id,
            isUnderLegalHold: $isUnderLegalHold,
            isRetained: $isRetained,
            canBeDisposed: $canBeDisposed,
            activeRequirementsCount: count($activeRequirements),
            earliestDisposalDate: $earliestDisposalDate,
        );
    }
}
