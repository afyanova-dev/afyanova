<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Presentation\Http\Controller;

use App\Domains\Clinical\Application\Command\AddProcedureNote;
use App\Domains\Clinical\Application\Command\CompleteProcedure;
use App\Domains\Clinical\Application\Command\MarkProcedureReady;
use App\Domains\Clinical\Application\Command\RecordProcedureMaterials;
use App\Domains\Clinical\Application\Command\RecordProcedureOutcome;
use App\Domains\Clinical\Application\Command\StartProcedure;
use App\Domains\Clinical\Application\Query\GetClinicalNote;
use App\Domains\Clinical\Application\Query\GetProcedure;
use App\Domains\Clinical\Application\Query\GetProcedureDefinition;
use App\Domains\Clinical\Contracts\ClinicalApi;
use App\Domains\Clinical\Contracts\Dto\ProcedureDefinitionSummary;
use App\Domains\Clinical\Contracts\Dto\ProcedureSummary;
use App\Domains\Clinical\Domain\Enum\ClinicalAuthoredContext;
use App\Models\User;
use App\Platform\Queueing\Contracts\DTO\QueueDto;
use App\Platform\Queueing\Contracts\DTO\QueueEntryDto;
use App\Platform\Queueing\Contracts\QueueingApi;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Domain\DomainException;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

final class ProcedureWorkController
{
    public function show(
        Request $request,
        TenantContext $context,
        QueryBus $queryBus,
        ClinicalApi $clinicalApi,
        QueueingApi $queueingApi,
        string $entry,
    ): Response {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $facilityId = $this->facilityId($context);
        $this->assertCanView($user);
        $ticket = $this->ticket($entry, $facilityId, $queueingApi);
        $queue = $this->assertProcedureTicket($ticket, $queueingApi);
        $this->assertAssignedProcedureRoom($user, $ticket, $context, $queueingApi);

        /** @var ProcedureSummary $procedure */
        $procedure = $queryBus->dispatch(new GetProcedure((string) $ticket->sourceOrderRef));
        abort_unless(
            $procedure->facilityId === $facilityId
                && $procedure->encounterId === $ticket->encounterId
                && $procedure->patientId === $ticket->patientId,
            404,
        );
        /** @var ProcedureDefinitionSummary $definition */
        $definition = $queryBus->dispatch(new GetProcedureDefinition($procedure->definitionId));
        $woundNote = null;
        if ($procedure->clinicalNoteId !== null && $user->can('clinical.notes.read')) {
            $woundNote = $queryBus->dispatch(new GetClinicalNote($procedure->clinicalNoteId));
            abort_unless(
                $woundNote->patientId === $procedure->patientId
                    && $woundNote->encounterId === $procedure->encounterId
                    && $woundNote->facilityId === $procedure->facilityId,
                404,
            );
        }
        $materials = $clinicalApi->getProcedureMaterials($context->tenantId(), $facilityId, $procedure->procedureId);

        return Inertia::render('ServiceQueues/ProcedureWork', [
            'entry' => [
                'id' => $ticket->id,
                'ticketNumber' => $ticket->ticketNumber,
                'queue' => $queue->name,
                'status' => $ticket->status,
            ],
            'procedure' => [
                'procedureId' => $procedure->procedureId,
                'procedureNumber' => $procedure->procedureNumber,
                'name' => $definition->name,
                'code' => $definition->code,
                'status' => $procedure->status,
                'indication' => $procedure->indication,
                'site' => $procedure->site,
                'laterality' => $procedure->laterality,
                'outcome' => $procedure->outcome,
                'outcomeDetail' => $procedure->outcomeDetail,
                'complications' => $procedure->complications,
                'requiresReadinessCheck' => $definition->requiresReadinessCheck,
                'requiresSite' => $definition->requiresSite,
                'requiresLaterality' => $definition->requiresLaterality,
                'clinicalNoteId' => $procedure->clinicalNoteId,
            ],
            'woundCare' => [
                'enabled' => $definition->code === 'DRESSING',
                'note' => $woundNote === null ? null : [
                    'title' => $woundNote->title,
                    'body' => $woundNote->body,
                    'status' => $woundNote->status,
                ],
                'materials' => $materials,
                'canRecordAssessment' => $definition->code === 'DRESSING'
                    && $user->can('clinical.procedures.perform')
                    && $user->can('clinical.notes.create')
                    && $procedure->clinicalNoteId === null,
                'canRecordMaterials' => $definition->code === 'DRESSING'
                    && $user->can('clinical.procedures.perform')
                    && in_array($procedure->status, ['IN_PROGRESS', 'COMPLETED'], true),
                'noteUnavailable' => $procedure->clinicalNoteId !== null
                    && ! $user->can('clinical.notes.read'),
            ],
            'canMarkReady' => $user->can('clinical.procedures.request'),
            'canPerform' => $user->can('clinical.procedures.perform'),
            'canComplete' => $user->can('clinical.procedures.complete'),
        ]);
    }

