import {
  Checkbox,
  CheckboxGroup,
  InlineNotification,
  Select,
  SelectItem,
  Stack,
  TextInput,
} from '@carbon/react';
import { useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { routes } from '@/app/routes';
import { DockedPanel } from '@/components/panels';
import { FormErrors } from '@/components/ui';
import {
  EMPTY_INSTRUMENT,
  formForInstrument,
  omitKey,
} from '../types';
import type {
  EquipmentItem,
  FacilityInstrument,
  InstrumentForm,
  MethodOption,
} from '../types';

const INSTRUMENT_ERROR_FIELDS = [
  'identifier',
  'name',
  'manufacturer',
  'model',
  'serial_number',
  'inventory_item_id',
  'status',
] as const;

const INSTRUMENT_STATUSES = [
  { value: 'ACTIVE', text: 'Active' },
  { value: 'OUT_OF_SERVICE', text: 'Out of service' },
  { value: 'DECOMMISSIONED', text: 'Decommissioned' },
] as const;

export interface InstrumentEditorPanelProps {
  instrument: FacilityInstrument | null;
  methods: MethodOption[];
  equipmentItems: EquipmentItem[];
  onClose: () => void;
}

export function InstrumentEditorPanel({
  instrument,
  methods,
  equipmentItems,
  onClose,
}: InstrumentEditorPanelProps): ReactNode {
  const isEditing = instrument !== null;
  const form = useForm<InstrumentForm>(
    instrument !== null ? formForInstrument(instrument, methods) : EMPTY_INSTRUMENT,
  );
  const unavailableMethods = instrument?.supportedMethods.filter(
    (supported) => !methods.some((option) => option.methodId === supported.methodId),
  ) ?? [];

  function submit(event: FormEvent): void {
    event.preventDefault();

    if (instrument !== null) {
      form.put(routes.workspaces.laboratoryInstrument(instrument.instrumentId), {
        preserveScroll: true,
        onSuccess: onClose,
      });

      return;
    }

    form.transform((data) => omitKey(data, 'status'));
    form.post(routes.workspaces.laboratoryInstruments, {
      preserveScroll: true,
      onSuccess: onClose,
    });
  }

  function toggleMethod(methodId: string, checked: boolean): void {
    const methodIds = new Set(form.data.supported_method_ids);

    if (checked) {
      methodIds.add(methodId);
    } else {
      methodIds.delete(methodId);
    }

    form.setData('supported_method_ids', [...methodIds]);
  }

  return (
    <DockedPanel
      heading={isEditing ? 'Edit laboratory instrument' : 'Register laboratory instrument'}
      description="Register the operational instrument and the active test methods it supports."
      onClose={onClose}
      onSubmit={(event) => submit(event)}
      noValidate
      primaryLabel={form.processing ? 'Saving…' : isEditing ? 'Save instrument changes' : 'Register instrument'}
      primaryDisabled={form.processing}
      width="min(42rem, 100%)"
      expandedWidth="min(56rem, 100%)"
      expandable
    >
      <Stack gap={5}>
        <FormErrors errors={form.errors} fields={INSTRUMENT_ERROR_FIELDS} />

        {unavailableMethods.length > 0 ? (
          <InlineNotification
            kind="warning"
            lowContrast
            title="Some previously assigned methods are no longer selectable"
            subtitle={`${unavailableMethods.map((method) => method.methodCode).join(', ')} will be removed when you save this instrument.`}
          />
        ) : null}

        <div className="laboratory-editor__row">
          <TextInput
            id="lab-instrument-identifier"
            labelText="Instrument identifier or asset tag"
            value={form.data.identifier}
            maxLength={100}
            onChange={(event) => form.setData('identifier', event.target.value)}
            invalid={Boolean(form.errors.identifier)}
            invalidText={form.errors.identifier}
            required
          />
          <TextInput
            id="lab-instrument-name"
            labelText="Instrument name"
            value={form.data.name}
            maxLength={200}
            onChange={(event) => form.setData('name', event.target.value)}
            invalid={Boolean(form.errors.name)}
            invalidText={form.errors.name}
            required
          />
        </div>
        <div className="laboratory-editor__row">
          <TextInput
            id="lab-instrument-manufacturer"
            labelText="Manufacturer (optional)"
            value={form.data.manufacturer}
            maxLength={200}
            onChange={(event) => form.setData('manufacturer', event.target.value)}
            invalid={Boolean(form.errors.manufacturer)}
            invalidText={form.errors.manufacturer}
          />
          <TextInput
            id="lab-instrument-model"
            labelText="Model (optional)"
            value={form.data.model}
            maxLength={200}
            onChange={(event) => form.setData('model', event.target.value)}
            invalid={Boolean(form.errors.model)}
            invalidText={form.errors.model}
          />
        </div>
        <div className="laboratory-editor__row">
          <TextInput
            id="lab-instrument-serial"
            labelText="Serial number (optional)"
            value={form.data.serial_number}
            maxLength={200}
            onChange={(event) => form.setData('serial_number', event.target.value)}
            invalid={Boolean(form.errors.serial_number)}
            invalidText={form.errors.serial_number}
          />
          <Select
            id="lab-instrument-inventory"
            labelText="Inventory equipment item (optional)"
            value={form.data.inventory_item_id}
            onChange={(event) => form.setData('inventory_item_id', event.target.value)}
            invalid={Boolean(form.errors.inventory_item_id)}
            invalidText={form.errors.inventory_item_id}
          >
            <SelectItem value="" text="No inventory item linked" />
            {equipmentItems.map((item) => (
              <SelectItem key={item.itemId} value={item.itemId} text={`${item.code} — ${item.name}`} />
            ))}
          </Select>
        </div>
        {isEditing ? (
          <Select
            id="lab-instrument-status"
            labelText="Operational status"
            value={form.data.status}
            onChange={(event) => form.setData('status', event.target.value)}
            invalid={Boolean(form.errors.status)}
            invalidText={form.errors.status}
          >
            {INSTRUMENT_STATUSES.map((status) => (
              <SelectItem key={status.value} value={status.value} text={status.text} />
            ))}
          </Select>
        ) : null}

        <CheckboxGroup
          legendText="Supported active test methods"
          helperText="Only active methods for available tests can be assigned."
          invalid={Boolean(form.errors.supported_method_ids)}
          invalidText={form.errors.supported_method_ids}
        >
          {methods.length === 0 ? (
            <InlineNotification
              kind="info"
              lowContrast
              hideCloseButton
              title="No active test methods available"
              subtitle="Create an active method for an available test before assigning method compatibility."
            />
          ) : (
            <div className="laboratory-editor__checkbox-grid">
              {methods.map((method) => (
                <Checkbox
                  key={method.methodId}
                  id={`lab-instrument-method-${method.methodId}`}
                  labelText={`${method.code} — ${method.name}`}
                  checked={form.data.supported_method_ids.includes(method.methodId)}
                  onChange={(_, { checked }) => toggleMethod(method.methodId, checked)}
                />
              ))}
            </div>
          )}
        </CheckboxGroup>

        <p className="laboratory-editor__hint">
          Inventory equipment remains the product record; this instrument is the operational
          instance installed at the facility.
        </p>
      </Stack>
    </DockedPanel>
  );
}
