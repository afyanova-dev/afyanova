import { MenuButton, MenuItem } from '@carbon/react';
import { useState, type ReactNode } from 'react';
import { Edit, List, UserMinus } from '@/icons';
import { EditDemographicsDialog } from '@/features/patients/forms/EditDemographicsDialog';
import { MarkDeceasedDialog } from '@/features/patients/forms/MarkDeceasedDialog';
import type { PatientChartPatient } from '@/features/patients/types/chart';
import { permissions, useCan } from '@/lib/authorization';

/**
 * OpenMRS-style Actions menu for the patient header.
 *
 * Text-labelled menu button with a chevron trigger (`Actions`) exposing the
 * patient-level actions: Edit patient details, Mark patient deceased.
 * "Add to list" is listed disabled — there is no patient-list backend yet,
 * so it must not look actionable.
 *
 * Each item opens the chart's existing dialog in place; dialogs stay
 * authoritative for permission, validation, and errors. Menu items mirror
 * those gates with `disabled` so unavailable actions are visible but not
 * clickable (OpenMRS pattern).
 */
export function PatientActionsMenu({
  patient,
}: {
  patient: PatientChartPatient;
}): ReactNode {
  const can = useCan();
  const [active, setActive] = useState<'edit' | 'deceased' | null>(null);

  const canEdit = can(permissions.patient.update);
  const canDeceased = can(permissions.patient.markDeceased);

  const isTerminal = patient.status === 'DECEASED' || patient.status === 'MERGED';
  const deceasedDisabled = !canDeceased || isTerminal;

  function clear(): void {
    setActive(null);
  }

  // Null trigger: dialogs are opened from menu items, no inline button.
  const nullTrigger = (): ReactNode => null;

  return (
    <>
      <MenuButton
        label="Actions"
        kind="ghost"
        size="lg"
        menuAlignment="bottom-end"
      >
        <MenuItem
          label="Edit patient details"
          renderIcon={Edit}
          disabled={!canEdit}
          onClick={() => {
            if (canEdit) setActive('edit');
          }}
        />
        <MenuItem
          label="Mark patient deceased"
          kind="danger"
          renderIcon={UserMinus}
          disabled={deceasedDisabled}
          onClick={() => {
            if (!deceasedDisabled) setActive('deceased');
          }}
        />
        <MenuItem
          label="Add to list (coming soon)"
          renderIcon={List}
          disabled
          onClick={() => {}}
        />
      </MenuButton>

      {/* Controlled dialogs — mounted for permission holders only; each
          dialog nulls itself without permission or for terminal states. */}
      {canEdit ? (
        <EditDemographicsDialog
          patient={patient}
          open={active === 'edit'}
          onClose={clear}
          offsetInlineEnd="3rem"
          renderTrigger={nullTrigger}
        />
      ) : null}
      {!deceasedDisabled || canDeceased ? (
        <MarkDeceasedDialog
          patient={patient}
          open={active === 'deceased'}
          onClose={clear}
          offsetInlineEnd="3rem"
          renderTrigger={nullTrigger}
        />
      ) : null}
    </>
  );
}