    public function update(
        Request $request,
        TenantContext $context,
        QueryBus $queryBus,
        CommandBus $commandBus,
        QueueingApi $queueingApi,
        string $entry,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $facilityId = $this->facilityId($context);
        $this->assertCanView($user);
        $ticket = $this->ticket($entry, $facilityId, $queueingApi);
        $this->assertProcedureTicket($ticket, $queueingApi);
        $this->assertAssignedProcedureRoom($user, $ticket, $context, $queueingApi);
        abort_unless($ticket->status === 'IN_SERVICE', 409, 'Start this queue ticket before working on its procedure.');

        /** @var ProcedureSummary $procedure */
        $procedure = $queryBus->dispatch(new GetProcedure((string) $ticket->sourceOrderRef));
        abort_unless(
            $procedure->facilityId === $facilityId
                && $procedure->encounterId === $ticket->encounterId
                && $procedure->patientId === $ticket->patientId,
            404,
        );
        /** @var ProcedureDefinitionSummary $definition */
        $definition = $queryBus->dispatch(new GetProcedureDefinition($procedure->definitionId));

        $action = $request->string('workflow_action')->toString();
        $actorId = (string) $user->getAuthIdentifier();

        if ($action === 'record-wound-assessment') {
            abort_unless($definition->code === 'DRESSING', 404);
            if ($procedure->clinicalNoteId !== null) {
                return back()->withErrors([
                    'wound_assessment' => 'A procedure note is already linked. Add a correction through the clinical note amendment workflow.',
                ]);
            }
            $woundData = $request->validate([
                'wound_assessment' => ['required', 'string', 'max:5000'],
                'dressing_performed' => ['required', 'string', 'max:5000'],
            ]);
            $body = "Wound assessment:\n".trim($woundData['wound_assessment'])
                ."\n\nDressing performed:\n".trim($woundData['dressing_performed']);

            try {
                $commandBus->dispatch(new AddProcedureNote(
                    procedureId: $procedure->procedureId,
                    actorId: $actorId,
                    body: $body,
                    title: 'Wound care documentation',
                    authoredContext: ClinicalAuthoredContext::Nursing->value,
                ));
            } catch (DomainException|InvalidArgumentException $exception) {
                return back()->withErrors(['wound_assessment' => $exception->getMessage()]);
            }

            return back()->with('status', 'Wound assessment and dressing performed were documented.');
        }

        if ($action === 'record-wound-materials') {
            abort_unless($definition->code === 'DRESSING', 404);
            $materialData = $request->validate([
                'material_lines' => ['required', 'array', 'min:1', 'max:30'],
                'material_lines.*.material_ref' => ['required', 'string', 'max:200'],
                'material_lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
                'material_lines.*.unit' => ['nullable', 'string', 'max:50'],
                'material_lines.*.batch_ref' => ['nullable', 'string', 'max:200'],
            ]);

            $materials = array_values(array_map(static fn (array $line): array => [
                'materialRef' => trim($line['material_ref']),
                'quantity' => (float) $line['quantity'],
                'unit' => isset($line['unit']) ? trim($line['unit']) : null,
                'batchRef' => isset($line['batch_ref']) ? trim($line['batch_ref']) : null,
            ], $materialData['material_lines']));

            try {
                $commandBus->dispatch(new RecordProcedureMaterials(
                    procedureId: $procedure->procedureId,
                    actorId: $actorId,
                    materials: $materials,
                ));
            } catch (DomainException|InvalidArgumentException $exception) {
                return back()->withErrors(['material_lines' => $exception->getMessage()]);
            }

            return back()->with('status', 'Materials used were recorded.');
        }

        $data = $request->validate([
            'workflow_action' => ['required', Rule::in(['ready', 'start', 'record-outcome', 'complete'])],
            'site' => [
                $action === 'start' && $definition->requiresSite ? 'required' : 'nullable',
                'string',
                'max:200',
            ],
            'laterality' => [
                $action === 'start' && $definition->requiresLaterality ? 'required' : 'nullable',
                'string',
                Rule::in(['LEFT', 'RIGHT', 'BILATERAL', 'NOT_APPLICABLE', 'UNKNOWN']),
            ],
            'outcome' => [
                $action === 'record-outcome' ? 'required' : 'nullable',
                Rule::in(['SUCCESSFUL', 'PARTIALLY_SUCCESSFUL', 'UNSUCCESSFUL']),
            ],
            'outcome_detail' => ['nullable', 'string', 'max:5000'],
            'complications' => ['nullable', 'string', 'max:3000'],
        ]);

        try {
            match ($action) {
                'ready' => $commandBus->dispatch(new MarkProcedureReady($procedure->procedureId, $actorId)),
                'start' => $commandBus->dispatch(new StartProcedure(
                    procedureId: $procedure->procedureId,
                    actorId: $actorId,
                    site: $data['site'] ?? null,
                    laterality: $data['laterality'] ?? null,
                )),
                'record-outcome' => $commandBus->dispatch(new RecordProcedureOutcome(
                    procedureId: $procedure->procedureId,
                    actorId: $actorId,
                    outcome: $data['outcome'] ?? null,
                    outcomeDetail: $data['outcome_detail'] ?? null,
                )),
                'complete' => $commandBus->dispatch(new CompleteProcedure(
                    procedureId: $procedure->procedureId,
                    actorId: $actorId,
                    outcome: $data['outcome'] ?? null,
                    outcomeDetail: $data['outcome_detail'] ?? null,
                    complications: $data['complications'] ?? null,
                )),
                default => abort(422, 'Choose a supported procedure action.'),
            };
        } catch (DomainException|InvalidArgumentException $exception) {
            return back()->withErrors(['workflow_action' => $exception->getMessage()]);
        }

        return back()->with('status', match ($action) {
            'ready' => 'Procedure marked ready.',
            'start' => 'Procedure started.',
            'record-outcome' => 'Procedure outcome saved.',
            'complete' => 'Procedure completed and documented.',
        });
    }

