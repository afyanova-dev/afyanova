<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Infrastructure\Reader;

use App\Domains\Radiology\Contracts\Dto\ImagingModalitySummary;
use App\Domains\Radiology\Domain\ImagingModalityReader;
use App\Domains\Radiology\Domain\Model\ImagingModality;
use App\Shared\Domain\NotFound;

final class EloquentImagingModalityReader implements ImagingModalityReader
{
    public function summary(string $modalityId): ImagingModalitySummary
    {
        $modality = ImagingModality::query()->whereKey($modalityId)->first()
            ?? throw NotFound::aggregate(ImagingModality::class, $modalityId);

        return $this->toSummary($modality);
    }

    public function list(?string $category = null, ?bool $active = null): array
    {
        $query = ImagingModality::query();

        if ($category !== null) {
            $query->where('category', $category);
        }

        if ($active !== null) {
            $query->where('active', $active);
        }

        return array_values(
            $query->orderBy('name')->orderBy('id')->get()
                ->map(fn (ImagingModality $modality): ImagingModalitySummary => $this->toSummary($modality))
                ->all()
        );
    }

    private function toSummary(ImagingModality $modality): ImagingModalitySummary
    {
        return new ImagingModalitySummary(
            modalityId: (string) $modality->id,
            code: (string) $modality->code,
            name: (string) $modality->name,
            category: $modality->category,
            description: $modality->description,
            requiredCapabilityCode: $modality->required_capability_code,
            operationalStatus: (string) $modality->operational_status,
            active: (bool) $modality->active,
            version: (int) $modality->version,
        );
    }
}
