<?php

declare(strict_types=1);

namespace App\Domains\Billing\Authorization;

use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * Payment policy (ADR-191 B5-C / G-4 / G-6). Registered for the `Payment`
 * aggregate.
 *
 * `view` — coarse `billing.access`. `manage` — coarse `billing.payments.manage`
 * (`RecordPayment`). `reverse` — coarse `billing.payments.reverse`
 * (`ReversePayment`), a DISTINCT key (§164 / §165 — the record-vs-reverse
 * separation-of-duties boundary). All tenant-wide — collecting a payment is a
 * billing-clerk function, and §150 / §151 explicitly allow a facility to
 * collect for another facility's account, so it is NOT per-facility-refined.
 * The open-cash-session requirement (Bill.6) is a handler business rule, not an
 * authorization path (G-4).
 */
final class PaymentPolicy extends BasePolicy
{
    public function view(User $user): bool
    {
        return $this->coarse($user, 'billing.access');
    }

    public function manage(User $user): bool
    {
        return $this->coarse($user, 'billing.payments.manage');
    }

    public function reverse(User $user): bool
    {
        return $this->coarse($user, 'billing.payments.reverse');
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, $key);
    }
}
