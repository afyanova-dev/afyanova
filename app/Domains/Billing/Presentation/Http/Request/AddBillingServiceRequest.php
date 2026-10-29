<?php

declare(strict_types=1);

namespace App\Domains\Billing\Presentation\Http\Request;

use App\Domains\Billing\Domain\ValueObject\BillingServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AddBillingServiceRequest extends FormRequest
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
        return [
            'code' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:200'],
            'category' => ['required', 'string', Rule::in(BillingServiceCategory::ALL)],
        ];
    }
}
