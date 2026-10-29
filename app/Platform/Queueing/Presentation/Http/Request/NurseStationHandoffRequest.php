<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Presentation\Http\Request;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class NurseStationHandoffRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User
            && $user->can('queue.ticket.transfer')
            && $user->can('clinical.triage.read');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'destination_queue_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
