<?php

declare(strict_types=1);

namespace App\Domains\Billing\Presentation\Http\Request;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class RecordPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('billing.payments.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // G-6: INSURANCE_REMITTANCE / CORPORATE_PAYMENT never pass the till.
        return [
            'patient_id' => ['required', 'uuid'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999.99'],
            'method' => ['required', 'string', 'in:CASH,CARD,BANK_TRANSFER,MOBILE_MONEY,POS,OTHER'],
            'payment_reference' => ['nullable', 'string', 'max:120'],
        ];
    }
}
