<?php

declare(strict_types=1);

namespace App\Domains\Billing\Presentation\Http\Controller;

use App\Domains\Billing\Application\Command\CreateInvoice;
use App\Domains\Billing\Application\Command\IssueInvoice;
use App\Domains\Billing\Presentation\Http\Request\CreateInvoiceRequest;
use App\Models\User;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class InvoiceController
{
    public function store(
        CreateInvoiceRequest $request,
        TenantContext $context,
        CommandBus $commandBus,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        $invoiceId = (string) Str::uuid7();
        /** @var list<string>|null $chargeIds */
        $chargeIds = $request->filled('charge_ids') ? array_values((array) $request->input('charge_ids')) : null;

        try {
            $commandBus->dispatch(new CreateInvoice(
                invoiceId: $invoiceId,
                actorId: (string) $user->getAuthIdentifier(),
                financialAccountId: (string) $request->input('financial_account_id'),
                chargeIds: $chargeIds,
            ));
        } catch (AuthorizationException) {
            return back()->withErrors(['invoice' => 'Not authorized to create an invoice.']);
        } catch (DomainException $e) {
            return back()->withErrors(['invoice' => $e->getMessage()]);
        }

        try {
            $commandBus->dispatch(new IssueInvoice(
                actorId: (string) $user->getAuthIdentifier(),
                invoiceId: $invoiceId,
            ));
        } catch (AuthorizationException) {
            return back()->withErrors(['invoice' => 'Invoice created as DRAFT but could not be issued: not authorized.']);
        } catch (DomainException $e) {
            return back()->withErrors(['invoice' => 'Invoice created as DRAFT but could not be issued: '.$e->getMessage()]);
        }

        return redirect()->route('billing.index')->with('status', 'Invoice issued.');
    }

    public function issue(
        Request $request,
        TenantContext $context,
        CommandBus $commandBus,
        string $invoiceId,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($user->can('billing.invoices.manage'), 403, 'Unauthorized.');

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        if (! Str::isUuid($invoiceId)) {
            return back()->withErrors(['invoice' => 'Unknown invoice.']);
        }

        try {
            $commandBus->dispatch(new IssueInvoice(
                actorId: (string) $user->getAuthIdentifier(),
                invoiceId: $invoiceId,
            ));
        } catch (AuthorizationException) {
            return back()->withErrors(['invoice' => 'Not authorized to issue this invoice.']);
        } catch (DomainException $e) {
            return back()->withErrors(['invoice' => $e->getMessage()]);
        }

        return redirect()->route('billing.index')->with('status', 'Invoice issued.');
    }
}
