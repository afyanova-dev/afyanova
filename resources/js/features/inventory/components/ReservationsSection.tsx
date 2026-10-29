import { OverflowMenuItem, TableToolbarMenu, TableToolbarSearch, Tag } from '@carbon/react';
import { useMemo, type ReactNode } from 'react';
import { EmptyState, CarbonDataTable } from '@/components/ui';
import { Filter } from '@/icons';
import type { InventoryItem, StockLocation, StockReservation } from '../types';

export interface ReservationsSectionProps {
  reservations: StockReservation[];
  /** Null = all statuses. */
  statusFilter: string | null;
  items: InventoryItem[];
  locations: StockLocation[];
  onStatusFilterChange: (status: string | null) => void;
}

const STATUS_FILTERS: ReadonlyArray<{ value: string | null; label: string }> = [
  { value: null, label: 'All statuses' },
  { value: 'ACTIVE', label: 'Active' },
  { value: 'FULFILLED', label: 'Fulfilled' },
  { value: 'RELEASED', label: 'Released' },
  { value: 'EXPIRED', label: 'Expired' },
  { value: 'CANCELLED', label: 'Cancelled' },
];

const STATUS_LABELS: Record<string, string> = {
  ACTIVE: 'Active',
  FULFILLED: 'Fulfilled',
  RELEASED: 'Released',
  EXPIRED: 'Expired',
  CANCELLED: 'Cancelled',
};

const STATUS_TAG: Record<string, 'blue' | 'green' | 'cool-gray' | 'magenta' | 'red'> = {
  ACTIVE: 'blue',
  FULFILLED: 'green',
  RELEASED: 'cool-gray',
  EXPIRED: 'magenta',
  CANCELLED: 'red',
};

function humanize(value: string): string {
  const normalized = value.replace(/_/g, ' ').toLowerCase();
  return normalized.length > 0 ? normalized[0]!.toUpperCase() + normalized.slice(1) : value;
}

function formatDate(value: string | null): string {
  if (!value) return '—';
  return new Date(value).toLocaleString();
}

function shortId(value: string): string {
  return value.length > 8 ? `${value.slice(0, 8)}…` : value;
}

/**
 * Facility-wide, read-only stock-reservation visibility
 * (`SearchStockReservations`, 18-inventory-and-warehousing-domain.md
 * §112–§116, §150). Reservations commit stock to a consumer without moving it;
 * this slice only surfaces them (item, location, quantity, consumer, hold, and
 * lifecycle outcome) — reservation mutations stay with their owning domains.
 */
export function ReservationsSection({
  reservations,
  statusFilter,
  items,
  locations,
  onStatusFilterChange,
}: ReservationsSectionProps): ReactNode {
  const itemLabels = useMemo(
    () => new Map(items.map((item) => [item.id, `${item.code} — ${item.name}`])),
    [items],
  );
  const locationLabels = useMemo(
    () => new Map(locations.map((location) => [location.id, `${location.code} — ${location.name}`])),
    [locations],
  );

  const rows = reservations.map((reservation) => ({
    id: reservation.reservationId,
    item: itemLabels.get(reservation.itemId) ?? shortId(reservation.itemId),
    location: locationLabels.get(reservation.locationId) ?? shortId(reservation.locationId),
    quantity: reservation.reservedQty,
    consumer: [humanize(reservation.consumerType), reservation.consumerRef].filter(Boolean).join(' · '),
    reason: reservation.reason ?? '—',
    holdUntil: formatDate(reservation.holdUntil),
    status: reservation.status,
  }));

  const headers = [
    { key: 'item', header: 'Item' },
    { key: 'location', header: 'Location' },
    { key: 'quantity', header: 'Quantity' },
    { key: 'consumer', header: 'Consumer' },
    { key: 'reason', header: 'Reason' },
    { key: 'holdUntil', header: 'Hold until' },
    { key: 'status', header: 'Status' },
  ];

  const currentStatusLabel = STATUS_FILTERS.find((option) => option.value === statusFilter)?.label
    ?? 'All statuses';

  return (
    <CarbonDataTable
      rows={rows}
      headers={headers}
      title={`Reservations (${rows.length})`}
      ariaLabel="Stock reservation visibility"
      emptyState={(
        <EmptyState
          title="No stock reservations match the current filter"
          description="Stock committed to a consumer at this facility appears here, with its hold and lifecycle outcome."
        />
      )}
      toolbar={(onInputChange) => (
        <>
          <TableToolbarSearch
            persistent
            onChange={onInputChange}
            placeholder="Search reservations"
            aria-label="Search reservations"
          />
          <TableToolbarMenu
            renderIcon={Filter}
            iconDescription={`Filter by status: ${currentStatusLabel}`}
          >
            {STATUS_FILTERS.map((option) => (
              <OverflowMenuItem
                key={option.value ?? 'all'}
                itemText={option.label}
                isDelete={false}
                onClick={() => onStatusFilterChange(option.value)}
              />
            ))}
          </TableToolbarMenu>
        </>
      )}
      renderCell={(cell) => {
        if (cell.info.header === 'status') {
          const status = String(cell.value);
          return (
            <Tag type={STATUS_TAG[status] ?? 'cool-gray'} size="sm">
              {STATUS_LABELS[status] ?? humanize(status)}
            </Tag>
          );
        }
        return cell.value;
      }}
    />
  );
}
