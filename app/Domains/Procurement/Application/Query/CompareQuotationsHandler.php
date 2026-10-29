<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Query;

use App\Domains\Procurement\Contracts\Dto\QuotationComparison;
use App\Domains\Procurement\Domain\Model\Quotation;
use App\Domains\Procurement\Domain\QuotationReader;
use App\Domains\Procurement\Domain\RfqReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class CompareQuotationsHandler
{
    public function __construct(
        private readonly QuotationReader $reader,
        private readonly RfqReader $rfqs,
        private readonly ScopeChecker $scope,
    ) {}

    public function __invoke(CompareQuotations $query): QuotationComparison
    {
        $facilityId = $this->rfqs->facilityOf($query->rfqId);

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('CompareQuotations dispatched without an authenticated user (IC §6.1).');
        }

        if (! in_array('procurement.quotations.view', $this->scope->effectivePermissions($user, $facilityId), true)) {
            throw NotFound::aggregate(Quotation::class, $query->rfqId);
        }

        return $this->reader->compareForRfq($query->rfqId);
    }
}
