import { Button, InlineNotification, Tag } from '@carbon/react';
import { router } from '@inertiajs/react';
import { useMemo, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import { CarbonDataTable } from '@/components/ui/CarbonDataTable';
import { EmptyState } from '@/components/ui';
import { PENDING_REASON_LABELS, type CatalogPendingEventItem } from '../types';

export interface CatalogPendingChargesSectionProps {
  events: CatalogPendingEventItem[];
  canRetryCharges: boolean;
}

function reasonTagType(reason: string): 'red' | 'magenta' | 'purple' {
  switch (reason) {
    case 'ITEM_UNMAPPED':
      return 'red';
    case 'SERVICE_UNPRICED':
      return 'magenta';
    default:
      return 'purple';
  }
}

/**
 * The revenue-leakage worklist: captured billable events that produced no charge
 * (ADR-189 D-G / §130-131). Retrying is idempotent — it re-attempts the charge
 * against the current mapping and tariff.
 */
export function CatalogPendingChargesSection({
  events,
  canRetryCharges,
}: CatalogPendingChargesSectionProps): ReactNode {
  const rows = useMemo(
    () => events.map((event) => ({
      id: event.billableEventId,
      patient: `${event.patientDisplayName} (${event.patientNumber})`,
      quantity: event.quantity,
      reason: PENDING_REASON_LABELS[event.pendingReason] ?? event.pendingReason,
      occurredAt: event.occurredAt,
    })),
    [events],
  );

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
      <InlineNotification
        kind="info"
        lowContrast
        hideCloseButton
        title="How pending charges clear"
        subtitle="Mapping a medication automatically retries its unmapped events. A tariff change does NOT retry unpriced events — set the price, then retry here."
      />
      <CarbonDataTable
        rows={rows}
        headers={[
          { key: 'patient', header: 'Patient' },
          { key: 'quantity', header: 'Quantity' },
          { key: 'reason', header: 'Blocked by' },
          { key: 'occurredAt', header: 'Occurred' },
          { key: 'actions', header: 'Actions' },
        ]}
        title={`Pending charges (${events.length})`}
        ariaLabel="Pending billable events"
        emptyState={(
          <EmptyState
            title="No pending charges"
            description="Every captured billable act has priced into a charge."
          />
        )}
        renderCell={(cell, row) => {
          if (cell.info.header === 'reason') {
            const raw = events.find((event) => event.billableEventId === row.id)?.pendingReason ?? '';
            return <Tag type={reasonTagType(raw)} size="sm">{String(cell.value)}</Tag>;
          }
          if (cell.info.header === 'quantity') {
            return <span style={{ fontVariantNumeric: 'tabular-nums' }}>{String(cell.value)}</span>;
          }
          if (cell.info.header === 'actions') {
            return (
              <Button
                kind="ghost"
                size="sm"
                disabled={!canRetryCharges}
                onClick={() => router.post(
                  routes.workspaces.billingCatalogEventRetry(String(row.id)),
                  {},
                  { preserveScroll: true },
                )}
              >
                Retry charge
              </Button>
            );
          }
          return cell.value;
        }}
      />
    </div>
  );
}
