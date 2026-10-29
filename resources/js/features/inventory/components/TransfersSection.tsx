import { Button, TableToolbarSearch } from '@carbon/react';
import { useMemo, useState, type ReactNode } from 'react';
import { CarbonDataTable, EmptyState, StatisticsCards } from '@/components/ui';
import { ArrowsHorizontal, Box, List } from '@/icons';
import type { StockTransfer } from '../types';

export interface TransfersSectionProps {
  transfers: StockTransfer[];
  canTransfer: boolean;
  onMoveStock: () => void;
}

const HEADERS = [
  { key: 'transfer', header: 'Transfer' },
  { key: 'item', header: 'Item' },
  { key: 'route', header: 'From → To' },
  { key: 'quantity', header: 'Quantity' },
  { key: 'batch', header: 'Batch' },
  { key: 'reason', header: 'Reason' },
];

/** Display the moment the transfer was recorded, in the user's local time. */
function formatWhen(iso: string): string {
  const date = new Date(iso);

  if (Number.isNaN(date.getTime())) {
    return iso;
  }

  return date.toLocaleString();
}

/**
 * Stock transfers (frontend-foundation-inventory-operations.md Phase 8).
 *
 * A transfer moves stock between two locations of THIS facility — main store to
 * a pharmacy counter, or a ward returning what it did not use. Nothing is
 * consumed and the facility total does not change, so this screen shows the
 * movement, not a loss. The two ledger sides are written together by the
 * server; nothing here edits a balance.
 */
export function TransfersSection({
  transfers,
  canTransfer,
  onMoveStock,
}: TransfersSectionProps): ReactNode {
  const [search, setSearch] = useState('');

  const filtered = useMemo(() => {
    const term = search.trim().toLowerCase();

    if (term === '') {
      return transfers;
    }

    return transfers.filter((transfer) => [
      transfer.transferNumber,
      transfer.itemCode,
      transfer.itemName,
      transfer.fromLocationCode,
      transfer.toLocationCode,
      transfer.batchNumber ?? '',
    ].some((value) => value.toLowerCase().includes(term)));
  }, [transfers, search]);

  const tableRows = filtered.map((transfer) => ({
    id: transfer.transferId,
    transfer: `${transfer.transferNumber} — ${formatWhen(transfer.movedAt)}`,
    item: `${transfer.itemCode} — ${transfer.itemName}`,
    route: `${transfer.fromLocationCode} → ${transfer.toLocationCode}`,
    quantity: `${transfer.quantity} ${transfer.uom}`,
    batch: transfer.batchNumber ?? '—',
    reason: transfer.reasonText ?? '—',
  }));

  // Summary metrics — cheap client-side aggregates over the worklist.
  const itemsMoved = new Set(transfers.map((transfer) => transfer.itemId)).size;
  const routesUsed = new Set(
    transfers.map((transfer) => `${transfer.fromLocationCode} → ${transfer.toLocationCode}`),
  ).size;

  return (
    <div className="afy-section">
      <div className="afy-section__heading">
        <div>
          <h2 id="inventory-transfers-heading" className="afy-section__title">Transfers</h2>
          <p className="afy-section__description">
            Stock moved between this facility&apos;s own locations — both sides are written to the
            stock ledger, so the facility total stays the same. A transfer is not a loss: nothing
            is added or removed, and expired stock cannot be moved here (remove it through an
            adjustment instead).
          </p>
        </div>
        {canTransfer ? (
          <Button kind="tertiary" size="md" onClick={onMoveStock}>
            Move stock
          </Button>
        ) : null}
      </div>

      <StatisticsCards
        label="Transfer summary"
        cards={[
          {
            id: 'transfer-moves',
            label: 'Moves recorded',
            value: transfers.length,
            detail: 'Every move writes two ledger entries',
            borderTone: 'information',
            icon: <ArrowsHorizontal aria-hidden="true" />,
          },
          {
            id: 'transfer-items',
            label: 'Items moved',
            value: itemsMoved,
            detail: 'Distinct items relocated between locations',
            borderTone: 'information',
            icon: <Box aria-hidden="true" />,
          },
          {
            id: 'transfer-routes',
            label: 'Location routes',
            value: routesUsed,
            detail: 'From → to pairs in use at this facility',
            borderTone: 'information',
            icon: <List aria-hidden="true" />,
          },
        ]}
      />

      <CarbonDataTable
        rows={tableRows}
        headers={HEADERS}
        title={`Transfers (${filtered.length} of ${transfers.length})`}
        ariaLabel="Transfers"
        emptyState={
          transfers.length > 0 ? (
            <EmptyState title="No matches" description="No transfer matches this search." />
          ) : (
            <EmptyState
              title="Nothing moved yet"
              description="No stock has been moved at this facility yet. Use Move stock to hand it from one location to another."
            />
          )
        }
        toolbar={(onInputChange) => (
          <TableToolbarSearch
            persistent
            onChange={(event) => {
              onInputChange(event);
              setSearch(typeof event === 'string' ? event : event.target.value);
            }}
            placeholder="Search transfer"
            aria-label="Search transfers"
          />
        )}
      />
    </div>
  );
}
