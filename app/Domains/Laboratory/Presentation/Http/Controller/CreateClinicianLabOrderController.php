<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Presentation\Http\Controller;

use App\Domains\Laboratory\Application\Command\CreateLabOrder;
use App\Domains\Laboratory\Application\Command\SubmitLabOrder;
use App\Domains\Laboratory\Presentation\Http\Request\CreateClinicianLabOrderRequest;
use App\Models\User;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;

final class CreateClinicianLabOrderController
{
    public function __invoke(
        CreateClinicianLabOrderRequest $request,
        CommandBus $commandBus,
        string $encounter,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        /** @var array{
         *     order_id: string,
         *     idempotency_key: string,
         *     submit_idempotency_key: string,
         *     patient_id: string,
         *     test_definition_ids?: list<string>,
         *     panel_ids?: list<string>,
         *     priority?: string|null,
         *     clinical_indication_text?: string|null
         * } $data
         */
        $data = $request->validated();

        try {
            $commandBus->dispatch(new CreateLabOrder(
                orderId: $data['order_id'],
                actorId: (string) $user->getAuthIdentifier(),
                patientId: $data['patient_id'],
                encounterId: $encounter,
                testDefinitionIds: $data['test_definition_ids'] ?? [],
                panelIds: $data['panel_ids'] ?? [],
                priority: $data['priority'] ?? 'ROUTINE',
                clinicalIndicationText: $data['clinical_indication_text'] ?? null,
                idempotencyKey: $data['idempotency_key'],
            ));

            $commandBus->dispatch(new SubmitLabOrder(
                orderId: $data['order_id'],
                actorId: (string) $user->getAuthIdentifier(),
                idempotencyKey: $data['submit_idempotency_key'],
            ));
        } catch (DomainException|InvalidArgumentException $exception) {
            return back()->withErrors(['lab_order_error' => $exception->getMessage()]);
        }

        return back()->with('status', 'Lab order submitted.');
    }
}
