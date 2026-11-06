<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Presentation\Http\Request;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class AmendClinicalNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('clinical.notes.amend');
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'amendment_type' => ['required', 'string', 'in:AMENDMENT,ADDENDUM,CORRECTION'],
            'body' => ['required', 'string', 'max:20000'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
