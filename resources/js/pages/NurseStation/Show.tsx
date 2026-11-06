import { router, useForm } from '@inertiajs/react';
import {
  ActionableNotification,
  Button,
  Form,
  InlineNotification,
  Select,
  SelectItem,
  Tab,
  TabListVertical,
  TabPanel,
  TabPanels,
  TabsVertical,
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
import { Checkmark, HealthCross, Send, Task } from '@/icons';

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

type VitalInputField =
  | 'temperature'
  | 'heart_rate'
  | 'respiratory_rate'
  | 'oxygen_saturation'
  | 'weight'
  | 'height'
  | 'pain_score';

type NurseWorkspaceTab = 'vitals' | 'triage' | 'handoff';

function availableWorkspaceTabs(props: NurseStationShowProps): NurseWorkspaceTab[] {
  return [
    ...(props.canRecordVitals ? ['vitals' as const] : []),
    ...(props.canRecordTriage ? ['triage' as const] : []),
    ...(props.canHandoff ? ['handoff' as const] : []),
  ];
}

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
    ['Temperature', measurement(observation.temperature), '°C'],
    ['Pulse', measurement(observation.heartRate), 'bpm'],
    ['Blood pressure', observation.systolicBp === null || observation.diastolicBp === null
      ? '—'
      : `${observation.systolicBp}/${observation.diastolicBp}`, 'mmHg'],
    ['SpO₂', measurement(observation.oxygenSaturation), '%'],
    ['Resp. rate', measurement(observation.respiratoryRate), '/min'],
    ['Pain', measurement(observation.painScore), '/10'],
    ['Weight', measurement(observation.weight), 'kg'],
    ['Height', measurement(observation.height), 'cm'],
  ];

  return (
    <div className="nurse-station-vitals">
      <p className="nurse-station-vitals__meta">
        {formatDate(observation.occurredAt)} · {observation.authoredContext ?? 'Clinical'}
      </p>
      <dl className="nurse-station-vitals__measurements">
        {measurements.map(([label, value, unit]) => (
          <div key={label}>
            <dt>{label}</dt>
            <dd>{value} <small>{unit}</small></dd>
          </div>
        ))}
      </dl>
    </div>
  );
}

