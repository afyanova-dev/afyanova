import { IconButton } from '@carbon/react';
import type { ReactNode } from 'react';
import { CalendarAdd, Checkmark, Edit } from '@/icons';
import { PatientStickyNote } from '@/features/patients/components/PatientStickyNote';
import { permissions, useCan } from '@/lib/authorization';
import { QuickActionsRail } from '@/components/ui';

export type ChartRailName = 'edit' | 'book' | 'checkin';

/**
 * Controlled right rail: stateless icon set. Single source of truth lives
 * in the parent (`PatientChart`) as one `workspace` union, so opening one
 * rail entry auto-closes any unrelated workspace — React controlled-component
 * pattern, no duplicated `useState` per icon.
 */
export function QuickActions({
  active,
  onSelect,
  canCheckIn,
  patientId,
}: {
  /** Currently open rail workspace, or null when the rail is idle. */
  active: ChartRailName | null;
  /** Toggle: parent closes the current workspace or switches to the picked one. */
  onSelect: (name: ChartRailName) => void;
  canCheckIn: boolean;
  patientId: string;
}): ReactNode {
  const can = useCan();
  const canBook = can(permissions.scheduling.book);

  return (
    <QuickActionsRail>
      <IconButton
        align="left"
        label="Edit demographics"
        kind={active === 'edit' ? 'primary' : 'ghost'}
        onClick={() => onSelect('edit')}
      >
        <Edit />
      </IconButton>
      {canBook ? (
        <IconButton
          align="left"
          label="Book appointment"
          kind={active === 'book' ? 'primary' : 'ghost'}
          onClick={() => onSelect('book')}
        >
          <CalendarAdd />
        </IconButton>
      ) : null}
      {canCheckIn ? (
        <IconButton
          align="left"
          label="Check in"
          kind={active === 'checkin' ? 'primary' : 'ghost'}
          onClick={() => onSelect('checkin')}
        >
          <Checkmark />
        </IconButton>
      ) : null}
      <PatientStickyNote key={patientId} patientId={patientId} />
    </QuickActionsRail>
  );
}
