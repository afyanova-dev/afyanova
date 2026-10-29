<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Presentation\Http\Request;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class UpdatePriorityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('queue.ticket.service');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'priority' => ['required', 'string', 'in:ROUTINE,PRIORITY,URGENT,EMERGENCY'],
            'return_to' => ['nullable', 'string', 'max:500'],
        ];
    }
}
