<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Query;

use App\Domains\Procurement\Contracts\Dto\QuotationSummary;
use App\Domains\Procurement\Domain\Model\Quotation;
use App\Domains\Procurement\Domain\QuotationReader;
use App\Domains\Procurement\Domain\RfqReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetRfqQuotationsHandler
{
    public function __construct(
        private readonly QuotationReader $reader,
        private readonly RfqReader $rfqs,
        private readonly ScopeChecker $scope,
    ) {}

    /**
     * @return list<QuotationSummary>
     */
    public function __invoke(GetRfqQuotations $query): array
    {
        $facilityId = $this->rfqs->facilityOf($query->rfqId);

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetRfqQuotations dispatched without an authenticated user (IC §6.1).');
        }

        // §101 / §208 — the confidentiality tier: NOT satisfied by `procurement.access`.
        if (! in_array('procurement.quotations.view', $this->scope->effectivePermissions($user, $facilityId), true)) {
            throw NotFound::aggregate(Quotation::class, $query->rfqId);
        }

        return $this->reader->listForRfq($query->rfqId);
    }
}
