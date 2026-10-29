<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Presentation\Http\Request;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class CreateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('pharmacy.orders.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'prescription_id' => ['required', 'uuid'],
            'priority' => ['nullable', 'string', 'in:ROUTINE,URGENT,STAT'],
        ];
    }
}
