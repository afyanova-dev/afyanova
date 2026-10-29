import {
  Button,
  Modal,
  OverflowMenuItem,
  TableToolbarMenu,
  TableToolbarSearch,
  Tag,
  TextArea,
} from '@carbon/react';
import { useMemo, useState, type ReactNode } from 'react';
import { EmptyState, CarbonDataTable } from '@/components/ui';
import { Filter } from '@/icons';
import type { InventoryItem, StockAdjustment, StockLocation } from '../types';

export interface AdjustmentsSectionProps {
  adjustments: StockAdjustment[];
  /** Null = all statuses. */
  statusFilter: string | null;
  items: InventoryItem[];
  locations: StockLocation[];
  canRequestAdjustment: boolean;
  canApproveAdjustments: boolean;
  /** The signed-in user; used to hide self-approval (the server still enforces it). */
  currentUserId: string;
  onStatusFilterChange: (status: string | null) => void;
  onRequestAdjustment: () => void;
  onApproveAdjustment: (adjustmentId: string) => void;
  onRejectAdjustment: (adjustmentId: string, reason: string) => void;
}

const STATUS_FILTERS: ReadonlyArray<{ value: string | null; label: string }> = [
  { value: null, label: 'All statuses' },
  { value: 'PENDING_APPROVAL', label: 'Pending approval' },
  { value: 'APPROVED', label: 'Approved' },
  { value: 'REJECTED', label: 'Rejected' },
];

const STATUS_LABELS: Record<string, string> = {
  PENDING_APPROVAL: 'Pending approval',
  APPROVED: 'Approved',
  REJECTED: 'Rejected',
};

