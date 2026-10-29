import { Button, Tile } from '@carbon/react';
import { router, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { Checkmark, Close, Pending, Renew, TrashCan } from '@/icons';
import { routes } from '@/app/routes';
import { Status } from '@/components/ui';
import { DispensePanel } from './DispensePanel';
import { OrderReasonDialog } from './OrderReasonDialog';
import { PickupOutcomeDialog } from './PickupOutcomeDialog';
import { PickupPanel } from './PickupPanel';
import { ReverseDispensingDialog } from './ReverseDispensingDialog';
import { refreshInPlace } from '../pharmacyNavigation';
import type { PharmacyBoardProps, RxDispensingItem } from '../types/pharmacy';
import type { OrderReasonAction, PickupOutcomeAction } from '../pharmacyCodes';

/**
 * Inline order details for an expanded pharmacy-board row, with lifecycle
 * actions gated by backend status and `canManageOrders`. Validate and
 * release post directly; reject / hold / cancel go through the shared
 * reason dialog. Dispense / reverse / pickup actions (Phase 3) post to
 * their canonical endpoints with status × permission gating.
 */
export function OrderDetailPanel(props: PharmacyBoardProps): ReactNode {
  const { url } = usePage();
  const [reasonAction, setReasonAction] = useState<OrderReasonAction | null>(null);
  const [dispensingOpen, setDispensingOpen] = useState(false);
  const [reverseTarget, setReverseTarget] = useState<RxDispensingItem | null>(null);
  const [pickupTarget, setPickupTarget] = useState<RxDispensingItem | null>(null);
  const [pickupOutcome, setPickupOutcome] = useState<{ dispensing: RxDispensingItem; action: PickupOutcomeAction } | null>(null);
  const selectedId = props.selectedOrderId;
  const order = props.orders.find((item) => item.pharmacyOrderId === selectedId);
  const prescription = order
    ? props.prescriptions.find((item) => item.prescriptionId === order.prescriptionId)
    : undefined;
  const status = props.selectedOrderStatus ?? order?.status ?? null;
  const statusKind = status === 'VALIDATED' || status === 'DISPENSED'
    ? 'active'
    : status === 'REJECTED' || status === 'CANCELLED'
      ? 'error'
      : status === 'RECEIVED'
        ? 'info'
        : status === 'ON_HOLD' || status === 'PARTIALLY_DISPENSED'
          ? 'warning'
          : 'inactive';

  if (selectedId === null) return null;

  const orderNumber = props.selectedOrderNumber ?? order?.orderNumber ?? selectedId;
  const medicationName = order?.medicationName ?? props.selectedMedicationName ?? 'Medication not recorded';
  const canAct = props.canManageOrders && status !== null;
  const showValidate = canAct && status === 'RECEIVED';
  const showHold = canAct && (status === 'RECEIVED' || status === 'VALIDATED');
  const showReject = canAct && (status === 'RECEIVED' || status === 'VALIDATED');
  const showRelease = canAct && status === 'ON_HOLD';
  const showCancel = canAct && (status === 'RECEIVED' || status === 'VALIDATED' || status === 'ON_HOLD');
  const dispensable = status !== null && (status === 'VALIDATED' || status === 'PARTIALLY_DISPENSED');
  const showDispense = props.canDispense && dispensable;
  const reversibleOrder = status !== null && (status === 'PARTIALLY_DISPENSED' || status === 'DISPENSED');

  function canReverseDispensing(dispensing: RxDispensingItem): boolean {
    return props.canReverse
      && reversibleOrder
      && dispensing.reversalId === null
      && dispensing.pickupStatus !== 'PICKED_UP';
  }

  function canRecordPickup(dispensing: RxDispensingItem): boolean {
    return props.canRecordPickup && (dispensing.pickupStatus === 'READY_FOR_PICKUP' || dispensing.pickupStatus === 'NOT_COLLECTED');
  }

  function canMarkNotCollected(dispensing: RxDispensingItem): boolean {
    return props.canRecordPickup && dispensing.pickupStatus === 'READY_FOR_PICKUP';
  }

  function canCancelPickup(dispensing: RxDispensingItem): boolean {
    return props.canRecordPickup && (dispensing.pickupStatus === 'READY_FOR_PICKUP' || dispensing.pickupStatus === 'NOT_COLLECTED');
  }

  function postAction(url: string): void {
    router.post(url, {}, { preserveScroll: true, preserveState: true });
  }

  function openDispense(): void {
    setDispensingOpen(true);
    refreshInPlace(url, { order_id: selectedId });
  }

  function closeDispense(): void {
    setDispensingOpen(false);
    refreshInPlace(url, { order_id: null, item_id: null });
  }

  const hasDirections = Boolean(props.selectedMedicationCode || props.selectedDose || props.selectedFrequency || props.selectedDuration || props.selectedPrescribedQuantity || props.selectedDosingInstruction || prescription?.route || prescription?.urgency);

  const directionItems: { label: string; value: string }[] = [];
  if (props.selectedMedicationCode) directionItems.push({ label: 'Code', value: props.selectedMedicationCode });
  if (props.selectedDose) directionItems.push({ label: 'Dose', value: props.selectedDose });
  if (props.selectedFrequency) directionItems.push({ label: 'Freq', value: props.selectedFrequency });
  if (props.selectedDuration) directionItems.push({ label: 'Duration', value: props.selectedDuration });
  if (props.selectedPrescribedQuantity) directionItems.push({ label: 'Qty', value: props.selectedPrescribedQuantity });
  if (prescription?.route) directionItems.push({ label: 'Route', value: prescription.route });
  if (prescription?.urgency) directionItems.push({ label: 'Urgency', value: prescription.urgency });

  return (
    <>
      <Tile className="pharmacy-detail pharmacy-detail--compact">
        <header className="pharmacy-detail__hero pharmacy-detail__hero--compact">
          <div className="pharmacy-detail__identity">
            <h3 className="pharmacy-detail__medication">{medicationName}</h3>
            <p className="pharmacy-detail__eyebrow">{orderNumber} · {order?.priority ?? ''} · Rx {prescription?.prescriptionNumber ?? '—'}</p>
          </div>
          <div className="pharmacy-detail__header-controls">
            <Status kind={statusKind}>
              {(status ?? 'Unknown').replaceAll('_', ' ')}
            </Status>
            {(showValidate || showHold || showReject || showRelease || showCancel || showDispense) ? (
              <div className="pharmacy-detail__actions pharmacy-detail__actions--compact">
                <div className="pharmacy-detail__actions">
                  {showValidate ? (
                    <Button size="sm" renderIcon={Checkmark} kind="primary" type="button" onClick={() => postAction(routes.workspaces.pharmacyOrderValidate(selectedId))}>
                      Validate
                    </Button>
                  ) : null}
                  {showDispense ? (
                    <Button size="sm" kind="primary" type="button" onClick={openDispense}>
                      Dispense
                    </Button>
                  ) : null}
                  {showHold ? (
                    <Button size="sm" renderIcon={Pending} kind="secondary" type="button" onClick={() => setReasonAction('hold')}>
                      Hold
                    </Button>
                  ) : null}
                  {showReject ? (
                    <Button size="sm" renderIcon={TrashCan} kind="danger" type="button" onClick={() => setReasonAction('reject')}>
                      Reject
                    </Button>
                  ) : null}
                  {showRelease ? (
                    <Button size="sm" renderIcon={Renew} kind="primary" type="button" onClick={() => postAction(routes.workspaces.pharmacyOrderRelease(selectedId))}>
                      Release
                    </Button>
                  ) : null}
                  {showCancel ? (
                    <Button size="sm" renderIcon={Close} kind="secondary" type="button" onClick={() => setReasonAction('cancel')}>
                      Cancel
                    </Button>
                  ) : null}
                </div>
              </div>
            ) : null}
          </div>
        </header>

        <div className="pharmacy-detail__body">
          <div className="pharmacy-detail__regimen-col">
            {(directionItems.length > 0 || props.selectedDosingInstruction) ? (
              <dl className="pharmacy-detail__grid pharmacy-detail__grid--compact">
                {directionItems.map((item) => (
                  <div key={item.label} className="pharmacy-detail__field pharmacy-detail__field--inline">
                    <dt>{item.label}</dt>
                    <dd>{item.value}</dd>
                  </div>
                ))}
              </dl>
            ) : null}
            {props.selectedDosingInstruction ? <p className="pharmacy-detail__instruction">{props.selectedDosingInstruction}</p> : null}
            {hasDirections === false && props.selectedDosingInstruction === null ? <p className="pharmacy-detail__muted">No directions recorded.</p> : null}
            {props.selectedTranscriptionNote ? (
              <p className="pharmacy-detail__note">{props.selectedTranscriptionNote}</p>
            ) : null}
          </div>

          <div className="pharmacy-detail__dispensing-col">
            <div className="pharmacy-detail__dispensing-compact">
              {props.dispensings.length === 0 ? (
                <span className="pharmacy-detail__muted">Nothing dispensed yet</span>
              ) : (
                <ul className="pharmacy-detail__dispensing-list pharmacy-detail__dispensing-list--compact">
                  {props.dispensings.map((dispensing) => (
                    <li key={dispensing.dispensingId}>
                      <div className="pharmacy-detail__dispensing-main">
                        <strong>{dispensing.itemName}</strong>
                        <span>{dispensing.quantity} {dispensing.quantityUnit} · {dispensing.status}</span>
                        <span>{dispensing.dispensingNumber}{dispensing.pickupStatus ? ` · Pickup ${dispensing.pickupStatus.replaceAll('_', ' ')}` : ''} · {shortDate(dispensing.receivedAt)}</span>
                      </div>
                      <span className="pharmacy-detail__actions pharmacy-detail__actions--compact">
                        {canReverseDispensing(dispensing) ? (
                          <Button size="sm" kind="ghost" type="button" onClick={() => setReverseTarget(dispensing)}>
                            Reverse
                          </Button>
                        ) : null}
                        {canRecordPickup(dispensing) ? (
                          <Button size="sm" kind="secondary" type="button" onClick={() => setPickupTarget(dispensing)}>
                            Record pickup
                          </Button>
                        ) : null}
                        {canMarkNotCollected(dispensing) ? (
                          <Button size="sm" kind="ghost" type="button" onClick={() => setPickupOutcome({ dispensing, action: 'not-collected' })}>
                            Not collected
                          </Button>
                        ) : null}
                        {canCancelPickup(dispensing) ? (
                          <Button size="sm" kind="secondary" type="button" onClick={() => setPickupOutcome({ dispensing, action: 'cancel-pickup' })}>
                            Cancel pickup
                          </Button>
                        ) : null}
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </div>
        </div>
      </Tile>
      {reasonAction ? (
        <OrderReasonDialog
          orderId={selectedId}
          orderNumber={orderNumber}
          action={reasonAction}
          onClose={() => setReasonAction(null)}
        />
      ) : null}
      {dispensingOpen && order ? (
        <DispensePanel
          order={order}
          prescription={prescription}
          items={props.items}
          locations={props.locations}
          availabilityStatus={props.availabilityStatus}
          stockByLocation={props.stockByLocation}
          selectedItemId={props.selectedItemId}
          selectedItemUom={props.selectedItemUom}
          patientAllergies={props.selectedPatientId === order.patientId ? props.selectedPatientAllergies : null}
          onClose={closeDispense}
        />
      ) : null}
      {reverseTarget ? (
        <ReverseDispensingDialog
          orderId={selectedId}
          orderNumber={orderNumber}
          dispensing={reverseTarget}
          onClose={() => setReverseTarget(null)}
        />
      ) : null}
      {pickupTarget ? (
        <PickupPanel
          orderId={selectedId}
          orderNumber={orderNumber}
          dispensing={pickupTarget}
          onClose={() => setPickupTarget(null)}
        />
      ) : null}
      {pickupOutcome ? (
        <PickupOutcomeDialog
          orderId={selectedId}
          dispensing={pickupOutcome.dispensing}
          action={pickupOutcome.action}
          onClose={() => setPickupOutcome(null)}
        />
      ) : null}
    </>
  );
}

function shortDate(value: string): string {
  const date = new Date(value);
  return Number.isNaN(date.getTime())
    ? value
    : date.toLocaleDateString(undefined, { day: '2-digit', month: 'short' });
}
