import {
  Button,
  InlineNotification,
  Select,
  SelectItem,
  Tag,
  TextArea,
  TextInput,
} from '@carbon/react';
import { useForm } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { routes } from '@/app/routes';
import { PageHeader } from '@/components/ui';
import { AppLayout } from '@/layouts/AppLayout';
import { ArrowLeft, Checkmark, Document, Play } from '@/icons';

interface ProcedureWorkProps {
  entry: {
    id: string;
    ticketNumber: string;
    queue: string;
    status: string;
  };
  procedure: {
    procedureId: string;
    procedureNumber: string;
    name: string;
    code: string;
    status: string;
    indication: string | null;
    site: string | null;
    laterality: string | null;
    outcome: string | null;
    outcomeDetail: string | null;
    complications: string | null;
    requiresReadinessCheck: boolean;
    requiresSite: boolean;
    requiresLaterality: boolean;
    clinicalNoteId: string | null;
  };
  woundCare: {
    enabled: boolean;
    note: { title: string | null; body: string; status: string } | null;
    materials: Array<{
      materialRef: string;
      quantity: string;
      unit: string | null;
      batchRef: string | null;
      recordedAt: string;
    }>;
    canRecordAssessment: boolean;
    canRecordMaterials: boolean;
    noteUnavailable: boolean;
  };
  canMarkReady: boolean;
  canPerform: boolean;
  canComplete: boolean;
}

interface MaterialLine {
  material_ref: string;
  quantity: string;
  unit: string;
  batch_ref: string;
}

const emptyMaterialLine = (): MaterialLine => ({
  material_ref: '',
  quantity: '',
  unit: '',
  batch_ref: '',
});

