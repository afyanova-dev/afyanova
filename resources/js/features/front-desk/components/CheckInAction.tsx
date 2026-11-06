import type { FormEvent, ReactNode } from 'react';
import { useEffect, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Checkbox, InlineLoading, InlineNotification, Select, SelectItem, TextArea } from '@carbon/react';
import { routes } from '@/app/routes';
import { FormErrors } from '@/components/ui';
import { PatientSearchPhoto, PatientSearchText } from '@/components/patient-search/PatientSearchRow';
import { DockedPanel } from '@/components/panels';
import { permissions, useCan } from '@/lib/authorization';
import { getJson } from '@/lib/http';

/**
 * Canonical check-in workflow (foundation: Front Desk owns arrival and
 * encounter check-in; many contextual entry points allowed).
 *
 * ONE implementation used by the reception board, the Patient Chart rail,
 * and chart appointment rows — same `POST /workspaces/reception/check-in`
 * contract, same `encounter.encounters.create` gate (+ `queue.ticket.place`
 * when placing into a queue). The backend auto-links appointment arrival
 * from `appointment_id` for BOOKED/CONFIRMED rows and validates everything;
 * the frontend never records arrival separately and never claims success
 * the backend did not confirm.
 */

export interface CheckInTarget {
  patientId: string;
  displayName: string;
  patientNumber: string;
  sex?: string | null;
  formattedDob?: string | null;
  age?: string | null;
  photoUrl?: string | null;
  activeEncounter?: { encounterNumber: string } | null;
  appointmentId?: string | null;
  appointmentNumber?: string | null;
  appointmentLabel?: string | null;
}

export interface CheckInQueueOption {
  id: string;
  departmentId?: string | null;
  name: string;
  code: string;
  serviceCapability: string | null;
  roomNames?: string[];
}

export interface CheckInProtocolProcedure {
  definitionId: string;
  code: string;
  name: string;
  queueId: string;
  queueName: string;
  protocolReference: string;
  protocolVersion: string;
}

/** Backend `EncounterType` values accepted by `encounter_type` (required). */
const ENCOUNTER_TYPES = [
  'OUTPATIENT',
  'EMERGENCY',
  'INPATIENT',
  'MATERNITY',
  'DIAGNOSTIC',
  'PROCEDURE',
  'DAY_CARE',
  'FOLLOW_UP',
  'TELEHEALTH',
  'REFERRAL',
  'OTHER',
] as const;

const ENCOUNTER_TYPE_LABELS: Record<(typeof ENCOUNTER_TYPES)[number], string> = {
  OUTPATIENT: 'Outpatient',
  EMERGENCY: 'Emergency',
  INPATIENT: 'Inpatient',
  MATERNITY: 'Maternity',
  DIAGNOSTIC: 'Diagnostic',
  PROCEDURE: 'Procedure',
  DAY_CARE: 'Day care',
  FOLLOW_UP: 'Follow-up',
  TELEHEALTH: 'Telehealth',
  REFERRAL: 'Referral',
  OTHER: 'Other',
};

const PRIORITIES = ['ROUTINE', 'URGENT', 'EMERGENCY'] as const;

interface StructureUnit {
  id: string;
  name: string;
  code: string;
}

interface StructureDepartment {
  id: string;
  name: string;
  code: string;
  units: StructureUnit[];
}

interface FacilityStructureResponse {
  facilityId: string;
  facilityName: string;
  departments: StructureDepartment[];
}

interface CheckInForm {
  patient_id: string;
  appointment_id: string;
  encounter_type: string;
  department_id: string;
  unit_id: string;
  queue_id: string;
  priority: string;
  protocol_procedure_definition_id: string;
  protocol_procedure_indication: string;
  return_to: string;
}

