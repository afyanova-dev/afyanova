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
import type { ClinicianShowProps } from '@/features/clinician/types';
import { Checkmark, HealthCross, Play } from '@/icons';

type NoteForm = {
  patient_id: string;
  note_type: string;
  title: string;
  body: string;
  note_error: string;
};

type DiagnosisForm = {
  patient_id: string;
  description: string;
  code: string;
  code_system: string;
  clinical_status: string;
  diagnosis_error: string;
};

type PlanForm = {
  patient_id: string;
  plan_type: string;
  clinical_intent: string;
  goals: string;
  planned_actions: string;
  instructions: string;
  follow_up: string;
  plan_error: string;
};

type ProcedureForm = {
  patient_id: string;
  definition_id: string;
  indication: string;
};

type DispositionForm = {
  disposition: string;
  disposition_error: string;
};

function formatDate(value: string | null, includeTime = false): string {
  if (!value) return 'Not recorded';
  const date = new Date(value);
  return Number.isNaN(date.getTime())
    ? value
    : new Intl.DateTimeFormat(undefined, includeTime
      ? { dateStyle: 'medium', timeStyle: 'short' }
      : { dateStyle: 'medium' }).format(date);
}

export default function ClinicianShow(props: ClinicianShowProps): ReactNode {
  const [dispositionSaved, setDispositionSaved] = useState(false);
  const [encounterActionError, setEncounterActionError] = useState<string | null>(null);
  const [completing, setCompleting] = useState(false);
  const [authorizingProcedureId, setAuthorizingProcedureId] = useState<string | null>(null);
  const [procedureActionError, setProcedureActionError] = useState<string | null>(null);
  const noteForm = useForm<NoteForm>({
    patient_id: props.patient.patientId,
    note_type: 'CONSULTATION',
    title: '',
    body: '',
    note_error: '',
  });
  const diagnosisForm = useForm<DiagnosisForm>({
    patient_id: props.patient.patientId,
    description: '',
    code: '',
    code_system: '',
    clinical_status: 'WORKING',
    diagnosis_error: '',
  });
  const planForm = useForm<PlanForm>({
    patient_id: props.patient.patientId,
    plan_type: 'GENERAL',
    clinical_intent: '',
    goals: '',
    planned_actions: '',
    instructions: '',
    follow_up: '',
    plan_error: '',
  });
  const procedureForm = useForm<ProcedureForm>({
    patient_id: props.patient.patientId,
    definition_id: props.procedureDefinitions[0]?.definitionId ?? '',
    indication: '',
  });
  const dispositionForm = useForm<DispositionForm>({
    disposition: 'FOLLOW_UP',
    disposition_error: '',
  });

  const active = props.encounter.status === 'ACTIVE';
  const returnTo = typeof window === 'undefined'
    ? routes.workspaces.clinician
    : `${window.location.pathname}${window.location.search}`;

  function startEncounter(): void {
    setEncounterActionError(null);
    router.post(routes.workspaces.receptionStart(props.encounter.encounterId), { return_to: returnTo }, {
      onError: (errors) => setEncounterActionError(errors.start ?? 'The encounter could not be started.'),
    });
  }

  function createNote(event: FormEvent): void {
    event.preventDefault();
    noteForm.post(routes.workspaces.encounterNote(props.encounter.encounterId), {
      preserveScroll: true,
      onSuccess: () => noteForm.reset('title', 'body'),
    });
  }

  function recordDiagnosis(event: FormEvent): void {
    event.preventDefault();
    diagnosisForm.post(routes.workspaces.encounterDiagnosis(props.encounter.encounterId), {
      preserveScroll: true,
      onSuccess: () => diagnosisForm.reset('description', 'code', 'code_system'),
    });
  }

  function createPlan(event: FormEvent): void {
    event.preventDefault();
    planForm.post(routes.workspaces.encounterPlan(props.encounter.encounterId), {
      preserveScroll: true,
      onSuccess: () => planForm.reset('clinical_intent', 'goals', 'planned_actions', 'instructions', 'follow_up'),
    });
  }

  function requestProcedure(event: FormEvent): void {
    event.preventDefault();
    procedureForm.post(routes.workspaces.encounterProcedureRequest(props.encounter.encounterId), {
      preserveScroll: true,
      onSuccess: () => procedureForm.reset('indication'),
    });
  }

  function authorizeProcedure(procedureId: string): void {
    setProcedureActionError(null);
    setAuthorizingProcedureId(procedureId);
    router.post(
      routes.workspaces.procedureAuthorize(props.encounter.encounterId, procedureId),
      {},
      {
        preserveScroll: true,
        onError: (errors) => setProcedureActionError(
          errors.authorize_error ?? 'The procedure could not be authorized.',
        ),
        onFinish: () => setAuthorizingProcedureId(null),
      },
    );
  }

  function saveDisposition(event: FormEvent): void {
    event.preventDefault();
    dispositionForm.post(routes.workspaces.receptionDisposition(props.encounter.encounterId), {
      preserveScroll: true,
      onSuccess: () => setDispositionSaved(true),
    });
  }

  function completeEncounter(): void {
    setEncounterActionError(null);
    setCompleting(true);
    router.post(routes.workspaces.receptionComplete(props.encounter.encounterId), { return_to: routes.workspaces.clinician }, {
      onError: (errors) => setEncounterActionError(errors.complete ?? 'The encounter could not be completed.'),
      onFinish: () => setCompleting(false),
    });
  }

  return (
    <AppLayout>
      <QueuePatientBanner
        workflow="consultation"
        patient={props.patient}
        entry={props.entry}
        queueNavigation={props.queueNavigation}
        queueHref={routes.workspaces.clinician}
        queueLabel="Open consultation queue"
        entryHref={routes.workspaces.clinicianEntry}
        encounter={props.encounter}
        chartHref={routes.patient.chart(props.patient.patientId)}
      />
      {encounterActionError ? <InlineNotification kind="error" title={encounterActionError} lowContrast /> : null}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 380px), 1fr))', gap: '1rem' }}>
        <section
          aria-labelledby="clinician-context-heading"
          style={{ padding: '1rem', alignSelf: 'start' }}
        >
          <h2 id="clinician-context-heading" style={{ marginTop: 0 }}>Encounter context</h2>
          <p><strong>Queue ticket:</strong> {props.entry.ticketNumber} · {props.entry.queue}</p>
          <p><strong>Queue priority:</strong> {props.entry.priority}</p>
          <p><strong>Encounter:</strong> {props.encounter.type.replaceAll('_', ' ')} · {props.encounter.status}</p>
          <p><strong>Patient status:</strong> {props.patient.status}</p>
          {props.triage ? (
            <>
              <h3 style={{ marginTop: '1.5rem' }}>Nursing handoff</h3>
              <Tag type={props.triage.priority === 'EMERGENCY' ? 'red' : props.triage.priority === 'URGENT' ? 'purple' : 'blue'}>
                {props.triage.priority}
              </Tag>
              <p>{props.triage.chiefComplaint}</p>
              {props.triage.acuity ? <p><strong>Acuity:</strong> {props.triage.acuity}</p> : null}
              {props.triage.consciousness ? <p><strong>Consciousness:</strong> {props.triage.consciousness}</p> : null}
              <p>Assessed {formatDate(props.triage.occurredAt, true)}</p>
            </>
          ) : <p style={{ marginTop: '1.5rem' }}>No triage assessment is linked to this encounter.</p>}

          <h3 style={{ marginTop: '1.5rem' }}>Encounter vital signs</h3>
          {props.vitals.length === 0 ? <p>No vital signs are recorded.</p> : props.vitals.map((vital, index) => (
            <div key={`${vital.occurredAt}-${index}`} style={{ paddingBlock: '0.75rem' }}>
              <p style={{ marginTop: 0 }}>{formatDate(vital.occurredAt, true)}</p>
              <p>
                Temp {vital.temperature ?? '—'} °C · Pulse {vital.heartRate ?? '—'} bpm · RR {vital.respiratoryRate ?? '—'} /min
              </p>
              <p>
                BP {vital.systolicBp ?? '—'}/{vital.diastolicBp ?? '—'} mmHg · SpO₂ {vital.oxygenSaturation ?? '—'}% · Pain {vital.painScore ?? '—'}/10
              </p>
            </div>
          ))}
        </section>

        <div style={{ display: 'grid', alignContent: 'start', gap: '1rem' }}>
          {!active ? (
            <section style={{ padding: '1rem' }}>
              <h2 style={{ marginTop: 0 }}>Begin consultation</h2>
              <p>Starting the encounter is separate from queue service; this changes the visit from ARRIVED to ACTIVE.</p>
              {props.canStartEncounter && props.encounter.status === 'ARRIVED' ? (
                <Button size="lg" renderIcon={Play} onClick={startEncounter}>Start encounter</Button>
              ) : <p>Documentation is available after the encounter has been started.</p>}
            </section>
          ) : (
            <>
              {props.canRecordNote ? (
                <section style={{ padding: '1rem' }}>
                  <h2 style={{ marginTop: 0 }}>Clinical note</h2>
                  <p>Notes are saved as drafts; signing and amendment follow their separate authorization workflow.</p>
                  <FormErrors errors={noteForm.errors} fields={['patient_id', 'note_type', 'title', 'body']} />
                  <Form onSubmit={createNote}>
                    <TextInput
                      id="clinician-note-title"
                      labelText="Title (optional)"
                      value={noteForm.data.title}
                      onChange={(event) => noteForm.setData('title', event.target.value)}
                      invalid={Boolean(noteForm.errors.title)}
                      invalidText={noteForm.errors.title}
                    />
                    <TextArea
                      id="clinician-note-body"
                      labelText="Consultation note"
                      rows={7}
                      value={noteForm.data.body}
                      onChange={(event) => noteForm.setData('body', event.target.value)}
                      invalid={Boolean(noteForm.errors.body)}
                      invalidText={noteForm.errors.body}
                      required
                    />
                    <Button size="lg" renderIcon={Checkmark} type="submit" disabled={noteForm.processing} style={{ marginTop: '1rem' }}>
                      {noteForm.processing ? 'Saving…' : 'Save note draft'}
                    </Button>
                  </Form>
                </section>
              ) : null}

              {props.canRecordDiagnosis ? (
                <section style={{ padding: '1rem' }}>
                  <h2 style={{ marginTop: 0 }}>Encounter diagnosis</h2>
                  <p>Coding is optional and accepted as an open code; AfyaNova does not infer diagnoses or validate against an unconfigured terminology.</p>
                  <FormErrors errors={diagnosisForm.errors} fields={['patient_id', 'description', 'code', 'code_system', 'clinical_status']} />
                  <Form onSubmit={recordDiagnosis}>
                    <TextInput
                      id="diagnosis-description"
                      labelText="Clinical description"
                      value={diagnosisForm.data.description}
                      onChange={(event) => diagnosisForm.setData('description', event.target.value)}
                      invalid={Boolean(diagnosisForm.errors.description)}
                      invalidText={diagnosisForm.errors.description}
                      required
                    />
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: '0.75rem', marginTop: '1rem' }}>
                      <TextInput
                        id="diagnosis-code"
                        labelText="Code (optional)"
                        value={diagnosisForm.data.code}
                        onChange={(event) => diagnosisForm.setData('code', event.target.value)}
                        invalid={Boolean(diagnosisForm.errors.code)}
                        invalidText={diagnosisForm.errors.code}
                      />
                      <TextInput
                        id="diagnosis-code-system"
                        labelText="Code system"
                        value={diagnosisForm.data.code_system}
                        onChange={(event) => diagnosisForm.setData('code_system', event.target.value)}
                        invalid={Boolean(diagnosisForm.errors.code_system)}
                        invalidText={diagnosisForm.errors.code_system}
                      />
                    </div>
                    <Button size="lg" renderIcon={Checkmark} type="submit" disabled={diagnosisForm.processing} style={{ marginTop: '1rem' }}>
                      {diagnosisForm.processing ? 'Saving…' : 'Record diagnosis'}
                    </Button>
                  </Form>
                </section>
              ) : null}

              {props.canRecordPlan ? (
                <section style={{ padding: '1rem' }}>
                  <h2 style={{ marginTop: 0 }}>Clinical plan</h2>
                  <p>Record clinical intent and follow-up. A medication plan is not a prescription or dispensing order.</p>
                  <FormErrors errors={planForm.errors} fields={[
                    'patient_id', 'plan_type', 'clinical_intent', 'goals',
                    'planned_actions', 'instructions', 'follow_up',
                  ]} />
                  <Form onSubmit={createPlan}>
                    <Select
                      id="clinical-plan-type"
                      labelText="Plan type"
                      value={planForm.data.plan_type}
                      onChange={(event) => planForm.setData('plan_type', event.currentTarget.value)}
                      invalid={Boolean(planForm.errors.plan_type)}
                      invalidText={planForm.errors.plan_type}
                    >
                      {['GENERAL', 'MEDICATION', 'INVESTIGATION', 'PROCEDURE', 'FOLLOW_UP', 'REFERRAL', 'OBSERVATION'].map((type) => (
                        <SelectItem key={type} value={type} text={type.replaceAll('_', ' ')} />
                      ))}
                    </Select>
                    <TextArea
                      id="clinical-plan-intent"
                      labelText="Clinical intent"
                      rows={3}
                      value={planForm.data.clinical_intent}
                      onChange={(event) => planForm.setData('clinical_intent', event.target.value)}
                      invalid={Boolean(planForm.errors.clinical_intent)}
                      invalidText={planForm.errors.clinical_intent}
                      required
                    />
                    <TextArea
                      id="clinical-plan-actions"
                      labelText="Planned actions (optional)"
                      rows={3}
                      value={planForm.data.planned_actions}
                      onChange={(event) => planForm.setData('planned_actions', event.target.value)}
                      invalid={Boolean(planForm.errors.planned_actions)}
                      invalidText={planForm.errors.planned_actions}
                    />
                    <TextArea
                      id="clinical-plan-follow-up"
                      labelText="Follow-up (optional)"
                      rows={2}
                      value={planForm.data.follow_up}
                      onChange={(event) => planForm.setData('follow_up', event.target.value)}
                      invalid={Boolean(planForm.errors.follow_up)}
                      invalidText={planForm.errors.follow_up}
                    />
                    <Button size="lg" renderIcon={Checkmark} type="submit" disabled={planForm.processing} style={{ marginTop: '1rem' }}>
                      {planForm.processing ? 'Saving…' : 'Save clinical plan'}
                    </Button>
                  </Form>
                </section>
              ) : null}

              {props.canRequestProcedure ? (
                <section style={{ padding: '1rem' }}>
                  <h2 style={{ marginTop: 0 }}>Procedure orders</h2>
                  <p>
                    Request the procedure, then authorize it. Authorization automatically routes the order to the configured procedure queue.
                  </p>
                  <FormErrors errors={procedureForm.errors} fields={['patient_id', 'definition_id', 'indication']} />
                  {procedureActionError ? (
                    <InlineNotification kind="error" title={procedureActionError} lowContrast />
                  ) : null}
                  {props.procedureDefinitions.length === 0 ? (
                    <p>No active procedure definitions are available.</p>
                  ) : (
                    <Form onSubmit={requestProcedure}>
                      <Select
                        id="procedure-definition"
                        labelText="Procedure"
                        value={procedureForm.data.definition_id}
                        onChange={(event) => procedureForm.setData('definition_id', event.currentTarget.value)}
                        invalid={Boolean(procedureForm.errors.definition_id)}
                        invalidText={procedureForm.errors.definition_id}
                      >
                        <SelectItem value="" text="Select a procedure" />
                        {props.procedureDefinitions.map((definition) => (
                          <SelectItem
                            key={definition.definitionId}
                            value={definition.definitionId}
                            text={`${definition.name} (${definition.code})`}
                          />
                        ))}
                      </Select>
                      <TextArea
                        id="procedure-indication"
                        labelText="Indication (optional)"
                        rows={3}
                        value={procedureForm.data.indication}
                        onChange={(event) => procedureForm.setData('indication', event.target.value)}
                        invalid={Boolean(procedureForm.errors.indication)}
                        invalidText={procedureForm.errors.indication}
                        style={{ marginTop: '1rem' }}
                      />
                      <Button size="lg" renderIcon={HealthCross}
                        type="submit"
                        disabled={procedureForm.processing || procedureForm.data.definition_id === ''}
                        style={{ marginTop: '1rem' }}
                      >
                        {procedureForm.processing ? 'Requesting…' : 'Request procedure'}
                      </Button>
                    </Form>
                  )}
                  <div style={{ marginTop: '1rem' }}>
                    {props.procedures.length === 0 ? <p>No procedure orders are recorded for this encounter.</p> : (
                      props.procedures.map((procedure) => {
                        const definition = props.procedureDefinitions.find(
                          (item) => item.definitionId === procedure.definitionId,
                        );
                        const queueConfigured = definition?.queueConfigured === true;

                        return (
                          <div
                            key={procedure.procedureId}
                            style={{ paddingBlock: '0.75rem' }}
                          >
                            <p style={{ margin: 0 }}>
                              <strong>{procedure.procedureNumber}</strong>
                              {' · '}
                              {definition?.name ?? 'Procedure'}
                              {' · '}
                              <Tag type={procedure.status === 'AUTHORIZED' ? 'green' : 'blue'}>
                                {procedure.status.replaceAll('_', ' ')}
                              </Tag>
                              {procedure.authorizationBasis === 'APPROVED_PROTOCOL' ? (
                                <Tag type="teal">
                                  Protocol · {procedure.authorizationProtocolReference}
                                </Tag>
                              ) : null}
                            </p>
                            {procedure.indication ? <p>{procedure.indication}</p> : null}
                            {procedure.status === 'REQUESTED' ? (
                              <>
                                {!queueConfigured ? (
                                  <p role="status">
                                    Configure an active procedure queue with an active room before authorizing this order.
                                  </p>
                                ) : null}
                                <Button
                                  kind="secondary"
                                  size="sm"
                                  disabled={!queueConfigured || authorizingProcedureId !== null}
                                  onClick={() => authorizeProcedure(procedure.procedureId)}
                                >
                                  {authorizingProcedureId === procedure.procedureId ? 'Authorizing…' : 'Authorize and route'}
                                </Button>
                              </>
                            ) : null}
                          </div>
                        );
                      })
                    )}
                  </div>
                </section>
              ) : null}

              {props.canChangeDisposition || props.canCompleteEncounter ? (
                <section style={{ padding: '1rem' }}>
                  <h2 style={{ marginTop: 0 }}>Disposition and completion</h2>
                  <p>Choose and save the encounter outcome before completing the visit.</p>
                  {props.canChangeDisposition ? (
                    <FormErrors errors={dispositionForm.errors} fields={['disposition']} />
                  ) : null}
                  {props.canChangeDisposition ? (
                    <Form onSubmit={saveDisposition}>
                      <Select
                        id="encounter-disposition"
                        labelText="Disposition"
                        value={dispositionForm.data.disposition}
                        onChange={(event) => {
                          dispositionForm.setData('disposition', event.currentTarget.value);
                          setDispositionSaved(false);
                        }}
                        invalid={Boolean(dispositionForm.errors.disposition)}
                        invalidText={dispositionForm.errors.disposition}
                      >
                        {['DISCHARGED', 'REFERRED', 'LEFT', 'FOLLOW_UP', 'DECEASED', 'OTHER'].map((value) => (
                          <SelectItem key={value} value={value} text={value.replaceAll('_', ' ')} />
                        ))}
                      </Select>
                      <Button size="lg" renderIcon={Checkmark} type="submit" kind="secondary" disabled={dispositionForm.processing} style={{ marginTop: '1rem' }}>
                        Save disposition
                      </Button>
                    </Form>
                  ) : null}
                  {props.canCompleteEncounter ? (
                    <Button size="lg" renderIcon={Checkmark}
                      kind="danger"
                      onClick={completeEncounter}
                      disabled={!dispositionSaved || completing}
                      style={{ marginTop: '1rem', marginInlineStart: '0.5rem' }}
                    >
                      {completing ? 'Completing…' : 'Complete visit'}
                    </Button>
                  ) : null}
                  {!dispositionSaved && props.canCompleteEncounter
                    ? <p>Save a disposition in this session before completing.</p>
                    : null}
                </section>
              ) : null}
            </>
          )}
        </div>
      </div>
    </AppLayout>
  );
}