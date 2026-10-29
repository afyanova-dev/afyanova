<?php

declare(strict_types=1);

namespace App\Domains\Billing\Presentation\Http\Controller;

use App\Domains\Billing\Presentation\Http\DTO\CashierPatientResult;
use App\Domains\Billing\Presentation\Http\Presenter\CashierPresenter;
use App\Domains\Patient\Contracts\Dto\PatientSearchResult;
use App\Domains\Patient\Contracts\PatientApi;
use App\Models\User;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class CashierController
{
    public function __invoke(
        Request $request,
        TenantContext $context,
        CashierPresenter $presenter,
        PatientApi $patientApi,
    ): Response {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($user->can('billing.access'), 403, 'Unauthorized.');

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        $patientId = $request->query('patient_id');
        $patientId = is_string($patientId) && Str::isUuid($patientId) ? $patientId : null;

        $query = $patientId === null && $request->filled('q') ? mb_substr(trim((string) $request->input('q')), 0, 80) : null;
        $searchResults = $query !== null && $query !== '' ? $this->search($patientApi, $query) : null;

        $props = $presenter->present(
            facilityId: $facilityId,
            cashierUserId: (string) $user->getAuthIdentifier(),
            patientId: $patientId,
            permissions: [
                'sessions' => $user->can('billing.cash_sessions.manage'),
                'invoices' => $user->can('billing.invoices.manage'),
                'payments' => $user->can('billing.payments.manage'),
            ],
            query: $query,
            searchResults: $searchResults,
        );

        return Inertia::render('Billing/Index', [
            'facilityId' => $props->facilityId,
            'mySession' => $props->mySession,
            'canManageSessions' => $props->canManageSessions,
            'canManageInvoices' => $props->canManageInvoices,
            'canRecordPayments' => $props->canRecordPayments,
            'accountId' => $props->accountId,
            'accountNumber' => $props->accountNumber,
            'patientId' => $props->patientId,
            'patientDisplayName' => $props->patientDisplayName,
            'patientNumber' => $props->patientNumber,
            'outstanding' => $props->outstanding,
            'chargedTotal' => $props->chargedTotal,
            'settledTotal' => $props->settledTotal,
            'currency' => $props->currency,
            'invoices' => $props->invoices,
            'uninvoicedCharges' => $props->uninvoicedCharges,
            'payments' => $props->payments,
            'searchError' => $props->searchError,
            'query' => $props->query,
            'searchResults' => $props->searchResults,
        ]);
    }

    /**
     * MPI lookup for the find-account search — same classification as the
     * Reception intake console (patient number / phone / name fragment);
     * Patient owns the search truth.
     *
     * @return list<CashierPatientResult>
     */
    private function search(PatientApi $patientApi, string $query): array
    {
        $cleanDigits = (string) preg_replace('/\D+/', '', $query);
        $isPatPrefix = str_starts_with(strtoupper($query), 'PAT-')
            || str_starts_with(strtoupper($query), 'PAT ')
            || preg_match('/^PAT\d+$/i', $query) === 1;

        $patientNumber = null;
        $phone = null;
        $nameFragment = null;

        if ($isPatPrefix || preg_match('/^#\d+$/', $query) === 1) {
            $patientNumber = ltrim($query, '#');
        } elseif ($cleanDigits !== '' && strlen($cleanDigits) >= 9 && (str_starts_with($query, '+') || str_starts_with($query, '0') || strlen($cleanDigits) >= 10)) {
            $phone = $cleanDigits;
        } elseif (mb_strlen(str_replace(' ', '', $query)) >= 3) {
            $nameFragment = $query;
        } else {
            return [];
        }

        try {
            /** @var LengthAwarePaginator<int, PatientSearchResult> $paginator */
            $paginator = $patientApi->searchPatients(
                patientNumber: $patientNumber,
                phone: $phone,
                nameFragment: $nameFragment,
                perPage: 10,
            );
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_map(static fn (PatientSearchResult $r): CashierPatientResult => new CashierPatientResult(
            patientId: $r->patientId,
            patientNumber: $r->patientNumber,
            displayName: $r->displayName,
            sex: $r->sex,
            status: $r->status,
            isProvisional: $r->isProvisional,
            hasAccessibleFacilityRegistration: $r->hasAccessibleFacilityRegistration,
            survivingPatientId: $r->survivingPatientId,
        ), $paginator->items()));
    }
}
