<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Query;

use App\Domains\Radiology\Contracts\Dto\ImagingStudySummary;
use App\Domains\Radiology\Domain\ImagingStudyReader;
use App\Domains\Radiology\Domain\Model\ImagingStudy;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetOrderStudiesHandler
{
    public function __construct(
        private readonly ImagingStudyReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    /**
     * @return list<ImagingStudySummary>
     */
    public function __invoke(GetOrderStudies $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetOrderStudies dispatched without an authenticated user (IC §6.1).');
        }

        $studies = $this->reader->forOrder($query->orderId);

        if ($studies === []) {
            return []; // the #[ProtectedQuery] `view` gate already confirmed radiology.access
        }

        if (! in_array('radiology.access', $this->scope->effectivePermissions($user, $studies[0]->facilityId), true)) {
            throw NotFound::aggregate(ImagingStudy::class, $query->orderId);
        }

        return $studies;
    }
}
