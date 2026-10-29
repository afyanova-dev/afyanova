<?php

declare(strict_types=1);

namespace App\Domains\Billing\Presentation\Http\Request;

use App\Domains\Billing\Domain\ValueObject\BillingServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateBillingServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('billing.catalog.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Code is SET-ONCE (§237) — never accepted here.
        return [
            'name' => ['required', 'string', 'max:200'],
            'category' => ['required', 'string', Rule::in(BillingServiceCategory::ALL)],
        ];
    }
}
