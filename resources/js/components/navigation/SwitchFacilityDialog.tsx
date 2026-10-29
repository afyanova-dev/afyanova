import { router } from '@inertiajs/react';
import { Modal, RadioButton, RadioButtonGroup } from '@carbon/react';
import { useEffect, useState, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import type { AuthFacility } from '@/types';

/**
 * Dedicated facility-switch surface (OpenMRS "change location" pattern).
 *
 * Lists every assigned facility with the active one preselected. Switching
 * posts the existing facility-context contract; the backend reloads the
 * workspace in the new context. Mounted only when open so a closed dialog
 * never leaks headings into queries or screen-reader navigation.
 */
export function SwitchFacilityDialog({
  open,
  onClose,
  facilities,
  activeFacility,
  contextMode,
  canUseGeneral,
}: {
  open: boolean;
  onClose: () => void;
  facilities: AuthFacility[];
  activeFacility: string | null;
  contextMode: 'general' | 'facility' | 'unresolved';
  canUseGeneral: boolean;
}): ReactNode {
  const currentContext = contextMode === 'general' ? 'general' : activeFacility ?? '';
  const [selected, setSelected] = useState(currentContext);

  useEffect(() => {
    if (open) setSelected(contextMode === 'general' ? 'general' : activeFacility ?? '');
  }, [open, activeFacility, contextMode]);

  if (!open) return null;

  const canSwitch = selected !== '' && selected !== currentContext;

  function submit(): void {
    if (!canSwitch) return;
    if (selected === 'general') {
      router.post(routes.facilityContext, { mode: 'general' });
    } else {
      router.post(routes.facilityContext, { facility_id: selected });
    }
    onClose();
  }

  return (
    <Modal
      open
      modalHeading="Switch facility"
      primaryButtonText="Switch facility"
      secondaryButtonText="Cancel"
      primaryButtonDisabled={!canSwitch}
      onRequestClose={onClose}
      onRequestSubmit={submit}
    >
      <p>Choose General or a facility. Your workspace reloads in the new context.</p>
      <RadioButtonGroup
        legendText="Working context"
        name="switch-facility"
        valueSelected={selected || undefined}
        onChange={(value) => setSelected(String(value))}
      >
        {canUseGeneral ? (
          <RadioButton
            id="switch-facility-general"
            value="general"
            labelText={contextMode === 'general' ? 'General (current)' : 'General'}
          />
        ) : null}
        {facilities.map((facility) => (
          <RadioButton
            key={facility.id}
            id={`switch-facility-${facility.id}`}
            value={facility.id}
            labelText={contextMode === 'facility' && facility.id === activeFacility ? `${facility.name} (current)` : facility.name}
          />
        ))}
      </RadioButtonGroup>
    </Modal>
  );
}