export default function NurseStationShow(props: NurseStationShowProps): ReactNode {
  const [startingService, setStartingService] = useState(false);
  const [startServiceError, setStartServiceError] = useState<string | null>(null);
  const [startServiceSuccess, setStartServiceSuccess] = useState<string | null>(null);
  const [callingPatient, setCallingPatient] = useState(false);
  const [callError, setCallError] = useState<string | null>(null);
  const [selectedCallRoom, setSelectedCallRoom] = useState(() => props.callRooms[0]?.id ?? '');
  const workspaceTabs = availableWorkspaceTabs(props);
  const [selectedWorkspaceTab, setSelectedWorkspaceTab] = useState<NurseWorkspaceTab>(
    () => workspaceTabs[0] ?? 'vitals',
  );
  const activeWorkspaceTab = workspaceTabs.includes(selectedWorkspaceTab)
    ? selectedWorkspaceTab
    : workspaceTabs[0];
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

  function renderVitalInput(field: VitalInputField, label: string): ReactNode {
    return (
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
    );
  }

  function saveVitals(event: FormEvent): void {
    event.preventDefault();
    vitalsForm.post(routes.workspaces.encounterVitals(props.encounter.encounterId), {
      preserveState: true,
      preserveScroll: true,
      onSuccess: () => vitalsForm.reset(
        'temperature', 'heart_rate', 'respiratory_rate', 'systolic_bp',
        'diastolic_bp', 'oxygen_saturation', 'weight', 'height', 'pain_score',
      ),
    });
  }

  function saveTriage(event: FormEvent): void {
    event.preventDefault();
    triageForm.post(routes.workspaces.encounterTriage(props.encounter.encounterId), {
      preserveState: true,
      preserveScroll: true,
      onSuccess: () => {
        triageForm.reset('chief_complaint', 'acuity', 'consciousness');
      },
    });
  }

  function handoffToConsultation(event: FormEvent): void {
    event.preventDefault();
    handoffForm.post(routes.workspaces.nurseStationHandoff(props.entry.id));
  }

  function callPatient(): void {
    if (callingPatient) {
      return;
    }

    setCallError(null);
    setCallingPatient(true);
    const returnTo = typeof window === 'undefined'
      ? routes.workspaces.nurseStation
      : `${window.location.pathname}${window.location.search}`;

    router.post(
      routes.workspaces.ticketCall(props.entry.id),
      { room: selectedCallRoom || null, return_to: returnTo },
      {
        preserveScroll: true,
        onError: (errors) => setCallError(
          errors.call ?? errors.room ?? 'The patient could not be called. Check the queue and try again.',
        ),
        onFinish: () => setCallingPatient(false),
      },
    );
  }

  function confirmPatientPresent(): void {
    if (startingService) {
      return;
    }

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
      <div className={`clinical-entry-shell${props.entry.status === 'IN_SERVICE' ? ' clinical-entry-shell--active' : ''}`}>
        <QueuePatientBanner
          workflow="nursing"
          patient={props.patient}
          entry={props.entry}
          queueNavigation={props.queueNavigation}
          queueHref={routes.workspaces.nurseStation}
          queueLabel="Open assessment queue"
          entryHref={routes.workspaces.nurseStationEntry}
          encounter={{
            encounterType: props.encounter.type,
            status: props.encounter.status,
          }}
          chartHref={routes.patient.chart(props.patient.patientId)}
        />
        {props.entry.status === 'WAITING' && props.canCall ? (
          <ActionableNotification
            className="nurse-station-status-notification nurse-station-call-notification"
            kind="info"
            lowContrast
            inline
            role="status"
            hideCloseButton
            title="Waiting to be called"
            subtitle="This patient is in the triage queue but has not yet been called to the room. Call them now, then confirm they have arrived before starting triage assessment."
            actionButtonLabel={callingPatient ? 'Calling…' : 'Call patient'}
            onActionButtonClick={callPatient}
          >
            {props.callRooms.length > 1 ? (
              <Select
                id="nurse-station-call-room"
                className="nurse-station-call-notification__room"
                labelText="Call to"
                value={selectedCallRoom}
                onChange={(event) => setSelectedCallRoom(event.currentTarget.value)}
              >
                {props.callRooms.map((room) => (
                  <SelectItem key={room.id} value={room.id} text={`${room.name} (${room.code})`} />
                ))}
              </Select>
            ) : null}
            {callError ? (
              <p className="nurse-station-form__message nurse-station-form__message--error" role="alert">{callError}</p>
            ) : null}
          </ActionableNotification>
        ) : null}
        {props.entry.status === 'WAITING' && !props.canCall ? (
          <InlineNotification
            className="nurse-station-status-notification"
            kind="info"
            lowContrast
            hideCloseButton
            title="Waiting to be called"
            subtitle="This patient is in the triage queue but has not yet been called to the room. Review the chart if needed; do not begin assessment until the patient arrives."
          />
        ) : null}
        {props.entry.status === 'CALLED' ? (
          props.canStartService ? (
            <ActionableNotification
              className="nurse-station-status-notification"
              kind="warning"
              lowContrast
              inline
              role="status"
              hideCloseButton
              title="Confirm the patient is present"
              subtitle="The patient has been called to the room. Confirm they have arrived before starting triage assessment."
              actionButtonLabel={startingService ? 'Confirming…' : 'Confirm patient present'}
              onActionButtonClick={confirmPatientPresent}
            >
              {startServiceError ? (
                <p className="nurse-station-form__message nurse-station-form__message--error" role="alert">{startServiceError}</p>
              ) : null}
            </ActionableNotification>
          ) : (
            <InlineNotification
              className="nurse-station-status-notification"
              kind="warning"
              lowContrast
              hideCloseButton
              title="Confirm the patient is present"
              subtitle="The patient has been called to the room. Confirm they have arrived before starting triage assessment."
            />
          )
        ) : null}
        <div className="clinical-entry-workspace nurse-station-workspace">
          <aside className="clinical-entry-workspace__sidebar nurse-station-workspace__sidebar" aria-label="Visit context">
            <section
              className="afy-card nurse-station-panel nurse-station-context-card"
              aria-labelledby="nursing-context-heading"
            >
              <div className="nurse-station-context__heading">
                <h2 id="nursing-context-heading" className="nurse-station-panel__title">Visit context</h2>
                <Tag
                  size="sm"
                  type={props.entry.status === 'IN_SERVICE' ? 'green' : props.entry.status === 'CALLED' ? 'blue' : 'cool-gray'}
                >
                  {props.entry.status.replaceAll('_', ' ')}
                </Tag>
              </div>
              <div className="nurse-station-context__ticket">
                <span>Ticket {props.entry.ticketNumber}</span>
                <strong>{props.entry.queue}</strong>
              </div>
              <dl className="nurse-station-context__details">
                <div className="nurse-station-context__detail">
                  <dt>Encounter</dt>
                  <dd className="nurse-station-context__value">
                    <span>{props.encounter.type.replaceAll('_', ' ')}</span>
                    <Tag size="sm" type="cool-gray">{props.encounter.status}</Tag>
                  </dd>
                </div>
                <div className="nurse-station-context__detail">
                  <dt>Patient status</dt>
                  <dd className="nurse-station-context__value">
                    <Tag size="sm" type="cool-gray">{props.patient.status}</Tag>
                  </dd>
                </div>
              </dl>
            </section>

            <section
              className="afy-card nurse-station-panel"
              aria-labelledby="current-triage-heading"
            >
              <div className="nurse-station-summary__heading">
                <h2 id="current-triage-heading" className="nurse-station-panel__title">Current triage</h2>
                {props.triage ? (
                  <Tag
                    size="sm"
                    type={props.triage.priority === 'EMERGENCY' ? 'red' : props.triage.priority === 'URGENT' ? 'purple' : 'blue'}
                  >
                    {props.triage.priority === 'ROUTINE' ? 'Normal' : props.triage.priority}
                  </Tag>
                ) : null}
              </div>
              {props.triage ? (
                <div className="nurse-station-triage">
                  <p className="nurse-station-triage__complaint">{props.triage.chiefComplaint}</p>
                  {(props.triage.acuity || props.triage.consciousness) ? (
                    <dl className="nurse-station-triage__details">
                      {props.triage.acuity ? (
                        <div>
                          <dt>Acuity</dt>
                          <dd>{props.triage.acuity}</dd>
                        </div>
                      ) : null}
                      {props.triage.consciousness ? (
                        <div>
                          <dt>Consciousness</dt>
                          <dd>{props.triage.consciousness}</dd>
                        </div>
                      ) : null}
                    </dl>
                  ) : null}
                  <p className="nurse-station-triage__recorded">
                    Recorded {formatDate(props.triage.occurredAt)}
                  </p>
                </div>
              ) : <p className="nurse-station-panel__empty">No triage assessment recorded.</p>}
            </section>

            <section
              className="afy-card nurse-station-panel"
              aria-labelledby="recent-vitals-heading"
            >
              <div className="nurse-station-summary__heading">
                <h2 id="recent-vitals-heading" className="nurse-station-panel__title">Recent vitals</h2>
                {props.vitals.length > 0 ? <span className="nurse-station-vitals__count">{props.vitals.length} recorded</span> : null}
              </div>
              {props.vitals.length === 0
                ? <p className="nurse-station-panel__empty">No vital signs recorded.</p>
                : props.vitals.map((observation, index) => <VitalsSummary key={`${observation.occurredAt}-${index}`} observation={observation} />)}
            </section>
          </aside>

          <div className="clinical-entry-workspace__workbench nurse-station-workspace__workbench">
            {workspaceTabs.length > 0 ? (
              <section
                className="clinical-entry-workspace-navigation nurse-station-workspace-navigation"
                aria-label="Nursing assessment workspace"
              >
                <TabsVertical
                  selectedIndex={activeWorkspaceTab ? workspaceTabs.indexOf(activeWorkspaceTab) : 0}
                  onChange={({ selectedIndex }) => setSelectedWorkspaceTab(workspaceTabs[selectedIndex] ?? 'vitals')}
                >
                  <TabListVertical
                    aria-label="Nursing assessment workspace"
                    className="clinical-entry-workspace__vertical-tabs nurse-station-workspace__vertical-tabs"
                    size="md"
                  >
                    {props.canRecordVitals ? <Tab renderIcon={HealthCross}>Vital signs</Tab> : null}
                    {props.canRecordTriage ? <Tab renderIcon={Task}>Triage</Tab> : null}
                    {props.canHandoff ? <Tab renderIcon={Send}>Handoff</Tab> : null}
                  </TabListVertical>
                  <TabPanels>
                    {workspaceTabs.map((tab) => (
                      <TabPanel key={tab}>
                        {activeWorkspaceTab === tab ? (
                          <div className="clinical-entry-tab-content nurse-station-workspace__tab-content">
                            {tab === 'vitals' ? (
                              <section
                                className="afy-card nurse-station-panel"
                                aria-labelledby="record-vitals-heading"
                              >
                                <h2 id="record-vitals-heading" className="nurse-station-panel__title">Record vital signs</h2>
                                {props.entry.status !== 'IN_SERVICE' ? (
                                  <p className="nurse-station-panel__note" role="note">Available after the patient is called and confirmed present in the triage room.</p>
                                ) : null}
                                {startServiceSuccess ? <p className="nurse-station-form__message" role="status" aria-live="polite">{startServiceSuccess}</p> : null}
                                <FormErrors errors={vitalsForm.errors} fields={[
                                  'patient_id', 'temperature', 'heart_rate', 'respiratory_rate',
                                  'systolic_bp', 'diastolic_bp', 'oxygen_saturation', 'weight',
                                  'height', 'pain_score',
                                ]} />
                                <Form onSubmit={saveVitals}>
                                  <div className="nurse-station-form-grid nurse-station-form-grid--vitals">
                                    {([
                                      ['temperature', 'Temperature (°C)'],
                                      ['heart_rate', 'Heart rate (bpm)'],
                                      ['respiratory_rate', 'Respiratory rate (/min)'],
                                    ] as const).map(([field, label]) => renderVitalInput(field, label))}
                                    <fieldset
                                      className="nurse-station-blood-pressure"
                                      disabled={props.entry.status !== 'IN_SERVICE'}
                                    >
                                      <legend className="cds--label">Blood pressure (mmHg)</legend>
                                      <div className="nurse-station-blood-pressure__values">
                                        <TextInput
                                          id="vitals-systolic_bp"
                                          labelText="Systolic"
                                          hideLabel
                                          type="number"
                                          step="1"
                                          value={vitalsForm.data.systolic_bp}
                                          onChange={(event) => vitalsForm.setData('systolic_bp', event.target.value)}
                                          invalid={Boolean(vitalsForm.errors.systolic_bp)}
                                          invalidText={vitalsForm.errors.systolic_bp}
                                        />
                                        <span aria-hidden="true">/</span>
                                        <TextInput
                                          id="vitals-diastolic_bp"
                                          labelText="Diastolic"
                                          hideLabel
                                          type="number"
                                          step="1"
                                          value={vitalsForm.data.diastolic_bp}
                                          onChange={(event) => vitalsForm.setData('diastolic_bp', event.target.value)}
                                          invalid={Boolean(vitalsForm.errors.diastolic_bp)}
                                          invalidText={vitalsForm.errors.diastolic_bp}
                                        />
                                      </div>
                                    </fieldset>
                                    {([
                                      ['oxygen_saturation', 'Oxygen saturation (%)'],
                                      ['weight', 'Weight (kg)'],
                                      ['height', 'Height (cm)'],
                                      ['pain_score', 'Pain score (0–10)'],
                                    ] as const).map(([field, label]) => renderVitalInput(field, label))}
                                  </div>
                                  <Button className="nurse-station-form__submit" size="lg" renderIcon={Checkmark} type="submit" disabled={props.entry.status !== 'IN_SERVICE' || vitalsForm.processing}>
                                    {vitalsForm.processing ? 'Saving…' : 'Save vital signs'}
                                  </Button>
                                </Form>
                              </section>
                            ) : null}

                            {tab === 'triage' ? (
                              <section
                                className="afy-card nurse-station-panel"
                                aria-labelledby="record-triage-heading"
                                aria-disabled={props.entry.status !== 'IN_SERVICE'}
                              >
                                <h2 id="record-triage-heading" className="nurse-station-panel__title">Record triage assessment</h2>
                                {props.entry.status !== 'IN_SERVICE' ? (
                                  <p className="nurse-station-panel__note" role="note">Available after the patient is called and confirmed present in the triage room.</p>
                                ) : null}
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
                                  <div className="nurse-station-form-grid nurse-station-form-grid--triage">
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
                                  <Button className="nurse-station-form__submit" size="lg" renderIcon={Checkmark} type="submit" disabled={props.entry.status !== 'IN_SERVICE' || triageForm.processing}>
                                    {triageForm.processing ? 'Saving…' : 'Save triage assessment'}
                                  </Button>
                                </Form>
                              </section>
                            ) : null}

                            {tab === 'handoff' ? (
                              <section className="afy-card nurse-station-panel">
                                <h2 className="nurse-station-panel__title">Send to consultation</h2>
                                <p className="nurse-station-panel__description">
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
                                  {!props.triage ? <p className="nurse-station-panel__note">Record a triage assessment before consultation handoff.</p> : null}
                                  {props.triage && props.consultationQueues.length === 0
                                    ? <p className="nurse-station-panel__note">No active consultation queue is configured for this facility.</p>
                                    : null}
                                  <Button size="lg" renderIcon={Send}
                                    type="submit"
                                    disabled={!props.triage || props.consultationQueues.length === 0 || handoffForm.processing || !handoffForm.data.destination_queue_id}
                                    className="nurse-station-form__submit"
                                  >
                                    {handoffForm.processing ? 'Sending…' : 'Send to consultation'}
                                  </Button>
                                </Form>
                              </section>
                            ) : null}
                          </div>
                        ) : null}
                      </TabPanel>
                    ))}
                  </TabPanels>
                </TabsVertical>
              </section>
            ) : (
              <section className="afy-card nurse-station-panel">
                <h2 className="nurse-station-panel__title">Nursing assessment</h2>
                <p className="nurse-station-panel__description">No nursing assessment actions are available for this encounter.</p>
              </section>
            )}
          </div>
        </div>
      </div>
    </AppLayout>
  );
}