export default function ProcedureWork(props: ProcedureWorkProps): ReactNode {
  const form = useForm({
    workflow_action: '',
    site: props.procedure.site ?? '',
    laterality: props.procedure.laterality ?? '',
    outcome: props.procedure.outcome ?? '',
    outcome_detail: props.procedure.outcomeDetail ?? '',
    complications: props.procedure.complications ?? '',
  });
  const woundAssessmentForm = useForm({
    workflow_action: 'record-wound-assessment',
    wound_assessment: '',
    dressing_performed: '',
  });
  const materialsForm = useForm({
    workflow_action: 'record-wound-materials',
    material_lines: [emptyMaterialLine()],
  });

  function submit(action: string): void {
    form.transform((data) => ({ ...data, workflow_action: action }));
    form.post(routes.workspaces.procedureWorkUpdate(props.entry.id), {
      preserveScroll: true,
    });
  }

  const canStart = props.canPerform
    && ['AUTHORIZED', 'READY'].includes(props.procedure.status)
    && (!props.procedure.requiresReadinessCheck || props.procedure.status === 'READY');
  const canReady = props.canMarkReady
    && props.procedure.requiresReadinessCheck
    && props.procedure.status === 'AUTHORIZED';
  const inProgress = props.procedure.status === 'IN_PROGRESS';
  const inService = props.entry.status === 'IN_SERVICE';
  const canDocumentWoundCare = props.woundCare.enabled
    && inService
    && ['IN_PROGRESS', 'COMPLETED'].includes(props.procedure.status);

  return (
    <AppLayout>
      <main style={{ maxWidth: '56rem', margin: '0 auto', padding: '1.5rem' }}>
        <PageHeader
          title="Procedure work"
          description={`${props.entry.ticketNumber} · ${props.entry.queue}`}
        />
        {!inService ? (
          <InlineNotification
            kind="warning"
            title="Start the queue service first"
            subtitle="Use the service queue board to start this ticket before performing or documenting the procedure."
            lowContrast
          />
        ) : null}
        {form.errors.workflow_action ? (
          <InlineNotification kind="error" title={form.errors.workflow_action} lowContrast />
        ) : null}
        <section style={{ marginBlock: '1rem', padding: '1rem',  }}>
          <h2 style={{ marginTop: 0 }}>{props.procedure.name}</h2>
          <p>
            <strong>{props.procedure.procedureNumber}</strong>
            {' · '}{props.procedure.code}{' · '}
            <Tag type={props.procedure.status === 'COMPLETED' ? 'green' : 'blue'}>
              {props.procedure.status.replaceAll('_', ' ')}
            </Tag>
          </p>
          {props.procedure.indication ? <p><strong>Indication:</strong> {props.procedure.indication}</p> : null}
          {props.procedure.site ? <p><strong>Site:</strong> {props.procedure.site}</p> : null}
          {props.procedure.laterality ? <p><strong>Laterality:</strong> {props.procedure.laterality.replaceAll('_', ' ')}</p> : null}
          {props.procedure.status === 'COMPLETED' ? (
            <>
              <p><strong>Outcome:</strong> {props.procedure.outcome?.replaceAll('_', ' ') ?? 'Not recorded'}</p>
              {props.procedure.outcomeDetail ? <p>{props.procedure.outcomeDetail}</p> : null}
              {props.procedure.complications ? <p><strong>Complications:</strong> {props.procedure.complications}</p> : null}
            </>
          ) : null}
        </section>

        {inService && canReady ? (
          <section style={{ marginBlock: '1rem', padding: '1rem',  }}>
            <h2 style={{ marginTop: 0 }}>Readiness check</h2>
            <p>This procedure requires a readiness check before it can start.</p>
            <Button size="lg" renderIcon={Checkmark} disabled={form.processing} onClick={() => submit('ready')}>
              {form.processing ? 'Saving…' : 'Mark ready'}
            </Button>
          </section>
        ) : null}

        {inService && canStart ? (
          <section style={{ marginBlock: '1rem', padding: '1rem',  }}>
            <h2 style={{ marginTop: 0 }}>Start procedure</h2>
            {props.procedure.requiresSite ? (
              <TextInput
                id="procedure-site"
                labelText="Procedure site"
                value={form.data.site}
                onChange={(event) => form.setData('site', event.currentTarget.value)}
                invalid={Boolean(form.errors.site)}
                invalidText={form.errors.site}
                required
              />
            ) : null}
            {props.procedure.requiresLaterality ? (
              <Select
                id="procedure-laterality"
                labelText="Laterality"
                value={form.data.laterality}
                onChange={(event) => form.setData('laterality', event.currentTarget.value)}
                invalid={Boolean(form.errors.laterality)}
                invalidText={form.errors.laterality}
              >
                <SelectItem value="" text="Select laterality" />
                {['LEFT', 'RIGHT', 'BILATERAL', 'NOT_APPLICABLE', 'UNKNOWN'].map((value) => (
                  <SelectItem key={value} value={value} text={value.replaceAll('_', ' ')} />
                ))}
              </Select>
            ) : null}
            <Button size="lg" renderIcon={Play} disabled={form.processing} onClick={() => submit('start')} style={{ marginTop: '1rem' }}>
              {form.processing ? 'Starting…' : 'Start procedure'}
            </Button>
          </section>
        ) : null}

        {canDocumentWoundCare ? (
          <section style={{ marginBlock: '1rem', padding: '1rem',  }}>
            <h2 style={{ marginTop: 0 }}>Wound care documentation</h2>
            {props.woundCare.note ? (
              <div>
                <h3>{props.woundCare.note.title ?? 'Procedure note'}</h3>
                <p>{props.woundCare.note.status.replaceAll('_', ' ')}</p>
                <div style={{ whiteSpace: 'pre-wrap' }}>{props.woundCare.note.body}</div>
              </div>
            ) : props.woundCare.noteUnavailable ? (
              <InlineNotification
                kind="info"
                title="A procedure note is already linked"
                subtitle="You need clinical note read access to view its contents."
                lowContrast
              />
            ) : props.woundCare.canRecordAssessment ? (
              <div>
                <TextArea
                  id="wound-assessment"
                  labelText="Wound assessment"
                  rows={4}
                  value={woundAssessmentForm.data.wound_assessment}
                  onChange={(event) => woundAssessmentForm.setData('wound_assessment', event.currentTarget.value)}
                  invalid={Boolean(woundAssessmentForm.errors.wound_assessment)}
                  invalidText={woundAssessmentForm.errors.wound_assessment}
                  required
                />
                <TextArea
                  id="dressing-performed"
                  labelText="Dressing performed"
                  rows={4}
                  value={woundAssessmentForm.data.dressing_performed}
                  onChange={(event) => woundAssessmentForm.setData('dressing_performed', event.currentTarget.value)}
                  invalid={Boolean(woundAssessmentForm.errors.dressing_performed)}
                  invalidText={woundAssessmentForm.errors.dressing_performed}
                  required
                  style={{ marginTop: '1rem' }}
                />
                {woundAssessmentForm.errors.workflow_action ? (
                  <InlineNotification kind="error" title={woundAssessmentForm.errors.workflow_action} lowContrast />
                ) : null}
                <Button size="lg" renderIcon={Document}
                  disabled={
                    woundAssessmentForm.processing
                    || woundAssessmentForm.data.wound_assessment.trim() === ''
                    || woundAssessmentForm.data.dressing_performed.trim() === ''
                  }
                  onClick={() => woundAssessmentForm.post(
                    routes.workspaces.procedureWorkUpdate(props.entry.id),
                    { preserveScroll: true },
                  )}
                  style={{ marginTop: '1rem' }}
                >
                  {woundAssessmentForm.processing ? 'Saving…' : 'Save wound documentation'}
                </Button>
              </div>
            ) : (
              <InlineNotification
                kind="warning"
                title="Wound documentation permission is required"
                subtitle="Ask an administrator for procedure perform and clinical note create access."
                lowContrast
              />
            )}
            <div style={{ marginTop: '1.5rem' }}>
              <h3>Materials used</h3>
              {props.woundCare.materials.length > 0 ? (
                <ul>
                  {props.woundCare.materials.map((material, index) => (
                    <li key={`${material.materialRef}-${material.recordedAt}-${index}`}>
                      {material.materialRef} — {material.quantity}{material.unit ? ` ${material.unit}` : ''}
                      {material.batchRef ? ` · Batch ${material.batchRef}` : ''}
                    </li>
                  ))}
                </ul>
              ) : (
                <p>No materials have been recorded for this procedure.</p>
              )}
              {props.woundCare.canRecordMaterials ? (
                <div>
                  {materialsForm.data.material_lines.map((line, index) => (
                    <div key={index} className="procedure-work__material-line">
                      <TextInput
                        id={`material-ref-${index}`}
                        labelText="Material"
                        value={line.material_ref}
                        onChange={(event) => materialsForm.setData('material_lines', materialsForm.data.material_lines.map(
                          (item, itemIndex) => itemIndex === index ? { ...item, material_ref: event.currentTarget.value } : item,
                        ))}
                        invalid={Boolean(materialsForm.errors[`material_lines.${index}.material_ref`])}
                        invalidText={materialsForm.errors[`material_lines.${index}.material_ref`]}
                      />
                      <TextInput
                        id={`material-quantity-${index}`}
                        labelText="Quantity"
                        type="number"
                        min="0.001"
                        step="0.001"
                        value={line.quantity}
                        onChange={(event) => materialsForm.setData('material_lines', materialsForm.data.material_lines.map(
                          (item, itemIndex) => itemIndex === index ? { ...item, quantity: event.currentTarget.value } : item,
                        ))}
                        invalid={Boolean(materialsForm.errors[`material_lines.${index}.quantity`])}
                        invalidText={materialsForm.errors[`material_lines.${index}.quantity`]}
                      />
                      <TextInput
                        id={`material-unit-${index}`}
                        labelText="Unit (optional)"
                        value={line.unit}
                        onChange={(event) => materialsForm.setData('material_lines', materialsForm.data.material_lines.map(
                          (item, itemIndex) => itemIndex === index ? { ...item, unit: event.currentTarget.value } : item,
                        ))}
                      />
                      <TextInput
                        id={`material-batch-${index}`}
                        labelText="Batch (optional)"
                        value={line.batch_ref}
                        onChange={(event) => materialsForm.setData('material_lines', materialsForm.data.material_lines.map(
                          (item, itemIndex) => itemIndex === index ? { ...item, batch_ref: event.currentTarget.value } : item,
                        ))}
                      />
                      {materialsForm.data.material_lines.length > 1 ? (
                        <Button
                          kind="ghost"
                          size="sm"
                          onClick={() => materialsForm.setData('material_lines', materialsForm.data.material_lines.filter(
                            (_item, itemIndex) => itemIndex !== index,
                          ))}
                        >
                          Remove
                        </Button>
                      ) : null}
                    </div>
                  ))}
                  {materialsForm.errors.material_lines ? (
                    <InlineNotification kind="error" title={materialsForm.errors.material_lines} lowContrast />
                  ) : null}
                  <Button
                    kind="tertiary"
                    size="sm"
                    disabled={materialsForm.processing || materialsForm.data.material_lines.length >= 30}
                    onClick={() => materialsForm.setData('material_lines', [
                      ...materialsForm.data.material_lines,
                      emptyMaterialLine(),
                    ])}
                  >
                    Add material
                  </Button>
                  <Button size="lg" renderIcon={Checkmark}
                    disabled={materialsForm.processing}
                    onClick={() => materialsForm.post(
                      routes.workspaces.procedureWorkUpdate(props.entry.id),
                      {
                        preserveScroll: true,
                        onSuccess: () => materialsForm.setData('material_lines', [emptyMaterialLine()]),
                      },
                    )}
                    style={{ marginInlineStart: '0.5rem' }}
                  >
                    {materialsForm.processing ? 'Saving…' : 'Record materials used'}
                  </Button>
                </div>
              ) : null}
            </div>
          </section>
        ) : null}

        {inService && inProgress && props.canPerform ? (
          <section style={{ marginBlock: '1rem', padding: '1rem',  }}>
            <h2 style={{ marginTop: 0 }}>Document outcome</h2>
            <Select
              id="procedure-outcome"
              labelText="Outcome"
              value={form.data.outcome}
              onChange={(event) => form.setData('outcome', event.currentTarget.value)}
              invalid={Boolean(form.errors.outcome)}
              invalidText={form.errors.outcome}
            >
              <SelectItem value="" text="Select an outcome" />
              <SelectItem value="SUCCESSFUL" text="Successful" />
              <SelectItem value="PARTIALLY_SUCCESSFUL" text="Partially successful" />
              <SelectItem value="UNSUCCESSFUL" text="Unsuccessful" />
            </Select>
            <TextArea
              id="procedure-outcome-detail"
              labelText="Outcome details (optional)"
              rows={3}
              value={form.data.outcome_detail}
              onChange={(event) => form.setData('outcome_detail', event.currentTarget.value)}
              invalid={Boolean(form.errors.outcome_detail)}
              invalidText={form.errors.outcome_detail}
              style={{ marginTop: '1rem' }}
            />
            <Button size="lg" renderIcon={Checkmark}
              kind="secondary"
              disabled={form.processing || form.data.outcome === ''}
              onClick={() => submit('record-outcome')}
              style={{ marginTop: '1rem' }}
            >
              Save outcome
            </Button>
            {props.canComplete ? (
              <>
                <TextArea
                  id="procedure-complications"
                  labelText="Complications (optional)"
                  rows={2}
                  value={form.data.complications}
                  onChange={(event) => form.setData('complications', event.currentTarget.value)}
                  invalid={Boolean(form.errors.complications)}
                  invalidText={form.errors.complications}
                  style={{ marginTop: '1rem' }}
                />
                <Button size="lg" renderIcon={Checkmark}
                  kind="primary"
                  disabled={form.processing}
                  onClick={() => submit('complete')}
                  style={{ marginTop: '1rem', marginInlineStart: '0.5rem' }}
                >
                  {form.processing ? 'Completing…' : 'Complete procedure'}
                </Button>
              </>
            ) : null}
          </section>
        ) : null}

        {inService && inProgress && props.canComplete && !props.canPerform ? (
          <section style={{ marginBlock: '1rem', padding: '1rem',  }}>
            <h2 style={{ marginTop: 0 }}>Complete procedure</h2>
            <p>Completion permission is separate from procedure performance permission. Outcome documentation is optional for completion.</p>
            <Select
              id="procedure-completion-outcome"
              labelText="Outcome (optional)"
              value={form.data.outcome}
              onChange={(event) => form.setData('outcome', event.currentTarget.value)}
              invalid={Boolean(form.errors.outcome)}
              invalidText={form.errors.outcome}
            >
              <SelectItem value="" text="No outcome recorded" />
              <SelectItem value="SUCCESSFUL" text="Successful" />
              <SelectItem value="PARTIALLY_SUCCESSFUL" text="Partially successful" />
              <SelectItem value="UNSUCCESSFUL" text="Unsuccessful" />
            </Select>
            <TextArea
              id="procedure-completion-outcome-detail"
              labelText="Outcome details (optional)"
              rows={3}
              value={form.data.outcome_detail}
              onChange={(event) => form.setData('outcome_detail', event.target.value)}
              invalid={Boolean(form.errors.outcome_detail)}
              invalidText={form.errors.outcome_detail}
              style={{ marginTop: '1rem' }}
            />
            <TextArea
              id="procedure-completion-complications"
              labelText="Complications (optional)"
              rows={2}
              value={form.data.complications}
              onChange={(event) => form.setData('complications', event.target.value)}
              invalid={Boolean(form.errors.complications)}
              invalidText={form.errors.complications}
              style={{ marginTop: '1rem' }}
            />
            <Button size="lg" renderIcon={Checkmark}
              disabled={form.processing}
              onClick={() => submit('complete')}
              style={{ marginTop: '1rem' }}
            >
              {form.processing ? 'Completing…' : 'Complete procedure'}
            </Button>
          </section>
        ) : null}

        {inService && inProgress && !props.canPerform ? (
          <InlineNotification
            kind="info"
            title="Procedure documentation access is required"
            subtitle="Ask an administrator for the procedure perform permission to record the outcome."
            lowContrast
          />
        ) : null}
        {props.procedure.status === 'COMPLETED' ? (
          <InlineNotification
            kind="success"
            title="Procedure completed"
            subtitle="Complete the queue ticket separately when service has ended."
            lowContrast
          />
        ) : null}
        <Button size="lg" renderIcon={ArrowLeft} kind="ghost" href={routes.workspaces.serviceQueues} style={{ marginTop: '1rem' }}>
          Back to service queues
        </Button>
      </main>
    </AppLayout>
  );
}