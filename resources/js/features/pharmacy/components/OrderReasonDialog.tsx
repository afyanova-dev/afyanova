import { Modal, Select, SelectItem, TextInput } from '@carbon/react';
import { router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import {
  ORDER_REASON_ACTION_LABEL,
  ORDER_REASON_OPTIONS,
  type OrderReasonAction,
} from '../pharmacyCodes';

const ACTION_ENDPOINT: Record<OrderReasonAction, (orderId: string) => string> = {
  reject: (orderId) => routes.workspaces.pharmacyOrderReject(orderId),
  hold: (orderId) => routes.workspaces.pharmacyOrderHold(orderId),
  cancel: (orderId) => routes.workspaces.pharmacyOrderCancel(orderId),
};

/**
 * Shared reason-code confirm for order lifecycle actions (reject / hold /
 * cancel). Mounted only when open: Carbon Modal stays in the DOM hidden
 * when closed, which would leak the heading into queries. The backend
 * re-validates the code and the transition — failures surface through the
 * `order` error bag on the page.
 */
export function OrderReasonDialog({
  orderId,
  orderNumber,
  action,
  onClose,
}: {
  orderId: string;
  orderNumber: string;
  action: OrderReasonAction;
  onClose: () => void;
}): ReactNode {
  const [reasonCode, setReasonCode] = useState('');
  const [note, setNote] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const heading = ORDER_REASON_ACTION_LABEL[action];

  function submit(): void {
    if (reasonCode === '' || submitting) return;
    setSubmitting(true);
    router.post(ACTION_ENDPOINT[action](orderId), {
      reason_code: reasonCode,
      note: note.trim(),
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
      danger={action !== 'hold'}
      primaryButtonDisabled={reasonCode === '' || submitting}
      onRequestClose={onClose}
      onRequestSubmit={submit}
    >
      <p>{orderNumber} will move out of dispensing circulation. This is recorded with your reason.</p>
      <Select
        id={`pharmacy-order-${action}-reason`}
        labelText="Reason"
        value={reasonCode}
        onChange={(event) => setReasonCode(event.currentTarget.value)}
        required
      >
        <SelectItem value="" text="Select a reason" />
        {ORDER_REASON_OPTIONS[action].map((option) => (
          <SelectItem key={option.value} value={option.value} text={option.label} />
        ))}
      </Select>
      <TextInput
        id={`pharmacy-order-${action}-note`}
        labelText="Note (optional)"
        value={note}
        maxLength={500}
        onChange={(event) => setNote(event.currentTarget.value)}
      />
    </Modal>
  );
}
