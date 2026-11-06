<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Contracts\Dto\PayerContractSummary;
use App\Domains\Insurance\Domain\Exception\PayerContractNotFound;
use App\Domains\Insurance\Domain\PayerContractReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetPayerContractHandler
{
    public function __construct(
        private readonly PayerContractReader $reader,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(GetPayerContract $query): PayerContractSummary
    {
        $summary = $this->reader->summary($query->contractId);
        if ($summary->facilityId === null) {
            return $summary;
        }

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetPayerContract dispatched without an authenticated user (IC §6.1).');
        }

        if (! $this->scope->hasFacilityPermissionInContext($user, $this->context, $summary->facilityId, 'insurance.access')) {
            throw PayerContractNotFound::id($query->contractId);
        }

        return $summary;
    }
}
