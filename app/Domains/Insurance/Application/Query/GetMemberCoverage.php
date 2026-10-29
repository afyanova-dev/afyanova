<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Domain\Model\MemberCoverage;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one member-coverage record (21-insurance-and-payer-domain.md §14;
 * ADR-199 D-C). `view` gates on the coarse `insurance.access`.
 */
#[ProtectedQuery(ability: 'view', subjectType: MemberCoverage::class)]
final readonly class GetMemberCoverage
{
    public function __construct(public string $memberCoverageId)
    {
        if (! Str::isUuid($memberCoverageId)) {
            throw new InvalidArgumentException('GetMemberCoverage::$memberCoverageId must be a UUID (IC §3).');
        }
    }
}
