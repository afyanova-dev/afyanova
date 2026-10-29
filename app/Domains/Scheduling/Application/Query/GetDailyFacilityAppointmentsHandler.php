<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Query;

use App\Domains\Scheduling\Contracts\Dto\AppointmentSummary;
use App\Domains\Scheduling\Domain\AppointmentReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final readonly class GetDailyFacilityAppointmentsHandler
{
    public function __construct(
        private AppointmentReader $reader,
        private ScopeChecker $scopeChecker,
    ) {}

    /**
     * @return list<AppointmentSummary>
     */
    public function __invoke(GetDailyFacilityAppointments $query): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('GetDailyFacilityAppointments dispatched without an authenticated user (IC §6.1/§6.2).');
        }

        if (! in_array(
            'sch.access',
            $this->scopeChecker->effectivePermissions($user, $query->facilityId),
            true,
        )) {
            throw new AuthorizationException("User does not hold sch.access at facility {$query->facilityId}");
        }

        return $this->reader->getFacilityAppointments(
            facilityId: $query->facilityId,
            date: $query->date,
        );
    }
}
