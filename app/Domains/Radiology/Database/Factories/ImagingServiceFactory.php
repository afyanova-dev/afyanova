<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Database\Factories;

use App\Domains\Radiology\Domain\Model\ImagingService;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ImagingService>
 */
class ImagingServiceFactory extends Factory
{
    protected $model = ImagingService::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => 'SVC-'.Str::upper(Str::random(5)),
            'name' => 'CT Abdomen with contrast',
            'modality_id' => (string) Str::uuid7(),
            'body_site' => 'ABDOMEN',
            'default_laterality' => 'NOT_APPLICABLE',
            'contrast_requirement' => ImagingService::CONTRAST_REQUIRED,
            'preparation_text' => 'Fast for 4 hours.',
            'duration_minutes' => 20,
            'turnaround_target_minutes' => 240,
            'required_capability_code' => null,
            'referral_only' => false,
            'availability_status' => ImagingService::AVAILABILITY_AVAILABLE,
            'active' => true,
            'version' => 1,
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }

    public function forModality(string $modalityId): static
    {
        return $this->state(['modality_id' => $modalityId]);
    }

    public function withCode(string $code): static
    {
        return $this->state(['code' => Str::upper($code)]);
    }

    public function requiringCapability(string $capabilityCode): static
    {
        return $this->state(['required_capability_code' => $capabilityCode]);
    }

    public function referralOnly(): static
    {
        return $this->state(['referral_only' => true]);
    }

    public function disabledForOrdering(): static
    {
        return $this->state(['availability_status' => ImagingService::AVAILABILITY_DISABLED]);
    }

    public function inactive(): static
    {
        return $this->state(['active' => false]);
    }
}
