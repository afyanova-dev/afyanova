<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Contracts\Dto\AdjudicationSummary;
use App\Domains\Insurance\Domain\AdjudicationReader;

/**
 * Orchestrates `GetAdjudication` (ADR-205).
 */
final class GetAdjudicationHandler
{
    public function __construct(private readonly AdjudicationReader $reader) {}

    public function __invoke(GetAdjudication $query): AdjudicationSummary
    {
        return $this->reader->getById($query->adjudicationId);
    }
}
