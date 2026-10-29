<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Presentation\Http\Request;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class TransferEncounterRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('queue.ticket.transfer');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'encounter_id' => ['required', 'uuid'],
            'queue_id' => ['required', 'uuid'],
            'comment' => ['nullable', 'string', 'max:500'],
            'return_to' => ['nullable', 'string', 'max:500'],
        ];
    }
}
