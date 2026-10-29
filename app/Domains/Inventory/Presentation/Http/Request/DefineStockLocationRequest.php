<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Presentation\Http\Request;

use App\Domains\Inventory\Application\Query\GetStockLocationTypes;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class DefineStockLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.locations.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:200'],
            'location_type' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z][A-Za-z0-9_-]*$/'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $type = $this->input('location_type');
                if ($validator->errors()->has('location_type') || ! is_string($type)) {
                    return;
                }

                $facilityId = app(TenantContext::class)->facilityId();
                if ($facilityId === null) {
                    $validator->errors()->add('location_type', 'An active facility is required to add a location.');

                    return;
                }

                $availableTypes = app(QueryBus::class)->dispatch(new GetStockLocationTypes($facilityId));
                $codes = [
                    'WAREHOUSE',
                    'STORE',
                    'QUARANTINE',
                    ...array_map(static fn ($locationType): string => $locationType->code, $availableTypes),
                ];
                if (! in_array(strtoupper(trim($type)), $codes, true)) {
                    $validator->errors()->add('location_type', 'Select a built-in or saved location type available at this facility.');
                }
            },
        ];
    }
}
