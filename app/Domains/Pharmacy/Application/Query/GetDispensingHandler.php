<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Query;

use App\Domains\Pharmacy\Contracts\Dto\DispensingSummary;
use App\Domains\Pharmacy\Domain\DispensingReader;
use App\Domains\Pharmacy\Domain\Model\Dispensing;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetDispensingHandler
{
    public function __construct(
        private readonly DispensingReader $reader,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(GetDispensing $query): DispensingSummary
    {
        $summary = $this->reader->summary($query->dispensingId);

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetDispensing dispatched without an authenticated user (IC §6.1).');
        }

        if (! $this->scope->hasFacilityPermissionInContext($user, $this->context, $summary->facilityId, 'pharmacy.access')) {
            throw NotFound::aggregate(Dispensing::class, $query->dispensingId);
        }

        return $summary;
    }
}
