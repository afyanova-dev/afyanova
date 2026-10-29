<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Presentation\Http\Request;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class AuthorizeEncounterProcedureRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('clinical.procedures.request');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
