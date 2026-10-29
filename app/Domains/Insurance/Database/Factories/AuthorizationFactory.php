<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Database\Factories;

use App\Domains\Insurance\Domain\Model\Authorization;
use App\Domains\Insurance\Domain\ValueObject\AuthorizationStatus;
use App\Domains\Insurance\Domain\ValueObject\CoveredServiceCategory;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Authorization>
 */
class AuthorizationFactory extends Factory
{
    protected $model = Authorization::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $now = CarbonImmutable::now();

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'authorization_number' => 'AUT-'.$this->faker->numerify('########'),
            'member_coverage_id' => (string) Str::uuid7(),
            'patient_id' => (string) Str::uuid7(),
            'plan_id' => (string) Str::uuid7(),
            'payer_id' => (string) Str::uuid7(),
            'service_category' => CoveredServiceCategory::PROCEDURE,
            'service_description' => 'Lumbar spine MRI',
            'diagnosis_code' => null,
            'facility_id' => null,
            'provider_user_id' => null,
            'requested_quantity' => null,
            'requested_amount' => null,
            'status' => AuthorizationStatus::REQUESTED,
            'external_authorization_number' => null,
            'approved_quantity' => null,
            'approved_amount' => null,
            'valid_from' => null,
            'valid_to' => null,
            'decision_reason' => null,
            'requested_by_user_id' => (string) Str::uuid7(),
            'requested_at' => $now,
            'decided_by_user_id' => null,
            'decided_at' => null,
            'expired_at' => null,
            'cancelled_by_user_id' => null,
            'cancellation_reason' => null,
            'cancelled_at' => null,
            'created_by_user_id' => (string) Str::uuid7(),
            'occurred_at' => $now,
            'recorded_at' => $now,
            'version' => 0,
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }

    public function forPatient(string $patientId): static
    {
        return $this->state(['patient_id' => $patientId]);
    }

    public function forCoverage(string $memberCoverageId): static
    {
        return $this->state(['member_coverage_id' => $memberCoverageId]);
    }

    public function approved(
        string $externalNumber = 'EXT-AUTH-9001',
        string $validFrom = '2026-01-01',
        string $validTo = '2026-12-31',
        ?string $approvedQuantity = null,
        ?string $approvedAmount = null,
    ): static {
        $now = CarbonImmutable::now();

        return $this->state([
            'status' => AuthorizationStatus::APPROVED,
            'external_authorization_number' => $externalNumber,
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'approved_quantity' => $approvedQuantity,
            'approved_amount' => $approvedAmount,
            'decided_by_user_id' => (string) Str::uuid7(),
            'decided_at' => $now,
        ]);
    }

    public function partiallyApproved(
        string $externalNumber = 'EXT-AUTH-9002',
        string $validFrom = '2026-01-01',
        string $validTo = '2026-12-31',
        ?string $approvedQuantity = '1.0000',
        ?string $approvedAmount = '5000.0000',
    ): static {
        $now = CarbonImmutable::now();

        return $this->state([
            'status' => AuthorizationStatus::PARTIALLY_APPROVED,
            'external_authorization_number' => $externalNumber,
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'approved_quantity' => $approvedQuantity,
            'approved_amount' => $approvedAmount,
            'decided_by_user_id' => (string) Str::uuid7(),
            'decided_at' => $now,
        ]);
    }

    public function denied(string $reasonCode = 'SERVICE_NOT_COVERED'): static
    {
        $now = CarbonImmutable::now();

        return $this->state([
            'status' => AuthorizationStatus::DENIED,
            'decision_reason' => $reasonCode,
            'decided_by_user_id' => (string) Str::uuid7(),
            'decided_at' => $now,
        ]);
    }

    public function expired(
        string $externalNumber = 'EXT-AUTH-9003',
        string $validFrom = '2026-01-01',
        string $validTo = '2026-01-15',
    ): static {
        $now = CarbonImmutable::now();

        return $this->state([
            'status' => AuthorizationStatus::EXPIRED,
            'external_authorization_number' => $externalNumber,
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'decided_by_user_id' => (string) Str::uuid7(),
            'decided_at' => $now->subMonth(),
            'expired_at' => $now,
        ]);
    }

    public function cancelled(string $reasonCode = 'PAYER_REVOKED'): static
    {
        $now = CarbonImmutable::now();

        return $this->state([
            'status' => AuthorizationStatus::CANCELLED,
            'cancellation_reason' => $reasonCode,
            'cancelled_by_user_id' => (string) Str::uuid7(),
            'cancelled_at' => $now,
        ]);
    }
}
