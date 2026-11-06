<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Infrastructure\Reader;

use App\Domains\Laboratory\Contracts\Dto\LabTestMethodSummary;
use App\Domains\Laboratory\Domain\LabTestMethodReader;
use App\Domains\Laboratory\Domain\Model\LabMethodInventoryRequirement;
use App\Domains\Laboratory\Domain\Model\LabTestMethod;
use Illuminate\Database\Eloquent\Collection;

final class EloquentLabTestMethodReader implements LabTestMethodReader
{
    public function list(): array
    {
        /** @var Collection<int, LabTestMethod> $methods */
        $methods = LabTestMethod::query()
            ->orderBy('test_definition_id')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        if ($methods->isEmpty()) {
            return [];
        }

        $currentRequirements = LabMethodInventoryRequirement::query()
            ->select('lab_method_inventory_requirements.*')
            ->join('lab_test_methods', 'lab_test_methods.id', '=', 'lab_method_inventory_requirements.method_id')
            ->whereColumn('lab_method_inventory_requirements.method_version', 'lab_test_methods.version')
            ->whereIn('lab_method_inventory_requirements.method_id', $methods->modelKeys())
            ->orderBy('requirement_role')
            ->orderBy('item_code_snapshot')
            ->get()
            ->groupBy('method_id');

        return array_values($methods->map(function (LabTestMethod $method) use ($currentRequirements): LabTestMethodSummary {
            /** @var iterable<LabMethodInventoryRequirement> $requirements */
            $requirements = $currentRequirements->get((string) $method->id, collect());

            return $this->toSummary($method, $requirements);
        })->all());
    }

    /**
     * @param  iterable<LabMethodInventoryRequirement>  $requirements
     */
    private function toSummary(LabTestMethod $method, iterable $requirements): LabTestMethodSummary
    {
        $requirementSummaries = [];
        foreach ($requirements as $requirement) {
            $requirementSummaries[] = [
                'requirementId' => (string) $requirement->id,
                'inventoryItemId' => (string) $requirement->inventory_item_id,
                'itemCode' => (string) $requirement->item_code_snapshot,
                'itemName' => (string) $requirement->item_name_snapshot,
                'itemUom' => (string) $requirement->item_uom_snapshot,
                'itemType' => (string) $requirement->item_type_snapshot,
                'itemCategory' => $requirement->item_category_snapshot,
                'role' => (string) $requirement->requirement_role,
                'required' => (bool) $requirement->required,
                'usageScope' => (string) $requirement->usage_scope,
                'quantity' => (string) $requirement->quantity,
            ];
        }

        return new LabTestMethodSummary(
            methodId: (string) $method->id,
            testDefinitionId: (string) $method->test_definition_id,
            code: (string) $method->code,
            name: (string) $method->name,
            description: $method->description,
            specimenType: $method->specimen_type,
            containerType: $method->container_type,
            active: (bool) $method->active,
            version: (int) $method->version,
            requirements: $requirementSummaries,
        );
    }
}
