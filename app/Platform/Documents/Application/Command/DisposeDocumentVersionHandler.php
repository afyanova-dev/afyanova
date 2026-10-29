<?php

declare(strict_types=1);

namespace App\Platform\Documents\Application\Command;

use App\Platform\Documents\Domain\Enum\DocumentStatus;
use App\Platform\Documents\Domain\Exception\DisposalBlockedException;
use App\Platform\Documents\Domain\Exception\DocumentNotFoundException;
use App\Platform\Documents\Domain\Port\DocumentStoragePort;
use App\Platform\Documents\Domain\Repository\DocumentRepositoryInterface;
use App\Platform\Documents\Domain\Repository\DocumentVersionRepositoryInterface;
use App\Platform\Documents\Domain\Repository\RetentionRequirementRepositoryInterface;
use Carbon\CarbonImmutable;
use Throwable;

final class DisposeDocumentVersionHandler
{
    public function __construct(
        private readonly DocumentRepositoryInterface $documentRepository,
        private readonly DocumentVersionRepositoryInterface $versionRepository,
        private readonly RetentionRequirementRepositoryInterface $retentionRepository,
        private readonly DocumentStoragePort $storagePort,
    ) {}

    public function __invoke(DisposeDocumentVersionCommand $command): void
    {
        $version = $this->versionRepository->findByIdForUpdate($command->versionId);
        if ($version === null) {
            throw new DocumentNotFoundException("Document version '{$command->versionId}' not found.");
        }

        if ($version->status === DocumentStatus::DISPOSED) {
            return; // Already disposed
        }

        // F13: Retention authority check — cannot dispose if active retention or legal hold
        $activeRequirements = $this->retentionRepository->listActiveByDocument($version->document_id);
        if ($activeRequirements !== []) {
            throw new DisposalBlockedException("Cannot dispose document version '{$command->versionId}': active retention requirements or legal holds exist.");
        }

        // Phase 1: Mark PENDING_DISPOSAL (Anti-reversion: fails closed on downloads)
        $version->markPendingDisposal();
        $this->versionRepository->save($version);

        // Phase 2: Physical byte destruction (shredding)
        $shredded = false;
        if ($version->storage_key !== null) {
            try {
                $shredded = $this->storagePort->shred($version->storage_key);
            } catch (Throwable) {
                $shredded = false;
            }
        } else {
            $shredded = true;
        }

        // Phase 3: Transition to DISPOSED or DISPOSAL_FAILED
        if (! $shredded) {
            $version->markDisposalFailed();
            $this->versionRepository->save($version);

            throw new DisposalBlockedException("Physical shredding failed for document version '{$command->versionId}'. Marked as DISPOSAL_FAILED.");
        }

        $now = CarbonImmutable::now();
        $version->markDisposed($now);
        $this->versionRepository->save($version);

        // Update parent document status if all versions disposed
        $document = $this->documentRepository->findById($version->document_id);
        if ($document !== null && $document->current_version_id === $version->id) {
            $document->updateStatus(DocumentStatus::DISPOSED);
            $this->documentRepository->save($document);
        }
    }
}
