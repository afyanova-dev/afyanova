import { usePage } from '@inertiajs/react';
import { Button } from '@carbon/react';
import { useState, type ReactNode } from 'react';
import type { SharedProps } from '@/types';
import { SwitchFacilityDialog } from './SwitchFacilityDialog';

/**
 * Facility context indicator (OpenMRS location pattern).
 *
 * General and facility selection are working contexts, never authorization.
 * The switchable facility set is limited to the user's active assignments.
 */
export function FacilitySwitcher(): ReactNode {
  const { props } = usePage<SharedProps>();
  const { facilities, activeFacility, contextMode, canUseGeneral } = props.auth;
  const [dialogOpen, setDialogOpen] = useState(false);

  if (facilities.length === 0 && !canUseGeneral) return null;

  const active = facilities.find((facility) => facility.id === activeFacility);
  const label = contextMode === 'general' ? 'General' : active?.name ?? 'Select facility';

  if (facilities.length === 1 && active && !canUseGeneral) {
    return (
      <span className="afyanova-facility-name" aria-label="Active facility">
        {active.name}
      </span>
    );
  }

  return (
    <div className="afyanova-facility-switcher">
      <Button kind="ghost" size="sm" onClick={() => setDialogOpen(true)} aria-label={label}>
        {label}
      </Button>
      <SwitchFacilityDialog
        open={dialogOpen}
        onClose={() => setDialogOpen(false)}
        facilities={facilities}
        activeFacility={activeFacility}
        contextMode={contextMode}
        canUseGeneral={canUseGeneral}
      />
    </div>
  );
}
