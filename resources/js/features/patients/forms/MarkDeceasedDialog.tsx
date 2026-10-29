import { useForm } from '@inertiajs/react';
import { Button, InlineNotification, TextArea } from '@carbon/react';
import { useState, type FormEvent, type ReactNode } from 'react';
import { FormErrors } from '@/components/ui';
import { DockedPanel } from '@/components/panels';
import { permissions, useCan } from '@/lib/authorization';
import { routes } from '@/app/routes';
import type { PatientChartPatient } from '@/features/patients/types/chart';

interface DeceasedForm {
  reason: string;
}

/**
 * Mark patient deceased (OpenMRS Actions > Mark patient deceased).
 * Terminal {ACTIVE,INACTIVE} -> DECEASED transition. Rendered only for
 * `patient.patients.mark_deceased`; hidden for DECEASED/MERGED identities.
 */
export function MarkDeceasedDialog({
  patient,
  renderTrigger,
  open: controlledOpen,
  onClose,
  offsetInlineEnd,
}: {
  patient: PatientChartPatient;
  renderTrigger?: (open: () => void) => ReactNode;
  open?: boolean;
  onClose?: () => void;
  offsetInlineEnd?: string;
}): ReactNode {
  const can = useCan();
  const [internalOpen, setInternalOpen] = useState(false);
  const open = controlledOpen ?? internalOpen;
  const { data, setData, post, processing, errors, reset } = useForm<DeceasedForm>({
    reason: '',
  });

  if (!can(permissions.patient.markDeceased)) return null;
  if (patient.status === 'DECEASED' || patient.status === 'MERGED') return null;

  function close(): void {
    reset();
    if (controlledOpen === undefined) setInternalOpen(false);
    onClose?.();
  }

  function submit(event: FormEvent): void {
    event.preventDefault();
    post(routes.patient.markDeceased(patient.patientId), {
      onSuccess: () => close(),
    });
  }

  return (
    <>
      {renderTrigger ? (
        renderTrigger(() => setInternalOpen(true))
      ) : controlledOpen !== undefined ? null : (
        <Button kind="danger--ghost" size="sm" onClick={() => setInternalOpen(true)}>
          Mark patient deceased
        </Button>
      )}
      {open ? (
        <DockedPanel
          heading="Mark patient deceased"
          primaryLabel={processing ? 'Saving…' : 'Mark deceased'}
          primaryDisabled={processing}
          secondaryLabel="Cancel"
          danger
          offsetInlineEnd={offsetInlineEnd}
          onClose={close}
          onSubmit={submit}
        >
          <FormErrors errors={errors} fields={['reason', 'mark_deceased']} />
          <InlineNotification
            kind="warning"
            title="This action is terminal"
            subtitle={`${patient.displayName} (${patient.patientNumber}) will move to DECEASED. New visits, orders, and appointments are blocked afterwards.`}
            lowContrast
          />
          <p>
            Patient: {patient.displayName} · {patient.patientNumber}
            <br />
            Current status: {patient.status}
          </p>
          <TextArea
            id={`mark-deceased-reason-${patient.patientId}`}
            labelText="Reason (optional)"
            helperText="Recorded in the audit log with this transition."
            value={data.reason}
            onChange={(event) => setData('reason', event.currentTarget.value)}
            invalid={Boolean(errors.reason)}
            invalidText={errors.reason}
            rows={3}
          />
        </DockedPanel>
      ) : null}
    </>
  );
}
