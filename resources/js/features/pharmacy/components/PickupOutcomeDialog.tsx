import { Modal, Select, SelectItem, TextInput } from '@carbon/react';
import { router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import { PICKUP_OUTCOME_ACTION_LABEL, PICKUP_OUTCOME_REASONS, type PickupOutcomeAction } from '../pharmacyCodes';
import type { RxDispensingItem } from '../types/pharmacy';

const ACTION_ENDPOINT: Record<PickupOutcomeAction, string> = {
  'not-collected': routes.workspaces.pharmacyPickupNotCollected,
  'cancel-pickup': routes.workspaces.pharmacyPickupCancel,
};

/**
 * Coded pickup outcome (not-collected / cancel) with the 6 fixed backend
 * outcome codes. Does not return stock — a follow-up reversal does.
 */
export function PickupOutcomeDialog({
  orderId,
  dispensing,
  action,
  onClose,
}: {
  orderId: string;
  dispensing: RxDispensingItem;
  action: PickupOutcomeAction;
  onClose: () => void;
}): ReactNode {
  const [reasonCode, setReasonCode] = useState('');
  const [note, setNote] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const heading = PICKUP_OUTCOME_ACTION_LABEL[action];

  function submit(): void {
    if (reasonCode === '' || submitting) return;
    setSubmitting(true);
    router.post(ACTION_ENDPOINT[action], {
      pharmacy_order_id: orderId,
      dispensing_id: dispensing.dispensingId,
      reason_code: reasonCode,
      note: note.trim() === '' ? null : note.trim(),
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
      modalHeading={heading}
      primaryButtonText={submitting ? 'Submitting…' : heading}
      secondaryButtonText="Cancel"
      danger={action === 'cancel-pickup'}
      primaryButtonDisabled={reasonCode === '' || submitting}
      onRequestClose={onClose}
      onRequestSubmit={submit}
    >
      <p>{dispensing.dispensingNumber} outcome is recorded with your reason. Stock is unchanged.</p>
      <Select
        id={`pharmacy-pickup-${action}-reason`}
        labelText="Reason"
        value={reasonCode}
        onChange={(event) => setReasonCode(event.currentTarget.value)}
        required
      >
        <SelectItem value="" text="Select a reason" />
        {PICKUP_OUTCOME_REASONS.map((option) => (
          <SelectItem key={option.value} value={option.value} text={option.label} />
        ))}
      </Select>
      <TextInput
        id={`pharmacy-pickup-${action}-note`}
        labelText="Note (optional)"
        value={note}
        maxLength={500}
        onChange={(event) => setNote(event.currentTarget.value)}
      />
    </Modal>
  );
}
