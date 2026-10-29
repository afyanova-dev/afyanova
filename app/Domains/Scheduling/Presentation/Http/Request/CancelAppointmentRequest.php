<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Presentation\Http\Request;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class CancelAppointmentRequest extends FormRequest
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
            'reason' => ['nullable', 'string', 'max:500'],
            'return_to' => ['nullable', 'string', 'max:500'],
        ];
    }
}
