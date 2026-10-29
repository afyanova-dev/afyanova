import { Button, DatePicker, DatePickerInput, InlineLoading, Select, SelectItem, TextArea, TextInput } from '@carbon/react';
import { useForm } from '@inertiajs/react';
import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import { FormErrors } from '@/components/ui';
import { PatientSearchPhoto, PatientSearchText } from '@/components/patient-search/PatientSearchRow';
import { usePatientSearch } from '@/components/patient-search/usePatientSearch';
import { DockedPanel } from '@/components/panels';
import { getJson } from '@/lib/http';
import type { PatientSearchRow } from '@/components/patient-search/patientSearch';

interface AppointmentOption {
  id: string;
  name: string;
  durationMinutes?: number;
  resourceType?: string;
}

interface AppointmentOptionsResponse {
  types: Array<AppointmentOption & { code: string; durationMinutes: number }>;
  resources: Array<AppointmentOption & { resourceType: string }>;
}

interface AvailableSlot {
  id: string;
  resourceId: string;
  startTime: string;
  endTime: string;
  capacity: number;
  bookedCount: number;
  status: string;
}

interface AvailabilityResponse {
  date: string;
  slots: AvailableSlot[];
}

interface BookingForm {
  scheduled_date: string;
  scheduled_time: string;
  appointment_type_id: string;
  resource_id: string;
  reason: string;
  slot_id: string;
  return_to: string;
}

function localTime(value: string): string {
  return /(?:T|\s)(\d{2}:\d{2})/.exec(value)?.[1] ?? '';
}

