<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Presentation\Http\Request;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class TransferTicketRequest extends FormRequest
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
            'destination_queue_id' => ['required', 'uuid'],
            'reason' => ['nullable', 'string', 'max:500'],
            'return_to' => ['nullable', 'string', 'max:500'],
        ];
    }
}
