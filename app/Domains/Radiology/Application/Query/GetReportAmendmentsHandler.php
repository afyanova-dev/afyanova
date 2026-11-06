<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Query;

use App\Domains\Radiology\Contracts\Dto\RadiologyReportAmendmentSummary;
use App\Domains\Radiology\Domain\Model\RadiologyReport;
use App\Domains\Radiology\Domain\RadiologyReportReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetReportAmendmentsHandler
{
    public function __construct(
        private readonly RadiologyReportReader $reader,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return list<RadiologyReportAmendmentSummary>
     */
    public function __invoke(GetReportAmendments $query): array
    {
        $summary = $this->reader->summary($query->reportId); // NotFound if unknown

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetReportAmendments dispatched without an authenticated user (IC §6.1).');
        }

        if (! $this->scope->hasFacilityPermissionInContext($user, $this->context, $summary->facilityId, 'radiology.access')) {
            throw NotFound::aggregate(RadiologyReport::class, $query->reportId);
        }

        return $this->reader->amendmentsFor($query->reportId);
    }
}
