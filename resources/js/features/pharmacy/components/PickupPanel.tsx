import { Select, SelectItem, TextInput } from '@carbon/react';
import { router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import { DockedPanel } from '@/components/panels';
import { PICKUP_RECIPIENT_TYPES } from '../pharmacyCodes';
import type { RxDispensingItem } from '../types/pharmacy';

/**
 * Pickup handover panel. The backend gates handover on billing clearance
 * (`DispensingPaymentNotCleared`) — an unpaid dispensing stays blocked here
 * with the server message. Self-pay needs a paid dedicated invoice for this
 * dispensing; insurance needs confirmed coverage plus the patient share.
 */
export function PickupPanel({
  orderId,
  orderNumber,
  dispensing,
  onClose,
}: {
  orderId: string;
  orderNumber: string;
  dispensing: RxDispensingItem;
  onClose: () => void;
}): ReactNode {
  const [recipientType, setRecipientType] = useState('');
  const [recipientName, setRecipientName] = useState('');
  const [verificationNote, setVerificationNote] = useState('');
  const [submitError, setSubmitError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  function canSubmit(): boolean {
    if (recipientType === '') return false;
    if (recipientName.length > 200 || verificationNote.length > 500) return false;
    return true;
  }

  function submit(event: React.FormEvent): void {
    event.preventDefault();
    if (!canSubmit() || submitting) return;
    setSubmitError(null);
    setSubmitting(true);
    router.post(routes.workspaces.pharmacyPickupsStore, {
      pharmacy_order_id: orderId,
      dispensing_id: dispensing.dispensingId,
      recipient_type: recipientType,
      recipient_name: recipientName.trim() === '' ? null : recipientName.trim(),
      verification_note: verificationNote.trim() === '' ? null : verificationNote.trim(),
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

  return (
    <DockedPanel
      heading={`Record pickup ${dispensing.dispensingNumber}`}
      primaryLabel={submitting ? 'Recording…' : 'Record pickup'}
      primaryDisabled={!canSubmit() || submitting}
      secondaryLabel="Cancel"
      onClose={onClose}
      onSubmit={submit}
    >
      <p>{dispensing.itemName} · {dispensing.quantity} {dispensing.quantityUnit} · {orderNumber}</p>
      <p>Handover is blocked until the billing clearance for this dispensing is settled.</p>
      <Select
        id="pharmacy-pickup-recipient"
        labelText="Recipient"
        value={recipientType}
        onChange={(event) => setRecipientType(event.currentTarget.value)}
        required
      >
        <SelectItem value="" text="Select who collected" />
        {PICKUP_RECIPIENT_TYPES.map((option) => (
          <SelectItem key={option.value} value={option.value} text={option.label} />
        ))}
      </Select>
      <TextInput
        id="pharmacy-pickup-name"
        labelText="Recipient name (optional)"
        value={recipientName}
        maxLength={200}
        onChange={(event) => setRecipientName(event.currentTarget.value)}
      />
      <TextInput
        id="pharmacy-pickup-note"
        labelText="Verification note (optional)"
        value={verificationNote}
        maxLength={500}
        onChange={(event) => setVerificationNote(event.currentTarget.value)}
      />
      {submitError !== null ? <p role="alert">{submitError}</p> : null}
    </DockedPanel>
  );
}
