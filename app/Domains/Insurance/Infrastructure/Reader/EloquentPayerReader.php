<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Infrastructure\Reader;

use App\Domains\Insurance\Contracts\Dto\PayerSummary;
use App\Domains\Insurance\Domain\Exception\PayerNotFound;
use App\Domains\Insurance\Domain\Model\Payer;
use App\Domains\Insurance\Domain\PayerReader;

/**
 * Eloquent implementation of {@see PayerReader} (IC §6.2; ADR-198 D-I).
 */
final class EloquentPayerReader implements PayerReader
{
    public function summary(string $payerId): PayerSummary
    {
        $payer = Payer::query()->whereKey($payerId)->first()
            ?? throw PayerNotFound::id($payerId);

        return $this->toSummary($payer);
    }

    public function exists(string $payerId): bool
    {
        return Payer::query()->whereKey($payerId)->exists();
    }

    public function search(?string $payerType, ?string $status): array
    {
        $query = Payer::query();

        if ($payerType !== null) {
            $query->where('payer_type', strtoupper(trim($payerType)));
        }
        if ($status !== null) {
            $query->where('status', strtoupper(trim($status)));
        }

        return array_values(
            $query->orderByDesc('recorded_at')->orderBy('id')->get()
                ->map(fn (Payer $p): PayerSummary => $this->toSummary($p))
                ->all(),
        );
    }

    private function toSummary(Payer $payer): PayerSummary
    {
        return new PayerSummary(
            payerId: (string) $payer->id,
            code: (string) $payer->code,
            name: (string) $payer->name,
            payerType: (string) $payer->payer_type,
            status: (string) $payer->status,
            statusReason: $payer->status_reason,
            contactName: $payer->contact_name,
            contactEmail: $payer->contact_email,
            contactPhone: $payer->contact_phone,
            recordedAt: $payer->recorded_at->toIso8601String(),
        );
    }
}
