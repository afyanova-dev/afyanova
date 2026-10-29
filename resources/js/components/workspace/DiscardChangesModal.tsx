import { Modal } from '@carbon/react';
import type { ReactNode } from 'react';

/**
 * Shared discard confirm for guarded workspaces. Mounted only when open:
 * Carbon Modal stays in the DOM hidden when closed, which would leak the
 * heading into queries and screen-reader navigation.
 */
export function DiscardChangesModal({
  pending,
  onKeep,
  onDiscard,
}: {
  pending: boolean;
  onKeep: () => void;
  onDiscard: () => void;
}): ReactNode {
  if (!pending) return null;
  return (
    <Modal
      open
      onRequestClose={onKeep}
      danger
      modalHeading="Discard unsaved changes?"
      primaryButtonText="Discard changes"
      secondaryButtonText="Keep editing"
      onRequestSubmit={onDiscard}
    >
      <p>Switching workspaces will discard what you typed in this panel.</p>
    </Modal>
  );
}
