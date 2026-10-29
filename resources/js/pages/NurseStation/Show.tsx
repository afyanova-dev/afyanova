import { router, useForm } from '@inertiajs/react';
import {
  Button,
  Form,
  InlineNotification,
  Select,
  SelectItem,
  Tag,
  TextArea,
  TextInput,
} from '@carbon/react';
import { useState, type FormEvent, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import { FormErrors } from '@/components/ui';
import { AppLayout } from '@/layouts/AppLayout';
import { QueuePatientBanner } from '@/features/clinical/QueuePatientBanner';
import type { NurseStationShowProps, NurseStationVitals } from '@/features/nurse-station/types';
import { Checkmark, Send } from '@/icons';

type VitalsForm = {
  patient_id: string;
  temperature: string;
  heart_rate: string;
  respiratory_rate: string;
  systolic_bp: string;
  diastolic_bp: string;
  oxygen_saturation: string;
  weight: string;
  height: string;
  pain_score: string;
  vitals_error: string;
};

type TriageForm = {
  patient_id: string;
  chief_complaint: string;
  triage_priority: string;
  acuity: string;
  consciousness: string;
  triage_error: string;
};

type HandoffForm = {
  destination_queue_id: string;
  reason: string;
  handoff_error: string;
};

function formatDate(value: string | null): string {
  if (!value) return 'Not recorded';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(date);
}

function measurement(value: number | null, unit = ''): string {
  return value === null ? '—' : `${value}${unit}`;
}

function VitalsSummary({ observation }: { observation: NurseStationVitals }): ReactNode {
  const measurements = [
    ['Temperature', measurement(observation.temperature, ' °C')],
    ['Heart rate', measurement(observation.heartRate, ' bpm')],
    ['Respiratory rate', measurement(observation.respiratoryRate, ' /min')],
    ['Blood pressure', observation.systolicBp === null || observation.diastolicBp === null
      ? '—'
      : `${observation.systolicBp}/${observation.diastolicBp} mmHg`],
    ['Oxygen saturation', measurement(observation.oxygenSaturation, '%')],
    ['Weight', measurement(observation.weight, ' kg')],
    ['Height', measurement(observation.height, ' cm')],
    ['Pain score', measurement(observation.painScore, '/10')],
  ];

  return (
    <div style={{ paddingBlock: '0.75rem' }}>
      <p style={{ margin: '0 0 0.6rem' }}>
        {formatDate(observation.occurredAt)} · {observation.authoredContext ?? 'Clinical'}
      </p>
      <div className="nurse-station-vitals__measurements">
        {measurements.map(([label, value]) => (
          <div key={label}>
            <div className="nurse-station-vitals__label">{label}</div>
            <strong className="nurse-station-vitals__value">{value}</strong>
          </div>
        ))}
      </div>
    </div>
  );
}

export default function NurseStationShow(props: NurseStationShowProps): ReactNode {
  const [startingService, setStartingService] = useState(false);
  const [startServiceError, setStartServiceError] = useState<string | null>(null);
  const [startServiceSuccess, setStartServiceSuccess] = useState<string | null>(null);
  const [triageSuccess, setTriageSuccess] = useState<string | null>(null);
  const vitalsForm = useForm<VitalsForm>({
    patient_id: props.patient.patientId,
    temperature: '',
    heart_rate: '',
    respiratory_rate: '',
    systolic_bp: '',
    diastolic_bp: '',
    oxygen_saturation: '',
    weight: '',
    height: '',
    pain_score: '',
    vitals_error: '',
  });
  const triageForm = useForm<TriageForm>({
    patient_id: props.patient.patientId,
    chief_complaint: '',
    triage_priority: 'NORMAL',
    acuity: '',
    consciousness: '',
    triage_error: '',
  });
  const handoffForm = useForm<HandoffForm>({
    destination_queue_id: props.consultationQueues[0]?.id ?? '',
    reason: 'Nursing assessment completed',
    handoff_error: '',
  });

  function saveVitals(event: FormEvent): void {
    event.preventDefault();
    vitalsForm.post(routes.workspaces.encounterVitals(props.encounter.encounterId), {
      preserveScroll: true,
      onSuccess: () => vitalsForm.reset(
        'temperature', 'heart_rate', 'respiratory_rate', 'systolic_bp',
        'diastolic_bp', 'oxygen_saturation', 'weight', 'height', 'pain_score',
      ),
    });
  }

  function saveTriage(event: FormEvent): void {
    event.preventDefault();
    setTriageSuccess(null);
    triageForm.post(routes.workspaces.encounterTriage(props.encounter.encounterId), {
      preserveScroll: true,
      onSuccess: () => {
        triageForm.reset('chief_complaint', 'acuity', 'consciousness');
        setTriageSuccess('Triage assessment recorded.');
      },
    });
  }

  function handoffToConsultation(event: FormEvent): void {
    event.preventDefault();
    handoffForm.post(routes.workspaces.nurseStationHandoff(props.entry.id));
  }

  function confirmPatientPresent(): void {
    setStartServiceError(null);
    setStartServiceSuccess(null);
    setStartingService(true);
    router.post(
      routes.workspaces.nurseStationStart(props.entry.id),
      { return_to: routes.workspaces.nurseStationEntry(props.entry.id) },
      {
        preserveScroll: true,
        onError: (errors) => setStartServiceError(
          errors.start ?? 'Triage service could not be started. Confirm the patient was called and try again.',
        ),
        onSuccess: () => setStartServiceSuccess(
          `Patient presence confirmed. Triage assessment started for ticket ${props.entry.ticketNumber}.`,
        ),
        onFinish: () => setStartingService(false),
      },
    );
  }

  return (
    <AppLayout>
      <QueuePatientBanner
        workflow="nursing"
        patient={props.patient}
        entry={props.entry}
        queueNavigation={props.queueNavigation}
        queueHref={routes.workspaces.nurseStation}
        queueLabel="Open assessment queue"
        entryHref={routes.workspaces.nurseStationEntry}
        chartHref={routes.patient.chart(props.patient.patientId)}
      />
      {props.entry.status === 'WAITING' ? (
        <InlineNotification
          className="nurse-station-status-notification"
          kind="info"
          lowContrast
          hideCloseButton
          title="Waiting to be called"
          subtitle="This patient is in the triage queue but has not yet been called to the room. Review the chart if needed; do not begin assessment until the patient arrives."
          style={{ marginBlockEnd: '1rem' }}
        />
      ) : null}
      {props.entry.status === 'CALLED' ? (
        <InlineNotification
          className="nurse-station-status-notification"
          kind="warning"
          lowContrast
          hideCloseButton
          title="Confirm the patient is present"
          subtitle="The patient has been called to the room. Confirm they have arrived before starting triage assessment."
          style={{ marginBlockEnd: '1rem' }}
        />
      ) : null}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 380px), 1fr))', gap: '1rem' }}>
        <section
          aria-labelledby="nursing-context-heading"
          style={{
            display: 'grid',
            alignContent: 'start',
            gap: '1.25rem',
            padding: '1rem',
          }}
        >
          <div>
            <h2 id="nursing-context-heading" style={{ margin: '0 0 0.75rem' }}>Visit context</h2>
            <dl style={{ display: 'grid', gap: '0.625rem', margin: 0 }}>
              <div style={{ display: 'grid', gridTemplateColumns: '7rem minmax(0, 1fr)', gap: '0.75rem' }}>
                <dt>Ticket</dt>
                <dd style={{ display: 'flex', alignItems: 'center', flexWrap: 'wrap', gap: '0.375rem', margin: 0 }}>
                  <Tag type="blue">{props.entry.ticketNumber}</Tag>
                  <span>{props.entry.queue}</span>
                </dd>
              </div>
              <div style={{ display: 'grid', gridTemplateColumns: '7rem minmax(0, 1fr)', gap: '0.75rem' }}>
                <dt>Queue status</dt>
                <dd style={{ margin: 0 }}>
                  <Tag type={props.entry.status === 'IN_SERVICE' ? 'green' : props.entry.status === 'CALLED' ? 'blue' : 'gray'}>
                    {props.entry.status.replaceAll('_', ' ')}
                  </Tag>
                </dd>
              </div>
              <div style={{ display: 'grid', gridTemplateColumns: '7rem minmax(0, 1fr)', gap: '0.75rem' }}>
                <dt>Encounter</dt>
                <dd style={{ display: 'flex', alignItems: 'center', flexWrap: 'wrap', gap: '0.375rem', margin: 0 }}>
                  <span>{props.encounter.type.replaceAll('_', ' ')}</span>
                  <Tag type="gray">{props.encounter.status}</Tag>
                </dd>
              </div>
              <div style={{ display: 'grid', gridTemplateColumns: '7rem minmax(0, 1fr)', gap: '0.75rem' }}>
                <dt>Patient status</dt>
                <dd style={{ margin: 0 }}><Tag type="gray">{props.patient.status}</Tag></dd>
              </div>
            </dl>
          </div>

          <section aria-labelledby="current-triage-heading" style={{ paddingTop: '1rem' }}>
            <h3 id="current-triage-heading" style={{ margin: '0 0 0.75rem' }}>Current triage</h3>
            {props.triage ? (
              <div style={{ display: 'grid', gap: '0.5rem' }}>
                <div>
                  <Tag type={props.triage.priority === 'EMERGENCY' ? 'red' : props.triage.priority === 'URGENT' ? 'purple' : 'blue'}>
                    {props.triage.priority}
                  </Tag>
                </div>
                <p style={{ margin: 0 }}>{props.triage.chiefComplaint}</p>
                {(props.triage.acuity || props.triage.consciousness) ? (
                  <dl style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(9rem, 1fr))', gap: '0.75rem 1rem', margin: 0 }}>
                    {props.triage.acuity ? (
                      <div>
                        <dt>Acuity</dt>
                        <dd style={{ margin: '0.2rem 0 0' }}>{props.triage.acuity}</dd>
                      </div>
                    ) : null}
                    {props.triage.consciousness ? (
                      <div>
                        <dt>Consciousness</dt>
                        <dd style={{ margin: '0.2rem 0 0' }}>{props.triage.consciousness}</dd>
                      </div>
                    ) : null}
                  </dl>
                ) : null}
                <p style={{ margin: 0,  }}>
                  Recorded {formatDate(props.triage.occurredAt)}
                </p>
              </div>
            ) : <p style={{ margin: 0 }}>No triage assessment recorded.</p>}
          </section>

          <section aria-labelledby="recent-vitals-heading" style={{ paddingTop: '1rem' }}>
            <h3 id="recent-vitals-heading" style={{ margin: '0 0 0.5rem' }}>Recent vitals</h3>
            {props.vitals.length === 0
              ? <p style={{ margin: 0 }}>No vital signs recorded.</p>
              : props.vitals.map((observation, index) => <VitalsSummary key={`${observation.occurredAt}-${index}`} observation={observation} />)}
          </section>
        </section>

        <div style={{ display: 'grid', alignContent: 'start', gap: '1rem' }}>
          {props.canRecordVitals ? (
            <section
              aria-labelledby="record-vitals-heading"
              style={{
                padding: '1rem',
              }}
            >
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '1rem' }}>
                <div>
                  <h2 id="record-vitals-heading" style={{ marginTop: 0 }}>Record vital signs</h2>
                  {props.entry.status !== 'IN_SERVICE' ? (
                    <p role="note" style={{ marginBlock: 0 }}>Available after the patient is called and confirmed present in the triage room.</p>
                  ) : null}
                </div>
                {props.entry.status === 'CALLED' && props.canStartService ? (
                  <Button
                    type="button"
                    size="sm"
                    disabled={startingService}
                    onClick={confirmPatientPresent}
                  >
                    {startingService ? 'Starting…' : 'Confirm patient present'}
                  </Button>
                ) : null}
              </div>
              {startServiceSuccess ? <p role="status" aria-live="polite">{startServiceSuccess}</p> : null}
              {startServiceError ? <p role="alert">{startServiceError}</p> : null}
              <FormErrors errors={vitalsForm.errors} fields={[
                'patient_id', 'temperature', 'heart_rate', 'respiratory_rate',
                'systolic_bp', 'diastolic_bp', 'oxygen_saturation', 'weight',
                'height', 'pain_score',
              ]} />
              <Form onSubmit={saveVitals}>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(140px, 1fr))', gap: '0.75rem' }}>
                  {([
                    ['temperature', 'Temperature (°C)'],
                    ['heart_rate', 'Heart rate (bpm)'],
                    ['respiratory_rate', 'Respiratory rate (/min)'],
                    ['systolic_bp', 'Systolic BP (mmHg)'],
                    ['diastolic_bp', 'Diastolic BP (mmHg)'],
                    ['oxygen_saturation', 'Oxygen saturation (%)'],
                    ['weight', 'Weight (kg)'],
                    ['height', 'Height (cm)'],
                    ['pain_score', 'Pain score (0–10)'],
                  ] as const).map(([field, label]) => (
                    <TextInput
                      key={field}
                      id={`vitals-${field}`}
                      labelText={label}
                      type="number"
                      step={field === 'temperature' || field === 'weight' || field === 'height' ? '0.1' : '1'}
                      value={vitalsForm.data[field]}
                      onChange={(event) => vitalsForm.setData(field, event.target.value)}
                      disabled={props.entry.status !== 'IN_SERVICE'}
                      invalid={Boolean(vitalsForm.errors[field])}
                      invalidText={vitalsForm.errors[field]}
                    />
                  ))}
                </div>
                <Button size="lg" renderIcon={Checkmark} type="submit" disabled={props.entry.status !== 'IN_SERVICE' || vitalsForm.processing} style={{ marginTop: '1rem' }}>
                  {vitalsForm.processing ? 'Saving…' : 'Save vital signs'}
                </Button>
              </Form>
            </section>
          ) : null}

          {props.canRecordTriage ? (
            <section
              aria-labelledby="record-triage-heading"
              aria-disabled={props.entry.status !== 'IN_SERVICE'}
              style={{
                padding: '1rem',
              }}
            >
              <h2 id="record-triage-heading" style={{ marginTop: 0 }}>Record triage assessment</h2>
              {props.entry.status !== 'IN_SERVICE' ? (
                <p role="note">Available after the patient is called and confirmed present in the triage room.</p>
              ) : null}
              {triageSuccess ? <p role="status" aria-live="polite">{triageSuccess}</p> : null}
              <FormErrors errors={triageForm.errors} fields={[
                'patient_id', 'chief_complaint', 'triage_priority', 'acuity', 'consciousness',
              ]} />
              <Form onSubmit={saveTriage}>
                <TextArea
                  id="triage-chief-complaint"
                  labelText="Chief complaint"
                  rows={3}
                  value={triageForm.data.chief_complaint}
                  onChange={(event) => triageForm.setData('chief_complaint', event.target.value)}
                  disabled={props.entry.status !== 'IN_SERVICE'}
                  invalid={Boolean(triageForm.errors.chief_complaint)}
                  invalidText={triageForm.errors.chief_complaint}
                  required
                />
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: '0.75rem', marginTop: '1rem' }}>
                  <Select
                    id="triage-priority"
                    labelText="Assigned triage priority"
                    value={triageForm.data.triage_priority}
                    onChange={(event) => triageForm.setData('triage_priority', event.currentTarget.value)}
                    disabled={props.entry.status !== 'IN_SERVICE'}
                    invalid={Boolean(triageForm.errors.triage_priority)}
                    invalidText={triageForm.errors.triage_priority}
                  >
                    {['EMERGENCY', 'URGENT', 'HIGH', 'NORMAL', 'LOW'].map((priority) => (
                      <SelectItem key={priority} value={priority} text={priority} />
                    ))}
                  </Select>
                  <TextInput
                    id="triage-acuity"
                    labelText="Acuity (optional; facility protocol)"
                    value={triageForm.data.acuity}
                    onChange={(event) => triageForm.setData('acuity', event.target.value)}
                    disabled={props.entry.status !== 'IN_SERVICE'}
                    invalid={Boolean(triageForm.errors.acuity)}
                    invalidText={triageForm.errors.acuity}
                  />
                  <TextInput
                    id="triage-consciousness"
                    labelText="Consciousness (optional)"
                    value={triageForm.data.consciousness}
                    onChange={(event) => triageForm.setData('consciousness', event.target.value)}
                    disabled={props.entry.status !== 'IN_SERVICE'}
                    invalid={Boolean(triageForm.errors.consciousness)}
                    invalidText={triageForm.errors.consciousness}
                  />
                </div>
                <Button size="lg" renderIcon={Checkmark} type="submit" disabled={props.entry.status !== 'IN_SERVICE' || triageForm.processing} style={{ marginTop: '1rem' }}>
                  {triageForm.processing ? 'Saving…' : 'Save triage assessment'}
                </Button>
              </Form>
            </section>
          ) : null}

          {props.canHandoff ? (
            <section style={{ padding: '1rem' }}>
              <h2 style={{ marginTop: 0 }}>Send to consultation</h2>
              <p>
                This transfers the existing encounter and the triage-assigned queue priority. Triage priority is not recalculated here.
              </p>
              <Form onSubmit={handoffToConsultation}>
                <FormErrors errors={handoffForm.errors} fields={['destination_queue_id', 'reason']} />
                <Select
                  id="nurse-handoff-destination"
                  labelText="Consultation queue"
                  value={handoffForm.data.destination_queue_id}
                  onChange={(event) => handoffForm.setData('destination_queue_id', event.currentTarget.value)}
                  invalid={Boolean(handoffForm.errors.destination_queue_id)}
                  invalidText={handoffForm.errors.destination_queue_id}
                  disabled={!props.triage || props.consultationQueues.length === 0}
                >
                  <SelectItem value="" text="Choose a consultation queue" />
                  {props.consultationQueues.map((queue) => (
                    <SelectItem key={queue.id} value={queue.id} text={queue.name} />
                  ))}
                </Select>
                <TextArea
                  id="nurse-handoff-reason"
                  labelText="Handoff note"
                  rows={2}
                  value={handoffForm.data.reason}
                  onChange={(event) => handoffForm.setData('reason', event.target.value)}
                  invalid={Boolean(handoffForm.errors.reason)}
                  invalidText={handoffForm.errors.reason}
                  required
                />
                {!props.triage ? <p>Record a triage assessment before consultation handoff.</p> : null}
                {props.triage && props.consultationQueues.length === 0
                  ? <p>No active consultation queue is configured for this facility.</p>
                  : null}
                <Button size="lg" renderIcon={Send}
                  type="submit"
                  disabled={!props.triage || props.consultationQueues.length === 0 || handoffForm.processing || !handoffForm.data.destination_queue_id}
                  style={{ marginTop: '1rem' }}
                >
                  {handoffForm.processing ? 'Sending…' : 'Send to consultation'}
                </Button>
              </Form>
            </section>
          ) : null}
        </div>
      </div>
    </AppLayout>
  );
}