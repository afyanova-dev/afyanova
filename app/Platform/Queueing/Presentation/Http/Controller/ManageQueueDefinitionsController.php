<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Presentation\Http\Controller;

use App\Models\User;
use App\Platform\Queueing\Application\Command\RegisterQueueCommand;
use App\Platform\Queueing\Domain\Enum\ServiceCapability;
use App\Platform\Queueing\Domain\Model\Queue;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class ManageQueueDefinitionsController
{
    public function store(Request $request, TenantContext $context, CommandBus $commandBus): RedirectResponse
    {
        return $this->save($request, $context, $commandBus);
    }

    public function update(
        Request $request,
        TenantContext $context,
        CommandBus $commandBus,
        string $queue,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($user->can('queue.definition.manage'), 403, 'You cannot manage queue definitions.');

        $tenantId = $context->tenantId();
        $facilityId = $context->facilityId();
        abort_if($tenantId === '' || $facilityId === null || $facilityId === '', 403, 'No active facility context.');

        $target = Queue::query()
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->findOrFail($queue);

        return $this->save($request, $context, $commandBus, $target);
    }

    private function save(
        Request $request,
        TenantContext $context,
        CommandBus $commandBus,
        ?Queue $target = null,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($user->can('queue.definition.manage'), 403, 'You cannot manage queue definitions.');

        $tenantId = $context->tenantId();
        $facilityId = $context->facilityId();
        abort_if($tenantId === '' || $facilityId === null || $facilityId === '', 403, 'No active facility context.');

        $request->merge([
            'code' => $target->code ?? Str::upper(trim((string) $request->input('code', ''))),
            'ticket_prefix' => Str::upper(trim((string) $request->input('ticket_prefix', ''))),
        ]);

        $rules = [
            'name' => ['required', 'string', 'max:100'],
            'department_id' => [
                'nullable',
                'uuid',
                Rule::exists('org_departments', 'id')->where(
                    function ($query) use ($tenantId, $facilityId, $target): void {
                        $query
                            ->where('tenant_id', $tenantId)
                            ->where('facility_id', $facilityId)
                            ->where(function ($query) use ($target): void {
                                $query->where('status', 'ACTIVE');
                                if ($target?->department_id !== null) {
                                    $query->orWhere('id', $target->department_id);
                                }
                            });
                    },
                ),
            ],
            'service_capability' => [
                'nullable',
                Rule::in(array_map(
                    static fn (ServiceCapability $capability): string => $capability->value,
                    ServiceCapability::cases(),
                )),
            ],
            'ticket_prefix' => ['required', 'string', 'max:5', 'regex:/^[A-Z0-9]+$/'],
            'is_active' => ['required', 'boolean'],
        ];

        if ($target === null) {
            $rules['code'] = [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Z0-9][A-Z0-9_-]*$/',
                Rule::unique('qms_queues', 'code')->where(
                    fn ($query) => $query
                        ->where('tenant_id', $tenantId)
                        ->where('facility_id', $facilityId),
                ),
            ];
        }

        $validated = $request->validate($rules);
        $code = $target->code ?? $validated['code'];

        $commandBus->dispatch(new RegisterQueueCommand(
            facilityId: $facilityId,
            code: $code,
            name: $validated['name'],
            actorId: (string) $user->getKey(),
            departmentId: $validated['department_id'] ?? null,
            serviceCapability: $validated['service_capability'] ?? null,
            ticketPrefix: $validated['ticket_prefix'],
            isActive: $request->boolean('is_active'),
        ));

        return redirect()
            ->route('service-queues.manage')
            ->with('status', $target === null ? 'Queue created.' : 'Queue updated.');
    }
}
