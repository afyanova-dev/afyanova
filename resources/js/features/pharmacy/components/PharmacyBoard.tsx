import { OverflowMenuItem, TabPanel, TabPanels, TableToolbarMenu, TableToolbarSearch, Tabs } from '@carbon/react';
import { usePage } from '@inertiajs/react';
import { useEffect, useState, type ChangeEvent, type ReactNode } from 'react';
import { Document, Filter, List, Medication } from '@/icons';
import { CarbonDataTable, ContainedTabList, EmptyState, IconTab } from '@/components/ui';
import { CreateOrderPanel } from './CreateOrderPanel';
import { OrderDetailPanel } from './OrderDetailPanel';
import { PrescriptionDetailPanel } from './PrescriptionDetailPanel';
import { refreshInPlace } from '../pharmacyNavigation';
import type { PharmacyBoardProps, RxPrescriptionItem } from '../types/pharmacy';

const PRESCRIPTION_HEADERS = [
  { key: 'created', header: 'Created' },
  { key: 'patient', header: 'Patient' },
  { key: 'prescriber', header: 'Prescriber' },
  { key: 'medication', header: 'Drugs' },
  { key: 'lastDispenser', header: 'Last dispenser' },
  { key: 'status', header: 'Status' },
];

const ORDER_HEADERS = [
  { key: 'orderNumber', header: 'Order #' },
  { key: 'created', header: 'Created' },
  { key: 'patient', header: 'Patient' },
  { key: 'medication', header: 'Drugs' },
  { key: 'status', header: 'Order status' },
];

const ORDER_STATUSES = [
  'RECEIVED',
  'VALIDATED',
  'PARTIALLY_DISPENSED',
  'DISPENSED',
  'ON_HOLD',
  'REJECTED',
  'CANCELLED',
];

type BoardTab = 'orders' | 'active' | 'all';

const BOARD_TABS: readonly { id: BoardTab; label: string }[] = [
  { id: 'orders', label: 'Orders' },
  { id: 'active', label: 'Active prescriptions' },
  { id: 'all', label: 'All prescriptions' },
];

type PatientPrescriptionGroup = { patientId: string; prescriptions: RxPrescriptionItem[] };

interface PharmacyBoardPanelOptions {
  hidePatientWorkspace?: boolean;
  onPatientWorkspaceOpen?: () => void;
}

function patientLabel(
  displayName: string,
  patientNumber: string,
  age: number | null,
  sex: string,
): ReactNode {
  const demographics = [
    age === null ? null : `${age} ${age === 1 ? 'year' : 'years'}`,
    sex === 'UNKNOWN' ? null : sex.toLocaleLowerCase().replace(/\b\w/g, (character) => character.toUpperCase()),
  ].filter((value): value is string => value !== null);

  return (
    <span className="pharmacy-worklist-patient">
      <strong>{displayName}</strong>
      <span>{[patientNumber, ...demographics].join(' · ')}</span>
    </span>
  );
}

function patientLabelText(displayName: string, patientNumber: string): string {
  return `${displayName} · ${patientNumber}`;
}

function medicationSummary(names: string[]): string {
  const counts = new Map<string, number>();
  names.filter(Boolean).forEach((name) => counts.set(name, (counts.get(name) ?? 0) + 1));
  const summaries = [...counts].map(([name, count]) => count > 1 ? `${name} × ${count}` : name);
  if (summaries.length <= 2) return summaries.join(', ') || 'Medication not recorded';
  return `${summaries.slice(0, 2).join(', ')} and ${summaries.length - 2} more`;
}

function statusSummary(statuses: string[]): string {
  const counts = new Map<string, number>();
  statuses.forEach((status) => counts.set(status, (counts.get(status) ?? 0) + 1));
  return [...counts].map(([status, count]) => `${status.replaceAll('_', ' ')} (${count})`).join(' · ');
}

function searchMatches(query: string, terms: string[]): boolean {
  const needle = query.trim().toLocaleLowerCase();
  return needle === '' || terms.join(' ').toLocaleLowerCase().includes(needle);
}

function displayDate(value: string): string {
  const date = new Date(value);
  return Number.isNaN(date.getTime())
    ? value
    : new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(date);
}

