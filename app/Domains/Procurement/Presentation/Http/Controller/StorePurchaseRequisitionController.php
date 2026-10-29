<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Presentation\Http\Controller;

use App\Domains\Procurement\Application\Command\CreatePurchaseRequisition;
use App\Domains\Procurement\Application\RequisitionDepartmentScope;
use App\Domains\Procurement\Presentation\Http\Request\StorePurchaseRequisitionRequest;
use App\Models\User;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class StorePurchaseRequisitionController
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly TenantContext $context,
        private readonly RequisitionDepartmentScope $departmentScope,
    ) {}

    public function __invoke(StorePurchaseRequisitionRequest $request): RedirectResponse
    {
        $facilityId = $this->context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'Select an active facility context.');

        $data = $request->validated();
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $departmentRef = isset($data['requesting_department_ref'])
            && is_string($data['requesting_department_ref'])
            && $data['requesting_department_ref'] !== ''
                ? $data['requesting_department_ref']
                : null;

        if (! $this->departmentScope->canCreateFor($user, $facilityId, $departmentRef)) {
            return back()
                ->withErrors(['request' => 'You may create requests only for an active department within your assigned scope.'])
                ->withInput();
        }

        $lines = array_map(static fn (array $line): array => [
            'lineType' => 'STOCK',
            'itemRef' => (string) $line['item_id'],
            'quantity' => (string) $line['quantity'],
            'unit' => isset($line['unit']) && trim((string) $line['unit']) !== '' ? strtoupper(trim((string) $line['unit'])) : null,
            'packSize' => isset($line['pack_size']) && trim((string) $line['pack_size']) !== '' ? (string) $line['pack_size'] : null,
        ], $data['lines']);

        try {
            $this->commands->dispatch(new CreatePurchaseRequisition(
                requisitionId: (string) Str::uuid7(),
                actorId: (string) $user->id,
                facilityId: $facilityId,
                lines: $lines,
                priority: (string) $data['priority'],
                requestingDepartmentRef: $departmentRef,
                reason: isset($data['reason']) && is_string($data['reason']) && $data['reason'] !== ''
                    ? $data['reason']
                    : null,
                costCentreRef: isset($data['cost_centre_ref']) && is_string($data['cost_centre_ref']) && $data['cost_centre_ref'] !== ''
                    ? $data['cost_centre_ref']
                    : null,
                budgetRef: isset($data['budget_ref']) && is_string($data['budget_ref']) && $data['budget_ref'] !== ''
                    ? $data['budget_ref']
                    : null,
            ));
        } catch (DomainException|InvalidArgumentException $exception) {
            return back()->withErrors(['request' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Request created as a draft.');
    }
}
