<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Query;

use App\Domains\Procurement\Contracts\Dto\RfqSummary;
use App\Domains\Procurement\Domain\Model\Rfq;
use App\Domains\Procurement\Domain\RfqReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class SearchRfqsHandler
{
    public function __construct(
        private readonly RfqReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    /**
     * @return list<RfqSummary>
     */
    public function __invoke(SearchRfqs $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('SearchRfqs dispatched without an authenticated user (IC §6.1).');
        }

        if (! $this->scope->hasFacilityPermission($user, $query->facilityId, 'procurement.access')) {
            throw NotFound::aggregate(Rfq::class, $query->facilityId);
        }

        return $this->reader->searchForFacility($query->facilityId, $query->status);
    }
}
