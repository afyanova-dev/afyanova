<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Infrastructure\Reader;

use App\Domains\Radiology\Contracts\Dto\ImagingServiceSummary;
use App\Domains\Radiology\Domain\ImagingServiceReader;
use App\Domains\Radiology\Domain\Model\ImagingModality;
use App\Domains\Radiology\Domain\Model\ImagingService;
use App\Shared\Domain\NotFound;

final class EloquentImagingServiceReader implements ImagingServiceReader
{
    public function summary(string $serviceId): ImagingServiceSummary
    {
        $service = ImagingService::query()->whereKey($serviceId)->first()
            ?? throw NotFound::aggregate(ImagingService::class, $serviceId);

        $modalityCode = (string) (ImagingModality::query()
            ->whereKey($service->modality_id)
            ->value('code') ?? '');

        return $this->toSummary($service, $modalityCode);
    }

    public function list(?string $modalityId = null, ?bool $active = null): array
    {
        $query = ImagingService::query();

        if ($modalityId !== null) {
            $query->where('modality_id', $modalityId);
        }

        if ($active !== null) {
            $query->where('active', $active);
        }

        $services = $query->orderBy('name')->orderBy('id')->get();

        /** @var array<string, string> $modalityCodes */
        $modalityCodes = [];
        foreach (
            ImagingModality::query()
                ->whereIn('id', $services->pluck('modality_id')->unique()->values()->all())
                ->get(['id', 'code']) as $modality
        ) {
            $modalityCodes[(string) $modality->id] = (string) $modality->code;
        }

        return array_values(
            $services
                ->map(fn (ImagingService $service): ImagingServiceSummary => $this->toSummary(
                    $service,
                    $modalityCodes[(string) $service->modality_id] ?? '',
                ))
                ->all()
        );
    }

    private function toSummary(ImagingService $service, string $modalityCode): ImagingServiceSummary
    {
        return new ImagingServiceSummary(
            serviceId: (string) $service->id,
            code: (string) $service->code,
            name: (string) $service->name,
            modalityId: (string) $service->modality_id,
            modalityCode: $modalityCode,
            bodySite: $service->body_site,
            defaultLaterality: $service->default_laterality,
            contrastRequirement: (string) $service->contrast_requirement,
            preparationText: $service->preparation_text,
            durationMinutes: $service->duration_minutes,
            turnaroundTargetMinutes: $service->turnaround_target_minutes,
            requiredCapabilityCode: $service->required_capability_code,
            referralOnly: (bool) $service->referral_only,
            availabilityStatus: (string) $service->availability_status,
            active: (bool) $service->active,
            version: (int) $service->version,
        );
    }
}
