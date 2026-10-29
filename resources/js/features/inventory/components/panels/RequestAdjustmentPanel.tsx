import { InlineNotification, Select, SelectItem, TextArea, TextInput } from '@carbon/react';
import { useForm } from '@inertiajs/react';
import { useEffect, useMemo, type FormEvent, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import { DockedPanel } from '@/components/panels';
import { SearchableComboBox } from '@/components/ui/SearchableComboBox';
import type { InventoryItem, StockLocation } from '../../types';

export interface AdjustmentFormData {
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
}

export interface RequestAdjustmentPanelProps {
  open: boolean;
  onClose: () => void;
  onSuccess?: () => void;
  items: InventoryItem[];
  locations: StockLocation[];
  initialItemId?: string | null;
  initialLocationId?: string | null;
}

const REASON_CODES: ReadonlyArray<{ value: string; label: string }> = [
  { value: 'COUNT_VARIANCE', label: 'Count variance' },
  { value: 'DAMAGED', label: 'Damaged' },
  { value: 'LOST', label: 'Lost' },
  { value: 'DATA_ERROR', label: 'Data error' },
  { value: 'EXPIRY', label: 'Expiry' },
  { value: 'OTHER', label: 'Other' },
];

/**
 * Raise a stock adjustment (stays PENDING_APPROVAL until a second authorised
 * actor approves it). Frontend stays thin: it mints the client ids and posts;
 * the server owns every rule and posts the compensating movement on approval.
 */
export function RequestAdjustmentPanel({
  open,
  onClose,
  onSuccess,
  items,
  locations,
  initialItemId,
  initialLocationId,
}: RequestAdjustmentPanelProps): ReactNode {
  const adjustableItems = useMemo(
    () => items.filter((item) => !item.tracksSerial),
    [items],
  );
  const defaultItemId = initialItemId && adjustableItems.some((item) => item.id === initialItemId)
    ? initialItemId
    : adjustableItems[0]?.id ?? '';
  const defaultLocationId = initialLocationId && locations.some((loc) => loc.id === initialLocationId)
    ? initialLocationId
    : locations[0]?.id ?? '';

  const form = useForm<AdjustmentFormData>({
    item_id: defaultItemId,
    location_id: defaultLocationId,
    direction: 'DECREASE',
    quantity: '',
    reason_code: 'COUNT_VARIANCE',
    reason_text: '',
    witness_name: '',
    batch_number: '',
    adjustment_id: crypto.randomUUID(),
    idempotency_key: crypto.randomUUID(),
  });

  useEffect(() => {
    if (initialItemId && adjustableItems.some((item) => item.id === initialItemId)) {
      form.setData('item_id', initialItemId);
    }
  }, [initialItemId, adjustableItems, form]);

  useEffect(() => {
    if (initialLocationId && locations.some((loc) => loc.id === initialLocationId)) {
      form.setData('location_id', initialLocationId);
    }
  }, [initialLocationId, locations, form]);

  const selectedItem = adjustableItems.find((item) => item.id === form.data.item_id);
  // Controlled medicine: a DECREASE needs a named witness (Phase 0 policy).
  // The domain refuses it without one, so the panel asks before posting.
  const witnessRequired = Boolean(selectedItem?.isControlled) && form.data.direction === 'DECREASE';

  function handleSubmit(event: FormEvent): void {
    event.preventDefault();
    form.post(routes.workspaces.inventoryAdjustmentsStore, {
      preserveScroll: true,
      onSuccess: () => {
        form.reset('quantity', 'reason_text', 'batch_number', 'witness_name');
        form.setData('adjustment_id', crypto.randomUUID());
        form.setData('idempotency_key', crypto.randomUUID());
        onSuccess?.();
        onClose();
      },
    });
  }

  return (
    <DockedPanel
      heading="Request stock adjustment"
      description="Raise a justified stock correction. It stays pending until a different authorised user approves or rejects it."
      hidden={!open}
      onClose={onClose}
      onSubmit={handleSubmit}
      primaryLabel={form.processing ? 'Submitting…' : 'Request adjustment'}
      primaryDisabled={
        form.processing
        || !form.data.item_id
        || !form.data.location_id
        || !form.data.quantity
        || (witnessRequired && form.data.witness_name.trim() === '')
      }
      secondaryLabel="Cancel"
      width="min(36rem, 100%)"
    >
      <div style={{ display: 'flex', flexDirection: 'column', gap: '1.25rem' }}>
        {items.length > 0 && adjustableItems.length === 0 ? (
          <InlineNotification
            kind="warning"
            title="Tracking Notice"
            subtitle="All configured items require serial-number tracking, which is not supported by this adjustment workflow yet."
            hideCloseButton
          />
        ) : null}

        {adjustableItems.length > 0 && locations.length > 0 ? (
          <>
            <SearchableComboBox
              id="adjustment-item"
              titleText="Inventory item"
              items={adjustableItems.map((item) => ({
                id: item.id,
                label: `${item.code} — ${item.name} (${item.itemType})`,
              }))}
              selectedId={form.data.item_id}
              onChange={(itemId) => form.setData('item_id', itemId)}
              invalid={Boolean(form.errors.item_id)}
              invalidText={form.errors.item_id}
              required
            />

            <Select
              id="adjustment-location"
              labelText="Stock location"
              value={form.data.location_id}
              onChange={(event) => form.setData('location_id', event.currentTarget.value)}
              invalid={Boolean(form.errors.location_id)}
              invalidText={form.errors.location_id}
            >
              {locations.map((location) => (
                <SelectItem key={location.id} value={location.id} text={`${location.code} — ${location.name}`} />
              ))}
            </Select>

            <Select
              id="adjustment-direction"
              labelText="Direction"
              value={form.data.direction}
              onChange={(event) => form.setData('direction', event.currentTarget.value)}
              invalid={Boolean(form.errors.direction)}
              invalidText={form.errors.direction}
            >
              <SelectItem value="DECREASE" text="Decrease (remove stock)" />
              <SelectItem value="INCREASE" text="Increase (add stock)" />
            </Select>

            <TextInput
              id="adjustment-quantity"
              labelText={`Quantity${selectedItem ? ` (${selectedItem.unitOfMeasure})` : ''}`}
              type="number"
              min="0.0001"
              step="0.0001"
              placeholder="e.g. 1"
              value={form.data.quantity}
              onChange={(event) => form.setData('quantity', event.currentTarget.value)}
              invalid={Boolean(form.errors.quantity)}
              invalidText={form.errors.quantity}
              required
            />

            <Select
              id="adjustment-reason-code"
              labelText="Reason"
              value={form.data.reason_code}
              onChange={(event) => form.setData('reason_code', event.currentTarget.value)}
              invalid={Boolean(form.errors.reason_code)}
              invalidText={form.errors.reason_code}
            >
              {REASON_CODES.map((reason) => (
                <SelectItem key={reason.value} value={reason.value} text={reason.label} />
              ))}
            </Select>

            {selectedItem?.tracksBatch ? (
              <TextInput
                id="adjustment-batch"
                labelText="Batch number"
                placeholder="An existing batch number for this item"
                value={form.data.batch_number}
                onChange={(event) => form.setData('batch_number', event.currentTarget.value)}
                invalid={Boolean(form.errors.batch_number)}
                invalidText={form.errors.batch_number}
                required
              />
            ) : null}

            {witnessRequired ? (
              <TextInput
                id="adjustment-witness"
                labelText="Witness (required for controlled medicine)"
                helperText="Name the person who watched the stock leave or be destroyed."
                value={form.data.witness_name}
                onChange={(event) => form.setData('witness_name', event.currentTarget.value)}
                invalid={Boolean(form.errors.witness_name)}
                invalidText={form.errors.witness_name}
                required
              />
            ) : null}

            <TextArea
              id="adjustment-reason-text"
              labelText="Notes (optional)"
              placeholder="Context a reviewer needs to decide"
              rows={3}
              value={form.data.reason_text}
              onChange={(event) => form.setData('reason_text', event.currentTarget.value)}
              invalid={Boolean(form.errors.reason_text)}
              invalidText={form.errors.reason_text}
            />
          </>
        ) : (
          <InlineNotification
            kind="warning"
            title="Setup Required"
            subtitle="Both catalogue items and active stock locations must be configured before requesting an adjustment."
            hideCloseButton
          />
        )}
      </div>
    </DockedPanel>
  );
}
