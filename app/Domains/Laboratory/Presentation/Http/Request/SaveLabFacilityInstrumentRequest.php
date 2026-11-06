<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Presentation\Http\Request;

use App\Domains\Laboratory\Domain\Model\LabFacilityInstrument;
use App\Domains\Laboratory\Domain\Model\LabOrder;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

final class SaveLabFacilityInstrumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageInstruments', LabOrder::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(TenantContext $context): array
    {
        $instrumentId = $this->route('instrument');

        return [
            'identifier' => [
                'required',
                'string',
                'max:100',
                Rule::unique('lab_facility_instruments', 'identifier')
                    ->where('tenant_id', $context->tenantId())
                    ->where('facility_id', $context->requireFacilityId())
                    ->ignore(is_string($instrumentId) ? $instrumentId : null),
            ],
            'name' => ['required', 'string', 'max:200'],
            'manufacturer' => ['nullable', 'string', 'max:200'],
            'model' => ['nullable', 'string', 'max:200'],
            'serial_number' => ['nullable', 'string', 'max:200'],
            'inventory_item_id' => ['nullable', 'uuid'],
            'supported_method_ids' => ['present', 'array', 'max:100'],
            'supported_method_ids.*' => ['required', 'uuid', 'distinct'],
            'status' => [
                $this->isMethod('put') ? 'required' : 'prohibited',
                Rule::in(LabFacilityInstrument::STATUSES),
            ],
        ];
    }

    /**
     * @return array{
     *     identifier: string, name: string, manufacturer: ?string, model: ?string,
     *     serial_number: ?string, inventory_item_id: ?string, supported_method_ids: list<string>
     * }
     */
    public function dataForCreate(): array
    {
        return $this->instrumentAttributes();
    }

    /**
     * @return array{
     *     identifier: string, name: string, manufacturer: ?string, model: ?string,
     *     serial_number: ?string, inventory_item_id: ?string, status: string,
     *     supported_method_ids: list<string>
     * }
     */
    public function dataForUpdate(): array
    {
        $data = $this->instrumentAttributes();
        $status = $this->validated('status');

        if (! is_string($status)) {
            throw new LogicException('Validated laboratory instrument status did not match its request contract.');
        }

        return [...$data, 'status' => $status];
    }

    /**
     * @return array{
     *     identifier: string, name: string, manufacturer: ?string, model: ?string,
     *     serial_number: ?string, inventory_item_id: ?string, supported_method_ids: list<string>
     * }
     */
    private function instrumentAttributes(): array
    {
        $data = $this->validated();
        $methodIds = $data['supported_method_ids'] ?? [];

        if (! is_string($data['identifier'] ?? null)
            || ! is_string($data['name'] ?? null)
            || ! is_array($methodIds)) {
            throw new LogicException('Validated laboratory instrument input did not match its request contract.');
        }

        $supportedMethodIds = [];
        foreach (array_values($methodIds) as $methodId) {
            if (! is_string($methodId)) {
                throw new LogicException('Validated supported laboratory method did not match its request contract.');
            }

            $supportedMethodIds[] = $methodId;
        }

        return [
            'identifier' => $data['identifier'],
            'name' => $data['name'],
            'manufacturer' => is_string($data['manufacturer'] ?? null) ? $data['manufacturer'] : null,
            'model' => is_string($data['model'] ?? null) ? $data['model'] : null,
            'serial_number' => is_string($data['serial_number'] ?? null) ? $data['serial_number'] : null,
            'inventory_item_id' => is_string($data['inventory_item_id'] ?? null) ? $data['inventory_item_id'] : null,
            'supported_method_ids' => $supportedMethodIds,
        ];
    }
}
