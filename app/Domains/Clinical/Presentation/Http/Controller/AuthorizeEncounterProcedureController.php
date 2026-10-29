<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Presentation\Http\Controller;

use App\Domains\Clinical\Application\Command\AuthorizeProcedure;
use App\Domains\Clinical\Application\Query\GetProcedure;
use App\Domains\Clinical\Contracts\Dto\ProcedureSummary;
use App\Domains\Clinical\Presentation\Http\Request\AuthorizeEncounterProcedureRequest;
use App\Models\User;
use App\Platform\Queueing\Contracts\QueueingApi;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Domain\DomainException;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;

final class AuthorizeEncounterProcedureController
{
    public function __invoke(
        AuthorizeEncounterProcedureRequest $request,
        CommandBus $commandBus,
        QueryBus $queryBus,
        QueueingApi $queueingApi,
        TenantContext $context,
        string $encounter,
        string $procedure,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        try {
            /** @var ProcedureSummary $summary */
            $summary = $queryBus->dispatch(new GetProcedure($procedure));
            abort_unless($summary->encounterId === $encounter, 404);
            $facilityId = $context->facilityId();
            abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');
            if (! $queueingApi->procedureQueueConfigured($facilityId, $summary->definitionId)) {
                return back()->withErrors([
                    'authorize_error' => 'Configure an active procedure queue with an active room before authorizing this order.',
                ]);
            }

            $commandBus->dispatch(new AuthorizeProcedure(
                procedureId: $procedure,
                actorId: (string) $user->getAuthIdentifier(),
            ));
        } catch (DomainException $exception) {
            return back()->withErrors(['authorize_error' => $exception->getMessage()]);
        }

        return back()->with('status', 'Procedure authorized. It will be routed to its configured service queue.');
    }
}
