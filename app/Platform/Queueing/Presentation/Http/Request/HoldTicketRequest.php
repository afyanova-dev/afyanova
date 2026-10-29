<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Presentation\Http\Request;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class HoldTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('queue.ticket.hold');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'hold_reason' => ['required', 'string', 'in:FINANCIAL_HOLD,CLINICAL_HOLD,ADMINISTRATIVE_HOLD'],
            'hold_provenance' => ['required', 'string', 'max:500'],
            'return_to' => ['nullable', 'string', 'max:500'],
        ];
    }
}
