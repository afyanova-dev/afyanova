<?php

declare(strict_types=1);

namespace App\Domains\Billing\Presentation\Http\DTO;

use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Cashier workspace root props (UI-SPECIFICATIONS.md §11.14, cashier phase 1:
 * session → account → invoice → payment → receipt → allocate).
 *
 * The selected account bundle is null until the cashier picks a patient
 * (`?patient_id=`); money figures are reader-derived strings, never computed
 * in the page. Permission flags drive show/disable only — every writer
 * re-authorizes server-side.
 */
#[TypeScript]
final readonly class CashierDashboardProps
{
    /**
     * @param  list<CashierInvoiceItem>  $invoices
     * @param  list<CashierChargeItem>  $uninvoicedCharges
     * @param  list<CashierPaymentItem>  $payments
     * @param  list<CashierPatientResult>|null  $searchResults
     */
    public function __construct(
        public string $facilityId,
        public ?CashierSessionItem $mySession,
        public bool $canManageSessions,
        public bool $canManageInvoices,
        public bool $canRecordPayments,
        public ?string $accountId = null,
        public ?string $accountNumber = null,
        public ?string $patientId = null,
        public ?string $patientDisplayName = null,
        public ?string $patientNumber = null,
        public ?string $outstanding = null,
        public ?string $chargedTotal = null,
        public ?string $settledTotal = null,
        public ?string $currency = null,
        #[LiteralTypeScriptType('Array<App.Domains.Billing.Presentation.Http.DTO.CashierInvoiceItem>')]
        public array $invoices = [],
        #[LiteralTypeScriptType('Array<App.Domains.Billing.Presentation.Http.DTO.CashierChargeItem>')]
        public array $uninvoicedCharges = [],
        #[LiteralTypeScriptType('Array<App.Domains.Billing.Presentation.Http.DTO.CashierPaymentItem>')]
        public array $payments = [],
        public ?string $searchError = null,
        public ?string $query = null,
        #[LiteralTypeScriptType('Array<App.Domains.Billing.Presentation.Http.DTO.CashierPatientResult>|null')]
        public ?array $searchResults = null,
    ) {}
}
