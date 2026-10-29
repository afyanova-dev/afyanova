import { Modal, Select, SelectItem, TextInput } from '@carbon/react';
import { router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import { DISPENSING_REVERSAL_REASONS } from '../pharmacyCodes';
import type { RxDispensingItem } from '../types/pharmacy';

/**
 * Full dispensing reversal with the 6 fixed backend reason codes.
 * The backend owns reversibility (already reversed, picked up, order
 * status) — failures surface through the `dispense` error bag.
 */
export function ReverseDispensingDialog({
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
  const [reasonCode, setReasonCode] = useState('');
  const [reasonNote, setReasonNote] = useState('');
  const [submitting, setSubmitting] = useState(false);

  function submit(): void {
    if (reasonCode === '' || submitting) return;
    setSubmitting(true);
    router.post(routes.workspaces.pharmacyDispensingReverse(dispensing.dispensingId), {
      pharmacy_order_id: orderId,
      reason_code: reasonCode,
      reason_note: reasonNote.trim() === '' ? null : reasonNote.trim(),
    }, {
      preserveScroll: true,
      preserveState: true,
      onFinish: () => {
        setSubmitting(false);
        onClose();
      },
    });
  }

  return (
    <Modal
      open
      modalHeading="Reverse dispensing"
      primaryButtonText={submitting ? 'Reversing…' : 'Reverse dispensing'}
      secondaryButtonText="Cancel"
      danger
      primaryButtonDisabled={reasonCode === '' || submitting}
      onRequestClose={onClose}
      onRequestSubmit={submit}
    >
      <p>{dispensing.dispensingNumber} for {orderNumber} will be fully reversed. Stock is restored and the charge reversed where posted.</p>
      <Select
        id="pharmacy-reverse-reason"
        labelText="Reason"
        value={reasonCode}
        onChange={(event) => setReasonCode(event.currentTarget.value)}
        required
      >
        <SelectItem value="" text="Select a reason" />
        {DISPENSING_REVERSAL_REASONS.map((option) => (
          <SelectItem key={option.value} value={option.value} text={option.label} />
        ))}
      </Select>
      <TextInput
        id="pharmacy-reverse-note"
        labelText="Note (optional)"
        value={reasonNote}
        maxLength={500}
        onChange={(event) => setReasonNote(event.currentTarget.value)}
      />
    </Modal>
  );
}
