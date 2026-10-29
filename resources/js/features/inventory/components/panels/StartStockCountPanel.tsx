import { InlineNotification, Select, SelectItem, TextArea } from '@carbon/react';
import { useForm } from '@inertiajs/react';
import { useEffect, type FormEvent, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import { DockedPanel } from '@/components/panels';
import type { StockLocation } from '../../types';

export interface StartStockCountFormData {
  stock_count_id: string;
  idempotency_key: string;
  location_id: string;
  notes: string;
}

function freshForm(): StartStockCountFormData {
  return {
    stock_count_id: crypto.randomUUID(),
    idempotency_key: crypto.randomUUID(),
    location_id: '',
    notes: '',
  };
}

export interface StartStockCountPanelProps {
  open: boolean;
  onClose: () => void;
  /** Active locations at this facility. */
  locations: StockLocation[];
}

/**
 * Start a physical stock count at one location (Phase 8).
 *
 * Starting freezes the system's list of what is there into the count sheet; the
 * counter then walks the shelf at their own pace. Only one count may be open at
 * a location at a time.
 */
export function StartStockCountPanel({ open, onClose, locations }: StartStockCountPanelProps): ReactNode {
  const form = useForm<StartStockCountFormData>(freshForm());

  // A fresh count id every time the panel is opened, so retrying after a
  // mistake posts a new count rather than colliding with the old one.
  useEffect(() => {
    if (!open) {
      form.setData(freshForm());
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  function handleSubmit(event: FormEvent): void {
    event.preventDefault();

    form.post(routes.workspaces.inventoryStockCountsStore, {
      preserveScroll: true,
      only: ['stockCounts', 'stockCountSheet', 'status', 'errors'],
      onSuccess: () => {
        form.setData(freshForm());
        onClose();
      },
    });
  }

  return (
    <DockedPanel
      heading="Start a stock count"
      description="Record what is physically on the shelf at one location and compare it with the system."
      hidden={!open}
      onClose={onClose}
      onSubmit={handleSubmit}
      primaryLabel={form.processing ? 'Starting…' : 'Start count'}
      primaryDisabled={form.processing || form.data.location_id === ''}
      width="min(36rem, 100%)"
    >
      <div style={{ display: 'flex', flexDirection: 'column', gap: '1.25rem' }}>
        <InlineNotification
          kind="info"
          lowContrast
          hideCloseButton
          title="The sheet is a snapshot"
          subtitle="Starting the count freezes what the system believes is at the location. Counted quantities are compared with that snapshot, and nothing on the shelf is changed."
        />

        <Select
          id="start-count-location"
          labelText="Location to count"
          helperText="One count can be open at a location at a time."
          value={form.data.location_id}
          onChange={(event) => form.setData('location_id', event.currentTarget.value)}
          invalid={Boolean(form.errors.location_id)}
          invalidText={form.errors.location_id}
        >
          <SelectItem value="" text="Choose a location" />
          {locations.map((location) => (
            <SelectItem
              key={location.id}
              value={location.id}
              text={`${location.code} — ${location.name}`}
            />
          ))}
        </Select>

        {locations.length === 0 ? (
          <InlineNotification
            kind="warning"
            title="No locations"
            subtitle="This facility needs an active stock location before a count can be started."
            hideCloseButton
          />
        ) : null}

        <TextArea
          id="start-count-notes"
          labelText="Notes (optional)"
          helperText="For example: monthly count, or who is counting with you."
          value={form.data.notes}
          onChange={(event) => form.setData('notes', event.target.value)}
          invalid={Boolean(form.errors.notes)}
          invalidText={form.errors.notes}
          rows={3}
        />

        <InlineNotification
          kind="info"
          lowContrast
          hideCloseButton
          title="Differences are not corrected here"
          subtitle="After the count, raise a stock adjustment for each difference. A second person approves it, so counting and correcting stay separate jobs."
        />
      </div>
    </DockedPanel>
  );
}
