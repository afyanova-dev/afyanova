<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Query;

use App\Domains\Radiology\Contracts\Dto\RadiologyReportSummary;
use App\Domains\Radiology\Domain\Model\RadiologyReport;
use App\Domains\Radiology\Domain\RadiologyReportReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetRadiologyReportHandler
{
    public function __construct(
        private readonly RadiologyReportReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    public function __invoke(GetRadiologyReport $query): RadiologyReportSummary
    {
        $summary = $this->reader->summary($query->reportId);

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetRadiologyReport dispatched without an authenticated user (IC §6.1).');
        }

        if (! in_array('radiology.access', $this->scope->effectivePermissions($user, $summary->facilityId), true)) {
            throw NotFound::aggregate(RadiologyReport::class, $query->reportId);
        }

        return $summary;
    }
}
