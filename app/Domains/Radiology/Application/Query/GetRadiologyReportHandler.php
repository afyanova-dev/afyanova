<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Query;

use App\Domains\Radiology\Contracts\Dto\RadiologyReportSummary;
use App\Domains\Radiology\Domain\Model\RadiologyReport;
use App\Domains\Radiology\Domain\RadiologyReportReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetRadiologyReportHandler
{
    public function __construct(
        private readonly RadiologyReportReader $reader,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(GetRadiologyReport $query): RadiologyReportSummary
    {
        $summary = $this->reader->summary($query->reportId);

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetRadiologyReport dispatched without an authenticated user (IC §6.1).');
        }

        if (! $this->scope->hasFacilityPermissionInContext($user, $this->context, $summary->facilityId, 'radiology.access')) {
            throw NotFound::aggregate(RadiologyReport::class, $query->reportId);
        }

        return $summary;
    }
}
