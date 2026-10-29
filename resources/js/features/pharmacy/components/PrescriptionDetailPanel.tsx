import { Button, Modal, TextInput, Tile } from '@carbon/react';
import { router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { Add, Checkmark, Close } from '@/icons';
import { routes } from '@/app/routes';
import { Status } from '@/components/ui';
import type { RxOrderItem, RxPrescriptionItem } from '../types/pharmacy';

/**
 * Inline prescription details for an expanded pharmacy-board row. Contextual
 * entry points: accept a RECEIVED electronic prescription (the clinical
 * snapshot is fetched server-side, never typed here) and create an order from
 * an ACTIVE prescription, both when `canManageOrders` holds; cancellation for a
 * live prescription.
 */
export function PrescriptionDetailPanel({
  prescription,
  medicationCode,
  route,
  urgency,
  dose,
  frequency,
  duration,
  prescribedQuantity,
  dosingInstruction,
  transcriptionNote,
  canCreateOrder,
  canAcceptPrescription,
  canCancelPrescription,
  linkedOrders,
  onCreateOrder,
}: {
  prescription: RxPrescriptionItem;
  medicationCode: string | null;
  route: string | null;
  urgency: string | null;
  dose: string | null;
  frequency: string | null;
  duration: string | null;
  prescribedQuantity: string | null;
  dosingInstruction: string | null;
  transcriptionNote: string | null;
  canCreateOrder: boolean;
  canAcceptPrescription: boolean;
  canCancelPrescription: boolean;
  linkedOrders: RxOrderItem[];
  onCreateOrder: (prescription: RxPrescriptionItem) => void;
}): ReactNode {
  const [cancelling, setCancelling] = useState(false);
  const [cancelReason, setCancelReason] = useState('');
  const [submittingCancel, setSubmittingCancel] = useState(false);
  const [accepting, setAccepting] = useState(false);
  const cancellable = prescription.status === 'RECEIVED' || prescription.status === 'ACTIVE';
  // Only an electronic prescription lands RECEIVED with no clinical snapshot;
  // paper is typed and activated at transcribe time (see TranscriptionController).
  const acceptable = canAcceptPrescription
    && prescription.status === 'RECEIVED'
    && prescription.source === 'ELECTRONIC';

  function submitAccept(): void {
    if (accepting) return;
    setAccepting(true);
    router.post(routes.workspaces.pharmacyPrescriptionAccept(prescription.prescriptionId), {}, {
      preserveScroll: true,
      preserveState: true,
      onFinish: () => setAccepting(false),
    });
  }

  function openCancel(): void {
    setCancelReason('');
    setCancelling(true);
  }

  function submitCancel(): void {
    if (cancelReason.trim().length < 3 || submittingCancel) return;
    setSubmittingCancel(true);
    router.post(routes.workspaces.pharmacyPrescriptionCancel(prescription.prescriptionId), {
      reason: cancelReason.trim(),
    }, {
      preserveScroll: true,
      preserveState: true,
      onFinish: () => {
        setSubmittingCancel(false);
        setCancelling(false);
      },
    });
  }

  const sourceLabel = prescription.source.replaceAll('_', ' ').toLowerCase()
    .replace(/\b\w/g, (character) => character.toUpperCase());
  const statusKind = prescription.status === 'ACTIVE'
    ? 'info'
    : prescription.status === 'CANCELLED' || prescription.status === 'REJECTED'
      ? 'error'
      : 'inactive';
  const directionItems: { label: string; value: string }[] = [];
  if (medicationCode) directionItems.push({ label: 'Code', value: medicationCode });
  if (dose) directionItems.push({ label: 'Dose', value: dose });
  if (frequency) directionItems.push({ label: 'Freq', value: frequency });
  if (duration) directionItems.push({ label: 'Duration', value: duration });
  if (prescribedQuantity) directionItems.push({ label: 'Qty', value: prescribedQuantity });
  if (route) directionItems.push({ label: 'Route', value: route });
  if (urgency) directionItems.push({ label: 'Urgency', value: urgency });

  return (
    <>
    <Tile className="pharmacy-detail pharmacy-detail--compact">
      <header className="pharmacy-detail__hero pharmacy-detail__hero--compact">
        <div className="pharmacy-detail__identity">
          <h3 className="pharmacy-detail__medication">{prescription.medicationName ?? 'Medication not recorded'}</h3>
          <p className="pharmacy-detail__eyebrow">{sourceLabel} · {prescription.prescriptionNumber} · {prescription.prescriberName ?? 'Prescriber not recorded'}</p>
        </div>
        <div className="pharmacy-detail__header-controls">
          <Status kind={statusKind}>
            {prescription.status.replaceAll('_', ' ')}
          </Status>
          {acceptable || (canCreateOrder && prescription.status === 'ACTIVE') || (canCancelPrescription && cancellable) ? (
            <div className="pharmacy-detail__actions pharmacy-detail__actions--compact">
              <div className="pharmacy-detail__actions">
                {acceptable ? (
                  <Button size="sm" renderIcon={Checkmark} kind="primary" type="button" onClick={submitAccept} disabled={accepting}>
                    {accepting ? 'Accepting…' : 'Accept prescription'}
                  </Button>
                ) : null}
                {canCreateOrder && prescription.status === 'ACTIVE' ? (
                  <Button size="sm" renderIcon={Add} kind="primary" type="button" onClick={() => onCreateOrder(prescription)}>
                    Create order
                  </Button>
                ) : null}
                {canCancelPrescription && cancellable ? (
                  <Button size="sm" renderIcon={Close} kind="secondary" type="button" onClick={openCancel}>
                    Cancel prescription
                  </Button>
                ) : null}
              </div>
            </div>
          ) : null}
        </div>
      </header>

      <div className="pharmacy-detail__body">
        <div className="pharmacy-detail__regimen-col">
          {directionItems.length > 0 || dosingInstruction ? (
            <dl className="pharmacy-detail__grid pharmacy-detail__grid--compact">
              {directionItems.map((item) => (
                <div key={item.label} className="pharmacy-detail__field pharmacy-detail__field--inline">
                  <dt>{item.label}</dt>
                  <dd>{item.value}</dd>
                </div>
              ))}
            </dl>
          ) : <p className="pharmacy-detail__muted">No directions recorded.</p>}
          {dosingInstruction ? <p className="pharmacy-detail__instruction">{dosingInstruction}</p> : null}
          {transcriptionNote ? (
            <p className="pharmacy-detail__note">{transcriptionNote}</p>
          ) : null}
        </div>

        <div className="pharmacy-detail__dispensing-col">
          <div className="pharmacy-detail__dispensing-compact">
            {linkedOrders.length === 0 ? (
              <span className="pharmacy-detail__muted">No order linked</span>
            ) : (
              <ul className="pharmacy-detail__dispensing-list pharmacy-detail__dispensing-list--compact">
                {linkedOrders.map((order) => (
                  <li key={order.pharmacyOrderId}>
                    <div className="pharmacy-detail__dispensing-main">
                      <strong>{order.orderNumber}</strong>
                      <span>{order.status.replaceAll('_', ' ')} · {order.priority}</span>
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      </div>
    </Tile>
    {cancelling ? (
      <Modal
        open
        modalHeading="Cancel prescription"
        primaryButtonText={submittingCancel ? 'Cancelling…' : 'Cancel prescription'}
        secondaryButtonText="Back"
        danger
        primaryButtonDisabled={cancelReason.trim().length < 3 || submittingCancel}
        onRequestClose={() => setCancelling(false)}
        onRequestSubmit={submitCancel}
      >
        <p>Cancelling ends this prescription. An order can no longer be created from it.</p>
        <TextInput
          id="pharmacy-prescription-cancel-reason"
          labelText="Reason"
          helperText="At least 3 characters."
          value={cancelReason}
          maxLength={500}
          onChange={(event) => setCancelReason(event.currentTarget.value)}
          required
        />
      </Modal>
    ) : null}
    </>
  );
}
