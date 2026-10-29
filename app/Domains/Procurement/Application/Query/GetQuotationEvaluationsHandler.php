<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Query;

use App\Domains\Procurement\Contracts\Dto\RfqEvaluationOverview;
use App\Domains\Procurement\Domain\Model\Quotation;
use App\Domains\Procurement\Domain\QuotationEvaluationReader;
use App\Domains\Procurement\Domain\RfqReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * `GetQuotationEvaluations` handler (ADR-174 D-D / D-E).
 *
 * The `#[ProtectedQuery]` coarse check is `QuotationPolicy::view`
 * (`procurement.quotations.view`). Here: resolve the RFQ facility → refine the
 * key at that facility (ADR-133 — `NotFound`, never an authz disclosure) →
 * delegate to the reader, which applies the §207 independence filter against
 * the acting user.
 */
final class GetQuotationEvaluationsHandler
{
    public function __construct(
        private readonly QuotationEvaluationReader $reader,
        private readonly RfqReader $rfqs,
        private readonly ScopeChecker $scope,
    ) {}

    public function __invoke(GetQuotationEvaluations $query): RfqEvaluationOverview
    {
        $facilityId = $this->rfqs->facilityOf($query->rfqId);

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetQuotationEvaluations dispatched without an authenticated user (IC §6.1).');
        }

        // §207 / §208 — the confidentiality tier: NOT satisfied by `procurement.access`.
        if (! in_array('procurement.quotations.view', $this->scope->effectivePermissions($user, $facilityId), true)) {
            throw NotFound::aggregate(Quotation::class, $query->rfqId);
        }

        return $this->reader->overviewForRfq($query->rfqId, (string) $user->id);
    }
}
