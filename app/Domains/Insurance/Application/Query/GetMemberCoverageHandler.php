<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Contracts\Dto\MemberCoverageSummary;
use App\Domains\Insurance\Domain\MemberCoverageReader;

final class GetMemberCoverageHandler
{
    public function __construct(private readonly MemberCoverageReader $reader) {}

    public function __invoke(GetMemberCoverage $query): MemberCoverageSummary
    {
        return $this->reader->summary($query->memberCoverageId);
    }
}
