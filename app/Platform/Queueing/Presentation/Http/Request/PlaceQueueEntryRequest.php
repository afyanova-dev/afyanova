<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Presentation\Http\Request;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class PlaceQueueEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('queue.ticket.place');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'queue_id' => ['required', 'uuid'],
            'encounter_id' => ['required', 'uuid'],
            'patient_id' => ['required', 'uuid'],
            'priority' => ['nullable', 'string', 'in:ROUTINE,PRIORITY,URGENT,EMERGENCY'],
            'source_order_ref' => ['nullable', 'string', 'max:255'],
            'order_type' => ['nullable', 'string', 'max:50'],
            'return_to' => ['nullable', 'string', 'max:500'],
        ];
    }
}
