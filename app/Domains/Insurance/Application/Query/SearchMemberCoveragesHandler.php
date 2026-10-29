<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Contracts\Dto\MemberCoverageSummary;
use App\Domains\Insurance\Domain\MemberCoverageReader;

final class SearchMemberCoveragesHandler
{
    public function __construct(private readonly MemberCoverageReader $reader) {}

    /**
     * @return list<MemberCoverageSummary>
     */
    public function __invoke(SearchMemberCoverages $query): array
    {
        return $this->reader->search($query->planId, $query->payerId, $query->status);
    }
}
