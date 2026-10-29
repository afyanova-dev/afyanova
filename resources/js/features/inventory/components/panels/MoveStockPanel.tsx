import { InlineNotification, Select, SelectItem, TextInput } from '@carbon/react';
import { useForm } from '@inertiajs/react';
import { useEffect, useMemo, type FormEvent, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import { DockedPanel } from '@/components/panels';
import { SearchableComboBox } from '@/components/ui/SearchableComboBox';
import type { InventoryItem, StockLocation, TransferSourceBatch, TransferSourceItem } from '../../types';

export interface MoveStockFormData {
  transfer_id: string;
  idempotency_key: string;
  from_location_id: string;
  to_location_id: string;
  item_id: string;
  batch_number: string;
  quantity: string;
  occurred_at: string;
  reason: string;
}

export interface MoveStockPanelProps {
  open: boolean;
  onClose: () => void;
  /** Active locations at this facility. */
  locations: StockLocation[];
  /** The item master, only for the pack hint. */
  items: InventoryItem[];
  /** Everything currently held at the chosen source location. */
  source: TransferSourceItem[];
  /** The source the server loaded stock for (`?transfer_from=`). */
  fromLocationId: string | null;
  /** Reload the source list when the chosen location changes. */
  onSourceChange: (locationId: string) => void;
}

function freshForm(fromLocationId: string): MoveStockFormData {
  return {
    transfer_id: crypto.randomUUID(),
    idempotency_key: crypto.randomUUID(),
    from_location_id: fromLocationId,
    to_location_id: '',
    item_id: '',
    batch_number: '',
    quantity: '',
    occurred_at: '',
    reason: '',
  };
}

/** "150 TABLET ≈ 1.5 BOX" — counting help only; the stock quantity is the truth. */
function packHint(item: InventoryItem | undefined, quantity: string): string {
  if (item === undefined) {
    return '';
  }

  if (item.packUnit === null || item.packSize === null) {
    return `Stock is counted in ${item.unitOfMeasure}.`;
  }

  const base = `${item.packSize} ${item.unitOfMeasure}`;
  const packs = Number(quantity) / Number(item.packSize);

  if (quantity.trim() === '' || !Number.isFinite(packs)) {
    return `1 ${item.packUnit} = ${base}.`;
  }

  return `${quantity} ${item.unitOfMeasure} ≈ ${Number.isInteger(packs) ? packs : packs.toFixed(2).replace(/0+$/, '').replace(/\.$/, '')} ${item.packUnit} (1 ${item.packUnit} = ${base}).`;
}

/**
 * Move stock between two locations of this facility (Phase 8).
 *
 * Plain English: the user says what is moving, where it is now, where it is
 * going, and how much (in the item's stock unit). The server writes both ledger
 * sides in one transaction, so the facility total never changes. A batch can be
 * chosen only from what the source location actually holds; expired batches are
 * shown but cannot be moved.
 */
export function MoveStockPanel({
  open,
  onClose,
  locations,
  items,
  source,
  fromLocationId,
  onSourceChange,
}: MoveStockPanelProps): ReactNode {
  const form = useForm<MoveStockFormData>(freshForm(fromLocationId ?? ''));

  // The source list arrives from the server after a partial reload; keep the
  // form in step with it.
  useEffect(() => {
    if (fromLocationId !== null && fromLocationId !== form.data.from_location_id) {
      form.setData('from_location_id', fromLocationId);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [fromLocationId]);

  const sourceItems = useMemo(
    () => source.filter((item) => Number(item.available) > 0),
    [source],
  );

  const selectedSourceItem = sourceItems.find((item) => item.itemId === form.data.item_id) ?? null;
  const batches: TransferSourceBatch[] = selectedSourceItem?.batches ?? [];
  const batchTracked = batches.some((batch) => batch.batchId !== null);
  const selectedBatch = batches.find((batch) => batch.batchNumber === form.data.batch_number) ?? null;

  const available = batchTracked
    ? (selectedBatch?.available ?? '0')
    : (selectedSourceItem?.available ?? '0');
  const expired = selectedBatch !== null && !selectedBatch.eligible;

  const destinationOptions = locations.filter((location) => location.id !== form.data.from_location_id);
  const catalogueItem = items.find((item) => item.id === form.data.item_id);

  const quantityNumber = Number.parseFloat(form.data.quantity);
  const overAvailable = Number.isFinite(quantityNumber) && quantityNumber > Number(available);

  // The server validates these too; this only keeps the obvious mistakes out.
  const formIncomplete = form.data.from_location_id === ''
    || form.data.to_location_id === ''
    || form.data.item_id === ''
    || form.data.quantity.trim() === ''
    || quantityNumber <= 0
    || (batchTracked && form.data.batch_number === '');

  function chooseSource(locationId: string): void {
    form.setData('from_location_id', locationId);
    form.setData('item_id', '');
    form.setData('batch_number', '');
    form.setData('quantity', '');

    // The destination cannot be the place the stock already is.
    if (form.data.to_location_id === locationId) {
      form.setData('to_location_id', '');
    }

    onSourceChange(locationId);
  }

  function chooseItem(itemId: string): void {
    const item = sourceItems.find((candidate) => candidate.itemId === itemId) ?? null;
    const trackedBatches = item?.batches.filter((batch) => batch.batchId !== null) ?? [];
    const firstBatch = trackedBatches.find((batch) => batch.eligible) ?? trackedBatches[0] ?? null;

    form.setData('item_id', itemId);
    form.setData('batch_number', firstBatch?.batchNumber ?? '');
    form.setData('quantity', '');
  }

  function handleSubmit(event: FormEvent): void {
    event.preventDefault();
    form.post(routes.workspaces.inventoryTransfersStore, {
      preserveScroll: true,
      onSuccess: () => {
        form.setData(freshForm(form.data.from_location_id));
        onClose();
      },
    });
  }

  return (
    <DockedPanel
      heading="Move stock"
      description="Hand stock from one location to another inside this facility. The facility total stays the same."
      hidden={!open}
      onClose={onClose}
      onSubmit={handleSubmit}
      primaryLabel={form.processing ? 'Moving…' : 'Move stock'}
      primaryDisabled={form.processing || formIncomplete || expired || overAvailable}
      secondaryLabel="Cancel"
      width="min(36rem, 100%)"
    >
      <div style={{ display: 'flex', flexDirection: 'column', gap: '1.25rem' }}>
        <InlineNotification
          kind="info"
          lowContrast
          hideCloseButton
          title="One move, two ledger entries"
          subtitle="The stock leaves the source and arrives at the destination at the same moment. Nothing is added or lost, so no second approver is needed."
        />

        <Select
          id="move-stock-from"
          labelText="Stock is in"
          helperText="Choose where the stock is now. Only that location's stock can be moved."
          value={form.data.from_location_id}
          onChange={(event) => chooseSource(event.currentTarget.value)}
          invalid={Boolean(form.errors.from_location_id)}
          invalidText={form.errors.from_location_id}
        >
          <SelectItem value="" text="Choose a source location" />
          {locations.map((location) => (
            <SelectItem
              key={location.id}
              value={location.id}
              text={`${location.code} — ${location.name}`}
            />
          ))}
        </Select>

        {form.data.from_location_id === '' ? (
          <p className="procurement-hint">
            Choose the source location first; the items held there will be listed.
          </p>
        ) : sourceItems.length === 0 ? (
          <InlineNotification
            kind="info"
            title="Nothing to move"
            subtitle="This location has no stock on hand right now."
            hideCloseButton
          />
        ) : (
          <SearchableComboBox
            id="move-stock-item"
            titleText="Item to move"
            items={sourceItems.map((item) => ({
              id: item.itemId,
              label: `${item.itemCode} — ${item.itemName} (${item.available} ${item.uom} available)`,
            }))}
            selectedId={form.data.item_id}
            onChange={chooseItem}
            invalid={Boolean(form.errors.item_id)}
            invalidText={form.errors.item_id}
            required
          />
        )}

        {batchTracked && selectedSourceItem !== null ? (
          <Select
            id="move-stock-batch"
            labelText="Batch"
            helperText="Only batches held at the source are listed. Expired batches cannot be moved."
            value={form.data.batch_number}
            onChange={(event) => form.setData('batch_number', event.currentTarget.value)}
            invalid={Boolean(form.errors.batch_number)}
            invalidText={form.errors.batch_number}
          >
            {batches.filter((batch) => batch.batchId !== null).map((batch) => (
              <SelectItem
                key={batch.batchId ?? ''}
                value={batch.batchNumber ?? ''}
                text={`${batch.batchNumber ?? ''} — ${batch.available} ${batch.uom}${batch.expiryDate ? `, expires ${batch.expiryDate}` : ''}${batch.eligible ? '' : ' (expired)'}`}
              />
            ))}
          </Select>
        ) : null}

        <Select
          id="move-stock-to"
          labelText="Move to"
          value={form.data.to_location_id}
          onChange={(event) => form.setData('to_location_id', event.currentTarget.value)}
          invalid={Boolean(form.errors.to_location_id)}
          invalidText={form.errors.to_location_id}
        >
          <SelectItem value="" text="Choose a destination location" />
          {destinationOptions.map((location) => (
            <SelectItem
              key={location.id}
              value={location.id}
              text={`${location.code} — ${location.name}`}
            />
          ))}
        </Select>

        <TextInput
          id="move-stock-quantity"
          labelText={`Quantity${selectedSourceItem ? ` (${selectedSourceItem.uom})` : ''}`}
          helperText={selectedSourceItem
            ? `${available} ${selectedSourceItem.uom} available${batchTracked && selectedBatch?.batchNumber ? ` in batch ${selectedBatch.batchNumber}` : ''}. ${packHint(catalogueItem, form.data.quantity)}`
            : packHint(catalogueItem, form.data.quantity)}
          type="number"
          min="0.0001"
          step="0.0001"
          value={form.data.quantity}
          onChange={(event) => form.setData('quantity', event.currentTarget.value)}
          invalid={Boolean(form.errors.quantity) || overAvailable}
          invalidText={form.errors.quantity
            ?? (overAvailable ? `Only ${available} ${selectedSourceItem?.uom ?? ''} is available here.` : undefined)}
          required
        />

        {expired ? (
          <InlineNotification
            kind="warning"
            title="Expired batch"
            subtitle="This batch cannot be moved. Remove expired stock through a two-person adjustment in Adjustments."
            hideCloseButton
          />
        ) : null}

        <TextInput
          id="move-stock-date"
          labelText="Transfer date and time (optional)"
          type="datetime-local"
          value={form.data.occurred_at}
          onChange={(event) => form.setData('occurred_at', event.currentTarget.value)}
          invalid={Boolean(form.errors.occurred_at)}
          invalidText={form.errors.occurred_at}
        />

        <TextInput
          id="move-stock-reason"
          labelText="Reason (required if backdated more than 24 hours)"
          placeholder="e.g. weekly refill of the pharmacy counter"
          value={form.data.reason}
          onChange={(event) => form.setData('reason', event.currentTarget.value)}
          invalid={Boolean(form.errors.reason)}
          invalidText={form.errors.reason}
        />

        {destinationOptions.length === 0 ? (
          <InlineNotification
            kind="warning"
            title="No destination"
            subtitle="This facility needs a second active stock location before stock can be moved."
            hideCloseButton
          />
        ) : null}
      </div>
    </DockedPanel>
  );
}
