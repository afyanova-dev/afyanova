import type { ReactNode } from 'react';
import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { Button, DatePicker, DatePickerInput, Modal, TextArea, TextInput } from '@carbon/react';
import { routes } from '@/app/routes';
import { ConfirmDialog } from '@/components/feedback/ConfirmDialog';
import { permissions, useCan } from '@/lib/authorization';
import { getJson } from '@/lib/http';

/**
 * Canonical appointment lifecycle actions (foundation §10, §24).
 *
 * ONE owner (Appointments), many contextual entry points: the calendar day
 * agenda and the Patient Chart reuse this exact component — same buttons,
 * same dialogs, same endpoints, same permission gates. Never reimplement
 * these transitions elsewhere.
 */

export interface ActionableAppointment {
  id: string;
  appointmentNumber: string;
  scheduledStart: string;
  scheduledEnd: string;
  status: string;
  appointmentTypeId?: string | null;
  appointmentTypeName?: string | null;
}

type StatusKind = 'active' | 'inactive' | 'warning' | 'error' | 'info';

export function appointmentStatusKind(status: string): StatusKind {
  switch (status) {
    case 'CONFIRMED':
    case 'ARRIVED':
    case 'BOOKED':
      return 'active';
    case 'CANCELLED':
      return 'error';
    case 'NO_SHOW':
      return 'warning';
    default:
      return 'info';
  }
}

export function appointmentStatusLabel(status: string): string {
  return status.replaceAll('_', ' ');
}

function returnTo(): string {
  if (typeof window === 'undefined') return '/workspaces/appointments/calendar';
  return `${window.location.pathname}${window.location.search}`;
}

