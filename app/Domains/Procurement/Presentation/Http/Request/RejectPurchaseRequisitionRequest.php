<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Presentation\Http\Request;

use App\Domains\Procurement\Application\RequisitionDepartmentScope;
use App\Models\User;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;

/** Reject a purchase requisition — the reason is mandatory (§24). */
final class RejectPurchaseRequisitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User
            && app(RequisitionDepartmentScope::class)->hasPermissionWithinFacility(
                $user,
                app(TenantContext::class)->facilityId(),
                'procurement.requisitions.approve',
            );
    }

    protected function prepareForValidation(): void
    {
        $reason = $this->input('reason');

        if (is_string($reason)) {
            $this->merge(['reason' => trim($reason)]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500']];
    }
}
