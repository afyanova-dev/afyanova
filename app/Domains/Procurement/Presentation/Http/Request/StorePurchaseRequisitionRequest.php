<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Presentation\Http\Request;

use App\Domains\Procurement\Application\RequisitionDepartmentScope;
use App\Models\User;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Raise a purchase requisition from the Requests screen
 * (19-procurement-domain.md §17–§24; ADR-171 D-E / D-F).
 *
 * The department is optional — a small facility may have none configured, and
 * then its request is judged at the facility approval limit. When a department
 * is chosen, it must belong to this facility (checked by the controller), and
 * it is what later selects that department's own limit.
 */
final class StorePurchaseRequisitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $facilityId = app(TenantContext::class)->facilityId();
        $departmentId = $this->input('requesting_department_ref');

        return $user instanceof User
            && $facilityId !== null
            && ($departmentId === null || is_string($departmentId))
            && app(RequisitionDepartmentScope::class)->canCreateFor(
                $user,
                $facilityId,
                is_string($departmentId) && $departmentId !== '' ? $departmentId : null,
            );
    }

    protected function prepareForValidation(): void
    {
        $reason = $this->input('reason');

        if (is_string($reason)) {
            $this->merge(['reason' => trim($reason)]);
        }

        foreach (['cost_centre_ref', 'budget_ref'] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $this->merge([$field => trim($value)]);
            }
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'requesting_department_ref' => ['nullable', 'uuid'],
            'priority' => ['required', 'string', Rule::in(['ROUTINE', 'URGENT'])],
            'reason' => ['nullable', 'string', 'max:1000'],
            'cost_centre_ref' => ['nullable', 'string', 'max:100'],
            'budget_ref' => ['nullable', 'string', 'max:100'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.item_id' => ['required', 'uuid'],
            'lines.*.unit' => ['nullable', 'string', 'max:20'],
            'lines.*.pack_size' => ['nullable', 'numeric', 'gt:0'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
