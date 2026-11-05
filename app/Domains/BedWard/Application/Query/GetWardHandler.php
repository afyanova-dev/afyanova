<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Query;

use App\Domains\BedWard\Contracts\Dto\WardSummary;
use App\Domains\BedWard\Domain\Model\Ward;
use App\Domains\BedWard\Domain\WardReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetWardHandler
{
    public function __construct(
        private readonly WardReader $reader,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(GetWard $query): WardSummary
    {
        $summary = $this->reader->summary($query->wardId);

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetWard dispatched without an authenticated user (IC §6.1).');
        }

        if (! $this->scope->hasFacilityPermissionInContext($user, $this->context, $summary->facilityId, 'bed_ward.access')) {
            throw NotFound::aggregate(Ward::class, $query->wardId);
        }

        return $summary;
    }
}
