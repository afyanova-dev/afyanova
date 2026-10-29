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

final class GetRfqHandler
{
    public function __construct(
        private readonly RfqReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    public function __invoke(GetRfq $query): RfqSummary
    {
        $summary = $this->reader->summary($query->rfqId);

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetRfq dispatched without an authenticated user (IC §6.1).');
        }

        if (! $this->scope->hasFacilityPermission($user, $summary->facilityId, 'procurement.access')) {
            throw NotFound::aggregate(Rfq::class, $query->rfqId);
        }

        return $summary;
    }
}
