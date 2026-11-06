import { MenuButton, MenuItem } from '@carbon/react';
import { router } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { ArrowRight, CalendarAdd, Checkmark, Edit, UserMinus } from '@/icons';
import type { PatientChartPatient } from '@/features/patients/types/chart';
import { permissions, useCan } from '@/lib/authorization';

export function PatientActionsMenu({
  patient,
  canBook = false,
  canCheckIn = false,
  workflowAction,
  onBook,
  onCheckIn,
  onEditPatient,
  onMarkDeceased,
}: {
  patient: PatientChartPatient;
  canBook?: boolean;
  canCheckIn?: boolean;
  workflowAction?: { label: string; href: string };
  onBook?: () => void;
  onCheckIn?: () => void;
  onEditPatient?: () => void;
  onMarkDeceased?: () => void;
}): ReactNode {
  const can = useCan();

  const canEdit = can(permissions.patient.update);
  const canDeceased = can(permissions.patient.markDeceased);

  const isTerminal = patient.status === 'DECEASED' || patient.status === 'MERGED';
  const canEditPatient = canEdit && onEditPatient !== undefined;
  const canMarkDeceased = canDeceased && !isTerminal && onMarkDeceased !== undefined;
  const canBookAppointment = canBook && !isTerminal && onBook !== undefined;
  const canCheckInPatient = canCheckIn && !isTerminal && onCheckIn !== undefined;

  if (
    !workflowAction
    && !canEditPatient
    && !canMarkDeceased
    && !canBookAppointment
    && !canCheckInPatient
  ) {
    return null;
  }

  return (
    <MenuButton
      className="patient-chart__actions-menu"
      label="Actions"
      kind="ghost"
      size="lg"
      menuAlignment="bottom-end"
    >
      {workflowAction ? (
        <MenuItem
          label={workflowAction.label}
          renderIcon={ArrowRight}
          onClick={() => router.visit(workflowAction.href)}
        />
      ) : null}
      {canBookAppointment ? (
        <MenuItem
          label="Book appointment"
          renderIcon={CalendarAdd}
          onClick={() => onBook?.()}
        />
      ) : null}
      {canCheckInPatient ? (
        <MenuItem
          label="Check in patient"
          renderIcon={Checkmark}
          onClick={() => onCheckIn?.()}
        />
      ) : null}
      {canEditPatient ? (
        <MenuItem
          label="Edit patient details"
          renderIcon={Edit}
          onClick={() => onEditPatient?.()}
        />
      ) : null}
      {canMarkDeceased ? (
        <MenuItem
          label="Mark patient deceased"
          kind="danger"
          renderIcon={UserMinus}
          onClick={() => onMarkDeceased?.()}
        />
      ) : null}
    </MenuButton>
  );
}