function localDate(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function dateFromValue(value: string): Date {
  const date = new Date(`${value}T00:00:00`);
  return Number.isNaN(date.getTime()) ? new Date() : date;
}

export interface FixedBookingPatient {
  patientId: string;
  patientNumber: string;
  displayName: string;
  sex: string | null;
  photoUrl: string | null;
}

function fixedPatientRow(fixed: FixedBookingPatient): PatientSearchRow {
  return {
    patientId: fixed.patientId,
    patientNumber: fixed.patientNumber,
    displayName: fixed.displayName,
    sex: fixed.sex,
    formattedDob: null,
    age: null,
    photoUrl: fixed.photoUrl,
    activeEncounter: null,
  };
}

export function BookAppointmentPanel({
  month,
  onClose,
  fixedPatient = null,
  returnTo,
  offsetInlineEnd = '0px',
  onDirtyChange,
}: {
  month: string;
  onClose: () => void;
  /**
   * Contextual entry point (e.g. Patient Chart): the patient is already
   * known, so the finder step is skipped. Same canonical booking workflow,
   * same endpoint — only the patient step is pre-filled.
   */
  fixedPatient?: FixedBookingPatient | null;
  /** Where the backend redirects after booking. Defaults to the calendar. */
  returnTo?: string;
  /** Keeps the sheet clear of a contextual action rail (chart pattern). */
  offsetInlineEnd?: string;
  /** Reports dirty state so the parent can guard workspace switches. */
  onDirtyChange?: (dirty: boolean) => void;
}): ReactNode {
  const [patientQuery, setPatientQuery] = useState('');
  const [selectedPatient, setSelectedPatient] = useState<PatientSearchRow | null>(() => (
    fixedPatient ? fixedPatientRow(fixedPatient) : null
  ));
  const [patientError, setPatientError] = useState<string | null>(null);
  const [options, setOptions] = useState<AppointmentOptionsResponse>({ types: [], resources: [] });
  const [optionsLoading, setOptionsLoading] = useState(true);
  const [optionsError, setOptionsError] = useState<string | null>(null);
  const [slots, setSlots] = useState<AvailableSlot[]>([]);
  const [slotsLoading, setSlotsLoading] = useState(false);
  const [slotsError, setSlotsError] = useState<string | null>(null);
  const { rows, loading: patientsLoading, errorMessage: patientsError } = usePatientSearch(patientQuery, {
    enabled: true,
    perPage: 5,
    fetchWhenEmpty: false,
  });
  const { data, setData, post, processing, errors, isDirty } = useForm<BookingForm>({
    scheduled_date: localDate(new Date()),
    scheduled_time: '',
    appointment_type_id: '',
    resource_id: '',
    reason: '',
    slot_id: '',
    return_to: returnTo ?? `${routes.workspaces.appointmentCalendar}?month=${encodeURIComponent(month)}`,
  });
  const dirty = isDirty || (!fixedPatient && selectedPatient !== null);

  useEffect(() => {
    onDirtyChange?.(dirty);
  }, [dirty, onDirtyChange]);

  useEffect(() => {
    let active = true;
    setOptionsLoading(true);
    setOptionsError(null);
    void getJson<AppointmentOptionsResponse>(routes.api.appointmentOptions)
      .then((response) => {
        if (active) setOptions({ types: response.types ?? [], resources: response.resources ?? [] });
      })
      .catch(() => {
        if (active) setOptionsError('Appointment types and resources could not be loaded.');
      })
      .finally(() => {
        if (active) setOptionsLoading(false);
      });
    return () => { active = false; };
  }, []);

  useEffect(() => {
    if (!data.resource_id || !data.scheduled_date) {
      setSlots([]);
      setSlotsError(null);
      setData('slot_id', '');
      setData('scheduled_time', '');
      return;
    }

    let active = true;
    setSlotsLoading(true);
    setSlotsError(null);
    setSlots([]);
    setData('slot_id', '');
    setData('scheduled_time', '');
    const query = new URLSearchParams({
      resource_id: data.resource_id,
      date: data.scheduled_date,
    });
    if (data.appointment_type_id) query.set('appointment_type_id', data.appointment_type_id);

    void getJson<AvailabilityResponse>(`${routes.api.availableSlots}?${query.toString()}`)
      .then((response) => {
        if (active) setSlots(response.slots ?? []);
      })
      .catch(() => {
        if (active) setSlotsError('Available times could not be loaded. Try again.');
      })
      .finally(() => {
        if (active) setSlotsLoading(false);
      });

    return () => { active = false; };
  }, [data.appointment_type_id, data.resource_id, data.scheduled_date, setData]);

  function submit(event: FormEvent): void {
    event.preventDefault();
    if (!selectedPatient) {
      setPatientError('Select a patient before booking.');
      return;
    }
    setPatientError(null);
    post(routes.patient.bookAppointment(selectedPatient.patientId), {
      preserveScroll: true,
    });
  }

  return (
    <DockedPanel
      heading="Book appointment"
      primaryLabel={processing ? 'Booking…' : 'Book appointment'}
      primaryDisabled={processing || optionsLoading || options.types.length === 0 || options.resources.length === 0}
      offsetInlineEnd={offsetInlineEnd}
      onClose={onClose}
      onSubmit={submit}
    >
      <FormErrors errors={errors} fields={[
        'scheduled_date', 'scheduled_time', 'appointment_type_id', 'resource_id', 'reason', 'slot_id',
      ]} />
      {optionsError ? <div role="alert" style={{ marginBlockEnd: '1rem' }}>{optionsError}</div> : null}
      {optionsLoading ? <InlineLoading description="Loading appointment options" /> : null}

      <section aria-labelledby="appointment-patient-heading" style={{ marginBlockEnd: '1.5rem' }}>
        <h3 id="appointment-patient-heading">Patient</h3>
        {fixedPatient ? (
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem' }}>
            <PatientSearchPhoto row={fixedPatientRow(fixedPatient)} />
            <PatientSearchText row={fixedPatientRow(fixedPatient)} />
          </div>
        ) : selectedPatient ? (
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem' }}>
            <PatientSearchPhoto row={selectedPatient} />
            <PatientSearchText row={selectedPatient} />
            <Button kind="ghost" size="sm" type="button" onClick={() => setSelectedPatient(null)}>
              Change
            </Button>
          </div>
        ) : (
          <>
            <TextInput
              id="appointment-patient-search"
              labelText="Find a patient"
              helperText="Enter at least 2 characters, then select a patient."
              value={patientQuery}
              onChange={(event) => setPatientQuery(event.currentTarget.value)}
              placeholder="Name, patient number, or phone"
              invalid={Boolean(patientError)}
              invalidText={patientError ?? undefined}
            />
            {patientsLoading ? <InlineLoading description="Searching patients" /> : null}
            {patientsError ? <p role="alert">{patientsError}</p> : null}
            {rows.length > 0 ? (
              <ul className="appointment-booking__patient-results" aria-label="Patient search results">
                {rows.map((row) => (
                  <li key={row.patientId}>
                    <button
                      className="appointment-booking__patient-result"
                      type="button"
                      onClick={() => {
                        setSelectedPatient(row);
                        setPatientError(null);
                      }}
                    >
                      <PatientSearchPhoto row={row} />
                      <PatientSearchText row={row} />
                    </button>
                  </li>
                ))}
              </ul>
            ) : null}
          </>
        )}
      </section>

      <Select
        id="appointment-type"
        labelText="Appointment type"
        value={data.appointment_type_id}
        onChange={(event) => setData('appointment_type_id', event.currentTarget.value)}
        disabled={optionsLoading || options.types.length === 0}
        invalid={Boolean(errors.appointment_type_id)}
        invalidText={errors.appointment_type_id}
      >
        <SelectItem value="" text="Choose an appointment type" />
        {options.types.map((type) => (
          <SelectItem key={type.id} value={type.id} text={`${type.name} · ${type.durationMinutes} min`} />
        ))}
      </Select>

      <Select
        id="appointment-resource"
        labelText="Resource"
        value={data.resource_id}
        onChange={(event) => setData('resource_id', event.currentTarget.value)}
        disabled={optionsLoading || options.resources.length === 0}
        invalid={Boolean(errors.resource_id)}
        invalidText={errors.resource_id}
      >
        <SelectItem value="" text="Choose a resource" />
        {options.resources.map((resource) => (
          <SelectItem key={resource.id} value={resource.id} text={resource.name} />
        ))}
      </Select>

      <DatePicker
        className="appointment-booking__date-picker"
        datePickerType="single"
        dateFormat="m/d/Y"
        value={dateFromValue(data.scheduled_date)}
        minDate={dateFromValue(localDate(new Date()))}
        onChange={(selectedDates) => {
          const selectedDate = selectedDates[0];
          if (selectedDate) setData('scheduled_date', localDate(selectedDate));
        }}
      >
        <DatePickerInput
          id="appointment-date"
          labelText="Date"
          placeholder="mm/dd/yyyy"
          style={{ width: '100%', maxWidth: '100%' }}
          invalid={Boolean(errors.scheduled_date)}
          invalidText={errors.scheduled_date}
        />
      </DatePicker>

      {slotsLoading ? <InlineLoading description="Checking availability" /> : null}
      {slotsError ? <p role="alert">{slotsError}</p> : null}
      {!slotsLoading && !slotsError && data.resource_id && data.scheduled_date && slots.length === 0 ? (
        <p role="status">No available slots for this resource and date. You can still enter a time.</p>
      ) : null}
      <TextInput
        id="appointment-time"
        type="time"
        labelText="Time"
        helperText="Choose a time, or select an available slot below."
        value={data.scheduled_time}
        onChange={(event) => {
          // Capture now: currentTarget is null once the event finishes attrition.
          const time = event.currentTarget.value;
          setData((current) => ({
            ...current,
            scheduled_time: time,
            slot_id: '',
          }));
        }}
        required
        invalid={Boolean(errors.scheduled_time)}
        invalidText={errors.scheduled_time}
      />
      {slots.length > 0 ? (
        <Select
          id="appointment-slot"
          labelText="Available slot (optional)"
          value={data.slot_id}
          onChange={(event) => {
            const slot = slots.find((item) => item.id === event.currentTarget.value);
            setData((current) => ({
              ...current,
              slot_id: slot?.id ?? '',
              scheduled_time: slot ? localTime(slot.startTime) : '',
            }));
          }}
          invalid={Boolean(errors.slot_id)}
          invalidText={errors.slot_id}
        >
          <SelectItem value="" text="No slot selected" />
          {slots.map((slot) => (
            <SelectItem
              key={slot.id}
              value={slot.id}
              text={`${localTime(slot.startTime)} · ${slot.capacity - slot.bookedCount} remaining`}
            />
          ))}
        </Select>
      ) : null}

      <TextArea
        id="appointment-reason"
        labelText="Reason (optional)"
        value={data.reason}
        onChange={(event) => setData('reason', event.currentTarget.value)}
        rows={3}
        invalid={Boolean(errors.reason)}
        invalidText={errors.reason}
      />
    </DockedPanel>
  );
}