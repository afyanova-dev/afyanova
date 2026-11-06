<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Presentation\Http\Controller;

use App\Domains\Clinical\Application\Command\AmendClinicalNote;
use App\Domains\Clinical\Presentation\Http\Request\AmendClinicalNoteRequest;
use App\Models\User;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;

final class AmendClinicalNoteController
{
    public function __invoke(AmendClinicalNoteRequest $request, CommandBus $commandBus, string $note): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $data = $request->validated();

        try {
            $commandBus->dispatch(new AmendClinicalNote(
                clinicalNoteId: $note,
                actorId: (string) $user->getAuthIdentifier(),
                amendmentType: $data['amendment_type'],
                body: $data['body'],
                reason: $data['reason'],
            ));
        } catch (DomainException|InvalidArgumentException $exception) {
            return back()->withErrors(['note_action_error' => $exception->getMessage()]);
        }

        return back()->with('status', 'Clinical note amendment recorded.');
    }
}
