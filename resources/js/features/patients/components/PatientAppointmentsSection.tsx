import { Button } from '@carbon/react';
import type { ReactNode } from 'react';
import { formatClinicalDateTime } from '@/lib/formatting';
import {
  appointmentStatusLabel,
} from '@/features/appointments/components/AppointmentLifecycleButtons';
import type { PatientChartAppointment } from '@/features/patients/types/chart';
import { PatientRecordTable, PatientSectionState, type PatientSectionAccessState } from './PatientRecordTable';

export function PatientAppointmentsSection({
  appointments,
  canBook,
  canManage,
  canCheckIn,
  onBook,
  onView,
  onReschedule,
  onCancel,
  onCheckIn,
  state,
}: {
  appointments: PatientChartAppointment[];
  canBook: boolean;
  canManage: boolean;
  canCheckIn: boolean;
  onBook: () => void;
  onView: (appointment: PatientChartAppointment) => void;
  onReschedule: (appointment: PatientChartAppointment) => void;
  onCancel: (appointment: PatientChartAppointment) => void;
  onCheckIn: (appointment: PatientChartAppointment) => void;
  state: PatientSectionAccessState;
}): ReactNode {
  if (state) return <PatientSectionState state={state} />;

  const byId = new Map(appointments.map((appointment) => [appointment.id, appointment]));

  return (
    <PatientRecordTable
      title="Appointments"
      ariaLabel="Patient appointments"
      searchLabel="Search appointments"
      headerActions={canBook ? (
        <Button kind="primary" size="sm" type="button" onClick={onBook}>
          Book appointment
        </Button>
      ) : null}
      headers={[
        { key: 'date', header: 'Date' },
        { key: 'type', header: 'Type' },
        { key: 'resource', header: 'Resource' },
        { key: 'status', header: 'Status' },
        { key: 'reason', header: 'Reason' },
        { key: 'actions', header: 'Actions' },
      ]}
      rows={appointments.map((appointment) => ({
        id: appointment.id,
        cells: [
          `${formatClinicalDateTime(appointment.start) ?? appointment.start} – ${formatClinicalDateTime(appointment.end) ?? appointment.end}`,
          appointment.appointmentTypeName ?? '—',
          appointment.resourceName ?? '—',
          appointmentStatusLabel(appointment.status),
          appointment.reason ?? '—',
          '',
        ],
      }))}
      statusHeaders={['status']}
      emptyTitle="No appointments"
      emptyDescription="No appointments are recorded for this patient."
      renderCell={(cell, rowId) => {
        if (cell.info.header !== 'actions') return cell.value;

        const appointment = byId.get(rowId);
        if (!appointment) return null;
        const canReschedule = canManage && (appointment.status === 'BOOKED' || appointment.status === 'CONFIRMED');
        const canCancel = canManage
          && (appointment.status === 'REQUESTED'
            || appointment.status === 'BOOKED'
            || appointment.status === 'CONFIRMED');
        const canCheckInRow = canCheckIn
          && (appointment.status === 'BOOKED' || appointment.status === 'CONFIRMED' || appointment.status === 'ARRIVED');

        return (
          <div className="patient-chart__row-actions">
            <Button kind="ghost" size="sm" type="button" onClick={() => onView(appointment)}>
              View
            </Button>
            {canReschedule ? (
              <Button kind="ghost" size="sm" type="button" onClick={() => onReschedule(appointment)}>
                Reschedule
              </Button>
            ) : null}
            {canCancel ? (
              <Button kind="ghost" size="sm" type="button" onClick={() => onCancel(appointment)}>
                Cancel
              </Button>
            ) : null}
            {canCheckInRow ? (
              <Button kind="ghost" size="sm" type="button" onClick={() => onCheckIn(appointment)}>
                {`Check in appointment ${appointment.number}`}
              </Button>
            ) : null}
          </div>
        );
      }}
    />
  );
}