export function useCheckInForm(target: CheckInTarget, returnTo?: string) {
  const can = useCan();
  const canPlaceTicket = can(permissions.queue.ticketPlace);
  const canRoute = can(permissions.organization.structureRead);
  const [departments, setDepartments] = useState<StructureDepartment[]>([]);
  const [departmentsLoading, setDepartmentsLoading] = useState(canRoute);
  const [departmentsLoadFailed, setDepartmentsLoadFailed] = useState(false);
  const { data, setData, setDefaults, reset, post, processing, errors, isDirty } = useForm<CheckInForm>({
    patient_id: target.patientId,
    appointment_id: target.appointmentId ?? '',
    encounter_type: '',
    department_id: '',
    unit_id: '',
    queue_id: '',
    priority: 'ROUTINE',
    protocol_procedure_definition_id: '',
    protocol_procedure_indication: '',
    return_to: returnTo ?? (typeof window === 'undefined'
      ? '/workspaces/reception'
      : `${window.location.pathname}${window.location.search}`),
  });

  useEffect(() => {
    if (!canRoute) {
      setDepartmentsLoading(false);
      return;
    }
    let active = true;
    setDepartmentsLoading(true);
    setDepartmentsLoadFailed(false);
    void getJson<FacilityStructureResponse>(routes.api.facilityStructure)
      .then((response) => {
        if (active) {
          setDepartments(response.departments ?? []);
          setDepartmentsLoading(false);
        }
      })
      .catch(() => {
        if (active) {
          setDepartments([]);
          setDepartmentsLoadFailed(true);
          setDepartmentsLoading(false);
        }
      });
    return () => { active = false; };
  }, [canRoute]);

  const departmentId = data.department_id;
  const units = departments.find((department) => department.id === departmentId)?.units ?? [];

  return {
    data,
    setData,
    setDefaults,
    reset,
    post,
    processing,
    errors,
    isDirty,
    departments,
    departmentsLoading,
    departmentsLoadFailed,
    departmentId,
    units,
    queueChosen: data.queue_id !== '',
    canPlaceTicket,
    canRoute,
  };
}

