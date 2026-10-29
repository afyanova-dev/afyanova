<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Contracts\Dto;

/**
 * Claim readiness explainability summary (21-insurance-and-payer-domain.md §217, §218; ADR-204).
 * NOT #[TypeScript].
 */
final readonly class ClaimReadinessSummary
{
    /**
     * @param  list<string>  $blockingReasons
     * @param  list<string>  $missingInformation
     */
    public function __construct(
        public string $claimId,
        public string $readinessStatus,
        public bool $isReady,
        public array $blockingReasons,
        public array $missingInformation,
        public string $checkedAt,
    ) {}
}