function createdSummary(values: string[]): string {
  const dates = [...new Set(values)]
    .sort((left, right) => new Date(left).getTime() - new Date(right).getTime());
  const first = dates[0];
  const last = dates.at(-1);
  if (!first || !last) return '—';
  if (first === last) return displayDate(first);
  return `${displayDate(first)} – ${displayDate(last)}`;
}

function nameSummary(names: Array<string | null>, fallback: string): string {
  const uniqueNames = [...new Set(names.filter((name): name is string => Boolean(name)))];
  if (uniqueNames.length === 0) return fallback;
  if (uniqueNames.length <= 2) return uniqueNames.join(', ');
  return `${uniqueNames.slice(0, 2).join(', ')} and ${uniqueNames.length - 2} more`;
}

function dispenserSummary(prescriptions: RxPrescriptionItem[]): string {
  const dispenserNames = nameSummary(
    prescriptions.map((prescription) => prescription.lastDispenserName),
    'Not dispensed',
  );
  const notDispensedCount = prescriptions.filter((prescription) => prescription.lastDispenserName === null).length;
  if (notDispensedCount === 0 || dispenserNames === 'Not dispensed') return dispenserNames;
  return `${dispenserNames} · ${notDispensedCount} not dispensed`;
}

function initialBoardTab(currentUrl: string): BoardTab {
  const params = new URL(currentUrl, 'http://afyanova.local').searchParams;
  if (params.get('view') === 'prescriptions') {
    return params.get('rx_status') === 'ACTIVE' ? 'active' : 'all';
  }
  return 'orders';
}

/**
 * Pharmacy board. Tabs switch locally (role-approvals pattern) — instant,
 * zero server traffic. Patient expansion contains the complete record
 * details and available actions; status filtering is client-side over the
 * loaded board.
 *
 * Selected-patient context (viewPatient / selectedPatient* / transcribe*)
 * remains in workflow panels. The board shows Orders / Active prescriptions /
 * All prescriptions tables with full record details in each patient expansion.
 */
