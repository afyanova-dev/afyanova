import { Checkbox, InlineNotification, Select, SelectItem, TextInput } from '@carbon/react';
import { useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { routes } from '@/app/routes';
import { DockedPanel } from '@/components/panels';

export interface InventoryItemFormData {
  code: string;
  name: string;
  item_type: string;
  uom: string;
  category: string;
  description: string;
  track_batch: boolean;
  track_serial: boolean;
  track_expiry: boolean;
  is_controlled: boolean;
  pack_unit: string;
  pack_size: string;
}

export interface AddItemPanelProps {
  open: boolean;
  onClose: () => void;
  onSuccess?: () => void;
  /** Server-authoritative controlled vocabularies (inventory-item-master-spec.md §4). */
  itemTypes: string[];
  itemCategories: string[];
  unitsOfMeasure: string[];
  /** Active catalogue item names, for the advisory duplicate warning. */
  existingItemNames?: string[];
}

function preferred(list: string[], preferredValue: string): string {
  return list.includes(preferredValue) ? preferredValue : (list[0] ?? '');
}

export function AddItemPanel({
  open,
  onClose,
  onSuccess,
  itemTypes,
  itemCategories,
  unitsOfMeasure,
  existingItemNames,
}: AddItemPanelProps): ReactNode {
  const itemForm = useForm<InventoryItemFormData>({
    code: '',
    name: '',
    item_type: preferred(itemTypes, 'MEDICATION'),
    uom: preferred(unitsOfMeasure, 'TABLET'),
    category: '',
    description: '',
    track_batch: true,
    track_serial: false,
    track_expiry: true,
    is_controlled: false,
    pack_unit: '',
    pack_size: '',
  });

  const trimmedName = itemForm.data.name.trim().toLowerCase();
  const duplicateName = trimmedName.length > 0
    ? (existingItemNames ?? []).find((name) => name.trim().toLowerCase() === trimmedName)
    : undefined;

  function handleSubmit(event: FormEvent): void {
    event.preventDefault();
    itemForm.post(routes.workspaces.inventoryItemsStore, {
      preserveScroll: true,
      onSuccess: () => {
        itemForm.reset();
        onSuccess?.();
        onClose();
      },
    });
  }

  return (
    <DockedPanel
      heading="Add catalogue item"
      description="Create a tenant-wide item definition for medical supplies, medications, or equipment."
      hidden={!open}
      onClose={onClose}
      onSubmit={handleSubmit}
      primaryLabel={itemForm.processing ? 'Adding…' : 'Add item'}
      primaryDisabled={itemForm.processing || !itemForm.data.code.trim() || !itemForm.data.name.trim()}
      secondaryLabel="Cancel"
      width="min(34rem, 100%)"
      expandable
    >
      <div style={{ display: 'flex', flexDirection: 'column', gap: '1.25rem' }}>
        <TextInput
          id="inventory-item-code"
          labelText="Item code"
          placeholder="e.g. PARA-500, SYR-10ML, PPE-GLOVE-M"
          value={itemForm.data.code}
          onChange={(event) => itemForm.setData('code', event.currentTarget.value)}
          invalid={Boolean(itemForm.errors.code)}
          invalidText={itemForm.errors.code}
          required
        />
        <TextInput
          id="inventory-item-name"
          labelText="Generic name (INN)"
          helperText="Use the generic (INN) name — NHIF reimburses medicines by generic name."
          placeholder="e.g. Paracetamol 500mg, Examination gloves M"
          value={itemForm.data.name}
          onChange={(event) => itemForm.setData('name', event.currentTarget.value)}
          invalid={Boolean(itemForm.errors.name)}
          invalidText={itemForm.errors.name}
          required
        />
        {duplicateName ? (
          <InlineNotification
            kind="warning"
            lowContrast
            title="Possible duplicate"
            subtitle={`An active item named "${duplicateName}" already exists. Continue only if this is a different product (its code must differ).`}
            hideCloseButton
          />
        ) : null}
        <Select
          id="inventory-item-type"
          labelText="Item type"
          helperText="MEDICATION is dispensable by Pharmacy; other types are stock-only."
          value={itemForm.data.item_type}
          onChange={(event) => itemForm.setData('item_type', event.currentTarget.value)}
          invalid={Boolean(itemForm.errors.item_type)}
          invalidText={itemForm.errors.item_type}
        >
          {itemTypes.map((type) => (
            <SelectItem key={type} value={type} text={type} />
          ))}
        </Select>
        <Select
          id="inventory-item-uom"
          labelText="Base unit of measure"
          helperText="The unit stock balances are kept in."
          value={itemForm.data.uom}
          onChange={(event) => itemForm.setData('uom', event.currentTarget.value)}
          invalid={Boolean(itemForm.errors.uom)}
          invalidText={itemForm.errors.uom}
        >
          {unitsOfMeasure.map((unit) => (
            <SelectItem key={unit} value={unit} text={unit} />
          ))}
        </Select>
        <Select
          id="inventory-item-category"
          labelText="Category (optional)"
          value={itemForm.data.category}
          onChange={(event) => itemForm.setData('category', event.currentTarget.value)}
          invalid={Boolean(itemForm.errors.category)}
          invalidText={itemForm.errors.category}
        >
          <SelectItem value="" text="No category" />
          {itemCategories.map((category) => (
            <SelectItem key={category} value={category} text={category} />
          ))}
        </Select>
        <TextInput
          id="inventory-item-description"
          labelText="Description (optional)"
          placeholder="Additional clinical or packaging details"
          value={itemForm.data.description}
          onChange={(event) => itemForm.setData('description', event.currentTarget.value)}
          invalid={Boolean(itemForm.errors.description)}
          invalidText={itemForm.errors.description}
        />

        <fieldset style={{ border: '1px solid var(--cds-border-subtle, #e0e0e0)', borderRadius: '4px', padding: '1rem' }}>
          <legend style={{ fontWeight: 600, paddingInline: '0.25rem' }}>Tracking requirements</legend>
          <div style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem', marginBlockStart: '0.5rem' }}>
            <Checkbox
              id="inventory-track-batch"
              labelText="Track batches/lots"
              checked={itemForm.data.track_batch}
              onChange={(_, { checked }) => {
                itemForm.setData('track_batch', checked);
                if (!checked) itemForm.setData('track_expiry', false);
              }}
            />
            <Checkbox
              id="inventory-track-expiry"
              labelText="Track expiry date (requires batches)"
              checked={itemForm.data.track_expiry}
              onChange={(_, { checked }) => {
                itemForm.setData('track_expiry', checked);
                if (checked) itemForm.setData('track_batch', true);
              }}
            />
            <Checkbox
              id="inventory-track-serial"
              labelText="Track serial numbers (receipt workflow not yet supported)"
              checked={itemForm.data.track_serial}
              onChange={(_, { checked }) => itemForm.setData('track_serial', checked)}
            />
          </div>
          {itemForm.errors.track_batch ? (
            <InlineNotification kind="error" title="Validation" subtitle={itemForm.errors.track_batch} hideCloseButton />
          ) : null}
        </fieldset>

        <fieldset style={{ border: '1px solid var(--cds-border-subtle, #e0e0e0)', borderRadius: '4px', padding: '1rem' }}>
          <legend style={{ fontWeight: 600, paddingInline: '0.25rem' }}>Controlled medicine</legend>
          <div style={{ display: 'flex', flexDirection: 'column', gap: '0.5rem', marginBlockStart: '0.5rem' }}>
            <Checkbox
              id="inventory-controlled"
              labelText="This is a controlled medicine (narcotic or psychotropic)"
              checked={itemForm.data.is_controlled}
              onChange={(_, { checked }) => itemForm.setData('is_controlled', checked)}
            />
            <p style={{ fontSize: '0.75rem', color: 'var(--cds-text-secondary, #525252)', margin: 0 }}>
              Removing stock for a controlled item then requires the name of a witness, recorded on the removal request.
            </p>
          </div>
        </fieldset>

        <fieldset style={{ border: '1px solid var(--cds-border-subtle, #e0e0e0)', borderRadius: '4px', padding: '1rem' }}>
          <legend style={{ fontWeight: 600, paddingInline: '0.25rem' }}>Buying unit (optional)</legend>
          <div style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem', marginBlockStart: '0.5rem' }}>
            <p style={{ fontSize: '0.75rem', color: 'var(--cds-text-secondary, #525252)', margin: 0 }}>
              How this item is bought, if that differs from the base unit it is counted in. A dispenser may still
              receive part of a pack — for example 8 bottles out of a pack of 20.
            </p>
            <Select
              id="inventory-item-pack-unit"
              labelText="Buying unit"
              value={itemForm.data.pack_unit}
              onChange={(event) => itemForm.setData('pack_unit', event.currentTarget.value)}
              invalid={Boolean(itemForm.errors.pack_unit)}
              invalidText={itemForm.errors.pack_unit}
            >
              <SelectItem value="" text="Not bought in a pack — counted in the base unit" />
              {unitsOfMeasure.map((unit) => (
                <SelectItem key={`pack-${unit}`} value={unit} text={unit} />
              ))}
            </Select>
            <TextInput
              id="inventory-item-pack-size"
              labelText={`Base units in one ${itemForm.data.pack_unit || 'pack'}`}
              type="number"
              min="2"
              step="1"
              placeholder="e.g. 100"
              value={itemForm.data.pack_size}
              onChange={(event) => itemForm.setData('pack_size', event.currentTarget.value)}
              invalid={Boolean(itemForm.errors.pack_size)}
              invalidText={itemForm.errors.pack_size}
            />
            {itemForm.data.pack_unit && itemForm.data.pack_size ? (
              <p style={{ fontSize: '0.8125rem', fontWeight: 600, margin: 0 }}>
                1 {itemForm.data.pack_unit} = {itemForm.data.pack_size} {itemForm.data.uom || 'base unit'}
              </p>
            ) : null}
          </div>
        </fieldset>
      </div>
    </DockedPanel>
  );
}
