<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Infrastructure\Reader;

use App\Domains\Laboratory\Contracts\Dto\LabFacilityInstrumentSummary;
use App\Domains\Laboratory\Domain\LabFacilityInstrumentReader;
use App\Domains\Laboratory\Domain\Model\LabFacilityInstrument;
use App\Domains\Laboratory\Domain\Model\LabFacilityInstrumentMethod;
use App\Domains\Laboratory\Domain\Model\LabTestDefinition;
use App\Shared\Domain\NotFound;

final class EloquentLabFacilityInstrumentReader implements LabFacilityInstrumentReader
{
    public function forFacility(string $facilityId): array
    {
        $instruments = LabFacilityInstrument::query()
            ->where('facility_id', $facilityId)
            ->orderBy('identifier')
            ->orderBy('id')
            ->get();

        if ($instruments->isEmpty()) {
            return [];
        }

        $supportedMethodsByInstrument = [];
        $methodSnapshots = LabFacilityInstrumentMethod::query()
            ->join('lab_facility_instruments', 'lab_facility_instruments.id', '=', 'lab_facility_instrument_methods.instrument_id')
            ->whereIn('lab_facility_instrument_methods.instrument_id', $instruments->modelKeys())
            ->whereColumn(
                'lab_facility_instrument_methods.instrument_version',
                'lab_facility_instruments.version',
            )
            ->orderBy('lab_facility_instrument_methods.method_code_snapshot')
            ->orderBy('lab_facility_instrument_methods.method_id')
            ->select('lab_facility_instrument_methods.*')
            ->get();

        foreach ($methodSnapshots as $method) {
            $supportedMethodsByInstrument[(string) $method->instrument_id][] = $this->methodSummary($method);
        }

        return array_values($instruments->map(
            fn (LabFacilityInstrument $instrument): LabFacilityInstrumentSummary => $this->toSummary(
                $instrument,
                $supportedMethodsByInstrument[(string) $instrument->id] ?? [],
            ),
        )->all());
    }

    public function activeForFacility(string $instrumentId, string $facilityId): LabFacilityInstrumentSummary
    {
        $instrument = LabFacilityInstrument::query()
            ->whereKey($instrumentId)
            ->where('facility_id', $facilityId)
            ->where('status', LabFacilityInstrument::STATUS_ACTIVE)
            ->first();

        if ($instrument === null) {
            throw NotFound::aggregate(LabFacilityInstrument::class, $instrumentId);
        }

        return $this->toSummary($instrument);
    }

    public function supportsTestDefinition(string $instrumentId, string $testDefinitionId): bool
    {
        return LabFacilityInstrumentMethod::query()
            ->join('lab_facility_instruments', 'lab_facility_instruments.id', '=', 'lab_facility_instrument_methods.instrument_id')
            ->join('lab_test_methods', 'lab_test_methods.id', '=', 'lab_facility_instrument_methods.method_id')
            ->join('lab_test_definitions', 'lab_test_definitions.id', '=', 'lab_test_methods.test_definition_id')
            ->where('lab_facility_instrument_methods.instrument_id', $instrumentId)
            ->whereColumn(
                'lab_facility_instrument_methods.instrument_version',
                'lab_facility_instruments.version',
            )
            ->whereColumn(
                'lab_facility_instrument_methods.method_version',
                'lab_test_methods.version',
            )
            ->where('lab_facility_instruments.status', LabFacilityInstrument::STATUS_ACTIVE)
            ->where('lab_test_definitions.active', true)
            ->where('lab_test_definitions.availability_status', LabTestDefinition::AVAILABILITY_AVAILABLE)
            ->where('lab_test_methods.test_definition_id', $testDefinitionId)
            ->where('lab_test_methods.active', true)
            ->exists();
    }

    public function supportsMethod(string $instrumentId, string $methodId): bool
    {
        return LabFacilityInstrumentMethod::query()
            ->join('lab_facility_instruments', 'lab_facility_instruments.id', '=', 'lab_facility_instrument_methods.instrument_id')
            ->join('lab_test_methods', 'lab_test_methods.id', '=', 'lab_facility_instrument_methods.method_id')
            ->join('lab_test_definitions', 'lab_test_definitions.id', '=', 'lab_test_methods.test_definition_id')
            ->where('lab_facility_instrument_methods.instrument_id', $instrumentId)
            ->where('lab_facility_instrument_methods.method_id', $methodId)
            ->whereColumn(
                'lab_facility_instrument_methods.instrument_version',
                'lab_facility_instruments.version',
            )
            ->whereColumn(
                'lab_facility_instrument_methods.method_version',
                'lab_test_methods.version',
            )
            ->where('lab_facility_instruments.status', LabFacilityInstrument::STATUS_ACTIVE)
            ->where('lab_test_definitions.active', true)
            ->where('lab_test_definitions.availability_status', LabTestDefinition::AVAILABILITY_AVAILABLE)
            ->where('lab_test_methods.active', true)
            ->exists();
    }

    /**
     * @param  list<array{methodId: string, methodCode: string, methodName: string, methodVersion: int}>|null  $supportedMethods
     */
    private function toSummary(
        LabFacilityInstrument $instrument,
        ?array $supportedMethods = null,
    ): LabFacilityInstrumentSummary {
        $supportedMethods ??= LabFacilityInstrumentMethod::query()
            ->where('instrument_id', $instrument->id)
            ->where('instrument_version', $instrument->version)
            ->orderBy('method_code_snapshot')
            ->orderBy('method_id')
            ->get()
            ->map(fn (LabFacilityInstrumentMethod $method): array => $this->methodSummary($method))
            ->all();

        return new LabFacilityInstrumentSummary(
            instrumentId: (string) $instrument->id,
            facilityId: (string) $instrument->facility_id,
            identifier: (string) $instrument->identifier,
            name: (string) $instrument->name,
            manufacturer: $instrument->manufacturer,
            model: $instrument->model,
            serialNumber: $instrument->serial_number,
            inventoryItemId: $instrument->inventory_item_id,
            inventoryCode: $instrument->inventory_code_snapshot,
            inventoryName: $instrument->inventory_name_snapshot,
            status: (string) $instrument->status,
            version: (int) $instrument->version,
            supportedMethods: $supportedMethods,
        );
    }

    /**
     * @return array{methodId: string, methodCode: string, methodName: string, methodVersion: int}
     */
    private function methodSummary(LabFacilityInstrumentMethod $method): array
    {
        return [
            'methodId' => (string) $method->method_id,
            'methodCode' => (string) $method->method_code_snapshot,
            'methodName' => (string) $method->method_name_snapshot,
            'methodVersion' => (int) $method->method_version,
        ];
    }
}
