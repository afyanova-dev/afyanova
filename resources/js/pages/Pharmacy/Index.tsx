import { Button, Modal } from '@carbon/react';
import { usePage } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';
import { PageHeader } from '@/components/ui';
import { PharmacyBoard, TranscribePanel, type PharmacyBoardProps } from '@/features/pharmacy';
import { refreshInPlace } from '@/features/pharmacy/pharmacyNavigation';
import { AppLayout } from '@/layouts/AppLayout';
import { Document } from '@/icons';

export default function PharmacyIndex(props: PharmacyBoardProps & { errors?: Record<string, string> }): ReactNode {
  const { url } = usePage();
  const errors = props.errors ?? {};
  const [transcribing, setTranscribing] = useState(props.externalIntake && props.view !== 'patient');
  const [confirmExternalIntake, setConfirmExternalIntake] = useState(false);

  useEffect(() => {
    if (props.view === 'patient') setTranscribing(false);
  }, [props.view]);

  function closeTranscriptionPanel(): void {
    refreshInPlace(url, {
      patient_id: null,
      view: null,
      external_intake: null,
      order_id: null,
      rx_id: null,
    });
    setTranscribing(false);
  }

  return (
    <AppLayout>
      <PageHeader
        title="Pharmacy"
        description="Find Afyanova prescriptions to dispense, or record a paper prescription from outside Afyanova."
        actions={props.canTranscribe && props.canManageOrders ? (
          <Button size="lg" renderIcon={Document} type="button" onClick={() => setConfirmExternalIntake(true)}>
            External prescription intake
          </Button>
        ) : null}
      />
      {props.canTranscribe && !props.canManageOrders ? (
        <p role="alert">
          External prescription intake requires pharmacy order-management access. Ask an administrator to update your facility permissions.
        </p>
      ) : null}
      <Modal
        open={confirmExternalIntake}
        modalHeading="Record an external prescription"
        primaryButtonText="Continue"
        secondaryButtonText="Cancel"
        onRequestClose={() => setConfirmExternalIntake(false)}
        onSecondarySubmit={() => setConfirmExternalIntake(false)}
        onRequestSubmit={() => {
          setConfirmExternalIntake(false);
          setTranscribing(true);
          refreshInPlace(url, {
            external_intake: '1',
            view: null,
            patient_id: null,
            order_id: null,
            rx_id: null,
          });
        }}
      >
        <p>
          This records a paper prescription issued outside AfyaNova. You’ll select a patient at this facility
          and choose medicines from the catalog. Each medicine is queued for pharmacist validation after
          submission. No clinic visit is created, and stock is not deducted until dispensing.
        </p>
      </Modal>
      {Object.keys(errors).length > 0 ? <p role="alert">{Object.values(errors).join(' ')}</p> : null}
      <PharmacyBoard
        {...props}
        hidePatientWorkspace={transcribing}
        onPatientWorkspaceOpen={() => setTranscribing(false)}
      />
      {transcribing ? (
        <TranscribePanel
          patient={props.transcribePatient}
          allergies={props.transcribeAllergies ?? []}
          items={props.items}
          canCreatePatient={props.canCreatePharmacyPatient}
          canManageOrders={props.canManageOrders}
          onClose={closeTranscriptionPanel}
        />
      ) : null}
    </AppLayout>
  );
}