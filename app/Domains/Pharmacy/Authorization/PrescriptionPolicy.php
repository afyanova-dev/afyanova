<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Authorization;

use App\Domains\Pharmacy\Application\Command\AcceptPrescription;
use App\Domains\Pharmacy\Application\Command\AcceptTranscription;
use App\Domains\Pharmacy\Application\Command\CancelPrescription;
use App\Domains\Pharmacy\Application\Command\TranscribePaperPrescription;
use App\Domains\Pharmacy\Domain\Model\Prescription;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * Prescription record policy (ADR-181 D-F).
 *
 * `view` is a coarse `pharmacy.access` check (`SearchPrescriptions` re-checks
 * the acting user's scope at the facility). `accept` / `cancel` are per-subject
 * — the actor must hold `pharmacy.prescriptions.accept` at the prescription's
 * ORIGINATING facility (the `StockAdjustmentPolicy` shape).
 */
final class PrescriptionPolicy extends BasePolicy
{
    public function view(User $user): bool
    {
        return $this->coarse($user, 'pharmacy.access');
    }

    public function create(User $user, ?TranscribePaperPrescription $command = null): bool
    {
        return $this->coarse($user, 'pharmacy.prescriptions.accept');
    }

    public function accept(User $user, Prescription $prescription, AcceptPrescription|AcceptTranscription|null $command = null): bool
    {
        return $this->atFacility($user, $prescription, 'pharmacy.prescriptions.accept');
    }

    public function cancel(User $user, Prescription $prescription, ?CancelPrescription $command = null): bool
    {
        return $this->atFacility($user, $prescription, 'pharmacy.prescriptions.accept');
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, $key);
    }

    private function atFacility(User $user, Prescription $prescription, string $key): bool
    {
        return $this->sameTenant($user, (string) $prescription->tenant_id)
            && in_array(
                $key,
                $this->scope->effectivePermissions($user, (string) $prescription->facility_id),
                true,
            );
    }
}
