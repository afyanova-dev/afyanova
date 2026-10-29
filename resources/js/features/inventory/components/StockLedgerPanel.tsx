import { Tag } from '@carbon/react';
import type { ReactNode } from 'react';
import { CarbonDataTable } from '@/components/ui/CarbonDataTable';
import { DockedPanel } from '@/components/panels';
import type { InventoryItem, StockLedger, StockLocation } from '../types';

export interface StockLedgerPanelProps {
  /** Null = no ledger loaded for the current selection; the drawer stays hidden. */
  ledger: StockLedger | null;
  item?: InventoryItem | null;
  location?: StockLocation | null;
  /** batchId → batch number, resolved from the loaded balances (never invented). */
  batchNumbers?: Map<string, string>;
  onClose: () => void;
}

/** Trim the 4-decimal storage scale for display (`100.0000` → `100`). */
function formatQuantity(value: string): string {
  const parsed = Number.parseFloat(value);

  return Number.isFinite(parsed) ? String(parsed) : value;
}

function humanize(value: string): string {
  const normalized = value.replace(/_/g, ' ').toLowerCase();
  return normalized.length > 0 ? normalized[0]!.toUpperCase() + normalized.slice(1) : value;
}

/**
 * Read-only movement ledger drawer for one (item, location). Renders the
 * authorized `GetStockLedger` contract: append-only entries with a running
 * balance plus the reconciliation drift. It never edits stock — corrections
 * are made through receipts or approved adjustments.
 */
export function StockLedgerPanel({
  ledger,
  item,
  location,
  batchNumbers,
  onClose,
}: StockLedgerPanelProps): ReactNode {
  const drift = ledger ? Number.parseFloat(ledger.drift) || 0 : 0;
  const uom = ledger?.entries[0]?.uom ?? item?.unitOfMeasure ?? '';

  const rows = (ledger?.entries ?? []).map((entry) => ({
    id: entry.movementId,
    movement: entry.movementNumber,
    type: humanize(entry.movementType),
    direction: entry.direction,
    quantity: entry.enteredUnit && entry.enteredQuantity
      ? `${formatQuantity(entry.enteredQuantity)} ${entry.enteredUnit} (${entry.quantity} ${entry.uom})`
      : `${entry.quantity} ${entry.uom}`,
    balance: `${entry.runningBalance} ${entry.uom}`,
    batch: entry.batchId ? (batchNumbers?.get(entry.batchId) ?? 'Batch recorded') : '—',
    source: [humanize(entry.sourceType), entry.sourceRef ?? entry.poRef].filter(Boolean).join(' · '),
    occurredAt: entry.occurredAt,
    reason: entry.reasonText ?? '—',
  }));

  const headers = [
    { key: 'movement', header: 'Movement' },
    { key: 'type', header: 'Type' },
    { key: 'direction', header: 'Direction' },
    { key: 'quantity', header: 'Quantity' },
    { key: 'balance', header: 'Running balance' },
    { key: 'batch', header: 'Batch' },
    { key: 'source', header: 'Source' },
    { key: 'occurredAt', header: 'Occurred' },
    { key: 'reason', header: 'Reason' },
  ];

  return (
    <DockedPanel
      heading="Stock movement ledger"
      ariaLabel="Stock movement ledger"
      description={
        item || location
          ? `${item ? `${item.code} — ${item.name}` : 'Item'} at ${location ? location.name : 'location'}`
          : 'Append-only stock movements for the selected item and location.'
      }
      hidden={ledger === null}
      onClose={onClose}
      width="min(48rem, 100%)"
    >
      <div style={{ display: 'flex', flexDirection: 'column', gap: '1.25rem' }}>
        {ledger ? (
          <div
            style={{
              display: 'flex',
              flexWrap: 'wrap',
              gap: '1.5rem',
              padding: '1rem 1.25rem',
              border: '1px solid var(--cds-border-subtle, #e0e0e0)',
              borderRadius: '4px',
              background: 'var(--cds-layer-01, #f4f4f4)',
            }}
          >
            <div>
              <div style={{ fontSize: '0.75rem', textTransform: 'uppercase', color: 'var(--cds-text-secondary, #525252)' }}>
                Current on hand
              </div>
              <div style={{ fontSize: '1.25rem', fontWeight: 700 }}>
                {ledger.currentOnHandQty} <span style={{ fontSize: '0.875rem', fontWeight: 400 }}>{uom}</span>
              </div>
            </div>
            <div>
              <div style={{ fontSize: '0.75rem', textTransform: 'uppercase', color: 'var(--cds-text-secondary, #525252)' }}>
                Ledger sum
              </div>
              <div style={{ fontSize: '1.25rem', fontWeight: 700 }}>
                {ledger.ledgerSum} <span style={{ fontSize: '0.875rem', fontWeight: 400 }}>{uom}</span>
              </div>
            </div>
            <div>
              <div style={{ fontSize: '0.75rem', textTransform: 'uppercase', color: 'var(--cds-text-secondary, #525252)' }}>
                Drift
              </div>
              <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', marginBlockStart: '0.25rem' }}>
                <span style={{ fontSize: '1.25rem', fontWeight: 700 }}>{ledger.drift}</span>
                {drift !== 0 ? <Tag type="red" size="sm">Reconciliation required</Tag> : <Tag type="green" size="sm">Balanced</Tag>}
              </div>
            </div>
          </div>
        ) : null}

        <CarbonDataTable
          rows={rows}
          headers={headers}
          title={`Movements (${rows.length})`}
          ariaLabel="Stock movement ledger"
          emptyState={<p>No stock movements have been recorded for this item at this location.</p>}
          renderCell={(cell) => {
            if (cell.info.header === 'direction') {
              return <Tag type={cell.value === 'IN' ? 'green' : 'cool-gray'} size="sm">{cell.value}</Tag>;
            }
            if (cell.info.header === 'occurredAt' && typeof cell.value === 'string' && cell.value.length > 0) {
              return new Date(cell.value).toLocaleString();
            }
            return cell.value;
          }}
        />

        <p style={{ fontSize: '0.875rem', color: 'var(--cds-text-secondary, #525252)' }}>
          The ledger is append-only. A non-zero drift means the materialised balance and the movement sum disagree and
          requires reconciliation; stock is never corrected by editing a balance directly.
        </p>
      </div>
    </DockedPanel>
  );
}
