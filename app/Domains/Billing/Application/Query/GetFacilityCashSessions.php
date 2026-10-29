<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Domain\Model\CashSession;
use App\Domains\Billing\Domain\ValueObject\CashSessionStatus;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The cash sessions at a facility, newest first — the supervisor / reconciler
 * worklist (20-billing-and-payments-domain.md §157; ADR-191 C6-C). `view` gates
 * on the coarse `billing.access`.
 */
#[ProtectedQuery(ability: 'view', subjectType: CashSession::class)]
final readonly class GetFacilityCashSessions
{
    public ?string $status;

    public function __construct(public string $facilityId, ?string $status = null)
    {
        if (! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('GetFacilityCashSessions::$facilityId must be a UUID (IC §3).');
        }

        if ($status !== null && ! in_array($status, CashSessionStatus::ALL, true)) {
            throw new InvalidArgumentException('GetFacilityCashSessions::$status is not a valid cash session status.');
        }

        $this->status = $status;
    }
}
