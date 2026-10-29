<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Presentation\Http\Request;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class BookAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('sch.appointment.book');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'scheduled_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'scheduled_time' => ['required', 'date_format:H:i'],
            'appointment_type_id' => ['required', 'uuid'],
            'resource_id' => ['required', 'uuid'],
            'reason' => ['nullable', 'string', 'max:500'],
            'return_to' => ['nullable', 'string', 'max:500'],
            'slot_id' => ['nullable', 'uuid'],
        ];
    }
}
