<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Presentation\Http\Request;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class CallTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('queue.ticket.call');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'room' => ['required', 'uuid', 'exists:org_rooms,id'],
            'return_to' => ['nullable', 'string', 'max:500'],
        ];
    }
}
