<?php

declare(strict_types=1);

namespace App\Domains\Billing\Presentation\Http\Controller;

use App\Domains\Billing\Application\Command\AddBillingService;
use App\Domains\Billing\Application\Command\DeactivateItemBillingMap;
use App\Domains\Billing\Application\Command\EndTariff;
use App\Domains\Billing\Application\Command\MapItemToBillingService;
use App\Domains\Billing\Application\Command\SetBillingServiceStatus;
use App\Domains\Billing\Application\Command\SetTariff;
use App\Domains\Billing\Application\Command\UpdateBillingService;
use App\Domains\Billing\Domain\ValueObject\BillingServiceCategory;
use App\Domains\Billing\Presentation\Http\Presenter\CatalogPresenter;
use App\Domains\Billing\Presentation\Http\Request\AddBillingServiceRequest;
use App\Domains\Billing\Presentation\Http\Request\MapItemBillingRequest;
use App\Domains\Billing\Presentation\Http\Request\SetTariffRequest;
use App\Domains\Billing\Presentation\Http\Request\UpdateBillingServiceRequest;
use App\Models\User;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class CatalogController
{
    public function index(
        Request $request,
        TenantContext $context,
        CatalogPresenter $presenter,
    ): Response {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($user->can('billing.access'), 403, 'Unauthorized.');

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        $category = strtoupper(trim((string) $request->query('category', '')));
        $category = in_array($category, BillingServiceCategory::ALL, true) ? $category : null;

        $status = strtoupper(trim((string) $request->query('status', '')));
        $status = in_array($status, ['ACTIVE', 'INACTIVE'], true) ? $status : null;

        $selected = $request->query('service_id');
        $selected = is_string($selected) && Str::isUuid($selected) ? $selected : null;

        $props = $presenter->present(
            facilityId: $facilityId,
            category: $category,
            status: $status,
            selectedServiceId: $selected,
            permissions: [
                'catalog' => $user->can('billing.catalog.manage'),
                'retry' => $user->can('billing.charges.manage'),
            ],
        );

        return Inertia::render('Billing/Catalog', [
            'facilityId' => $props->facilityId,
            'canManageCatalog' => $props->canManageCatalog,
            'canRetryCharges' => $props->canRetryCharges,
            'services' => $props->services,
            'category' => $props->category,
            'status' => $props->status,
            'selectedServiceId' => $props->selectedServiceId,
            'selectedCode' => $props->selectedCode,
            'selectedName' => $props->selectedName,
            'selectedCategory' => $props->selectedCategory,
            'selectedStatus' => $props->selectedStatus,
            'facilityAmount' => $props->facilityAmount,
            'facilityEffectiveFrom' => $props->facilityEffectiveFrom,
            'defaultAmount' => $props->defaultAmount,
            'defaultEffectiveFrom' => $props->defaultEffectiveFrom,
            'currency' => $props->currency,
            'pendingEvents' => $props->pendingEvents,
            'tariffHistory' => $props->tariffHistory,
            'medicationMaps' => $props->medicationMaps,
            'itemMapHistory' => $props->itemMapHistory,
            'searchError' => $props->searchError,
        ]);
    }

    public function store(
        AddBillingServiceRequest $request,
        TenantContext $context,
        CommandBus $commandBus,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        $serviceId = (string) Str::uuid7();
        try {
            $commandBus->dispatch(new AddBillingService(
                billingServiceId: $serviceId,
                actorId: (string) $user->getAuthIdentifier(),
                code: (string) $request->input('code'),
                name: (string) $request->input('name'),
                category: (string) $request->input('category'),
            ));
        } catch (AuthorizationException) {
            return back()->withErrors(['catalog' => 'Not authorized to manage the service catalog.']);
        } catch (DomainException $e) {
            return back()->withErrors(['catalog' => $e->getMessage()]);
        }

        return redirect()->route('billing.catalog.index', ['service_id' => $serviceId])
            ->with('status', 'Billing service added. Set its tariff to make it billable.');
    }

    public function update(
        UpdateBillingServiceRequest $request,
        TenantContext $context,
        CommandBus $commandBus,
        string $billingServiceId,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        if (! Str::isUuid($billingServiceId)) {
            return back()->withErrors(['catalog' => 'Unknown billing service.']);
        }

        try {
            $commandBus->dispatch(new UpdateBillingService(
                billingServiceId: $billingServiceId,
                actorId: (string) $user->getAuthIdentifier(),
                name: (string) $request->input('name'),
                category: (string) $request->input('category'),
            ));
        } catch (AuthorizationException) {
            return back()->withErrors(['catalog' => 'Not authorized to manage the service catalog.']);
        } catch (DomainException $e) {
            return back()->withErrors(['catalog' => $e->getMessage()]);
        }

        return back()->with('status', 'Billing service updated.');
    }

    public function setStatus(
        Request $request,
        TenantContext $context,
        CommandBus $commandBus,
        string $billingServiceId,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($user->can('billing.catalog.manage'), 403, 'Unauthorized.');

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        if (! Str::isUuid($billingServiceId)) {
            return back()->withErrors(['catalog' => 'Unknown billing service.']);
        }

        $status = strtoupper(trim((string) $request->input('status', '')));
        if (! in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
            return back()->withErrors(['catalog' => 'Status must be ACTIVE or INACTIVE.']);
        }

        try {
            $commandBus->dispatch(new SetBillingServiceStatus(
                billingServiceId: $billingServiceId,
                actorId: (string) $user->getAuthIdentifier(),
                status: $status,
            ));
        } catch (AuthorizationException) {
            return back()->withErrors(['catalog' => 'Not authorized to manage the service catalog.']);
        } catch (DomainException $e) {
            return back()->withErrors(['catalog' => $e->getMessage()]);
        }

        return back()->with('status', "Service {$status}.");
    }

    public function setTariff(
        SetTariffRequest $request,
        TenantContext $context,
        CommandBus $commandBus,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        $serviceId = (string) $request->input('billing_service_id');
        $facilityScoped = $request->boolean('facility_scope', true);

        try {
            $commandBus->dispatch(new SetTariff(
                tariffId: (string) Str::uuid7(),
                actorId: (string) $user->getAuthIdentifier(),
                billingServiceId: $serviceId,
                facilityId: $facilityScoped ? $facilityId : null,
                amount: (string) $request->input('amount'),
                effectiveFrom: (string) $request->input('effective_from'),
            ));
        } catch (AuthorizationException) {
            return back()->withErrors(['catalog' => 'Not authorized to manage the service catalog.']);
        } catch (DomainException $e) {
            return back()->withErrors(['catalog' => $e->getMessage()]);
        }

        return redirect()->route('billing.catalog.index', ['service_id' => $serviceId])
            ->with('status', 'New tariff row set. History is preserved.');
    }

    public function endTariff(
        Request $request,
        TenantContext $context,
        CommandBus $commandBus,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($user->can('billing.catalog.manage'), 403, 'Unauthorized.');

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        $validated = $request->validate([
            'billing_service_id' => ['required', 'uuid'],
            'effective_to' => ['required', 'date_format:Y-m-d'],
            'facility_scope' => ['nullable', 'boolean'],
        ]);

        try {
            $commandBus->dispatch(new EndTariff(
                actorId: (string) $user->getAuthIdentifier(),
                billingServiceId: $validated['billing_service_id'],
                facilityId: $request->boolean('facility_scope', true) ? $facilityId : null,
                effectiveTo: $validated['effective_to'],
            ));
        } catch (AuthorizationException) {
            return back()->withErrors(['catalog' => 'Not authorized to manage the service catalog.']);
        } catch (DomainException $e) {
            return back()->withErrors(['catalog' => $e->getMessage()]);
        }

        return back()->with('status', 'Tariff row ended. The service is unpriced from that date.');
    }

    /**
     * Map a medication (`inv_items.id`) to a billing service (ADR-189 D-A). The
     * handler supersedes the item's prior active mapping and sweeps any
     * `ITEM_UNMAPPED` pending events for the item — so the success message says
     * exactly what happened (a tariff change does NOT retry, §131).
     */
    public function mapItem(
        MapItemBillingRequest $request,
        TenantContext $context,
        CommandBus $commandBus,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        try {
            $commandBus->dispatch(new MapItemToBillingService(
                mapId: (string) Str::uuid7(),
                actorId: (string) $user->getAuthIdentifier(),
                invItemId: (string) $request->input('item_id'),
                billingServiceId: (string) $request->input('billing_service_id'),
            ));
        } catch (AuthorizationException) {
            return back()->withErrors(['catalog' => 'Not authorized to manage the service catalog.']);
        } catch (DomainException $e) {
            return back()->withErrors(['catalog' => $e->getMessage()]);
        }

        return back()->with('status', 'Medication mapped. Unmapped events for this item were retried.');
    }

    /**
     * Remove a medication's active mapping (ADR-189 D-A). A subsequent
     * `MedicationDispensed` for the item then captures a PENDING /
     * `ITEM_UNMAPPED` event (visible on the worklist).
     */
    public function deactivateItemMap(
        Request $request,
        TenantContext $context,
        CommandBus $commandBus,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($user->can('billing.catalog.manage'), 403, 'Unauthorized.');

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        $validated = $request->validate([
            'item_id' => ['required', 'uuid'],
        ]);

        try {
            $commandBus->dispatch(new DeactivateItemBillingMap(
                actorId: (string) $user->getAuthIdentifier(),
                invItemId: $validated['item_id'],
            ));
        } catch (AuthorizationException) {
            return back()->withErrors(['catalog' => 'Not authorized to manage the service catalog.']);
        } catch (DomainException $e) {
            return back()->withErrors(['catalog' => $e->getMessage()]);
        }

        return back()->with('status', 'Mapping removed. New dispensing of this item is flagged ITEM_UNMAPPED.');
    }
}
