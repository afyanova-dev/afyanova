import { useCallback, useState } from 'react';

/**
 * Dirty-discard guard for exclusive right-rail workspaces (OpenMRS
 * `hasUnsavedChanges` pattern). The open panel reports dirty via `setDirty`
 * (reset it when opening a workspace); any switch away from a dirty
 * workspace pauses in `pendingSwitch` until the confirm modal resolves.
 */
export function useWorkspaceGuard(): {
  setDirty: (dirty: boolean) => void;
  pendingSwitch: (() => void) | null;
  requestSwitch: (action: () => void) => void;
  confirmPendingSwitch: () => void;
  cancelPendingSwitch: () => void;
} {
  const [dirty, setDirty] = useState(false);
  const [pendingSwitch, setPendingSwitch] = useState<(() => void) | null>(null);

  const requestSwitch = useCallback((action: () => void) => {
    if (dirty) {
      setPendingSwitch(() => action);
    } else {
      action();
    }
  }, [dirty]);

  const confirmPendingSwitch = useCallback(() => {
    const action = pendingSwitch;
    setPendingSwitch(null);
    setDirty(false);
    action?.();
  }, [pendingSwitch]);

  const cancelPendingSwitch = useCallback(() => {
    setPendingSwitch(null);
  }, []);

  return { setDirty, pendingSwitch, requestSwitch, confirmPendingSwitch, cancelPendingSwitch };
}
