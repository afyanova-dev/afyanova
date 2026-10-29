<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Authorization;

use App\Domains\Scheduling\Domain\Model\Appointment;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

final class AppointmentPolicy extends BasePolicy
{
    public function view(User $user): bool
    {
        return $this->coarse($user, 'sch.access');
    }

    public function book(User $user): bool
    {
        return $this->coarse($user, 'sch.appointment.book');
    }

    public function manage(User $user, Appointment $appointment): bool
    {
        return $this->sameTenant($user, (string) $appointment->tenant_id)
            && $this->inActiveFacility((string) $appointment->facility_id)
            && $this->permits($user, 'sch.appointment.manage');
    }

    public function recordArrival(User $user, Appointment $appointment): bool
    {
        return $this->sameTenant($user, (string) $appointment->tenant_id)
            && $this->inActiveFacility((string) $appointment->facility_id)
            && $this->permits($user, 'sch.appointment.record_arrival');
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, $key);
    }
}
