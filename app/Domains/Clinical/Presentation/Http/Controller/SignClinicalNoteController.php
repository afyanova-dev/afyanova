<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Presentation\Http\Controller;

use App\Domains\Clinical\Application\Command\SignClinicalNote;
use App\Domains\Clinical\Presentation\Http\Request\SignClinicalNoteRequest;
use App\Models\User;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use Illuminate\Http\RedirectResponse;

final class SignClinicalNoteController
{
    public function __invoke(SignClinicalNoteRequest $request, CommandBus $commandBus, string $note): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        try {
            $commandBus->dispatch(new SignClinicalNote(
                clinicalNoteId: $note,
                actorId: (string) $user->getAuthIdentifier(),
            ));
        } catch (DomainException $exception) {
            return back()->withErrors(['note_action_error' => $exception->getMessage()]);
        }

        return back()->with('status', 'Clinical note signed.');
    }
}