export function PharmacyBoard(props: PharmacyBoardProps & PharmacyBoardPanelOptions): React.JSX.Element {
  const { url } = usePage();
  const [createFor, setCreateFor] = useState<RxPrescriptionItem | null>(null);
  const [orderStatusFilter, setOrderStatusFilter] = useState('ALL');
  const [orderSearch, setOrderSearch] = useState('');
  const [prescriptionSearch, setPrescriptionSearch] = useState('');
  const [activeTab, setActiveTab] = useState<BoardTab>(() => initialBoardTab(url));

  useEffect(() => {
    if (new URL(url, 'http://afyanova.local').searchParams.has('order_id')) {
      setActiveTab('orders');
    }
  }, [url]);

  function handleTabChange(index: number): void {
    const next = BOARD_TABS[index]?.id ?? 'orders';
    setActiveTab(next);
    setCreateFor(null);
    // Leaving patient/order/rx context behind: clean the URL in place so a
    // stale selection can never hijack the view on reload. Pure board
    // switches (no context) stay fully local — zero server traffic.
    const context = new URL(url, 'http://afyanova.local').searchParams;
    if (context.get('patient_id') || context.get('order_id') || context.get('rx_id')) {
      refreshInPlace(url, next === 'orders'
        ? { view: 'orders', patient_id: null, order_id: null, rx_id: null }
        : { view: 'prescriptions', rx_status: next === 'active' ? 'ACTIVE' : null, patient_id: null, order_id: null, rx_id: null });
    }
  }

  const selectedTab = activeTab === 'all' ? 2 : activeTab === 'active' ? 1 : 0;
  // Legacy ?view=patient&patient_id= deep links still expand that patient's
  // group inline now that the bottom dock is gone.
  const expandedOrderPatientId = props.selectedPatientId
    ?? props.orders.find((order) => order.pharmacyOrderId === props.selectedOrderId)?.patientId
    ?? (props.view === 'patient' ? props.viewPatient?.patientId ?? null : null);

  const visibleOrders = orderStatusFilter === 'ALL'
    ? props.orders
    : props.orders.filter((order) => order.status === orderStatusFilter);
  const activePrescriptions = props.prescriptions.filter(
    (prescription) => prescription.status === 'ACTIVE',
  );
  const ordersByPatient = new Map<string, typeof visibleOrders>();
  visibleOrders.forEach((order) => {
      const group = ordersByPatient.get(order.patientId) ?? [];
      group.push(order);
      ordersByPatient.set(order.patientId, group);
  });
  const visibleOrderGroups = [...ordersByPatient.entries()]
      .map(([patientId, orders]) => ({ patientId, orders }))
      .filter(({ orders }) => orders.some((order) => searchMatches(orderSearch, [
        order.orderNumber,
        order.patientDisplayName,
        order.patientNumber,
        String(order.patientAge ?? ''),
        order.patientSex ?? '',
        order.medicationName ?? '',
        order.status,
        order.priority,
      ])));
  const orderRows = visibleOrderGroups.map(({ patientId, orders }) => {
      const firstOrder = orders[0];
      if (!firstOrder) return null;
      return {
        id: patientId,
        orderNumber: orders.length > 1 ? `${firstOrder.orderNumber} + ${orders.length - 1} more` : firstOrder.orderNumber,
        created: createdSummary(orders.map((order) => order.occurredAt)),
        patient: patientLabel(
          firstOrder.patientDisplayName,
          firstOrder.patientNumber,
          firstOrder.patientAge ?? null,
          firstOrder.patientSex ?? 'UNKNOWN',
        ),
        medication: medicationSummary(orders.map((order) => order.medicationName ?? '')),
        status: statusSummary(orders.map((order) => order.status)),
      };
  }).filter((row): row is NonNullable<typeof row> => row !== null);

  function orderPatientName(patientId: string): string {
      const order = ordersByPatient.get(patientId)?.[0];
      return patientLabelText(order?.patientDisplayName ?? 'Unknown patient', order?.patientNumber ?? '—');
  }

  function orderDetails(patientId: string): ReactNode {
      const orders = ordersByPatient.get(patientId) ?? [];
      return (
        <div className="pharmacy-group-expansion pharmacy-group-expansion--compact">
          <ul className="pharmacy-medicine-list" aria-label={`${orders.length} patient orders`}>
            {orders.map((order) => {
              const prescription = props.prescriptions.find((item) => item.prescriptionId === order.prescriptionId);
              return (
                <li key={order.pharmacyOrderId} className="pharmacy-medicine-list__item">
                  <OrderDetailPanel
                    {...props}
                    selectedOrderId={order.pharmacyOrderId}
                    selectedOrderNumber={order.orderNumber}
                    selectedOrderStatus={order.status}
                    selectedMedicationName={order.medicationName}
                    selectedMedicationCode={prescription?.medicationCode ?? null}
                    selectedDose={prescription?.dose ?? null}
                    selectedFrequency={prescription?.frequency ?? null}
                    selectedDuration={prescription?.duration ?? null}
                    selectedPrescribedQuantity={prescription?.prescribedQuantity ?? null}
                    selectedDosingInstruction={prescription?.dosingInstruction ?? null}
                    selectedPrescriberName={prescription?.prescriberName ?? null}
                    selectedTranscriptionNote={prescription?.transcriptionNote ?? null}
                    dispensings={order.dispensings}
                  />
                </li>
              );
            })}
          </ul>
        </div>
      );
  }

  const prescriptionRows = (grouped: PatientPrescriptionGroup[]): {
    id: string;
    created: string;
    patient: ReactNode;
    prescriber: string;
    medication: string;
    lastDispenser: string;
    status: string;
  }[] => grouped.map(({ patientId, prescriptions }) => ({
    id: patientId,
    created: createdSummary(prescriptions.map((prescription) => prescription.occurredAt)),
    patient: patientLabel(
      prescriptions[0]?.patientDisplayName ?? 'Unknown patient',
      prescriptions[0]?.patientNumber ?? '—',
      prescriptions[0]?.patientAge ?? null,
      prescriptions[0]?.patientSex ?? 'UNKNOWN',
    ),
    prescriber: nameSummary(prescriptions.map((prescription) => prescription.prescriberName), 'Not recorded'),
    medication: medicationSummary(prescriptions.map((prescription) => prescription.medicationName ?? '')),
    lastDispenser: dispenserSummary(prescriptions),
    status: statusSummary(prescriptions.map((prescription) => prescription.status)),
  }));

  function prescriptionGroupDetails(
    patientId: string,
    prescriptionsByPatient: Map<string, RxPrescriptionItem[]>,
  ): ReactNode {
    const prescriptions = prescriptionsByPatient.get(patientId) ?? [];
    const intakes = new Map<string, RxPrescriptionItem[]>();
    prescriptions.forEach((prescription) => {
      const groupKey = prescription.source === 'EXTERNAL_PAPER' && prescription.externalIntakeId
        ? `intake:${prescription.externalIntakeId}`
        : `prescription:${prescription.prescriptionId}`;
      const group = intakes.get(groupKey) ?? [];
      group.push(prescription);
      intakes.set(groupKey, group);
    });

    return (
      <div className="pharmacy-group-expansion pharmacy-group-expansion--compact" aria-label={`${prescriptions.length} patient prescriptions`}>
        {[...intakes.entries()].map(([intakeKey, intakePrescriptions]) => {
          const first = intakePrescriptions[0];
          if (!first) return null;
          const isExternalIntake = first.source === 'EXTERNAL_PAPER' && first.externalIntakeId !== null;
          const intakeHeading = isExternalIntake
            ? `External paper intake · ${intakePrescriptions.length} ${intakePrescriptions.length === 1 ? 'medicine' : 'medicines'}`
            : first.source === 'EXTERNAL_PAPER'
              ? `External paper prescription · ${first.prescriptionNumber}`
              : `${first.source.replaceAll('_', ' ')} · ${first.prescriptionNumber}`;
          return (
            <section className="pharmacy-group-expansion__intake" key={intakeKey} aria-label={intakeHeading}>
              <header className="pharmacy-group-expansion__intake-heading">
                <div>
                  <strong>{intakeHeading}</strong>
                  <span>{displayDate(first.occurredAt)}{first.prescriberName ? ` · Prescribed by ${first.prescriberName}` : ''}</span>
                </div>
              </header>
              <ul className="pharmacy-medicine-list" aria-label={`${intakePrescriptions.length} prescriptions`}>
                {intakePrescriptions.map((prescription) => {
                  const linkedOrders = props.orders.filter((order) => (
                    order.prescriptionId === prescription.prescriptionId
                  ));
                  const hasOpenOrder = linkedOrders.some((order) => (
                    ['RECEIVED', 'VALIDATED', 'ON_HOLD', 'PARTIALLY_DISPENSED'].includes(order.status)
                  ));
                  return (
                    <li key={prescription.prescriptionId} className="pharmacy-medicine-list__item">
                      <PrescriptionDetailPanel
                        prescription={prescription}
                        medicationCode={prescription.medicationCode}
                        route={prescription.route}
                        urgency={prescription.urgency}
                        dose={prescription.dose}
                        frequency={prescription.frequency}
                        duration={prescription.duration}
                        prescribedQuantity={prescription.prescribedQuantity}
                        dosingInstruction={prescription.dosingInstruction}
                        transcriptionNote={prescription.transcriptionNote}
                        canCreateOrder={props.canManageOrders && !hasOpenOrder}
                        canAcceptPrescription={props.canTranscribe}
                        canCancelPrescription={props.canTranscribe}
                        linkedOrders={linkedOrders}
                        onCreateOrder={setCreateFor}
                      />
                    </li>
                  );
                })}
              </ul>
            </section>
          );
        })}
      </div>
    );
  }

  function setSearch(setter: (value: string) => void) {
    return (event: '' | ChangeEvent<HTMLInputElement>, value?: string): void => {
      setter(event === '' ? (value ?? '') : event.currentTarget.value);
    };
  }

  function prescriptionTable(
    prescriptions: RxPrescriptionItem[],
    title: string,
    ariaLabel: string,
    emptyDescription: string,
  ): ReactNode {
    const prescriptionsByPatient = new Map<string, RxPrescriptionItem[]>();
    prescriptions.filter((prescription) => searchMatches(prescriptionSearch, [
      prescription.patientDisplayName,
      prescription.patientNumber,
      String(prescription.patientAge ?? ''),
      prescription.patientSex ?? '',
      prescription.prescriptionNumber,
      prescription.medicationName ?? '',
      prescription.status,
      prescription.source,
      prescription.prescriberName ?? '',
    ])).forEach((prescription) => {
      const group = prescriptionsByPatient.get(prescription.patientId) ?? [];
      group.push(prescription);
      prescriptionsByPatient.set(prescription.patientId, group);
    });
    const groups = [...prescriptionsByPatient.entries()]
      .map(([patientId, groupPrescriptions]) => ({ patientId, prescriptions: groupPrescriptions }));

    return (
      <CarbonDataTable
        rows={prescriptionRows(groups)}
        headers={PRESCRIPTION_HEADERS}
        title={title}
        ariaLabel={ariaLabel}
        expandedRowId={props.selectedPatientId ?? (props.view === 'patient' ? props.viewPatient?.patientId ?? null : null)}
        getExpandRowLabel={(row) => {
          const patient = prescriptionsByPatient.get(row.id)?.[0];
          return patientLabelText(
            patient?.patientDisplayName ?? 'Unknown patient',
            patient?.patientNumber ?? '—',
          );
        }}
        emptyState={<EmptyState title="No prescriptions to display" description={emptyDescription} />}
        renderExpandedRow={(row) => prescriptionGroupDetails(row.id, prescriptionsByPatient)}
        toolbar={() => (
          <TableToolbarSearch
            persistent
            value={prescriptionSearch}
            onChange={setSearch(setPrescriptionSearch)}
            placeholder="Search prescriptions"
            aria-label="Search prescriptions"
          />
        )}
      />
    );
  }

  return (
    <div className="pharmacy-board__tabs">
      <Tabs selectedIndex={selectedTab} onChange={({ selectedIndex }) => handleTabChange(selectedIndex)}>
        <ContainedTabList aria-label="Pharmacy views">
          <IconTab icon={Document}>Orders</IconTab>
          <IconTab icon={Medication}>Active prescriptions</IconTab>
          <IconTab icon={List}>All prescriptions</IconTab>
        </ContainedTabList>
        <TabPanels>
          <TabPanel>
            <section aria-label="Orders">
              <CarbonDataTable
                rows={orderRows}
                headers={ORDER_HEADERS}
                title={`Orders (${visibleOrders.length})`}
                ariaLabel="Pharmacy orders"
                expandedRowId={expandedOrderPatientId}
                getExpandRowLabel={(row) => orderPatientName(row.id)}
                renderExpandedRow={(row) => orderDetails(row.id)}
                emptyState={(
                  <EmptyState
                    title="No orders to display"
                    description={orderStatusFilter === 'ALL'
                      ? 'Orders for prescriptions being fulfilled by this facility will appear here.'
                      : 'No orders match this status filter.'}
                  />
                )}
                toolbar={() => (
                  <>
                    <TableToolbarSearch
                      persistent
                      value={orderSearch}
                      onChange={setSearch(setOrderSearch)}
                      placeholder="Search orders"
                      aria-label="Search orders"
                    />
                    <TableToolbarMenu
                      renderIcon={Filter}
                      iconDescription={`Filter by status: ${orderStatusFilter === 'ALL' ? 'All statuses' : orderStatusFilter}`}
                    >
                      <OverflowMenuItem
                        itemText={`All statuses (${props.orders.length})`}
                        onClick={() => setOrderStatusFilter('ALL')}
                      />
                      {ORDER_STATUSES.map((status) => (
                        <OverflowMenuItem
                          key={status}
                          itemText={`${status} (${props.orders.filter((order) => order.status === status).length})`}
                          onClick={() => setOrderStatusFilter(status)}
                        />
                      ))}
                    </TableToolbarMenu>
                  </>
                )}
              />
            </section>
          </TabPanel>
          <TabPanel>
            <section aria-label="Active prescriptions">
              {prescriptionTable(
                activePrescriptions,
                `Active prescriptions (${activePrescriptions.length})`,
                'Active pharmacy prescriptions',
                'Active prescriptions will appear here once transcribed or ingested.',
              )}
            </section>
          </TabPanel>
          <TabPanel>
            <section aria-label="All prescriptions">
              {prescriptionTable(
                props.prescriptions,
                `Prescriptions (${props.prescriptions.length})`,
                'Pharmacy prescriptions',
                'Transcribed and ingested prescriptions will appear here.',
              )}
            </section>
          </TabPanel>
        </TabPanels>
      </Tabs>
      {createFor ? (
        <CreateOrderPanel prescription={createFor} onClose={() => setCreateFor(null)} />
      ) : null}
    </div>
  );
}
