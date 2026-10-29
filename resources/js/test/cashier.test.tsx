import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { router } from '@inertiajs/react';
import BillingIndex from '@/pages/Billing/Index';

const state = vi.hoisted(() => ({ props: { errors: {}, flash: { status: null } } }));

vi.mock('@inertiajs/react', () => ({
  Link: ({ children, href }: { children: React.ReactNode; href: string }) => <a href={href}>{children}</a>,
  router: { get: vi.fn(), post: vi.fn(), reload: vi.fn() },
  usePage: () => ({ props: state.props }),
}));

vi.mock('@/layouts/AppLayout/AppLayout', () => ({
  AppLayout: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

const baseProps = {
  facilityId: 'facility-1',
  mySession: null,
  canManageSessions: false,
  canManageInvoices: true,
  canRecordPayments: true,
  accountId: 'account-1',
  accountNumber: 'ACC-0001',
  patientId: 'patient-1',
  patientDisplayName: 'Amina Juma',
  patientNumber: 'PAT-00000001',
  outstanding: '5000.00',
  chargedTotal: '5000.00',
  settledTotal: '0.00',
  currency: 'TZS',
  invoices: [],
  uninvoicedCharges: [
    {
      chargeId: 'charge-dispensing',
      description: 'Paracetamol dispensing',
      quantity: '10',
      unitPrice: '500.00',
      amount: '5000.00',
      status: 'POSTED',
      currency: 'TZS',
      occurredAt: '2026-10-14T20:00:00Z',
    },
    {
      chargeId: 'charge-other',
      description: 'Consultation',
      quantity: '1',
      unitPrice: '10000.00',
      amount: '10000.00',
      status: 'POSTED',
      currency: 'TZS',
      occurredAt: '2026-10-14T19:00:00Z',
    },
  ],
  payments: [],
  searchError: null,
  query: null,
  searchResults: null,
};

describe('cashier workspace', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    window.matchMedia = vi.fn().mockImplementation((query: string) => ({
      matches: false,
      media: query,
      onchange: null,
      addListener: vi.fn(),
      removeListener: vi.fn(),
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
      dispatchEvent: vi.fn(),
    }));
    globalThis.ResizeObserver ??= class {
      observe(): void {}
      unobserve(): void {}
      disconnect(): void {}
    };
  });

  it('creates a dispensing invoice from exactly one selected charge', () => {
    render(<BillingIndex {...baseProps} />);
    fireEvent.click(screen.getAllByRole('button', { name: 'Issue invoice for this charge' })[0]!);

    expect(router.post).toHaveBeenCalledWith('/workspaces/billing/invoices', {
      financial_account_id: 'account-1',
      charge_ids: ['charge-dispensing'],
    });
  });

  it('records and allocates payment using explicit patient, receipt, and invoice selections', () => {
    state.props.errors = {};
    render(<BillingIndex
      {...baseProps}
      uninvoicedCharges={[]}
      invoices={[{
        invoiceId: 'invoice-1',
        invoiceNumber: 'INV-0001',
        status: 'ISSUED',
        net: '5000.00',
        paidAmount: '0.00',
        paymentStatus: 'UNPAID',
        derivedOutstanding: '5000.00',
        currency: 'TZS',
        lineCount: 1,
        issuedAt: '2026-10-14T20:00:00Z',
      }]}
      payments={[{
        paymentId: 'payment-1',
        receiptNumber: 'RCT-0001',
        method: 'MOBILE_MONEY',
        amount: '5000.00',
        status: 'POSTED',
        unallocatedAmount: '5000.00',
        currency: 'TZS',
        receivedAt: '2026-10-14T20:00:00Z',
      }]}
    />);

    fireEvent.change(screen.getByLabelText('Amount'), { target: { value: '5000.00' } });
    fireEvent.change(screen.getByLabelText('Payment method'), { target: { value: 'MOBILE_MONEY' } });
    fireEvent.click(screen.getByRole('button', { name: 'Record payment' }));
    expect(router.post).toHaveBeenCalledWith('/workspaces/billing/payments', expect.objectContaining({
      patient_id: 'patient-1',
      amount: '5000.00',
      method: 'MOBILE_MONEY',
    }));

    fireEvent.change(screen.getByLabelText('Payment receipt'), { target: { value: 'payment-1' } });
    fireEvent.change(screen.getByLabelText('Invoice to settle'), { target: { value: 'invoice-1' } });
    fireEvent.change(screen.getByLabelText('Amount to allocate'), { target: { value: '5000.00' } });
    fireEvent.click(screen.getByRole('button', { name: 'Allocate payment' }));
    expect(router.post).toHaveBeenLastCalledWith('/workspaces/billing/payments/allocate', {
      payment_id: 'payment-1',
      invoice_id: 'invoice-1',
      amount: '5000.00',
    });
  });
});
