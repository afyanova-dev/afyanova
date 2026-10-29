import { Select, SelectItem, Tag, TextInput } from '@carbon/react';
import { router, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import { DockedPanel } from '@/components/panels';
import { refreshInPlace } from '../pharmacyNavigation';
import type { PharmacyPatientAllergy, RxItemOption, RxLocationOption, RxOrderItem, RxPrescriptionItem, RxStockEntry } from '../types/pharmacy';

interface DispensePanelProps {
  order: RxOrderItem;
  prescription?: RxPrescriptionItem;
  items: RxItemOption[];
  locations: RxLocationOption[];
  /** NOT_CHECKED | AVAILABLE | FAILED — a caught read error is never shown as zero. */
  availabilityStatus: string;
  /** Per-location, per-batch availability for the selected item (all locations). */
  stockByLocation: RxStockEntry[];
  selectedItemId: string | null;
  selectedItemUom: string | null;
  /** Null = allergy data not loaded for this patient; never invent it. */
  patientAllergies: PharmacyPatientAllergy[] | null;
  onClose: () => void;
}

/**
 * Add server decimal quantity strings without float drift. Quantities carry up
 * to 4 decimal places; scale to 1/10000 and format back with trailing zeros
 * trimmed so the panel never shows `0.30000000000000004`.
 */
function sumQuantities(values: string[]): string {
  let scaled = 0;
  for (const value of values) {
    const parsed = Number(value);
    if (Number.isFinite(parsed)) scaled += Math.round(parsed * 10000);
  }
  const whole = Math.trunc(scaled / 10000);
  const fraction = Math.abs(scaled % 10000);
  if (fraction === 0) return `${whole}`;
  return `${whole}.${String(fraction).padStart(4, '0').replace(/0+$/, '')}`;
}

/**
 * Phase 3 dispense panel, Phase 4 stock-aware. Posts the canonical
 * `pharmacy.dispensings.store` payload (order, item, location, quantity, batch).
 * Item is fixed for EXTERNAL_PAPER (must equal the transcribed item); otherwise
 * chosen from active MEDICATION catalogue entries. Availability is shown per
 * SELECTED location and, for batch-tracked items, per batch with expiry — never
 * inferred from a facility total. It is display-only: the backend revalidates
 * stock, expiry, ceiling, and facility at submit.
 */
export function DispensePanel({
  order,
  prescription,
  items,
  locations,
  availabilityStatus,
  stockByLocation,
  selectedItemId,
  selectedItemUom,
  patientAllergies,
  onClose,
}: DispensePanelProps): ReactNode {
  const { url } = usePage();
  const isPaper = prescription?.source === 'EXTERNAL_PAPER';
  const fixedItemId = isPaper ? (prescription?.inventoryItemId ?? null) : null;
  const medicationItems = items.filter((item) => item.active && item.itemType === 'MEDICATION');
  const [itemId, setItemId] = useState(fixedItemId ?? selectedItemId ?? '');
  const [locationId, setLocationId] = useState('');
  const [quantity, setQuantity] = useState('');
  const [batchNumber, setBatchNumber] = useState('');
  const [submitError, setSubmitError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const chosenItem = medicationItems.find((item) => item.itemId === itemId) ?? null;
  const stockLoaded = chosenItem !== null && selectedItemId === chosenItem.itemId;
  const batchTracked = chosenItem?.trackBatch ?? false;
  const uom = chosenItem?.uom ?? selectedItemUom ?? '';

  // Availability is scoped to the SELECTED location — never a facility sum.
  const rowsAtLocation = locationId === '' ? [] : stockByLocation.filter((entry) => entry.locationId === locationId);
  const eligibleRows = rowsAtLocation.filter((entry) => entry.eligible);
  const expiredCount = rowsAtLocation.length - eligibleRows.length;
  const locationAvailable = sumQuantities(eligibleRows.map((entry) => entry.available));
  const locationReserved = sumQuantities(eligibleRows.map((entry) => entry.reserved));
  const locationOnHand = sumQuantities(eligibleRows.map((entry) => entry.onHand));

  const eligibleBatchRows = eligibleRows.filter((entry) => entry.batchNumber !== null);
  const eligibleBatchNumbers = new Set(eligibleBatchRows.map((entry) => entry.batchNumber as string));
  const expiredBatchRows = rowsAtLocation.filter((entry) => !entry.eligible && entry.batchNumber !== null);

  function handleItemChange(value: string): void {
    setItemId(value);
    setBatchNumber('');
    setSubmitError(null);
    refreshInPlace(url, { item_id: value === '' ? null : value });
  }

  function handleLocationChange(value: string): void {
    setLocationId(value);
    const eligible = stockByLocation
      .filter((entry) => entry.locationId === value && entry.eligible && entry.batchNumber !== null)
      .map((entry) => entry.batchNumber as string);
    if (!eligible.includes(batchNumber)) setBatchNumber('');
  }

  function canSubmit(): boolean {
    if (itemId === '' || locationId === '') return false;
    const parsed = Number(quantity);
    if (!Number.isFinite(parsed) || parsed < 0.01 || parsed > 999999.99) return false;
    if (batchTracked) {
      // Batch-tracked items require an explicit, expiry-eligible batch at this location.
      if (!eligibleBatchNumbers.has(batchNumber)) return false;
    } else if (batchNumber !== '') {
      return false;
    }
    return true;
  }

  function submit(event: React.FormEvent): void {
    event.preventDefault();
    if (!canSubmit() || submitting) return;
    setSubmitError(null);
    setSubmitting(true);
    router.post(routes.workspaces.pharmacyDispensingsStore, {
      pharmacy_order_id: order.pharmacyOrderId,
      item_id: itemId,
      location_id: locationId,
      quantity: quantity.trim(),
      batch_number: batchTracked && batchNumber !== '' ? batchNumber : null,
    }, {
      preserveScroll: true,
      preserveState: true,
      onSuccess: () => {
        setSubmitting(false);
        onClose();
      },
      onError: (errors) => {
        setSubmitError(Object.values(errors).join(' '));
        setSubmitting(false);
      },
    });
  }

  const patientDemographics = [
    order.patientAge === null || order.patientAge === undefined ? null : `${order.patientAge} ${order.patientAge === 1 ? 'year' : 'years'}`,
    !order.patientSex || order.patientSex === 'UNKNOWN' ? null : order.patientSex.toLocaleLowerCase().replace(/\b\w/g, (character) => character.toUpperCase()),
  ].filter((value): value is string => value !== null);

function orderStatusTag(status: string): 'green' | 'blue' | 'teal' | 'cyan' | 'red' | 'gray' {
  switch (status) {
    case 'VALIDATED':
      return 'green';
    case 'DISPENSED':
      return 'teal';
    case 'PARTIALLY_DISPENSED':
      return 'cyan';
    case 'RECEIVED':
      return 'blue';
    case 'CANCELLED':
    case 'REJECTED':
      return 'red';
    default:
      return 'gray';
  }
}

  function renderStockStatus(): ReactNode {
    if (chosenItem === null) return null;

    let tone: 'available' | 'checking' | 'empty' | 'error' = 'checking';
    let message = 'Checking stock…';

    if (!stockLoaded) {
      tone = 'checking';
      message = 'Checking stock…';
    } else if (availabilityStatus === 'FAILED') {
      tone = 'error';
      message = 'Stock could not be checked. Try again before dispensing.';
    } else if (availabilityStatus === 'AVAILABLE') {
      if (locationId === '') {
        tone = 'checking';
        message = 'Select a dispensing location to see availability.';
      } else if (Number(locationAvailable) > 0) {
        tone = 'available';
        message = `Available here: ${locationAvailable} ${uom} (${locationOnHand} on hand, ${locationReserved} reserved).`;
      } else {
        tone = 'empty';
        message = expiredCount > 0
          ? 'No eligible stock at this location — expired batches are excluded.'
          : 'No eligible stock at this location.';
      }
    } else {
      tone = 'checking';
      message = 'Availability has not been checked.';
    }

    return (
      <p
        role={tone === 'error' ? 'alert' : 'status'}
        className={`dispense-panel__stock-status dispense-panel__stock-status--${tone}`}
      >
        {message}
      </p>
    );
  }

  function renderBatchField(): ReactNode {
    if (!batchTracked) return null;
    if (!stockLoaded) {
      return <p className="pharmacy-detail__muted">Batch options load once stock is checked.</p>;
    }
    if (availabilityStatus === 'FAILED') {
      return <p className="pharmacy-detail__muted">Batch options unavailable — stock could not be checked.</p>;
    }
    if (locationId === '') {
      return <p className="pharmacy-detail__muted">Select a location to choose a batch.</p>;
    }
    if (eligibleBatchRows.length === 0 && expiredBatchRows.length === 0) {
      return <p className="pharmacy-detail__muted">No batch recorded at this location.</p>;
    }

    return (
      <Select
        id="pharmacy-dispense-batch"
        labelText="Batch"
        helperText="Batch-tracked item — an expiry-eligible batch is required."
        value={batchNumber}
        onChange={(event) => setBatchNumber(event.currentTarget.value)}
        required
      >
        <SelectItem value="" text={eligibleBatchRows.length === 0 ? 'No eligible batch' : 'Select a batch'} />
        {eligibleBatchRows.map((entry) => (
          <SelectItem
            key={entry.batchId ?? (entry.batchNumber as string)}
            value={entry.batchNumber as string}
            text={`${entry.batchNumber}${entry.expiryDate ? ` · exp ${entry.expiryDate}` : ''} · ${entry.available} available`}
          />
        ))}
        {expiredBatchRows.map((entry) => (
          <SelectItem
            key={entry.batchId ?? (entry.batchNumber as string)}
            value={entry.batchNumber as string}
            disabled
            text={`${entry.batchNumber}${entry.expiryDate ? ` · exp ${entry.expiryDate}` : ''} · expired`}
          />
        ))}
      </Select>
    );
  }

  return (
    <DockedPanel
      heading={`Dispense ${order.orderNumber}`}
      description={[order.patientDisplayName, order.patientNumber, ...patientDemographics].join(' · ')}
      primaryLabel={submitting ? 'Dispensing…' : 'Dispense'}
      primaryDisabled={!canSubmit() || submitting}
      secondaryLabel="Cancel"
      onClose={onClose}
      onSubmit={submit}
      contentClassName="dispense-panel"
    >
      <div className="dispense-panel__summary">
        <div className="dispense-panel__summary-header">
          <span className="dispense-panel__medication-name">
            {order.medicationName ?? 'Medication not recorded'}
          </span>
          <Tag size="sm" type={orderStatusTag(order.status)}>
            {order.status.replaceAll('_', ' ')}
          </Tag>
        </div>
        {prescription && (prescription.dose || prescription.frequency || prescription.duration || prescription.route) ? (
          <div className="dispense-panel__regimen-meta">
            {[
              prescription.dose ? `Dose: ${prescription.dose}` : null,
              prescription.frequency ? `Freq: ${prescription.frequency}` : null,
              prescription.duration ? `Duration: ${prescription.duration}` : null,
              prescription.route ? `Route: ${prescription.route}` : null,
            ].filter(Boolean).join(' · ')}
          </div>
        ) : null}
        {prescription?.dosingInstruction ? (
          <div className="dispense-panel__instruction">
            {prescription.dosingInstruction}
          </div>
        ) : null}
      </div>

      {patientAllergies !== null && patientAllergies.length > 0 ? (
        <p role="alert" className="dispense-panel__allergy-alert">
          Allergies: {patientAllergies.map((allergy) => `${allergy.substanceName} (${allergy.severity}${allergy.reaction ? `, ${allergy.reaction}` : ''})`).join('; ')}
        </p>
      ) : null}
      {patientAllergies !== null && patientAllergies.length === 0 ? (
        <p className="pharmacy-detail__muted">No recorded allergies.</p>
      ) : null}

      {isPaper ? (
        <p className="dispense-panel__source-note">
          Paper prescription — item is fixed to the transcribed medicine.
        </p>
      ) : null}

      <div className="dispense-panel__field">
        {fixedItemId !== null ? (
          <TextInput
            id="pharmacy-dispense-item"
            labelText="Item"
            value={chosenItem?.name ?? prescription?.medicationName ?? fixedItemId}
            readOnly
          />
        ) : (
          <Select
            id="pharmacy-dispense-item"
            labelText="Item"
            value={itemId}
            onChange={(event) => handleItemChange(event.currentTarget.value)}
            required
          >
            <SelectItem value="" text="Select a medicine" />
            {medicationItems.map((item) => (
              <SelectItem key={item.itemId} value={item.itemId} text={`${item.name} · ${item.code}`} />
            ))}
          </Select>
        )}
        {renderStockStatus()}
      </div>

      <Select
        id="pharmacy-dispense-location"
        labelText="Location"
        value={locationId}
        onChange={(event) => handleLocationChange(event.currentTarget.value)}
        required
      >
        <SelectItem value="" text="Select a dispensing location" />
        {locations.map((location) => (
          <SelectItem key={location.locationId} value={location.locationId} text={`${location.name} · ${location.code}`} />
        ))}
      </Select>
      {renderBatchField()}
      <TextInput
        id="pharmacy-dispense-quantity"
        labelText="Quantity"
        helperText="0.01 – 999999.99. Stock, expiry and the authorised ceiling are rechecked server-side."
        value={quantity}
        onChange={(event) => setQuantity(event.currentTarget.value)}
        required
      />
      {submitError !== null ? <p role="alert" className="dispense-panel__submit-error">{submitError}</p> : null}
    </DockedPanel>
  );
}
