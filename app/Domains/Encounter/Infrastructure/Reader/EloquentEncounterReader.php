<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Infrastructure\Reader;

use App\Domains\Encounter\Contracts\Dto\EncounterSummary;
use App\Domains\Encounter\Domain\EncounterReader;
use App\Domains\Encounter\Domain\Enum\EncounterStatus;
use App\Domains\Encounter\Domain\Model\Encounter;
use App\Shared\Domain\NotFound;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class EloquentEncounterReader implements EncounterReader
{
    public function exists(string $encounterId): bool
    {
        return Encounter::query()->whereKey($encounterId)->exists();
    }

    public function summary(string $encounterId): EncounterSummary
    {
        $encounter = Encounter::query()->whereKey($encounterId)->first()
            ?? throw NotFound::aggregate(Encounter::class, $encounterId);

        return $this->toSummary($encounter);
    }

    /**
     * @param  list<EncounterStatus|string>|null  $statuses
     * @return list<EncounterSummary>
     */
    public function forFacility(
        string $facilityId,
        ?CarbonImmutable $date = null,
        ?array $statuses = null,
        int $limit = 100,
    ): array {
        $query = Encounter::query()->where('facility_id', $facilityId);

        if ($date !== null) {
            $start = $date->startOfDay();
            $end = $date->endOfDay();
            $query->where(function (Builder $q) use ($start, $end): void {
                $q->whereBetween('arrived_at', [$start, $end])
                    ->orWhere(function (Builder $sub) use ($start, $end): void {
                        $sub->whereNull('arrived_at')
                            ->whereBetween('created_at', [$start, $end]);
                    });
            });
        }

        if ($statuses !== null && $statuses !== []) {
            $statusValues = array_map(
                static fn ($s): string => $s instanceof EncounterStatus ? $s->value : (string) $s,
                $statuses,
            );
            $query->whereIn('status', $statusValues);
        }

        /** @var Collection<int, Encounter> $encounters */
        $encounters = $query
            ->orderByDesc('arrived_at')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return array_values($encounters->map(fn (Encounter $encounter): EncounterSummary => $this->toSummary($encounter))->all());
    }

    /**
     * @param  list<EncounterStatus|string>|null  $statuses
     */
    public function countForFacility(
        string $facilityId,
        CarbonImmutable $date,
        ?array $statuses = null,
    ): int {
        $query = Encounter::query()
            ->where('facility_id', $facilityId)
            ->whereBetween('arrived_at', [$date->startOfDay(), $date->endOfDay()]);

        if ($statuses !== null && $statuses !== []) {
            $statusValues = array_map(
                static fn ($status): string => $status instanceof EncounterStatus ? $status->value : (string) $status,
                $statuses,
            );
            $query->whereIn('status', $statusValues);
        }

        return $query->count();
    }

    public function activeForPatient(string $facilityId, string $patientId): ?EncounterSummary
    {
        /** @var Encounter|null $encounter */
        $encounter = Encounter::query()
            ->where('facility_id', $facilityId)
            ->where('patient_id', $patientId)
            ->whereIn('status', [EncounterStatus::Arrived->value, EncounterStatus::Active->value])
            ->first();

        return $encounter !== null ? $this->toSummary($encounter) : null;
    }

    /**
     * @param  list<EncounterStatus|string>|null  $statuses
     * @return list<EncounterSummary>
     */
    public function forPatient(
        string $facilityId,
        string $patientId,
        ?array $statuses = null,
        int $limit = 100,
    ): array {
        $query = Encounter::query()
            ->where('facility_id', $facilityId)
            ->where('patient_id', $patientId);

        if ($statuses !== null && $statuses !== []) {
            $statusValues = array_map(
                static fn ($s): string => $s instanceof EncounterStatus ? $s->value : (string) $s,
                $statuses,
            );
            $query->whereIn('status', $statusValues);
        }

        /** @var Collection<int, Encounter> $encounters */
        $encounters = $query
            ->orderByDesc('arrived_at')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return array_values($encounters->map(fn (Encounter $encounter): EncounterSummary => $this->toSummary($encounter))->all());
    }

    private function toSummary(Encounter $encounter): EncounterSummary
    {
        return new EncounterSummary(
            encounterId: (string) $encounter->id,
            encounterNumber: (string) $encounter->encounter_number,
            patientId: (string) $encounter->patient_id,
            facilityId: (string) $encounter->facility_id,
            encounterType: $encounter->encounter_type->value,
            status: $encounter->status->value,
            disposition: $encounter->disposition?->value,
            departmentId: $encounter->department_id,
            unitId: $encounter->unit_id,
            arrivedAt: $encounter->arrived_at?->toISOString(),
            startedAt: $encounter->started_at?->toISOString(),
            admittedAt: $encounter->admitted_at?->toISOString(),
            completedAt: $encounter->completed_at?->toISOString(),
            createdSource: $encounter->created_source,
        );
    }
}
