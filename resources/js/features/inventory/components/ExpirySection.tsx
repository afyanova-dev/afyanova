import {
  Button,
  InlineNotification,
  OverflowMenuItem,
  Stack,
  TableToolbarMenu,
  TableToolbarSearch,
  Tag,
  TextArea,
  TextInput,
} from '@carbon/react';
import { useForm } from '@inertiajs/react';
import { useMemo, useState, type FormEvent, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import { DockedPanel } from '@/components/panels';
import { CarbonDataTable, EmptyState, StatisticsCards } from '@/components/ui';
import { Calendar, Filter, Timer, WarningAlt } from '@/icons';
import type { ExpiringStock, StockAdjustment, StockLocation } from '../types';

export interface ExpirySectionProps {
  rows: ExpiringStock[];
  /** The server's near-expiry window (90 days). */
  windowDays: number;
  locations: StockLocation[];
  /** Pending/decided removals, used to show "already requested" and avoid duplicates. */
  adjustments: StockAdjustment[];
  canRequestAdjustment: boolean;
}

type RemovalForm = {
  item_id: string;
  location_id: string;
  direction: string;
  quantity: string;
  reason_code: string;
  reason_text: string;
  witness_name: string;
  batch_number: string;
  adjustment_id: string;
  idempotency_key: string;
};

const HEADERS = [
  { key: 'item', header: 'Item' },
  { key: 'batch', header: 'Batch' },
  { key: 'expiry', header: 'Expires' },
  { key: 'days', header: 'Time left' },
  { key: 'location', header: 'Location' },
  { key: 'onHand', header: 'On hand' },
  { key: 'actions', header: 'Actions' },
];

function formatDate(value: string): string {
  const date = new Date(`${value}T00:00:00`);

  return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString('en-GB');
}

/** "Expired 3 days ago", "Expires today", "In 12 days". */
function formatDays(row: ExpiringStock): string {
  if (row.daysRemaining < 0) {
    const days = Math.abs(row.daysRemaining);

    return `Expired ${days} day${days === 1 ? '' : 's'} ago`;
  }

  if (row.daysRemaining === 0) {
    return 'Expires today';
  }

  return `In ${row.daysRemaining} day${row.daysRemaining === 1 ? '' : 's'}`;
}

function daysTag(row: ExpiringStock): 'red' | 'magenta' | 'gray' {
  if (row.expired) {
    return 'red';
  }

  return row.daysRemaining <= 30 ? 'magenta' : 'gray';
}

/**
 * Expiry worklist (frontend-foundation-inventory-operations.md Phase 0
 * disposition policy). Expired stock is already unusable for dispensing — this
 * screen makes it visible before it becomes a loss and offers the ONE removal
 * path the domain allows: a two-person DECREASE adjustment. Nothing here edits
 * a stock balance.
 *
 * A second authorised person must approve the removal in Adjustments; that rule
 * lives in the handler, so this screen only offers the request.
 */
export function ExpirySection({
  rows,
  windowDays,
  locations,
  adjustments,
  canRequestAdjustment,
}: ExpirySectionProps): ReactNode {
  const [filter, setFilter] = useState<'all' | 'expired' | 'soon'>('all');
  const [target, setTarget] = useState<ExpiringStock | null>(null);
  const [search, setSearch] = useState('');

  const locationLabels = useMemo(
    () => new Map(locations.map((location) => [location.id, `${location.code} — ${location.name}`])),
    [locations],
  );

  const pendingBatchIds = useMemo(() => {
    const ids = new Set<string>();

    for (const adjustment of adjustments) {
      if (
        adjustment.status === 'PENDING_APPROVAL'
        && adjustment.direction === 'DECREASE'
        && adjustment.reasonCode === 'EXPIRY'
        && adjustment.batchId !== null
      ) {
        ids.add(adjustment.batchId);
      }
    }

    return ids;
  }, [adjustments]);

  const expiredCount = rows.filter((row) => row.expired).length;
  const soonCount = rows.filter((row) => !row.expired && row.daysRemaining <= 30).length;

  const filtered = useMemo(() => {
    const term = search.trim().toLowerCase();

    return rows.filter((row) => {
      if (filter === 'expired' && !row.expired) {
        return false;
      }

      if (filter === 'soon' && (row.expired || row.daysRemaining > 30)) {
        return false;
      }

      if (term === '') {
        return true;
      }

      return [row.itemCode, row.itemName, row.batchNumber]
        .some((value) => value.toLowerCase().includes(term));
    });
  }, [rows, filter, search]);

  const removalForm = useForm<RemovalForm>({
    item_id: '',
    location_id: '',
    direction: 'DECREASE',
    quantity: '',
    reason_code: 'EXPIRY',
    reason_text: '',
    witness_name: '',
    batch_number: '',
    adjustment_id: '',
    idempotency_key: '',
  });

  function openRemoval(row: ExpiringStock): void {
    removalForm.clearErrors();
    removalForm.setData({
      item_id: row.itemId,
      location_id: row.locationId,
      direction: 'DECREASE',
      quantity: row.onHand,
      reason_code: 'EXPIRY',
      reason_text: '',
      witness_name: '',
      batch_number: row.batchNumber,
      adjustment_id: crypto.randomUUID(),
      idempotency_key: crypto.randomUUID(),
    });
    setTarget(row);
  }

  function closeRemoval(): void {
    removalForm.reset();
    setTarget(null);
  }

  function submitRemoval(event: FormEvent): void {
    event.preventDefault();
    removalForm.post(routes.workspaces.inventoryAdjustmentsStore, {
      preserveScroll: true,
      onSuccess: () => {
        removalForm.reset();
        setTarget(null);
      },
    });
  }

  const tableRows = filtered.map((row) => ({
    id: row.balanceId,
    item: `${row.itemCode} — ${row.itemName}`,
    batch: row.batchNumber,
    expiry: formatDate(row.expiryDate),
    days: row.daysRemaining,
    location: locationLabels.get(row.locationId) ?? row.locationCode,
    onHand: `${row.onHand} ${row.uom}`,
    actions: row.balanceId,
  }));

  const rowsById = new Map(filtered.map((row) => [row.balanceId, row]));

  return (
    <div className="afy-section">
      <div className="afy-section__heading">
        <div>
          <h2 id="inventory-expiry-heading" className="afy-section__title">Expiry</h2>
          <p className="afy-section__description">
            Batches that have expired, or will expire in the next {windowDays} days.
            Expired stock is already blocked from dispensing. Removing a batch creates a
            normal stock removal request — stock only leaves when a second authorised
            person approves it in Adjustments.
          </p>
        </div>
      </div>

      <StatisticsCards
        label="Expiry summary"
        cards={[
          {
            id: 'expiry-expired',
            label: 'Expired',
            value: expiredCount,
            detail: 'Blocked from dispensing — request removal',
            borderTone: expiredCount > 0 ? 'negative' : 'information',
            icon: <WarningAlt aria-hidden="true" />,
          },
          {
            id: 'expiry-soon',
            label: 'Expiring within 30 days',
            value: soonCount,
            detail: 'Stock to dispense first (FEFO)',
            borderTone: soonCount > 0 ? 'caution' : 'information',
            icon: <Timer aria-hidden="true" />,
          },
          {
            id: 'expiry-window',
            label: `Inside the ${windowDays}-day window`,
            value: rows.length,
            detail: 'Batches with stock on hand',
            borderTone: 'information',
            icon: <Calendar aria-hidden="true" />,
          },
        ]}
      />

      <CarbonDataTable
        rows={tableRows}
        headers={HEADERS}
        title={`Expiring stock (${filtered.length} of ${rows.length})`}
        ariaLabel="Expiring stock"
        emptyState={
          rows.length === 0 ? (
            <EmptyState
              title="Nothing expiring"
              description={`No batch with stock on hand expires in the next ${windowDays} days.`}
            />
          ) : (
            <EmptyState title="No matches" description="No batch matches this filter." />
          )
        }
        toolbar={(onInputChange) => (
          <>
            <TableToolbarSearch
              persistent
              onChange={(event) => {
                onInputChange(event);
                setSearch(typeof event === 'string' ? event : event.target.value);
              }}
              placeholder="Search item or batch"
              aria-label="Search expiring stock"
            />
            <TableToolbarMenu
              renderIcon={Filter}
              iconDescription={`Filter expiry: ${filter === 'all' ? 'All' : filter === 'expired' ? 'Expired' : 'Within 30 days'}`}
            >
              <OverflowMenuItem
                itemText={`All in the ${windowDays}-day window (${rows.length})`}
                isDelete={false}
                onClick={() => setFilter('all')}
              />
              <OverflowMenuItem
                itemText={`Expired (${expiredCount})`}
                isDelete={false}
                onClick={() => setFilter('expired')}
              />
              <OverflowMenuItem
                itemText={`Expiring within 30 days (${soonCount})`}
                isDelete={false}
                onClick={() => setFilter('soon')}
              />
            </TableToolbarMenu>
          </>
        )}
        renderCell={(cell, row) => {
          if (cell.info.header === 'batch') {
            return <code className="procurement-code">{String(cell.value)}</code>;
          }

          if (cell.info.header === 'days') {
            const expiryRow = rowsById.get(String(row.id));

            if (expiryRow === undefined) {
              return null;
            }

            return (
              <Tag type={daysTag(expiryRow)} size="sm">
                {formatDays(expiryRow)}
              </Tag>
            );
          }

          if (cell.info.header === 'actions') {
            const expiryRow = rowsById.get(String(row.id));

            if (expiryRow === undefined) {
              return null;
            }

            if (pendingBatchIds.has(expiryRow.batchId)) {
              return <Tag type="blue" size="sm">Removal requested</Tag>;
            }

            if (!canRequestAdjustment) {
              return null;
            }

            return (
              <Button kind="ghost" size="sm" onClick={() => openRemoval(expiryRow)}>
                Remove batch
              </Button>
            );
          }

          return cell.value;
        }}
      />

      <DockedPanel
        heading={target ? `Remove ${target.batchNumber}` : 'Remove batch'}
        description="This asks to remove the whole remaining batch. A different authorised user must approve it before stock moves."
        hidden={target === null}
        onClose={closeRemoval}
        onSubmit={submitRemoval}
        primaryLabel={removalForm.processing ? 'Requesting…' : 'Request removal'}
        primaryDisabled={removalForm.processing || (target?.controlled === true && removalForm.data.witness_name.trim() === '')}
        danger
        width="min(34rem, 100%)"
      >
        {target ? (
          <Stack gap={5}>
            <InlineNotification
              kind="warning"
              lowContrast
              hideCloseButton
              title="Nothing leaves stock yet"
              subtitle="Approving the request in Adjustments records the removal in the stock ledger."
            />

            <div>
              <strong>{target.itemCode} — {target.itemName}</strong>
              <p className="procurement-hint">
                Batch {target.batchNumber} · expires {formatDate(target.expiryDate)} ·{' '}
                {locationLabels.get(target.locationId) ?? target.locationCode}
              </p>
            </div>

            <TextInput
              id="expiry-removal-quantity"
              labelText="Quantity to remove"
              helperText="The full remaining quantity of this batch."
              value={`${target.onHand} ${target.uom}`}
              readOnly
            />

            {target.controlled ? (
              <TextInput
                id="expiry-removal-witness"
                labelText="Witness (required for controlled medicine)"
                helperText="Name the person who watched the stock be destroyed or removed."
                value={removalForm.data.witness_name}
                onChange={(event) => removalForm.setData('witness_name', event.currentTarget.value)}
                invalid={Boolean(removalForm.errors.witness_name)}
                invalidText={removalForm.errors.witness_name}
                required
              />
            ) : null}

            <TextArea
              id="expiry-removal-note"
              labelText="Note (optional)"
              helperText="How it was destroyed. A witness is named above for controlled medicines."
              rows={3}
              value={removalForm.data.reason_text}
              onChange={(event) => removalForm.setData('reason_text', event.currentTarget.value)}
              invalid={Boolean(removalForm.errors.reason_text)}
              invalidText={removalForm.errors.reason_text}
            />

            <p className="procurement-hint">
              The request will show in Adjustments as “Pending approval” for someone else to decide.
            </p>
          </Stack>
        ) : null}
      </DockedPanel>
    </div>
  );
}