const STATUS_TAG: Record<string, 'blue' | 'green' | 'red' | 'cool-gray'> = {
  PENDING_APPROVAL: 'blue',
  APPROVED: 'green',
  REJECTED: 'red',
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
 * Facility-wide stock-adjustment worklist (`GetStockAdjustments`). Shows the
 * request/decision lifecycle and, for authorized approvers, the approve/reject
 * decision. It never edits stock directly — approval posts a compensating
 * ledger movement server-side, and the requester cannot decide their own
 * adjustment (enforced by the handler, so self-approval is hidden here too).
 */
export function AdjustmentsSection({
  adjustments,
  statusFilter,
  items,
  locations,
  canRequestAdjustment,
  canApproveAdjustments,
  currentUserId,
  onStatusFilterChange,
  onRequestAdjustment,
  onApproveAdjustment,
  onRejectAdjustment,
}: AdjustmentsSectionProps): ReactNode {
  const [rejectTarget, setRejectTarget] = useState<StockAdjustment | null>(null);
  const [rejectReason, setRejectReason] = useState('');

  const itemLabels = useMemo(
    () => new Map(items.map((item) => [item.id, `${item.code} — ${item.name}`])),
    [items],
  );
  const locationLabels = useMemo(
    () => new Map(locations.map((location) => [location.id, `${location.code} — ${location.name}`])),
    [locations],
  );
  const adjustmentsById = useMemo(
    () => new Map(adjustments.map((adjustment) => [adjustment.adjustmentId, adjustment])),
    [adjustments],
  );

  const rows = adjustments.map((adjustment) => ({
    id: adjustment.adjustmentId,
    item: itemLabels.get(adjustment.itemId) ?? shortId(adjustment.itemId),
    location: locationLabels.get(adjustment.locationId) ?? shortId(adjustment.locationId),
    adjustment: `${adjustment.direction === 'DECREASE' ? '−' : '+'}${adjustment.quantity}`,
    reason: [
      humanize(adjustment.reasonCode),
      adjustment.reasonText,
      adjustment.witnessName ? `Witness: ${adjustment.witnessName}` : null,
    ].filter(Boolean).join(' · '),
    status: adjustment.status,
    requested: formatDate(adjustment.requestedAt),
    decision: adjustment.status === 'REJECTED'
      ? (adjustment.rejectionReason ?? 'Rejected')
      : adjustment.status === 'APPROVED'
        ? formatDate(adjustment.approvedAt)
        : 'Awaiting approval',
    actions: adjustment.adjustmentId,
  }));

  const headers = [
    { key: 'item', header: 'Item' },
    { key: 'location', header: 'Location' },
    { key: 'adjustment', header: 'Adjustment' },
    { key: 'reason', header: 'Reason' },
    { key: 'status', header: 'Status' },
    { key: 'requested', header: 'Requested' },
    { key: 'decision', header: 'Decision' },
    ...(canApproveAdjustments ? [{ key: 'actions', header: 'Actions' }] : []),
  ];

  const currentStatusLabel = STATUS_FILTERS.find((option) => option.value === statusFilter)?.label
    ?? 'All statuses';

  function closeReject(): void {
    setRejectTarget(null);
    setRejectReason('');
  }

  function submitReject(): void {
    if (!rejectTarget || rejectReason.trim() === '') return;
    onRejectAdjustment(rejectTarget.adjustmentId, rejectReason.trim());
    closeReject();
  }

  return (
    <>
      <CarbonDataTable
        rows={rows}
        headers={headers}
        title={`Adjustments (${rows.length})`}
        ariaLabel="Stock adjustment worklist"
        emptyState={(
          <EmptyState
            title="No stock adjustments match the current filter"
            description="Adjustments raised at this facility appear here once requested, approved, or rejected."
          />
        )}
        toolbar={(onInputChange) => (
          <>
            <TableToolbarSearch
              persistent
              onChange={onInputChange}
              placeholder="Search adjustments"
              aria-label="Search adjustments"
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
            {canRequestAdjustment ? (
              <Button size="lg" onClick={onRequestAdjustment}>
                Request adjustment
              </Button>
            ) : null}
          </>
        )}
        renderCell={(cell, row) => {
          if (cell.info.header === 'status') {
            const status = String(cell.value);
            return (
              <Tag type={STATUS_TAG[status] ?? 'cool-gray'} size="sm">
                {STATUS_LABELS[status] ?? humanize(status)}
              </Tag>
            );
          }
          if (cell.info.header === 'actions') {
            const adjustment = adjustmentsById.get(row.id);
            if (!adjustment || adjustment.status !== 'PENDING_APPROVAL') {
              return <span style={{ color: 'var(--cds-text-muted, #8d8d8d)' }}>—</span>;
            }
            if (adjustment.requestedByUserId === currentUserId) {
              return (
                <span style={{ color: 'var(--cds-text-secondary, #525252)', fontSize: '0.875rem' }}>
                  Awaiting another approver
                </span>
              );
            }
            return (
              <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'center' }}>
                <Button kind="danger--tertiary" size="sm" onClick={() => setRejectTarget(adjustment)}>
                  Reject
                </Button>
                <Button size="sm" onClick={() => onApproveAdjustment(adjustment.adjustmentId)}>
                  Approve
                </Button>
              </div>
            );
          }
          return cell.value;
        }}
      />

      {rejectTarget ? (
        <Modal
          open
          danger
          modalHeading="Reject stock adjustment"
          primaryButtonText="Reject adjustment"
          secondaryButtonText="Cancel"
          primaryButtonDisabled={rejectReason.trim() === ''}
          onRequestClose={closeReject}
          onRequestSubmit={submitReject}
        >
          <p style={{ marginBlockEnd: '1rem' }}>
            Rejecting records your decision and leaves stock unchanged. The requester can raise a
            corrected adjustment.
          </p>
          <TextArea
            id="adjustment-rejection-reason"
            labelText="Decision note"
            helperText="Required when rejecting."
            value={rejectReason}
            onChange={(event) => setRejectReason(event.currentTarget.value)}
            maxLength={500}
            rows={3}
            required
          />
        </Modal>
      ) : null}
    </>
  );
}
