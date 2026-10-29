<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Domain\Model\MemberCoverage;
use App\Domains\Insurance\Domain\ValueObject\MemberCoverageStatus;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Member coverages, optionally filtered by plan / payer / status
 * (21-insurance-and-payer-domain.md §14; ADR-199 D-C). `view` gates on the
 * coarse `insurance.access`.
 */
#[ProtectedQuery(ability: 'view', subjectType: MemberCoverage::class)]
final readonly class SearchMemberCoverages
{
    public ?string $planId;

    public ?string $payerId;

    public ?string $status;

    public function __construct(?string $planId = null, ?string $payerId = null, ?string $status = null)
    {
        if ($planId !== null && ! Str::isUuid($planId)) {
            throw new InvalidArgumentException('SearchMemberCoverages::$planId must be a UUID or null (IC §3).');
        }
        if ($payerId !== null && ! Str::isUuid($payerId)) {
            throw new InvalidArgumentException('SearchMemberCoverages::$payerId must be a UUID or null (IC §3).');
        }
        if ($status !== null && ! MemberCoverageStatus::isValid($status)) {
            throw new InvalidArgumentException('SearchMemberCoverages::$status must be ACTIVE | TERMINATED or null.');
        }

        $this->planId = $planId;
        $this->payerId = $payerId;
        $this->status = $status !== null ? strtoupper(trim($status)) : null;
    }
}
