import { Button, InlineNotification, Link, Select, SelectItem, Stack, TextInput } from '@carbon/react';
import { useForm, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { routes } from '@/app/routes';
import { DockedPanel } from '@/components/panels';
import type { GoodsReceiptDetail, StockLocation } from '../types';

export interface ReceiptDetailPanelProps {
  receipt: GoodsReceiptDetail;
  locations: StockLocation[];
  canManageInvoice: boolean;
  canMatchInvoice: boolean;
  canManageReturns: boolean;
  onClose: () => void;
}

function formatWhen(iso: string | null): string {
  if (iso === null) {
    return '—';
  }

  const date = new Date(iso);

  return Number.isNaN(date.getTime()) ? iso : date.toLocaleString();
}

function formatDate(value: string | null): string {
  if (value === null) {
    return '—';
  }

  const date = new Date(`${value}T00:00:00`);

  return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString();
}

function qty(value: string): string {
  const parsed = Number.parseFloat(value);

  return Number.isFinite(parsed) ? String(Number(parsed.toFixed(4))) : value;
}

function signed(value: number, decimals: number): string {
  const formatted = value.toFixed(decimals);

  return value > 0 ? `+${formatted}` : formatted;
}

function sourceLabel(source: string): string {
  return source.replaceAll('_', ' ').toLowerCase().replace(/^./, (character) => character.toUpperCase());
}

/**
 * Read-only detail of a posted goods receipt (Phase 1 / P1-08).
 *
 * The same document prints as the GRN; this panel is the on-screen audit view
 * and exposes the reprint action.
 */
export function ReceiptDetailPanel({
  receipt,
  locations,
  canManageInvoice,
  canMatchInvoice,
  canManageReturns,
  onClose,
}: ReceiptDetailPanelProps): ReactNode {
  const page = usePage();
  const pageErrors = (page.props.errors ?? {}) as Record<string, string | string[] | undefined>;
  const invoiceForm = useForm({
    invoice_number: receipt.invoiceNumber ?? '',
    invoice_date: receipt.invoiceDate ?? '',
    invoice_currency: receipt.invoiceCurrency,
    invoice_total: receipt.invoiceTotal ?? '',
  });
  const invoiceMatchForm = useForm({
    lines: receipt.lines.map((line) => ({
      receipt_line_id: line.receiptLineId,
      invoice_quantity: line.invoiceQuantity ?? '',
      invoice_unit_price: line.invoiceUnitPrice ?? '',
    })),
  });
  const returnForm = useForm({
    return_id: '',
    location_id: receipt.locationId,
    reason: 'DAMAGED_AFTER_RECEIPT',
    lines: receipt.lines.map((line) => ({
      receipt_line_id: line.receiptLineId,
      quantity: '',
      credit_note_ref: '',
      notes: '',
    })),
  });
  const returnFormError = Object.values(returnForm.errors).filter(Boolean).join(' ');
  const setInvoiceFormData = invoiceForm.setData;
  const setInvoiceMatchFormData = invoiceMatchForm.setData;

  useEffect(() => {
    setInvoiceFormData({
      invoice_number: receipt.invoiceNumber ?? '',
      invoice_date: receipt.invoiceDate ?? '',
      invoice_currency: receipt.invoiceCurrency,
      invoice_total: receipt.invoiceTotal ?? '',
    });
    setInvoiceMatchFormData({
      lines: receipt.lines.map((line) => ({
        receipt_line_id: line.receiptLineId,
        invoice_quantity: line.invoiceQuantity ?? '',
        invoice_unit_price: line.invoiceUnitPrice ?? '',
      })),
    });
  }, [
    receipt.goodsReceiptId,
    receipt.invoiceNumber,
    receipt.invoiceDate,
    receipt.invoiceCurrency,
    receipt.invoiceTotal,
    receipt.matchStatus,
    receipt.lines,
    setInvoiceFormData,
    setInvoiceMatchFormData,
  ]);

  function attachInvoice(event: FormEvent<HTMLFormElement>): void {
    event.preventDefault();
    invoiceForm.post(routes.workspaces.inventoryGoodsReceiptInvoice(receipt.goodsReceiptId), {
      preserveScroll: true,
      only: ['goodsReceiptDetail', 'goodsReceipts'],
    });
  }

  function matchInvoice(event: FormEvent<HTMLFormElement>): void {
    event.preventDefault();
    invoiceMatchForm.post(routes.workspaces.inventoryGoodsReceiptMatch(receipt.goodsReceiptId), {
      preserveScroll: true,
      only: ['goodsReceiptDetail', 'goodsReceipts'],
    });
  }

  function returnGoods(event: FormEvent<HTMLFormElement>): void {
    event.preventDefault();
    const returnId = returnForm.data.return_id || crypto.randomUUID();
    returnForm.setData('return_id', returnId);
    returnForm.transform((data) => ({
      ...data,
      return_id: returnId,
      lines: data.lines.filter((line) => line.quantity !== '' && Number.parseFloat(line.quantity) > 0),
    }));
    returnForm.post(routes.workspaces.inventoryGoodsReceiptReturn(receipt.goodsReceiptId), {
      preserveScroll: true,
      only: ['goodsReceiptDetail', 'goodsReceipts'],
      onSuccess: () => returnForm.setData({
        return_id: crypto.randomUUID(),
        location_id: receipt.locationId,
        reason: 'DAMAGED_AFTER_RECEIPT',
        lines: receipt.lines.map((line) => ({
          receipt_line_id: line.receiptLineId,
          quantity: '',
          credit_note_ref: '',
          notes: '',
        })),
      }),
    });
  }

  return (
    <DockedPanel
      heading={`Goods receipt ${receipt.receiptNumber}`}
      description="Posted delivery document. Accepted quantity moved stock; rejected quantity is recorded only."
      onClose={onClose}
      width="min(64rem, 100%)"
      expandedWidth="min(88rem, 100%)"
      expandable
      actions={(
        <Link href={routes.workspaces.inventoryGoodsReceiptPrint(receipt.goodsReceiptId)} target="_blank" rel="noreferrer">
          Print GRN
        </Link>
      )}
    >
      <div style={{ display: 'flex', flexDirection: 'column', gap: '1.25rem' }}>
        <section aria-labelledby="receipt-detail-heading">
          <h3 id="receipt-detail-heading" style={{ marginBlockEnd: '0.5rem', fontSize: '1rem' }}>Delivery</h3>
          <dl style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(14rem, 1fr))', gap: '0.5rem 1rem', margin: 0 }}>
            <div><dt style={{ fontSize: '0.75rem', color: 'var(--cds-text-secondary, #525252)' }}>Received</dt><dd style={{ margin: 0 }}>{formatWhen(receipt.receivedAt)}</dd></div>
            <div><dt style={{ fontSize: '0.75rem', color: 'var(--cds-text-secondary, #525252)' }}>Source</dt><dd style={{ margin: 0 }}>{sourceLabel(receipt.sourceType)}</dd></div>
            <div><dt style={{ fontSize: '0.75rem', color: 'var(--cds-text-secondary, #525252)' }}>Purchase order</dt><dd style={{ margin: 0 }}>{receipt.poRef ?? '—'}</dd></div>
            <div><dt style={{ fontSize: '0.75rem', color: 'var(--cds-text-secondary, #525252)' }}>Destination</dt><dd style={{ margin: 0 }}>{receipt.locationCode} — {receipt.locationName}</dd></div>
            <div><dt style={{ fontSize: '0.75rem', color: 'var(--cds-text-secondary, #525252)' }}>Delivery note</dt><dd style={{ margin: 0 }}>{receipt.deliveryNoteNumber ?? '—'}</dd></div>
            <div><dt style={{ fontSize: '0.75rem', color: 'var(--cds-text-secondary, #525252)' }}>Recorded</dt><dd style={{ margin: 0 }}>{formatWhen(receipt.recordedAt)}</dd></div>
            <div><dt style={{ fontSize: '0.75rem', color: 'var(--cds-text-secondary, #525252)' }}>Status</dt><dd style={{ margin: 0 }}>{receipt.status} · {receipt.matchStatus}</dd></div>
          </dl>
        </section>

        <section aria-labelledby="receipt-invoice-heading">
          <h3 id="receipt-invoice-heading" style={{ marginBlockEnd: '0.5rem', fontSize: '1rem' }}>Supplier invoice</h3>
          {receipt.invoiceNumber !== null ? (
            <dl style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(12rem, 1fr))', gap: '0.5rem 1rem', margin: 0 }}>
              <div><dt style={{ fontSize: '0.75rem', color: 'var(--cds-text-secondary, #525252)' }}>Invoice</dt><dd style={{ margin: 0 }}>{receipt.invoiceNumber}</dd></div>
              <div><dt style={{ fontSize: '0.75rem', color: 'var(--cds-text-secondary, #525252)' }}>Invoice date</dt><dd style={{ margin: 0 }}>{formatDate(receipt.invoiceDate)}</dd></div>
              <div><dt style={{ fontSize: '0.75rem', color: 'var(--cds-text-secondary, #525252)' }}>Status</dt><dd style={{ margin: 0 }}>{receipt.invoiceStatus.replaceAll('_', ' ')}</dd></div>
              <div><dt style={{ fontSize: '0.75rem', color: 'var(--cds-text-secondary, #525252)' }}>Total</dt><dd style={{ margin: 0 }}>{receipt.invoiceTotal !== null ? `${receipt.invoiceCurrency} ${receipt.invoiceTotal}` : '—'}</dd></div>
            </dl>
          ) : (
            <p>Invoice pending. Attach it when it is available; this does not change posted stock.</p>
          )}
          {canManageInvoice ? (
            <form onSubmit={attachInvoice} style={{ marginBlockStart: '1rem' }}>
              <Stack gap={4}>
                <TextInput
                  id="receipt-invoice-number"
                  labelText="Invoice number"
                  value={invoiceForm.data.invoice_number}
                  onChange={(event) => invoiceForm.setData('invoice_number', event.currentTarget.value)}
                  invalid={Boolean(invoiceForm.errors.invoice_number)}
                  invalidText={invoiceForm.errors.invoice_number}
                  maxLength={100}
                  required
                />
                <TextInput
                  id="receipt-invoice-date"
                  labelText="Invoice date"
                  type="date"
                  value={invoiceForm.data.invoice_date}
                  onChange={(event) => invoiceForm.setData('invoice_date', event.currentTarget.value)}
                  invalid={Boolean(invoiceForm.errors.invoice_date)}
                  invalidText={invoiceForm.errors.invoice_date}
                />
                <TextInput
                  id="receipt-invoice-total"
                  labelText="Invoice total (TZS)"
                  type="number"
                  min="0"
                  step="0.01"
                  value={invoiceForm.data.invoice_total}
                  onChange={(event) => invoiceForm.setData('invoice_total', event.currentTarget.value)}
                  invalid={Boolean(invoiceForm.errors.invoice_total)}
                  invalidText={invoiceForm.errors.invoice_total}
                />
                {pageErrors.invoice ? (
                  <InlineNotification
                    kind="error"
                    lowContrast
                    hideCloseButton
                    title={Array.isArray(pageErrors.invoice) ? pageErrors.invoice.join(' ') : pageErrors.invoice}
                  />
                ) : null}
                <Button type="submit" kind="tertiary" disabled={invoiceForm.processing || invoiceForm.data.invoice_number.trim() === ''}>
                  {invoiceForm.processing ? 'Saving invoice…' : receipt.invoiceNumber === null ? 'Attach invoice' : 'Update invoice'}
                </Button>
              </Stack>
            </form>
          ) : null}
        </section>

        <section aria-labelledby="receipt-lines-heading">
          <h3 id="receipt-lines-heading" style={{ marginBlockEnd: '0.5rem', fontSize: '1rem' }}>Lines ({receipt.lines.length})</h3>
          <div style={{ overflowX: 'auto' }}>
            <table className="afy-table">
              <caption className="afy-visually-hidden">Goods receipt lines</caption>
              <thead>
                <tr>
                  <th scope="col">Item</th>
                  <th scope="col">Delivered</th>
                  <th scope="col">Rejected</th>
                  <th scope="col">Accepted</th>
                  <th scope="col">Batch / expiry</th>
                  <th scope="col">Condition</th>
                  <th scope="col">Movement</th>
                </tr>
              </thead>
              <tbody>
                {receipt.lines.map((line) => (
                  <tr key={line.receiptLineId}>
                    <td>
                      <strong>{line.itemCode}</strong>
                      <div style={{ fontSize: '0.8125rem' }}>{line.itemName}</div>
                      {line.poLineRef !== null ? (
                        <div style={{ fontSize: '0.75rem', color: 'var(--cds-text-secondary, #525252)' }}>
                          PO line {line.poLineRef.slice(0, 8)}
                        </div>
                      ) : null}
                      {line.substitutedForItemId !== null ? (
                        <div style={{ fontSize: '0.75rem', color: 'var(--cds-text-secondary, #525252)' }}>
                          Substitute for the ordered item
                        </div>
                      ) : null}
                    </td>
                    <td>{qty(line.quantityDelivered)} {line.uom}</td>
                    <td>{qty(line.quantityRejected)} {line.uom}</td>
                    <td><strong>{qty(line.quantityAccepted)} {line.uom}</strong></td>
                    <td>{line.batchNumber ?? '—'}{line.expiryDate !== null ? <div style={{ fontSize: '0.75rem' }}>Exp {formatDate(line.expiryDate)}</div> : null}</td>
                    <td>
                      {line.condition.replaceAll('_', ' ')}
                      {line.conditionNotes !== null ? (
                        <div style={{ fontSize: '0.75rem', color: 'var(--cds-text-secondary, #525252)' }}>
                          {line.conditionNotes}
                        </div>
                      ) : null}
                      {line.rejectionReason !== null ? (
                        <div style={{ fontSize: '0.75rem', color: 'var(--cds-text-secondary, #525252)' }}>
                          {line.rejectionReason}
                        </div>
                      ) : null}
                    </td>
                    <td>{line.movementNumber ?? 'Not posted'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>

        {canMatchInvoice && receipt.invoiceNumber !== null && receipt.sourceType === 'PROCUREMENT' ? (
          <section aria-labelledby="receipt-invoice-match-heading">
            <h3 id="receipt-invoice-match-heading" style={{ marginBlockEnd: '0.5rem', fontSize: '1rem' }}>Invoice matching</h3>
            <p>Compare invoice quantities with accepted receipt quantities and invoice prices with the PO prices. Taxes and other charges are not calculated here.</p>
            <form onSubmit={matchInvoice}>
              <Stack gap={5}>
                {receipt.lines.map((line, index) => {
                  const formLine = invoiceMatchForm.data.lines[index];
                  const acceptedEntered = Number.parseFloat(line.quantityAccepted) / Number.parseFloat(line.packSize);
                  const unitSnapshotMissing = line.poUnit === null;
                  const unitMismatch = line.poUnit !== null && line.poUnit.toUpperCase() !== line.enteredUnit.toUpperCase();
                  const priceSnapshotMissing = line.poUnitPrice === null;
                  const invoiceQuantity = Number.parseFloat(formLine?.invoice_quantity ?? '');
                  const invoiceUnitPrice = Number.parseFloat(formLine?.invoice_unit_price ?? '');
                  const quantityVariance = invoiceQuantity - acceptedEntered;
                  const priceVariance = invoiceUnitPrice - Number.parseFloat(line.poUnitPrice ?? '');

                  return (
                    <div key={line.receiptLineId} style={{ borderBlockEnd: '1px solid var(--cds-border-subtle, #c6c6c6)', paddingBlockEnd: '1rem' }}>
                      <strong>{line.itemCode} — {line.itemName}</strong>
                      <p>
                        Accepted: {qty(String(acceptedEntered))} {line.enteredUnit}
                        {' · '}PO price: {line.poUnitPrice !== null ? `${receipt.invoiceCurrency} ${line.poUnitPrice} / ${line.poUnit ?? 'unit'}` : '—'}
                      </p>
                      {unitMismatch ? (
                        <InlineNotification
                          kind="warning"
                          lowContrast
                          hideCloseButton
                          title={`Unit variance: PO uses ${line.poUnit}; receipt uses ${line.enteredUnit}.`}
                        />
                      ) : null}
                      {unitSnapshotMissing ? (
                        <InlineNotification
                          kind="warning"
                          lowContrast
                          hideCloseButton
                          title="The PO unit snapshot is unavailable; this line requires manual unit review."
                        />
                      ) : null}
                      {priceSnapshotMissing ? (
                        <InlineNotification
                          kind="warning"
                          lowContrast
                          hideCloseButton
                          title="The PO price snapshot is unavailable; this line requires manual price review."
                        />
                      ) : null}
                      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(14rem, 1fr))', gap: '1rem' }}>
                        <TextInput
                          id={`invoice-quantity-${line.receiptLineId}`}
                          labelText={`Invoice quantity (${line.enteredUnit})`}
                          type="number"
                          min="0"
                          step="0.0001"
                          value={formLine?.invoice_quantity ?? ''}
                          onChange={(event) => invoiceMatchForm.setData('lines', invoiceMatchForm.data.lines.map((entry, lineIndex) => (
                            lineIndex === index ? { ...entry, invoice_quantity: event.currentTarget.value } : entry
                          )))}
                          required
                        />
                        <TextInput
                          id={`invoice-price-${line.receiptLineId}`}
                          labelText={`Invoice unit price (TZS / ${line.enteredUnit})`}
                          type="number"
                          min="0"
                          step="0.01"
                          value={formLine?.invoice_unit_price ?? ''}
                          onChange={(event) => invoiceMatchForm.setData('lines', invoiceMatchForm.data.lines.map((entry, lineIndex) => (
                            lineIndex === index ? { ...entry, invoice_unit_price: event.currentTarget.value } : entry
                          )))}
                          required
                        />
                      </div>
                      {formLine?.invoice_quantity !== '' && Number.isFinite(quantityVariance) ? (
                        <p>Quantity variance: {signed(quantityVariance, 4)} {line.enteredUnit}</p>
                      ) : null}
                      {formLine?.invoice_unit_price !== ''
                        && line.poUnitPrice !== null
                        && !unitMismatch
                        && Number.isFinite(priceVariance) ? (
                          <p>Price variance: {receipt.invoiceCurrency} {signed(priceVariance, 2)} / {line.enteredUnit}</p>
                        ) : null}
                    </div>
                  );
                })}
                {pageErrors.invoice_match ? (
                  <InlineNotification
                    kind="error"
                    lowContrast
                    hideCloseButton
                    title={Array.isArray(pageErrors.invoice_match) ? pageErrors.invoice_match.join(' ') : pageErrors.invoice_match}
                  />
                ) : null}
                <div style={{ display: 'flex', gap: '1rem', alignItems: 'center' }}>
                  <Button type="submit" kind="primary" disabled={invoiceMatchForm.processing}>
                    {invoiceMatchForm.processing ? 'Matching invoice…' : receipt.matchStatus === 'UNMATCHED' ? 'Match invoice' : 'Re-match invoice'}
                  </Button>
                  {receipt.matchStatus !== 'UNMATCHED' ? (
                    <Link href={routes.workspaces.inventoryGoodsReceiptMatchExport(receipt.goodsReceiptId)}>
                      Export invoice match CSV
                    </Link>
                  ) : null}
                </div>
              </Stack>
            </form>
          </section>
        ) : null}

        {canManageReturns ? (
          <section aria-labelledby="receipt-return-heading">
            <h3 id="receipt-return-heading" style={{ marginBlockEnd: '0.5rem', fontSize: '1rem' }}>Return stock to supplier</h3>
            <p>Post a return against accepted goods only. To quarantine stock first, transfer it to a QUARANTINE location, then select that location as the return source.</p>
            <form onSubmit={returnGoods}>
              <Stack gap={5}>
                <Select
                  id="supplier-return-location"
                  labelText="Return from location"
                  value={returnForm.data.location_id}
                  onChange={(event) => returnForm.setData('location_id', event.currentTarget.value)}
                  invalid={Boolean(returnForm.errors.location_id)}
                  invalidText={returnForm.errors.location_id}
                  required
                >
                  {locations.filter((location) => location.active).map((location) => (
                    <SelectItem
                      key={location.id}
                      value={location.id}
                      text={`${location.code} — ${location.name}${location.locationType === 'QUARANTINE' ? ' (Quarantine)' : ''}`}
                    />
                  ))}
                </Select>
                <Select
                  id="supplier-return-reason"
                  labelText="Return reason"
                  value={returnForm.data.reason}
                  onChange={(event) => returnForm.setData('reason', event.currentTarget.value)}
                  invalid={Boolean(returnForm.errors.reason)}
                  invalidText={returnForm.errors.reason}
                  required
                >
                  <SelectItem value="DAMAGED_AFTER_RECEIPT" text="Damaged after receipt" />
                  <SelectItem value="WRONG_ITEM" text="Wrong item" />
                  <SelectItem value="EXPIRED" text="Expired" />
                </Select>
                {receipt.lines.map((line, index) => {
                  const returnLine = returnForm.data.lines[index];
                  const accepted = Number.parseFloat(line.quantityAccepted);

                  return accepted > 0 ? (
                    <div key={line.receiptLineId} style={{ borderBlockEnd: '1px solid var(--cds-border-subtle, #c6c6c6)', paddingBlockEnd: '1rem' }}>
                      <strong>{line.itemCode} — {line.itemName}</strong>
                      <p>Accepted: {qty(line.quantityAccepted)} {line.uom}{line.batchNumber !== null ? ` · Batch ${line.batchNumber}` : ''}</p>
                      <TextInput
                        id={`supplier-return-quantity-${line.receiptLineId}`}
                        labelText={`Return quantity (${line.uom})`}
                        type="number"
                        min="0"
                        max={line.quantityAccepted}
                        step="0.0001"
                        value={returnLine?.quantity ?? ''}
                        onChange={(event) => returnForm.setData('lines', returnForm.data.lines.map((entry, lineIndex) => (
                          lineIndex === index ? { ...entry, quantity: event.currentTarget.value } : entry
                        )))}
                      />
                      <TextInput
                        id={`supplier-return-credit-${line.receiptLineId}`}
                        labelText="Credit note reference (optional)"
                        value={returnLine?.credit_note_ref ?? ''}
                        onChange={(event) => returnForm.setData('lines', returnForm.data.lines.map((entry, lineIndex) => (
                          lineIndex === index ? { ...entry, credit_note_ref: event.currentTarget.value } : entry
                        )))}
                      />
                      <TextInput
                        id={`supplier-return-notes-${line.receiptLineId}`}
                        labelText="Notes (optional)"
                        value={returnLine?.notes ?? ''}
                        onChange={(event) => returnForm.setData('lines', returnForm.data.lines.map((entry, lineIndex) => (
                          lineIndex === index ? { ...entry, notes: event.currentTarget.value } : entry
                        )))}
                      />
                    </div>
                  ) : null;
                })}
                {pageErrors.supplier_return || returnFormError ? (
                  <InlineNotification
                    kind="error"
                    lowContrast
                    hideCloseButton
                    title={pageErrors.supplier_return
                      ? Array.isArray(pageErrors.supplier_return) ? pageErrors.supplier_return.join(' ') : pageErrors.supplier_return
                      : returnFormError}
                  />
                ) : null}
                <Button
                  type="submit"
                  kind="danger"
                  disabled={
                    returnForm.processing
                    || returnForm.data.location_id === ''
                    || !returnForm.data.lines.some((line) => Number.parseFloat(line.quantity) > 0)
                  }
                >
                  {returnForm.processing ? 'Posting return…' : 'Post supplier return'}
                </Button>
              </Stack>
            </form>
          </section>
        ) : null}

        {receipt.remarks !== null ? (
          <section aria-labelledby="receipt-remarks-heading">
            <h3 id="receipt-remarks-heading" style={{ marginBlockEnd: '0.5rem', fontSize: '1rem' }}>Remarks</h3>
            <p style={{ margin: 0 }}>{receipt.remarks}</p>
          </section>
        ) : null}
      </div>
    </DockedPanel>
  );
}
