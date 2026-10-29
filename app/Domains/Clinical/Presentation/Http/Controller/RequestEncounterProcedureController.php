<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Presentation\Http\Controller;

use App\Domains\Clinical\Application\Command\RequestProcedure;
use App\Domains\Clinical\Application\Command\RequestProcedureResult;
use App\Domains\Clinical\Domain\Enum\ClinicalAuthoredContext;
use App\Domains\Clinical\Presentation\Http\Request\RequestEncounterProcedureRequest;
use App\Models\User;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

final class RequestEncounterProcedureController
{
    public function __invoke(
        RequestEncounterProcedureRequest $request,
        CommandBus $commandBus,
        string $encounter,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $data = $request->validated();

        try {
            /** @var RequestProcedureResult $result */
            $result = $commandBus->dispatch(new RequestProcedure(
                procedureId: (string) Str::uuid7(),
                actorId: (string) $user->getAuthIdentifier(),
                patientId: $data['patient_id'],
                encounterId: $encounter,
                definitionId: $data['definition_id'],
                indication: $data['indication'] ?? null,
                authoredContext: ClinicalAuthoredContext::Clinician->value,
            ));
        } catch (DomainException $exception) {
            return back()->withErrors(['procedure_error' => $exception->getMessage()]);
        }

        return back()->with('status', "Procedure order {$result->procedureNumber} requested. It must be authorized before service routing.");
    }
}
