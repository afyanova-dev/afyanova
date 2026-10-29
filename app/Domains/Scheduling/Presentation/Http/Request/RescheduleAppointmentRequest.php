<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Presentation\Http\Request;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class RescheduleAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('sch.appointment.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'new_start' => ['required', 'date'],
            'new_end' => ['required', 'date', 'after:new_start'],
            'return_to' => ['nullable', 'string', 'max:500'],
            'slot_id' => ['nullable', 'uuid'],
        ];
    }
}