export function CheckInFields({
  form,
  target,
  availableQueues,
  protocolProcedures = [],
  onDepartmentChoice,
  onQueueChoice,
  onProtocolChoice,
  onEncounterTypeChange,
}: {
  form: ReturnType<typeof useCheckInForm>;
  target: CheckInTarget;
  availableQueues: CheckInQueueOption[];
  protocolProcedures?: CheckInProtocolProcedure[];
  onDepartmentChoice?: () => void;
  onQueueChoice?: () => void;
  onProtocolChoice?: () => void;
  onEncounterTypeChange?: () => void;
}): ReactNode {
  const selectedProtocolProcedure = protocolProcedures.find(
    (procedure) => procedure.definitionId === form.data.protocol_procedure_definition_id,
  );
  const departmentQueues = availableQueues.filter((queue) => (
    queue.departmentId === null || queue.departmentId === form.departmentId
  ));
  const hasDepartment = form.data.department_id !== '';
  const canChooseQueue = form.data.encounter_type !== ''
    && form.canPlaceTicket
    && availableQueues.length > 0
    && (!form.canRoute || hasDepartment);
  const patient = {
    displayName: target.displayName,
    patientNumber: target.patientNumber,
    sex: target.sex ?? null,
    formattedDob: target.formattedDob ?? null,
    age: target.age ?? null,
    photoUrl: target.photoUrl ?? null,
  };

  return (
    <>
      <div
        role="group"
        aria-label="Selected patient"
        style={{ display: 'flex', alignItems: 'center', gap: '0.75rem', marginBlockEnd: '1rem' }}
      >
        <PatientSearchPhoto row={patient} />
        <PatientSearchText row={patient} />
      </div>
      {target.activeEncounter ? (
        <InlineNotification
          kind="warning"
          lowContrast
          title="This patient already has an active visit"
          subtitle={`Visit ${target.activeEncounter.encounterNumber}. Use the existing visit instead of checking in again.`}
          style={{ marginBlockEnd: '1rem' }}
        />
      ) : null}
      {target.appointmentId ? (
        <p>
          Appointment {target.appointmentNumber ?? ''}
          {target.appointmentLabel ? ` · ${target.appointmentLabel}` : ''}
        </p>
      ) : null}
      {form.departmentsLoadFailed ? (
        <InlineNotification
          kind="warning"
          lowContrast
          title="Department options could not be loaded"
          subtitle="You can continue check-in without department routing."
          style={{ marginBlockEnd: '1rem' }}
        />
      ) : null}

      <Select
        id="check-in-encounter-type"
        labelText="Encounter type"
        value={form.data.encounter_type}
        onChange={(event) => {
          onEncounterTypeChange?.();
          form.setData('encounter_type', event.currentTarget.value);
          form.setData('department_id', '');
          form.setData('unit_id', '');
          form.setData('queue_id', '');
          form.setData('protocol_procedure_definition_id', '');
          form.setData('protocol_procedure_indication', '');
        }}
        invalid={Boolean(form.errors.encounter_type)}
        invalidText={form.errors.encounter_type}
      >
        <SelectItem value="" text="Select encounter type" />
        {ENCOUNTER_TYPES.map((type) => (
          <SelectItem key={type} value={type} text={ENCOUNTER_TYPE_LABELS[type]} />
        ))}
      </Select>

      {form.data.encounter_type === 'PROCEDURE' && protocolProcedures.length > 0 ? (
        <div style={{ marginBlockStart: '1rem' }}>
          <Select
            id="check-in-protocol-procedure"
            labelText="Direct protocol-approved procedure"
            value={form.data.protocol_procedure_definition_id}
            onChange={(event) => {
              const definitionId = event.currentTarget.value;
              const procedure = protocolProcedures.find((item) => item.definitionId === definitionId);
              onProtocolChoice?.();
              form.setData('protocol_procedure_definition_id', definitionId);
              form.setData('queue_id', procedure?.queueId ?? '');
              form.setData('department_id', '');
              form.setData('unit_id', '');
            }}
            invalid={Boolean(form.errors.protocol_procedure_definition_id)}
            invalidText={form.errors.protocol_procedure_definition_id}
          >
            <SelectItem value="" text="No direct procedure selected" />
            {protocolProcedures.map((procedure) => (
              <SelectItem
                key={procedure.definitionId}
                value={procedure.definitionId}
                text={`${procedure.name} (${procedure.code}) · ${procedure.queueName}`}
              />
            ))}
          </Select>
          {selectedProtocolProcedure ? (
            <>
              <p>
                Approved protocol {selectedProtocolProcedure.protocolReference} - version {selectedProtocolProcedure.protocolVersion}
              </p>
              <TextArea
                id="check-in-protocol-indication"
                labelText="Reason / indication (optional)"
                rows={2}
                value={form.data.protocol_procedure_indication}
                onChange={(event) => form.setData('protocol_procedure_indication', event.currentTarget.value)}
                invalid={Boolean(form.errors.protocol_procedure_indication)}
                invalidText={form.errors.protocol_procedure_indication}
                style={{ marginTop: '1rem' }}
              />
            </>
          ) : null}
        </div>
      ) : null}

      {form.data.encounter_type !== '' && !selectedProtocolProcedure && form.canRoute && form.departments.length > 0 ? (
        <>
          <div style={{ marginBlockStart: '1rem' }}>
            <Select
              id="check-in-department"
              labelText="Department"
              value={form.data.department_id}
              onChange={(event) => {
                const value = event.currentTarget.value;
                onDepartmentChoice?.();
                form.setData('department_id', value);
                form.setData('unit_id', '');
                form.setData('queue_id', '');
              }}
              invalid={Boolean(form.errors.department_id)}
              invalidText={form.errors.department_id}
            >
              <SelectItem value="" text="Select department (optional)" />
              {form.departments.map((department) => (
                <SelectItem key={department.id} value={department.id} text={`${department.name} (${department.code})`} />
              ))}
            </Select>
          </div>
          {form.departmentId !== '' && form.units.length > 0 ? (
            <div style={{ marginBlockStart: '1rem' }}>
              <Select
                id="check-in-unit"
                labelText="Unit (optional)"
                value={form.data.unit_id}
                onChange={(event) => form.setData('unit_id', event.currentTarget.value)}
                invalid={Boolean(form.errors.unit_id)}
                invalidText={form.errors.unit_id}
              >
                <SelectItem value="" text="No unit selected" />
                {form.units.map((unit) => (
                  <SelectItem key={unit.id} value={unit.id} text={`${unit.name} (${unit.code})`} />
                ))}
              </Select>
            </div>
          ) : null}
        </>
      ) : null}

      {canChooseQueue && !selectedProtocolProcedure && departmentQueues.length > 0 ? (
        <>
          <div style={{ marginBlockStart: '1rem' }}>
            <Select
              id="check-in-queue"
              labelText="Place into queue (optional)"
              value={form.data.queue_id}
              onChange={(event) => {
                onQueueChoice?.();
                form.setData('queue_id', event.currentTarget.value);
              }}
              invalid={Boolean(form.errors.queue_id)}
              invalidText={form.errors.queue_id}
            >
              <SelectItem value="" text="Check in without queue placement" />
              {departmentQueues.map((queue) => (
                <SelectItem
                  key={queue.id}
                  value={queue.id}
                  text={
                    `${queue.name} (${queue.code})` +
                    `${queue.serviceCapability ? ` · ${queue.serviceCapability.replaceAll('_', ' ')}` : ''}` +
                    `${queue.roomNames?.length ? '' : ' · No specific location'}`
                  }
                />
              ))}
            </Select>
          </div>
          {canChooseQueue && departmentQueues.length === 0 ? (
            <p role="status" style={{ marginBlockStart: '1rem' }}>
              No queues are configured for this department. You can still check in without queue placement.
            </p>
          ) : null}

          {form.queueChosen ? (
            <div style={{ marginBlockStart: '1rem' }}>
              <Select
                id="check-in-priority"
                labelText="Priority"
                value={form.data.priority}
                onChange={(event) => form.setData('priority', event.currentTarget.value)}
                invalid={Boolean(form.errors.priority)}
                invalidText={form.errors.priority}
              >
                {PRIORITIES.map((priority) => (
                  <SelectItem key={priority} value={priority} text={priority} />
                ))}
              </Select>
            </div>
          ) : null}
        </>
      ) : null}
    </>
  );
}

