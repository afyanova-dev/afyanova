<?php

declare(strict_types=1);

namespace App\Domains\Billing\Presentation\Http\Controller;

use App\Domains\Billing\Application\Command\AllocatePayment;
use App\Domains\Billing\Application\Command\OpenFinancialAccount;
use App\Domains\Billing\Application\Command\RecordPayment;
use App\Domains\Billing\Application\Query\GetFacilityCashSessions;
use App\Domains\Billing\Application\Query\GetFinancialAccountByPatient;
use App\Domains\Billing\Contracts\Dto\CashSessionSummary;
use App\Domains\Billing\Contracts\Dto\FinancialAccountSummary;
use App\Domains\Billing\Presentation\Http\Request\AllocatePaymentRequest;
use App\Domains\Billing\Presentation\Http\Request\RecordPaymentRequest;
use App\Models\User;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Domain\DomainException;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class PaymentController
{
    public function store(
        RecordPaymentRequest $request,
        TenantContext $context,
        CommandBus $commandBus,
        QueryBus $queryBus,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        $patientId = (string) $request->input('patient_id');
        $method = (string) $request->input('method');
        $actorId = (string) $user->getAuthIdentifier();

        // A refused payment must leave no trace: CASH needs the cashier's live
        // session, so verify it BEFORE the account is auto-opened (the handler
        // re-enforces this under lock — this pre-check only keeps failures clean).
        if ($method === 'CASH' && ! $this->hasLiveSession($queryBus, $facilityId, $actorId)) {
            return back()->withErrors(['payment' => 'No till session is open. Open a session before taking cash — nothing was recorded.']);
        }

        /** @var FinancialAccountSummary|null */
        $account = $queryBus->dispatch(new GetFinancialAccountByPatient($patientId, $facilityId));

        if ($account === null) {
            if (! $user->can('billing.accounts.manage')) {
                return back()->withErrors(['payment' => 'No open financial account for this patient. Ask a billing administrator to open one first.']);
            }

            $accountId = (string) Str::uuid7();
            try {
                $commandBus->dispatch(new OpenFinancialAccount(
                    financialAccountId: $accountId,
                    actorId: (string) $user->getAuthIdentifier(),
                    patientId: $patientId,
                    facilityId: $facilityId,
                ));
            } catch (AuthorizationException) {
                return back()->withErrors(['payment' => 'Not authorized to open a financial account.']);
            } catch (DomainException $e) {
                return back()->withErrors(['payment' => $e->getMessage()]);
            }
        } else {
            $accountId = $account->financialAccountId;
        }

        $paymentId = (string) Str::uuid7();
        try {
            $commandBus->dispatch(new RecordPayment(
                paymentId: $paymentId,
                actorId: (string) $user->getAuthIdentifier(),
                financialAccountId: $accountId,
                amount: (string) $request->input('amount'),
                method: (string) $request->input('method'),
                paymentReference: $request->filled('payment_reference') ? trim((string) $request->input('payment_reference')) : null,
            ));
        } catch (AuthorizationException) {
            return back()->withErrors(['payment' => 'Not authorized to record a payment.']);
        } catch (DomainException $e) {
            return back()->withErrors(['payment' => $e->getMessage()]);
        }

        return redirect()->route('billing.index', ['patient_id' => $patientId])
            ->with('status', 'Payment recorded. Receipt issued.')
            ->with('recorded_payment_id', $paymentId);
    }

    public function allocate(
        AllocatePaymentRequest $request,
        TenantContext $context,
        CommandBus $commandBus,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        try {
            $commandBus->dispatch(new AllocatePayment(
                actorId: (string) $user->getAuthIdentifier(),
                allocationId: (string) Str::uuid7(),
                paymentId: (string) $request->input('payment_id'),
                invoiceId: (string) $request->input('invoice_id'),
                amount: (string) $request->input('amount'),
            ));
        } catch (AuthorizationException) {
            return back()->withErrors(['payment' => 'Not authorized to allocate a payment.']);
        } catch (DomainException $e) {
            return back()->withErrors(['payment' => $e->getMessage()]);
        }

        return back()->with('status', 'Payment allocated to invoice.');
    }

    private function hasLiveSession(QueryBus $queryBus, string $facilityId, string $cashierUserId): bool
    {
        /** @var list<CashSessionSummary> $sessions */
        $sessions = $queryBus->dispatch(new GetFacilityCashSessions($facilityId));

        foreach ($sessions as $session) {
            if ($session->cashierUserId === $cashierUserId && in_array($session->status, ['OPEN', 'ACTIVE'], true)) {
                return true;
            }
        }

        return false;
    }
}
