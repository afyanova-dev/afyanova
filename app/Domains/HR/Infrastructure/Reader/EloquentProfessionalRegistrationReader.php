<?php

declare(strict_types=1);

namespace App\Domains\HR\Infrastructure\Reader;

use App\Domains\HR\Contracts\Dto\ProfessionalRegistrationSummary;
use App\Domains\HR\Domain\Exception\ProfessionalRegistrationNotFoundException;
use App\Domains\HR\Domain\Model\ProfessionalRegistration;
use App\Domains\HR\Domain\ProfessionalRegistrationReader;
use App\Domains\HR\Domain\ValueObject\RegistrationStatus;
use Carbon\CarbonImmutable;

/**
 * Eloquent implementation of ProfessionalRegistrationReader (25-hr-and-workforce-domain.md §6, §10; ADR-206).
 */
final class EloquentProfessionalRegistrationReader implements ProfessionalRegistrationReader
{
    public function summary(string $registrationId): ProfessionalRegistrationSummary
    {
        /** @var ProfessionalRegistration|null $reg */
        $reg = ProfessionalRegistration::query()->whereKey($registrationId)->first();

        if ($reg === null) {
            throw ProfessionalRegistrationNotFoundException::forId($registrationId);
        }

        return $this->toSummary($reg);
    }

    public function forStaff(string $staffId): array
    {
        return array_values(ProfessionalRegistration::query()
            ->where('staff_id', $staffId)
            ->orderByDesc('issued_on')
            ->get()
            ->map(fn (ProfessionalRegistration $reg) => $this->toSummary($reg))
            ->all());
    }

    public function watchlist(): array
    {
        $cutoff = CarbonImmutable::now()->addDays(60)->toDateString();

        return array_values(ProfessionalRegistration::query()
            ->where(function ($query) use ($cutoff): void {
                $query->where('status', '!=', RegistrationStatus::ACTIVE->value)
                    ->orWhere('expires_on', '<=', $cutoff);
            })
            ->orderBy('expires_on')
            ->get()
            ->map(fn (ProfessionalRegistration $reg) => $this->toSummary($reg))
            ->all());
    }

    private function toSummary(ProfessionalRegistration $reg): ProfessionalRegistrationSummary
    {
        return new ProfessionalRegistrationSummary(
            registrationId: $reg->id,
            tenantId: $reg->tenant_id,
            staffId: $reg->staff_id,
            council: $reg->council,
            category: $reg->category,
            licenceNumber: $reg->licence_number,
            issuedOn: $reg->issued_on->toDateString(),
            expiresOn: $reg->expires_on->toDateString(),
            verifiedAt: $reg->verified_at?->toIso8601String(),
            verificationSource: $reg->verification_source->value,
            status: $reg->status->value,
            notes: $reg->notes,
            version: $reg->version,
        );
    }
}
