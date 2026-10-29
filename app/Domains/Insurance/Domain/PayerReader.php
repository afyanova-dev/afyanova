<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain;

use App\Domains\Insurance\Contracts\Dto\PayerSummary;

/**
 * Read-side port for the payer master (IC §6.2; ADR-198 D-I). Returns DTOs only.
 */
interface PayerReader
{
    /** Throws `App\Domains\Insurance\Domain\Exception\PayerNotFound` if absent (RLS). */
    public function summary(string $payerId): PayerSummary;

    public function exists(string $payerId): bool;

    /**
     * @return list<PayerSummary> newest first
     */
    public function search(?string $payerType, ?string $status): array;
}
