<?php

declare(strict_types=1);

namespace App\Domains\Billing\Presentation\Http\Request;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class OpenCashSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('billing.cash_sessions.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'opening_float' => ['required', 'numeric', 'min:0', 'max:999999999.99'],
        ];
    }
}