export function CheckInAction({
  target,
  availableQueues,
  protocolProcedures = [],
  returnTo,
  offsetInlineEnd = '0px',
  onClose,
  onDirtyChange,
}: {
  target: CheckInTarget;
  availableQueues: CheckInQueueOption[];
  protocolProcedures?: CheckInProtocolProcedure[];
  returnTo?: string;
  offsetInlineEnd?: string;
  onClose: () => void;
  /** Reports dirty state so the parent can guard workspace switches. */
  onDirtyChange?: (dirty: boolean) => void;
}): ReactNode {
  const form = useCheckInForm(target, returnTo);
  const [confirmedDetails, setConfirmedDetails] = useState<string | null>(null);
  const [departmentChoiceMade, setDepartmentChoiceMade] = useState(false);
  const [queueChoiceMade, setQueueChoiceMade] = useState(false);
  const [protocolChoiceMade, setProtocolChoiceMade] = useState(false);
  const selectedProtocolProcedure = protocolProcedures.find(
    (procedure) => procedure.definitionId === form.data.protocol_procedure_definition_id,
  );
  const selectedQueue = availableQueues.find((queue) => queue.id === form.data.queue_id);
  const selectedDepartment = form.departments.find((department) => department.id === form.data.department_id);
  const selectedUnit = form.units.find((unit) => unit.id === form.data.unit_id);
  const destinationQueueName = selectedQueue?.name ?? selectedProtocolProcedure?.queueName;
  const encounterType = ENCOUNTER_TYPES.find((type) => type === form.data.encounter_type);
  const confirmationKey = JSON.stringify([
    form.data.patient_id,
    form.data.appointment_id,
    form.data.encounter_type,
    form.data.department_id,
    form.data.unit_id,
    form.data.queue_id,
    form.data.priority,
    form.data.protocol_procedure_definition_id,
    form.data.protocol_procedure_indication,
  ]);
  const destinationConfirmed = confirmedDetails === confirmationKey;
  const departmentChoiceRequired = form.data.encounter_type !== ''
    && !selectedProtocolProcedure
    && form.canRoute
    && form.departments.length > 0;
  const queueChoiceRequired = form.data.encounter_type !== ''
    && form.canPlaceTicket
    && availableQueues.length > 0
    && (!form.canRoute || form.departmentId !== '')
    && !selectedProtocolProcedure
    && availableQueues.some((queue) => queue.departmentId === null || queue.departmentId === form.departmentId);
  const protocolChoiceRequired = form.data.encounter_type === 'PROCEDURE' && protocolProcedures.length > 0;
  const reviewReady = form.data.encounter_type !== ''
    && !form.departmentsLoading
    && (!departmentChoiceRequired || departmentChoiceMade)
    && (!queueChoiceRequired || queueChoiceMade)
    && (!protocolChoiceRequired || protocolChoiceMade);

  useEffect(() => {
    setConfirmedDetails(null);
  }, [confirmationKey]);

  useEffect(() => {
    onDirtyChange?.(form.isDirty);
  }, [form.isDirty, onDirtyChange]);

  function submit(event: FormEvent): void {
    event.preventDefault();
    if (!destinationConfirmed) return;
    form.post(routes.workspaces.receptionCheckIn, { preserveScroll: true });
  }

  return (
    <>
      <DockedPanel
        heading="Check in patient"
        primaryLabel={form.processing ? 'Checking in…' : 'Check in patient'}
        primaryDisabled={form.processing || !reviewReady || !destinationConfirmed}
        offsetInlineEnd={offsetInlineEnd}
        onClose={onClose}
        onSubmit={submit}
      >
        <FormErrors errors={form.errors} fields={[
          'patient_id', 'appointment_id', 'encounter_type', 'department_id', 'unit_id',
          'queue_id', 'priority', 'protocol_procedure_definition_id',
          'protocol_procedure_indication', 'return_to',
        ]} />
        <CheckInFields
          form={form}
          target={target}
          availableQueues={availableQueues}
          protocolProcedures={protocolProcedures}
          onDepartmentChoice={() => {
            setDepartmentChoiceMade(true);
            setQueueChoiceMade(false);
          }}
          onQueueChoice={() => setQueueChoiceMade(true)}
          onProtocolChoice={() => setProtocolChoiceMade(true)}
          onEncounterTypeChange={() => {
            setDepartmentChoiceMade(false);
            setQueueChoiceMade(false);
            setProtocolChoiceMade(false);
            setConfirmedDetails(null);
          }}
        />
        {reviewReady ? (
          <section className="front-desk-check-in-preview" aria-label="Check-in preview">
            <h3>Review before check-in</h3>
            <dl className="front-desk-check-in-preview__details">
              <div>
                <dt>Visit</dt>
                <dd>{encounterType ? ENCOUNTER_TYPE_LABELS[encounterType] : form.data.encounter_type}</dd>
              </div>
              <div>
                <dt>Department</dt>
                <dd>{selectedDepartment?.name ?? 'Not selected'}</dd>
              </div>
              <div>
                <dt>Unit</dt>
                <dd>{selectedUnit?.name ?? 'Not selected'}</dd>
              </div>
              <div>
                <dt>Destination</dt>
                <dd>{destinationQueueName ?? 'No queue placement'}</dd>
              </div>
              {form.queueChosen ? (
                <div>
                  <dt>Priority</dt>
                  <dd>{form.data.priority}</dd>
                </div>
              ) : null}
              {selectedQueue?.roomNames?.length ? (
                <div>
                  <dt>Available locations</dt>
                  <dd>{selectedQueue.roomNames.join(', ')}</dd>
                </div>
              ) : null}
              {selectedProtocolProcedure ? (
                <div>
                  <dt>Procedure</dt>
                  <dd>{selectedProtocolProcedure.name} ({selectedProtocolProcedure.code})</dd>
                </div>
              ) : null}
            </dl>
          </section>
        ) : null}
        {reviewReady ? (
          <Checkbox
            id="check-in-confirm-destination"
            labelText="I’ve reviewed these details and confirm check-in."
            checked={destinationConfirmed}
            onChange={(_, { checked }) => setConfirmedDetails(checked ? confirmationKey : null)}
          />
        ) : null}
        {form.processing ? <InlineLoading description="Checking in" /> : null}
      </DockedPanel>
    </>
  );
}
