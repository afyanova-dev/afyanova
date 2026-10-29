<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Presentation\Http\Request;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class RecordClinicianNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User
            && $user->can('clinical.notes.create')
            && $user->can('clinical.consultations.create');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'patient_id' => ['required', 'uuid'],
            'note_type' => ['required', 'string', 'max:100'],
            'title' => ['nullable', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:20000'],
        ];
    }
}
