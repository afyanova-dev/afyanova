import { Modal } from '@carbon/react';
import type { ReactNode } from 'react';

interface ConfirmDialogProps {
  open: boolean;
  heading: string;
  body: string;
  confirmLabel?: string;
  primaryButtonDisabled?: boolean;
  danger?: boolean;
  onClose: () => void;
  onConfirm: () => void;
}

/** Destructive-action confirmation built on Carbon Modal. */
export function ConfirmDialog({
  open,
  heading,
  body,
  confirmLabel = 'Confirm',
  primaryButtonDisabled = false,
  danger = false,
  onClose,
  onConfirm,
}: ConfirmDialogProps): ReactNode {
  return (
    <Modal
      open={open}
      modalHeading={heading}
      primaryButtonText={confirmLabel}
      primaryButtonDisabled={primaryButtonDisabled}
      secondaryButtonText="Cancel"
      danger={danger}
      onRequestClose={onClose}
      onRequestSubmit={onConfirm}
      onSecondarySubmit={onClose}
    >
      <p>{body}</p>
    </Modal>
  );
}
