import { Button, Select, SelectItem, TextInput } from '@carbon/react';
import { Link, router, usePage } from '@inertiajs/react';
import { useState, type FormEvent, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import { PageHeader } from '@/components/ui';
import { AppLayout } from '@/layouts/AppLayout';
import type { SharedProps } from '@/types/auth';
import { ArrowsHorizontal, Money, Search } from '@/icons';

interface CashierSession {
  cashSessionId: string;
  status: string;
  openingFloat: string;
  cashPaymentsTotal: string;
  expectedCash: string;
  countedCash: string | null;
  variance: string | null;
  currency: string;
  openedAt: string;
  closedAt: string | null;
}

interface InvoiceItem {
  invoiceId: string;
  invoiceNumber: string | null;
  status: string;
  net: string;
  paidAmount: string;
  paymentStatus: string;
  derivedOutstanding: string;
  currency: string;
  lineCount: number;
  issuedAt: string | null;
}

interface ChargeItem {
  chargeId: string;
  description: string;
  quantity: string;
  unitPrice: string;
  amount: string;
  status: string;
  currency: string;
  occurredAt: string;
}

interface PaymentItem {
  paymentId: string;
  receiptNumber: string;
  method: string;
  amount: string;
  status: string;
  unallocatedAmount: string;
  currency: string;
  receivedAt: string;
}

interface PatientResult {
  patientId: string;
  patientNumber: string;
  displayName: string;
  sex: string;
  status: string;
  isProvisional: boolean;
  hasAccessibleFacilityRegistration: boolean;
  survivingPatientId: string | null;
}

interface CashierPageProps {
  facilityId: string;
  mySession: CashierSession | null;
  canManageSessions: boolean;
  canManageInvoices: boolean;
  canRecordPayments: boolean;
  accountId: string | null;
  accountNumber: string | null;
  patientId: string | null;
  patientDisplayName: string | null;
  patientNumber: string | null;
  outstanding: string | null;
  chargedTotal: string | null;
  settledTotal: string | null;
  currency: string | null;
  invoices: InvoiceItem[];
  uninvoicedCharges: ChargeItem[];
  payments: PaymentItem[];
  searchError: string | null;
  query: string | null;
  searchResults: PatientResult[] | null;
}

export default function BillingIndex(props: CashierPageProps): ReactNode {
  const { props: shared } = usePage<SharedProps>();
  const errors = isStringRecord(shared.errors) ? shared.errors : {};
  const [search, setSearch] = useState(props.query ?? '');
  const [openingFloat, setOpeningFloat] = useState('0.00');
  const [countedCash, setCountedCash] = useState('');
  const [paymentAmount, setPaymentAmount] = useState('');
  const [paymentMethod, setPaymentMethod] = useState('CASH');
  const [paymentReference, setPaymentReference] = useState('');
  const [allocationPaymentId, setAllocationPaymentId] = useState('');
  const [allocationInvoiceId, setAllocationInvoiceId] = useState('');
  const [allocationAmount, setAllocationAmount] = useState('');

  const liveSession = props.mySession !== null && ['OPEN', 'ACTIVE'].includes(props.mySession.status);

  function searchPatients(event: FormEvent): void {
    event.preventDefault();
    router.get(routes.workspaces.billing, { q: search.trim() }, { preserveState: true, replace: true });
  }

  function openSession(event: FormEvent): void {
    event.preventDefault();
    router.post(routes.workspaces.billingOpenCashSession, { opening_float: openingFloat });
  }

  function closeSession(event: FormEvent): void {
    event.preventDefault();
    if (!props.mySession) return;
    router.post(routes.workspaces.billingCloseCashSession(props.mySession.cashSessionId), {
      counted_cash: countedCash,
    });
  }

  function recordPayment(event: FormEvent): void {
    event.preventDefault();
    if (!props.patientId) return;
    router.post(routes.workspaces.billingPayments, {
      patient_id: props.patientId,
      amount: paymentAmount,
      method: paymentMethod,
      payment_reference: paymentReference.trim() || null,
    });
  }

  function allocatePayment(event: FormEvent): void {
    event.preventDefault();
    if (!allocationPaymentId || !allocationInvoiceId) return;
    const payment = props.payments.find((entry) => entry.paymentId === allocationPaymentId);
    if (!payment) return;
    router.post(routes.workspaces.billingPaymentAllocate, {
      payment_id: payment.paymentId,
      invoice_id: allocationInvoiceId,
      amount: allocationAmount,
    });
  }

  function invoiceCharge(chargeId: string): void {
    if (!props.accountId) return;
    router.post(routes.workspaces.billingInvoices, {
      financial_account_id: props.accountId,
      charge_ids: [chargeId],
    });
  }

  return (
    <AppLayout>
      <main>
        <PageHeader
          title="Billing"
          description="Manage this facility’s patient accounts, invoices, and payments."
        />
        {shared.flash?.status ? <p role="status">{shared.flash.status}</p> : null}
        {Object.keys(errors).length > 0 ? (
          <div role="alert">{Object.values(errors).map((message) => <p key={message}>{message}</p>)}</div>
        ) : null}

        {props.canManageSessions ? (
          <section aria-labelledby="cash-session-heading" style={{ marginBlockEnd: '1.5rem' }}>
            <h2 id="cash-session-heading">Cash session</h2>
            {liveSession && props.mySession ? (
              <>
                <p>
                  Till is open · opening float {formatMoney(props.mySession.openingFloat, props.mySession.currency)} ·
                  expected cash {formatMoney(props.mySession.expectedCash, props.mySession.currency)}
                </p>
                <form onSubmit={closeSession} style={formStyle}>
                  <TextInput
                    id="billing-counted-cash"
                    labelText="Counted cash"
                    type="number"
                    min="0"
                    step="0.01"
                    value={countedCash}
                    onChange={(event) => setCountedCash(event.currentTarget.value)}
                    required
                  />
                  <Button size="lg" renderIcon={Money} type="submit" disabled={countedCash === ''}>Close cash session</Button>
                </form>
              </>
            ) : (
              <>
                <p>No cash session is open. Cash payments require an open till session.</p>
                <form onSubmit={openSession} style={formStyle}>
                  <TextInput
                    id="billing-opening-float"
                    labelText="Opening float"
                    type="number"
                    min="0"
                    step="0.01"
                    value={openingFloat}
                    onChange={(event) => setOpeningFloat(event.currentTarget.value)}
                    required
                  />
                  <Button size="lg" renderIcon={Money} type="submit">Open cash session</Button>
                </form>
              </>
            )}
          </section>
        ) : null}

        {!props.patientId ? (
          <section aria-labelledby="billing-patient-heading">
            <h2 id="billing-patient-heading">Find a patient account</h2>
            <form onSubmit={searchPatients} style={formStyle}>
              <TextInput
                id="billing-patient-search"
                labelText="Search this facility’s registered patients"
                helperText="Search by patient number, name, or phone."
                value={search}
                onChange={(event) => setSearch(event.currentTarget.value)}
                required
              />
              <Button size="lg" renderIcon={Search} type="submit">Search</Button>
            </form>
            {props.searchError ? <p role="alert">{props.searchError}</p> : null}
            {props.searchResults ? (
              props.searchResults.length > 0 ? (
                <ul aria-label="Patient account search results">
                  {props.searchResults.map((patient) => (
                    <li key={patient.patientId}>
                      <Link href={`${routes.workspaces.billing}?patient_id=${encodeURIComponent(patient.patientId)}`}>
                        {patient.displayName} · {patient.patientNumber}
                        {patient.isProvisional ? ' · Provisional' : ''}
                      </Link>
                    </li>
                  ))}
                </ul>
              ) : (
                <p>No registered patients matched that search.</p>
              )
            ) : null}
          </section>
        ) : (
          <section aria-labelledby="billing-account-heading">
            <h2 id="billing-account-heading">Patient account</h2>
            <p>
              <strong>{props.patientDisplayName}</strong> · {props.patientNumber} · Account {props.accountNumber ?? '—'}
            </p>
            <Link href={routes.workspaces.billing}>Change patient</Link>
            {props.accountId ? (
              <>
                <dl style={summaryStyle}>
                  <div><dt>Outstanding</dt><dd>{formatMoney(props.outstanding, props.currency)}</dd></div>
                  <div><dt>Total charged</dt><dd>{formatMoney(props.chargedTotal, props.currency)}</dd></div>
                  <div><dt>Settled</dt><dd>{formatMoney(props.settledTotal, props.currency)}</dd></div>
                </dl>

                <section aria-labelledby="uninvoiced-heading">
                  <h3 id="uninvoiced-heading">Charges awaiting invoice</h3>
                  <p>For medication pickup, invoice the dispensing charge by itself so payment is tied to that handover.</p>
                  {props.uninvoicedCharges.length === 0 ? <p>No uninvoiced charges.</p> : (
                    <ul aria-label="Uninvoiced charges">
                      {props.uninvoicedCharges.map((charge) => (
                        <li key={charge.chargeId} style={rowStyle}>
                          <span>{charge.description} · Qty {charge.quantity} · {formatMoney(charge.amount, charge.currency)}</span>
                          {props.canManageInvoices ? (
                            <Button type="button" size="sm" onClick={() => invoiceCharge(charge.chargeId)}>
                              Issue invoice for this charge
                            </Button>
                          ) : null}
                        </li>
                      ))}
                    </ul>
                  )}
                </section>

                <section aria-labelledby="invoices-heading">
                  <h3 id="invoices-heading">Invoices</h3>
                  {props.invoices.length === 0 ? <p>No invoices on this account.</p> : (
                    <ul aria-label="Patient invoices">
                      {props.invoices.map((invoice) => (
                        <li key={invoice.invoiceId} style={rowStyle}>
                          <span>
                            {invoice.invoiceNumber ?? invoice.invoiceId} · {invoice.status} ·
                            {' '}{formatMoney(invoice.net, invoice.currency)} · outstanding {formatMoney(invoice.derivedOutstanding, invoice.currency)}
                            {' '}· {invoice.lineCount} line{invoice.lineCount === 1 ? '' : 's'}
                          </span>
                          {props.canManageInvoices && invoice.status === 'DRAFT' ? (
                            <Button
                              type="button"
                              size="sm"
                              onClick={() => router.post(routes.workspaces.billingInvoiceIssue(invoice.invoiceId))}
                            >
                              Issue invoice
                            </Button>
                          ) : null}
                        </li>
                      ))}
                    </ul>
                  )}
                </section>

                {props.canRecordPayments ? (
                  <section aria-labelledby="payment-heading">
                    <h3 id="payment-heading">Record payment</h3>
                    <form onSubmit={recordPayment} style={formStyle}>
                      <TextInput
                        id="billing-payment-amount"
                        labelText="Amount"
                        type="number"
                        min="0.01"
                        step="0.01"
                        value={paymentAmount}
                        onChange={(event) => setPaymentAmount(event.currentTarget.value)}
                        required
                      />
                      <Select
                        id="billing-payment-method"
                        labelText="Payment method"
                        value={paymentMethod}
                        onChange={(event) => setPaymentMethod(event.currentTarget.value)}
                      >
                        <SelectItem value="CASH" text="Cash" disabled={!liveSession} />
                        <SelectItem value="MOBILE_MONEY" text="Mobile money" />
                        <SelectItem value="CARD" text="Card" />
                        <SelectItem value="BANK_TRANSFER" text="Bank transfer" />
                        <SelectItem value="POS" text="POS" />
                        <SelectItem value="OTHER" text="Other" />
                      </Select>
                      <TextInput
                        id="billing-payment-reference"
                        labelText="Payment reference (optional)"
                        value={paymentReference}
                        onChange={(event) => setPaymentReference(event.currentTarget.value)}
                      />
                      <Button size="lg" renderIcon={Money} type="submit" disabled={paymentAmount === ''}>Record payment</Button>
                    </form>
                  </section>
                ) : null}

                <section aria-labelledby="payments-heading">
                  <h3 id="payments-heading">Receipts and payment allocation</h3>
                  {props.payments.length === 0 ? <p>No payments recorded.</p> : (
                    <ul aria-label="Account payments">
                      {props.payments.map((payment) => (
                        <li key={payment.paymentId}>
                          {payment.receiptNumber} · {payment.method} · {formatMoney(payment.amount, payment.currency)} ·
                          {' '}unallocated {formatMoney(payment.unallocatedAmount, payment.currency)}
                        </li>
                      ))}
                    </ul>
                  )}
                  {props.canRecordPayments
                    && props.payments.some((payment) => Number(payment.unallocatedAmount) > 0)
                    && props.invoices.some((invoice) => invoice.status === 'ISSUED' && Number(invoice.derivedOutstanding) > 0) ? (
                    <form onSubmit={allocatePayment} style={formStyle}>
                      <Select
                        id="billing-allocation-payment"
                        labelText="Payment receipt"
                        value={allocationPaymentId}
                        onChange={(event) => setAllocationPaymentId(event.currentTarget.value)}
                      >
                        <SelectItem value="" text="Choose an unallocated payment" />
                        {props.payments
                          .filter((payment) => Number(payment.unallocatedAmount) > 0)
                          .map((payment) => (
                            <SelectItem
                              key={payment.paymentId}
                              value={payment.paymentId}
                              text={`${payment.receiptNumber} · ${formatMoney(payment.unallocatedAmount, payment.currency)} available`}
                            />
                          ))}
                      </Select>
                      <Select
                        id="billing-allocation-invoice"
                        labelText="Invoice to settle"
                        value={allocationInvoiceId}
                        onChange={(event) => setAllocationInvoiceId(event.currentTarget.value)}
                      >
                        <SelectItem value="" text="Choose an issued invoice" />
                        {props.invoices
                          .filter((invoice) => invoice.status === 'ISSUED' && Number(invoice.derivedOutstanding) > 0)
                          .map((invoice) => (
                            <SelectItem
                              key={invoice.invoiceId}
                              value={invoice.invoiceId}
                              text={`${invoice.invoiceNumber ?? invoice.invoiceId} · due ${formatMoney(invoice.derivedOutstanding, invoice.currency)}`}
                            />
                          ))}
                      </Select>
                      <TextInput
                        id="billing-allocation-amount"
                        labelText="Amount to allocate"
                        type="number"
                        min="0.01"
                        step="0.01"
                        value={allocationAmount}
                        onChange={(event) => setAllocationAmount(event.currentTarget.value)}
                        required
                      />
                      <Button size="lg" renderIcon={ArrowsHorizontal} type="submit" disabled={!allocationPaymentId || !allocationInvoiceId || allocationAmount === ''}>Allocate payment</Button>
                    </form>
                  ) : null}
                </section>
              </>
            ) : null}
          </section>
        )}
      </main>
    </AppLayout>
  );
}

function formatMoney(value: string | null, currency: string | null): string {
  if (value === null) return '—';
  return `${currency ?? 'TZS'} ${Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function isStringRecord(value: unknown): value is Record<string, string> {
  return typeof value === 'object' && value !== null
    && Object.values(value).every((entry) => typeof entry === 'string');
}

const formStyle = { display: 'flex', flexWrap: 'wrap' as const, alignItems: 'end', gap: '0.75rem', maxInlineSize: '48rem' };
const rowStyle = { display: 'flex', flexWrap: 'wrap' as const, alignItems: 'center', justifyContent: 'space-between', gap: '0.75rem', marginBlock: '0.5rem' };
const summaryStyle = { display: 'flex', flexWrap: 'wrap' as const, gap: '1.5rem' };