function localDate(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

export interface AppointmentTypeDuration {
  id: string;
  name: string;
  durationMinutes: number;
}

/**
 * Reschedule end time follows the appointment type's duration — the same
 * rule booking uses server-side — so the moved slot keeps the right length.
 * Matches by id first (exact), then by name (agenda rows carry names only).
 * Returns null when the type is unknown; callers fall back to 30 minutes
 * and the backend still validates overlap and capacity.
 */
export function resolveTypeDurationMinutes(
  types: AppointmentTypeDuration[],
  appointmentTypeId?: string | null,
  appointmentTypeName?: string | null,
): number | null {
  if (appointmentTypeId) {
    const byId = types.find((type) => type.id === appointmentTypeId);
    if (byId) return byId.durationMinutes;
  }
  if (appointmentTypeName) {
    const byName = types.find((type) => type.name === appointmentTypeName);
    if (byName) return byName.durationMinutes;
  }
  return null;
}

export function RescheduleAppointmentDialog({
  appointment,
  open,
  onClose,
  returnTo: returnToOverride,
}: {
  appointment: ActionableAppointment;
  open: boolean;
  onClose: () => void;
  returnTo?: string;
}): ReactNode {
  const [newDate, setNewDate] = useState(appointment.scheduledStart.slice(0, 10));
  const [newTime, setNewTime] = useState('');
  const [busy, setBusy] = useState(false);
  const [durationMinutes, setDurationMinutes] = useState<number | null>(null);

  useEffect(() => {
    if (!open) return;
    let active = true;
    void getJson<{ types: AppointmentTypeDuration[] }>(routes.api.appointmentOptions)
      .then((response) => {
        if (active) {
          setDurationMinutes(resolveTypeDurationMinutes(
            response.types ?? [],
            appointment.appointmentTypeId,
            appointment.appointmentTypeName,
          ));
        }
      })
      .catch(() => {
        if (active) setDurationMinutes(null);
      });
    return () => { active = false; };
  }, [open, appointment.appointmentTypeId, appointment.appointmentTypeName]);

  function reschedule(): void {
    if (!newDate || !newTime) return;
    const start = new Date(`${newDate}T${newTime}:00`);
    if (Number.isNaN(start.getTime())) return;
    const end = new Date(start.getTime() + (durationMinutes ?? 30) * 60 * 1000);
    setBusy(true);
    router.post(routes.workspaces.appointmentReschedule(appointment.id), {
      new_start: start.toISOString(),
      new_end: end.toISOString(),
      return_to: returnToOverride ?? returnTo(),
    }, {
      preserveScroll: true,
      onFinish: () => {
        setBusy(false);
        onClose();
      },
    });
  }

  return (
    <Modal
      open={open}
      modalHeading="Reschedule appointment"
      primaryButtonText={busy ? 'Rescheduling…' : 'Reschedule'}
      secondaryButtonText="Keep date"
      onRequestClose={onClose}
      onRequestSubmit={reschedule}
    >
      <DatePicker
        datePickerType="single"
        dateFormat="m/d/Y"
        minDate={localDate(new Date())}
        value={new Date(`${newDate}T00:00:00`)}
        onChange={(dates) => {
          const [date] = dates;
          if (date) setNewDate(localDate(date));
        }}
      >
        <DatePickerInput id={`reschedule-date-${appointment.id}`} labelText="New date" placeholder="mm/dd/yyyy" />
      </DatePicker>
      <div style={{ marginBlockStart: '1rem' }}>
        <TextInput
          id={`reschedule-time-${appointment.id}`}
          type="time"
          labelText="New time"
          helperText={durationMinutes
              ? `End time defaults to ${durationMinutes} minutes later (this appointment type). Backend validates overlap and capacity.`
              : 'End time defaults to 30 minutes later. Backend validates overlap and capacity.'}
          value={newTime}
          onChange={(event) => setNewTime(event.currentTarget.value)}
        />
      </div>
    </Modal>
  );
}

export function CancelAppointmentDialog({
  appointment,
  open,
  onClose,
  returnTo: returnToOverride,
}: {
  appointment: ActionableAppointment;
  open: boolean;
  onClose: () => void;
  returnTo?: string;
}): ReactNode {
  const [cancelReason, setCancelReason] = useState('');
  const [busy, setBusy] = useState(false);

  function cancel(): void {
    setBusy(true);
    router.post(routes.workspaces.appointmentCancel(appointment.id), {
      ...(cancelReason.trim() ? { reason: cancelReason.trim() } : {}),
      return_to: returnToOverride ?? returnTo(),
    }, {
      preserveScroll: true,
      onFinish: () => {
        setBusy(false);
        onClose();
      },
    });
  }

  return (
    <Modal
      open={open}
      modalHeading="Cancel appointment"
      primaryButtonText={busy ? 'Cancelling…' : 'Cancel appointment'}
      secondaryButtonText="Keep booking"
      danger
      onRequestClose={onClose}
      onRequestSubmit={cancel}
    >
      <TextArea
        id={`cancel-reason-${appointment.id}`}
        labelText="Reason (optional)"
        value={cancelReason}
        onChange={(event) => setCancelReason(event.currentTarget.value)}
        rows={3}
        maxLength={500}
      />
    </Modal>
  );
}

export function AppointmentLifecycleButtons({
  appointment,
  returnTo: returnToOverride,
}: {
  appointment: ActionableAppointment;
  returnTo?: string;
}): ReactNode {
  const can = useCan();
  const [confirming, setConfirming] = useState<null | 'confirm' | 'arrive' | 'no-show'>(null);
  const [cancelOpen, setCancelOpen] = useState(false);
  const [rescheduleOpen, setRescheduleOpen] = useState(false);
  const [busy, setBusy] = useState(false);

  const canManage = can(permissions.scheduling.manage);
  const canArrive = can(permissions.scheduling.recordArrival);
  const status = appointment.status;
  const isCancelled = status === 'CANCELLED';
  const isNoShow = status === 'NO_SHOW';
  const destination = returnToOverride ?? returnTo();

  function post(url: string): void {
    setBusy(true);
    router.post(url, { return_to: destination }, {
      preserveScroll: true,
      onFinish: () => {
        setBusy(false);
        setConfirming(null);
      },
    });
  }

  if (isCancelled || (!canManage && !canArrive)) return null;

  return (
    <div className="appointment-agenda__actions" aria-label={`Actions for ${appointment.appointmentNumber}`}>
      {canManage && (status === 'BOOKED' || status === 'CONFIRMED') ? (
        <Button kind="ghost" size="sm" type="button" disabled={busy} onClick={() => setRescheduleOpen(true)}>
          Reschedule
        </Button>
      ) : null}
      {canManage && status === 'BOOKED' ? (
        <Button kind="ghost" size="sm" type="button" disabled={busy} onClick={() => setConfirming('confirm')}>
          Confirm
        </Button>
      ) : null}
      {canArrive && status === 'CONFIRMED' ? (
        <Button kind="ghost" size="sm" type="button" disabled={busy} onClick={() => setConfirming('arrive')}>
          Record arrival
        </Button>
      ) : null}
      {canManage && !isCancelled && !isNoShow ? (
        <Button kind="ghost" size="sm" type="button" disabled={busy} onClick={() => setConfirming('no-show')}>
          No-show
        </Button>
      ) : null}
      {canManage && !isCancelled ? (
        <Button kind="ghost" size="sm" type="button" disabled={busy} onClick={() => setCancelOpen(true)}>
          Cancel
        </Button>
      ) : null}

      <ConfirmDialog
        open={confirming === 'confirm'}
        heading="Confirm appointment?"
        body={`Confirm ${appointment.appointmentNumber}? This keeps the booking and marks it confirmed.`}
        confirmLabel={busy ? 'Confirming…' : 'Confirm'}
        onClose={() => setConfirming(null)}
        onConfirm={() => post(routes.workspaces.appointmentConfirm(appointment.id))}
      />
      <ConfirmDialog
        open={confirming === 'arrive'}
        heading="Record arrival?"
        body={`Record arrival for ${appointment.appointmentNumber}? This is appointment arrival, not Front Desk check-in.`}
        confirmLabel={busy ? 'Recording…' : 'Record arrival'}
        onClose={() => setConfirming(null)}
        onConfirm={() => post(routes.workspaces.appointmentArrive(appointment.id))}
      />
      <ConfirmDialog
        open={confirming === 'no-show'}
        heading="Mark as no-show?"
        body={`Mark ${appointment.appointmentNumber} as no-show? The slot becomes free for rebooking.`}
        confirmLabel={busy ? 'Saving…' : 'Mark no-show'}
        danger
        onClose={() => setConfirming(null)}
        onConfirm={() => post(routes.workspaces.appointmentNoShow(appointment.id))}
      />

      <CancelAppointmentDialog
        appointment={appointment}
        open={cancelOpen}
        onClose={() => setCancelOpen(false)}
        returnTo={returnToOverride}
      />
      <RescheduleAppointmentDialog
        appointment={appointment}
        open={rescheduleOpen}
        onClose={() => setRescheduleOpen(false)}
        returnTo={returnToOverride}
      />
    </div>
  );
}
