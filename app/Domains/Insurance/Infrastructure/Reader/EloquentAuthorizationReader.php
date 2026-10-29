<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Infrastructure\Reader;

use App\Domains\Insurance\Contracts\Dto\AuthorizationSummary;
use App\Domains\Insurance\Domain\AuthorizationReader;
use App\Domains\Insurance\Domain\Exception\AuthorizationNotFound;
use App\Domains\Insurance\Domain\Model\Authorization;
use App\Domains\Insurance\Domain\ValueObject\AuthorizationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Eloquent implementation of {@see AuthorizationReader} (IC §6.2; ADR-202 D-C).
 */
final class EloquentAuthorizationReader implements AuthorizationReader
{
    public function summary(string $authorizationId): AuthorizationSummary
    {
        $authorization = Authorization::query()->whereKey($authorizationId)->first()
            ?? throw AuthorizationNotFound::id($authorizationId);

        return $this->toSummary($authorization);
    }

    public function forPatient(string $patientId): array
    {
        $rows = Authorization::query()->where('patient_id', $patientId)->get();

        $sorted = $rows->sort(function (Authorization $a, Authorization $b): int {
            // Active / effective first (APPROVED / PARTIALLY_APPROVED = 0, REQUESTED = 1, terminal = 2), then newest
            $weightA = match ($a->status) {
                AuthorizationStatus::APPROVED, AuthorizationStatus::PARTIALLY_APPROVED => 0,
                AuthorizationStatus::REQUESTED => 1,
                default => 2,
            };
            $weightB = match ($b->status) {
                AuthorizationStatus::APPROVED, AuthorizationStatus::PARTIALLY_APPROVED => 0,
                AuthorizationStatus::REQUESTED => 1,
                default => 2,
            };

            if ($weightA !== $weightB) {
                return $weightA <=> $weightB;
            }

            return $b->recorded_at->getTimestamp() <=> $a->recorded_at->getTimestamp();
        })->values();

        return array_values($sorted->map(fn (Authorization $a): AuthorizationSummary => $this->toSummary($a))->all());
    }

    public function pending(): array
    {
        $rows = Authorization::query()
            ->where('status', AuthorizationStatus::REQUESTED)
            ->orderBy('requested_at')
            ->orderBy('id')
            ->get();

        return array_values($rows->map(fn (Authorization $a): AuthorizationSummary => $this->toSummary($a))->all());
    }

    public function findEffective(string $patientId, string $serviceCategory, ?string $onDate = null): ?AuthorizationSummary
    {
        $date = $onDate !== null ? CarbonImmutable::parse($onDate)->toDateString() : CarbonImmutable::now()->toDateString();

        $row = Authorization::query()
            ->where('patient_id', $patientId)
            ->where('service_category', $serviceCategory)
            ->whereIn('status', AuthorizationStatus::EFFECTIVE)
            ->where(function (Builder $q) use ($date): void {
                $q->whereNull('valid_from')->orWhere('valid_from', '<=', $date);
            })
            ->where(function (Builder $q) use ($date): void {
                $q->whereNull('valid_to')->orWhere('valid_to', '>=', $date);
            })
            ->orderByDesc('decided_at')
            ->orderByDesc('recorded_at')
            ->first();

        return $row !== null ? $this->toSummary($row) : null;
    }

    private function toSummary(Authorization $a): AuthorizationSummary
    {
        return new AuthorizationSummary(
            id: (string) $a->id,
            authorizationNumber: (string) $a->authorization_number,
            memberCoverageId: (string) $a->member_coverage_id,
            patientId: (string) $a->patient_id,
            planId: (string) $a->plan_id,
            payerId: (string) $a->payer_id,
            serviceCategory: (string) $a->service_category,
            serviceDescription: $a->service_description,
            diagnosisCode: $a->diagnosis_code,
            facilityId: $a->facility_id !== null ? (string) $a->facility_id : null,
            providerUserId: $a->provider_user_id !== null ? (string) $a->provider_user_id : null,
            requestedQuantity: $a->requested_quantity !== null ? (string) $a->requested_quantity : null,
            requestedAmount: $a->requested_amount !== null ? (string) $a->requested_amount : null,
            status: (string) $a->status,
            externalAuthorizationNumber: $a->external_authorization_number,
            approvedQuantity: $a->approved_quantity !== null ? (string) $a->approved_quantity : null,
            approvedAmount: $a->approved_amount !== null ? (string) $a->approved_amount : null,
            validFrom: $a->valid_from?->toDateString(),
            validTo: $a->valid_to?->toDateString(),
            decisionReason: $a->decision_reason,
            requestedByUserId: (string) $a->requested_by_user_id,
            requestedAt: $a->requested_at->toIso8601String(),
            decidedByUserId: $a->decided_by_user_id !== null ? (string) $a->decided_by_user_id : null,
            decidedAt: $a->decided_at?->toIso8601String(),
            expiredAt: $a->expired_at?->toIso8601String(),
            cancelledByUserId: $a->cancelled_by_user_id !== null ? (string) $a->cancelled_by_user_id : null,
            cancellationReason: $a->cancellation_reason,
            cancelledAt: $a->cancelled_at?->toIso8601String(),
            version: (int) $a->version,
        );
    }
}