    private function facilityId(TenantContext $context): string
    {
        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        return $facilityId;
    }

    private function assertCanView(User $user): void
    {
        abort_unless($user->can('queue.ticket.view'), 403, 'You do not have access to service queues.');
        abort_unless($user->can('clinical.procedures.read'), 403, 'You do not have access to procedure records.');
    }

    private function ticket(string $entryId, string $facilityId, QueueingApi $queueingApi): QueueEntryDto
    {
        $ticket = $queueingApi->getEntry($entryId);
        abort_unless(
            $ticket !== null
                && $ticket->facilityId === $facilityId
                && in_array($ticket->status, ['CALLED', 'IN_SERVICE'], true),
            404,
        );

        return $ticket;
    }

    private function assertProcedureTicket(QueueEntryDto $ticket, QueueingApi $queueingApi): QueueDto
    {
        $queue = $queueingApi->getQueue($ticket->queueId);
        abort_unless(
            $ticket->orderType === 'PROCEDURE_BOOKING'
                && $ticket->sourceOrderRef !== null
                && $ticket->sourceOrderRef !== ''
                && $queue !== null
                && $queue->facilityId === $ticket->facilityId
                && $queue->serviceCapability === 'PROCEDURE',
            404,
        );

        return $queue;
    }

    private function assertAssignedProcedureRoom(
        User $user,
        QueueEntryDto $ticket,
        TenantContext $context,
        QueueingApi $queueingApi,
    ): void {
        abort_unless(
            $ticket->calledRoomId !== null
                && $queueingApi->userAssignedToRoom(
                    $context->tenantId(),
                    $ticket->facilityId,
                    $ticket->queueId,
                    $ticket->calledRoomId,
                    (string) $user->getAuthIdentifier(),
                ),
            403,
            'You are not assigned to this procedure room.',
        );
    }
}
