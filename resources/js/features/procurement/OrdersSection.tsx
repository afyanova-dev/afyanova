import {
  Button,
  IconButton,
  InlineNotification,
  Modal,
  OverflowMenuItem,
  Select,
  SelectItem,
  Stack,
  TableToolbarMenu,
  TableToolbarSearch,
  Tag,
  TextArea,
  TextInput,
} from '@carbon/react';
import { router, useForm, usePage } from '@inertiajs/react';
import { Add, Checkmark, Close, Edit, Export, Filter, Money, Pending, Send, TrashCan } from '@/icons';
import { useMemo, useState, type FormEvent, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import { DockedPanel } from '@/components/panels';
import { CarbonDataTable, FormErrors, SearchableComboBox, StatisticsCards } from '@/components/ui';
import { getJson } from '@/lib/http/json';
import {
  formatMoney,
  formatStatus,
  type InitialRequest,
  type ProcurementItem,
  type PurchaseOrder,
  type Supplier,
} from './types';

export interface OrdersSectionProps {
  orders: PurchaseOrder[];
  suppliers: Supplier[];
  items: ProcurementItem[];
  currentUserId: string | null;
  canCreate: boolean;
  /** This facility's one-person approval/issue limit, or null if unset. */
  approvalLimit: string | null;
  /** An APPROVED request to order (from the Requests screen), or null. */
  initialRequest: InitialRequest | null;
  /** Approved requests without an active order that the buyer may select. */
  orderableRequests: InitialRequest[];
}

type OrderLineDraft = {
  item_id: string;
  /** Blank = the item's base unit; otherwise its buying unit (e.g. BOX). */
  unit: string;
  quantity: string;
  /** Base units in one `unit`; blank when ordering in the base unit. */
  pack_size: string;
  unit_price: string;
};
type CreateOrderForm = {
  supplier_id: string;
  requisition_id: string;
  priority: string;
  cost_centre_ref: string;
  budget_ref: string;
  notes: string;
  duplicate_override_reason: string;
  lines: OrderLineDraft[];
};

interface ItemPricePoint {
  poNumber: string;
  unitPrice: string;
  baseUnitPrice: string;
  unit: string;
  uom: string;
  packSize: string | null;
  orderedAt: string;
}

interface ContractPriceReference {
  contractNumber: string;
  unitPrice: string;
  uom: string;
  remaining: string | null;
}

interface ItemPriceReference {
  currency: string;
  lastSupplierPrice: ItemPricePoint | null;
  lastAnySupplierPrice: ItemPricePoint | null;
  contractPrice: ContractPriceReference | null;
}

const HEADERS = [
  { key: 'poNumber', header: 'Order' },
  { key: 'supplier', header: 'Supplier' },
  { key: 'status', header: 'Status' },
  { key: 'total', header: 'Total' },
  { key: 'lineCount', header: 'Lines' },
  { key: 'createdAt', header: 'Raised' },
  { key: 'actions', header: 'Actions' },
];

const STATUS_TAG: Record<string, 'gray' | 'blue' | 'purple' | 'teal' | 'cyan' | 'green' | 'red'> = {
  DRAFT: 'gray',
  SUBMITTED: 'blue',
  APPROVED: 'purple',
  ISSUED: 'teal',
  PARTIALLY_RECEIVED: 'cyan',
  FULLY_RECEIVED: 'green',
  CLOSED_SHORT: 'red',
  CANCELLED: 'red',
};

const PRIORITIES = ['ROUTINE', 'URGENT'];
const MAX_LINES = 500;

const EMPTY_LINE: OrderLineDraft = {
  item_id: '',
  unit: '',
  quantity: '1',
  pack_size: '',
  unit_price: '',
};

function priceReferenceKey(supplierId: string, itemId: string): string {
  return `${supplierId}:${itemId}`;
}

function priceForLine(
  reference: ItemPricePoint,
  line: OrderLineDraft,
  item: ProcurementItem,
): string {
  const unit = line.unit === '' ? item.uom : line.unit;

  if (reference.unit === unit) {
    return reference.unitPrice;
  }

  if (unit === item.packUnit && line.pack_size !== '') {
    return (Number(reference.baseUnitPrice) * Number(line.pack_size)).toString();
  }

  return reference.baseUnitPrice;
}

function formatDate(value: string | null): string {
  if (value === null) {
    return '—';
  }

  const date = new Date(value);

  return Number.isNaN(date.getTime()) ? '—' : date.toLocaleDateString('en-GB');
}

/**
 * The one-person rule, in the operator's words. `selfApprovalPermitted` is the
 * server's answer for THIS order — the UI never recomputes the limit.
 */
function nextStepHint(order: PurchaseOrder, currentUserId: string | null): string | null {
  if (order.status === 'SUBMITTED' && currentUserId !== null && order.createdByUserId === currentUserId) {
    return order.selfApprovalPermitted
      ? 'You raised this order. It is within the one-person limit, so you may approve it yourself.'
      : 'You raised this order. Someone else has to approve it — it is above the one-person limit.';
  }

  if (order.status === 'APPROVED' && currentUserId !== null && order.approvedByUserId === currentUserId) {
    return order.selfApprovalPermitted
      ? 'You approved this order. It is within the one-person limit, so you may issue it too.'
      : 'You approved this order. Someone else has to issue it to the supplier.';
  }

  if (order.status === 'ISSUED' || order.status === 'PARTIALLY_RECEIVED') {
    return 'Sent to the supplier. Receive the goods in Inventory against this order.';
  }

  if (order.status === 'CLOSED_SHORT') {
    return 'Closed short: the outstanding balance will not be delivered. Received goods stay in stock.';
  }

  if (order.status === 'DRAFT') {
    return 'A draft is only visible to your facility. Submit it when the lines are right.';
  }

  return null;
}

export function OrdersSection({
  orders,
  suppliers,
  items,
  currentUserId,
  canCreate,
  approvalLimit,
  initialRequest,
  orderableRequests,
}: OrdersSectionProps): ReactNode {
  const page = usePage();
  const pageErrors = (page.props.errors ?? {}) as Record<string, string | string[] | undefined>;
  const orderError = pageErrors.order;

  const [statusFilter, setStatusFilter] = useState('');
  const [creating, setCreating] = useState(initialRequest !== null);
  const [cancelling, setCancelling] = useState<PurchaseOrder | null>(null);
  const [closingShort, setClosingShort] = useState<PurchaseOrder | null>(null);
  const [busyId, setBusyId] = useState<string | null>(null);
  const [linesImportOpen, setLinesImportOpen] = useState(false);
  const [linesImportText, setLinesImportText] = useState('');
  const [linesImportError, setLinesImportError] = useState<string | null>(null);
  const [priceReferences, setPriceReferences] = useState<Record<string, ItemPriceReference>>({});
  const [priceReferenceErrors, setPriceReferenceErrors] = useState<Record<string, string>>({});
  const [loadingPriceReference, setLoadingPriceReference] = useState<Record<string, boolean>>({});

  const createForm = useForm<CreateOrderForm>({
    supplier_id: '',
    requisition_id: initialRequest?.id ?? '',
    priority: initialRequest?.priority ?? 'ROUTINE',
    cost_centre_ref: initialRequest?.costCentreRef ?? '',
    budget_ref: initialRequest?.budgetRef ?? '',
    notes: initialRequest?.reason ?? '',
    duplicate_override_reason: '',
    lines: initialRequest === null || initialRequest.lines.length === 0
      ? [{ ...EMPTY_LINE }]
      : initialRequest.lines.map((line) => ({
        item_id: line.itemId,
        unit: line.unit === line.uom ? '' : line.unit,
        quantity: line.quantity,
        pack_size: line.packSize ?? '',
        unit_price: '',
      })),
  });

  const cancelForm = useForm({ reason: '' });
  const closeShortForm = useForm({ reason: '' });

  const activeSuppliers = useMemo(
    () => suppliers.filter((supplier) => supplier.status === 'ACTIVE'),
    [suppliers],
  );

  const requestOptions = useMemo(() => {
    const requests = new Map(orderableRequests.map((request) => [request.id, request]));
    if (initialRequest !== null) {
      requests.set(initialRequest.id, initialRequest);
    }

    return [...requests.values()];
  }, [initialRequest, orderableRequests]);

  const selectedRequest = requestOptions.find((request) => request.id === createForm.data.requisition_id) ?? null;

  const supplierNames = useMemo(
    () => new Map(suppliers.map((supplier) => [supplier.id, supplier.name])),
    [suppliers],
  );

  const ordersById = useMemo(
    () => new Map(orders.map((order) => [order.id, order])),
    [orders],
  );

  const itemsById = useMemo(
    () => new Map(items.map((item) => [item.id, item])),
    [items],
  );

  const statuses = useMemo(() => {
    const present = new Set(orders.map((order) => order.status));

    return Object.keys(STATUS_TAG).filter((status) => present.has(status));
  }, [orders]);

  const filtered = useMemo(
    () => orders.filter((order) => statusFilter === '' || order.status === statusFilter),
    [orders, statusFilter],
  );

  const rows = useMemo(
    () => filtered.map((order) => ({
      id: order.id,
      poNumber: order.poNumber,
      supplier: supplierNames.get(order.supplierId) ?? 'Unknown supplier',
      status: order.status,
      total: formatMoney(order.orderTotal, order.currency),
      lineCount: String(order.lines.length),
      createdAt: formatDate(order.createdAt),
      actions: '',
    })),
    [filtered, supplierNames],
  );

  const draftCount = orders.filter((order) => order.status === 'DRAFT').length;
  const awaitingApprovalCount = orders.filter((order) => order.status === 'SUBMITTED').length;
  const withSupplierCount = orders.filter(
    (order) => order.status === 'ISSUED' || order.status === 'PARTIALLY_RECEIVED',
  ).length;
  const committedTotal = orders
    .filter((order) => order.status !== 'CANCELLED')
    .reduce((sum, order) => sum + Number(order.orderTotal), 0);
  const currency = orders[0]?.currency ?? 'TZS';

  const draftTotal = useMemo(
    () => createForm.data.lines.reduce((sum, line) => {
      const quantity = Number(line.quantity);
      const price = Number(line.unit_price);

      return Number.isFinite(quantity) && Number.isFinite(price) ? sum + quantity * price : sum;
    }, 0),
    [createForm.data.lines],
  );

  const draftIncomplete = createForm.data.supplier_id === ''
    || createForm.data.lines.some((line) => {
      const item = itemsById.get(line.item_id);
      const packed = item !== undefined && item.packUnit !== null && line.unit === item.packUnit;

      return line.item_id === ''
        || line.quantity.trim() === ''
        || Number(line.quantity) <= 0
        || line.unit_price.trim() === ''
        || Number(line.unit_price) < 0
        || (packed && (line.pack_size.trim() === '' || Number(line.pack_size) <= 0));
    });

  function openCreate(): void {
    createForm.clearErrors();
    createForm.setData({
      supplier_id: '',
      requisition_id: '',
      priority: 'ROUTINE',
      cost_centre_ref: '',
      budget_ref: '',
      notes: '',
      duplicate_override_reason: '',
      lines: [{ ...EMPTY_LINE }],
    });
    setPriceReferences({});
    setPriceReferenceErrors({});
    setLoadingPriceReference({});
    setCreating(true);
  }

  function selectRequest(requestId: string): void {
    const request = requestOptions.find((option) => option.id === requestId) ?? null;
    createForm.setData({
      ...createForm.data,
      requisition_id: request?.id ?? '',
      priority: request?.priority ?? 'ROUTINE',
      cost_centre_ref: request?.costCentreRef ?? '',
      budget_ref: request?.budgetRef ?? '',
      notes: request?.reason ?? '',
      lines: request === null
        ? [{ ...EMPTY_LINE }]
        : request.lines.length === 0
          ? [{ ...EMPTY_LINE }]
          : request.lines.map((line) => ({
            item_id: line.itemId,
            unit: line.unit === line.uom ? '' : line.unit,
            quantity: line.quantity,
            pack_size: line.packSize ?? '',
            unit_price: '',
          })),
    });
    setPriceReferences({});
    setPriceReferenceErrors({});
    setLoadingPriceReference({});
  }

  function closeCreate(): void {
    createForm.reset();
    setPriceReferences({});
    setPriceReferenceErrors({});
    setLoadingPriceReference({});
    setCreating(false);
  }

  function submitCreate(event: FormEvent): void {
    event.preventDefault();
    createForm.post(routes.workspaces.procurementOrdersStore, {
      preserveScroll: true,
      onSuccess: () => {
        createForm.reset();
        setPriceReferences({});
        setCreating(false);
      },
    });
  }

  function updateLine(index: number, patch: Partial<OrderLineDraft>): void {
    createForm.setData('lines', createForm.data.lines.map((line, position) => (
      position === index ? { ...line, ...patch } : line
    )));
  }

  /**
   * Choosing an item also chooses the unit it is normally bought in. The buyer
   * can switch back to the base unit — a whole pack is never required.
   */
  function selectItem(index: number, itemId: string): void {
    const item = itemsById.get(itemId);
    const packUnit = item?.packUnit ?? null;
    const packSize = item?.packSize ?? null;

    updateLine(index, {
      item_id: itemId,
      unit: packUnit ?? '',
      pack_size: packSize ?? '',
    });
  }

  async function loadPriceReference(index: number): Promise<void> {
    const line = createForm.data.lines[index];
    const supplierId = createForm.data.supplier_id;

    if (line === undefined || line.item_id === '' || supplierId === '') {
      return;
    }

    const key = priceReferenceKey(supplierId, line.item_id);
    const cached = priceReferences[key];

    if (cached !== undefined) {
      applyPriceReference(index, cached, supplierId, line.item_id);

      return;
    }

    setLoadingPriceReference((current) => ({ ...current, [key]: true }));
    setPriceReferenceErrors((current) => ({ ...current, [key]: '' }));

    try {
      const query = new URLSearchParams({ supplier_id: supplierId, item_id: line.item_id });
      const payload = await getJson<{ data?: ItemPriceReference }>(
        `${routes.workspaces.procurementItemPrices}?${query.toString()}`,
      );

      if (payload.data === undefined) {
        throw new Error('The price reference response was incomplete.');
      }

      const reference = payload.data;
      setPriceReferences((current) => ({ ...current, [key]: reference }));
      applyPriceReference(index, reference, supplierId, line.item_id);
    } catch (error) {
      setPriceReferenceErrors((current) => ({
        ...current,
        [key]: error instanceof Error ? error.message : 'Price references could not be loaded.',
      }));
    } finally {
      setLoadingPriceReference((current) => ({ ...current, [key]: false }));
    }
  }

  function applyPriceReference(
    index: number,
    reference: ItemPriceReference,
    expectedSupplierId: string,
    expectedItemId: string,
  ): void {
    const line = createForm.data.lines[index];
    const item = itemsById.get(expectedItemId);

    if (line === undefined
      || line.item_id !== expectedItemId
      || createForm.data.supplier_id !== expectedSupplierId
      || item === undefined
      || line.unit_price.trim() !== '') {
      return;
    }

    const historicalPrice = reference.lastSupplierPrice ?? reference.lastAnySupplierPrice;

    if (historicalPrice !== null) {
      updateLine(index, { unit_price: priceForLine(historicalPrice, line, item) });
    }
  }

  function changeUnit(index: number, unit: string): void {
    const item = itemsById.get(createForm.data.lines[index]?.item_id ?? '');
    const packSize = item !== undefined && item.packUnit === unit ? item.packSize : null;

    updateLine(index, { unit, pack_size: packSize ?? '' });
  }

  /**
   * P1-12 — paste an order sheet instead of typing 300 lines.
   *
   * One row per line: `item code, quantity, unit price` (base units; the buyer
   * can switch a line to its buying unit afterwards). The paste replaces the
   * current draft lines so a clean sheet is predictable.
   */
  function applyImportedOrderLines(): void {
    const rows = linesImportText
      .split(/\r?\n/)
      .map((row) => row.trim())
      .filter((row) => row !== '');

    if (rows.length === 0) {
      setLinesImportError('Paste at least one item code, quantity and price.');

      return;
    }

    const itemsByCode = new Map(items.map((item) => [item.code.toUpperCase(), item]));
    const drafts: OrderLineDraft[] = [];
    const errors: string[] = [];

    rows.forEach((row, rowIndex) => {
      const parts = row.split(/[,\t;]/).map((part) => part.trim());

      if (rowIndex === 0 && /^item(\s*code)?$/i.test(parts[0] ?? '')) {
        return;
      }

      if (parts.length < 3 || parts[0] === '') {
        errors.push(`Row ${rowIndex + 1}: expected item code, quantity, unit price.`);

        return;
      }

      const code = parts[0] ?? '';
      const item = itemsByCode.get(code.toUpperCase());

      if (item === undefined) {
        errors.push(`Row ${rowIndex + 1}: “${code}” is not in the item catalogue.`);

        return;
      }

      const quantity = Number(parts[1]);
      const unitPrice = Number(parts[2]);

      if (!Number.isFinite(quantity) || quantity <= 0) {
        errors.push(`Row ${rowIndex + 1}: quantity must be more than zero.`);

        return;
      }

      if (!Number.isFinite(unitPrice) || unitPrice < 0) {
        errors.push(`Row ${rowIndex + 1}: unit price must be zero or more.`);

        return;
      }

      drafts.push({
        item_id: item.id,
        unit: '',
        quantity: String(quantity),
        pack_size: '',
        unit_price: String(unitPrice),
      });
    });

    if (errors.length > 0) {
      setLinesImportError(errors.slice(0, 3).join(' '));

      return;
    }

    if (drafts.length > MAX_LINES) {
      setLinesImportError(`An order can contain at most ${MAX_LINES} lines.`);

      return;
    }

    createForm.setData('lines', drafts);
    setLinesImportOpen(false);
    setLinesImportText('');
    setLinesImportError(null);
  }

  function addLine(): void {
    if (createForm.data.lines.length >= MAX_LINES) {
      return;
    }

    createForm.setData('lines', [...createForm.data.lines, { ...EMPTY_LINE }]);
  }

  function removeLine(index: number): void {
    createForm.setData('lines', createForm.data.lines.filter((_line, position) => position !== index));
  }

  function openCancel(order: PurchaseOrder): void {
    cancelForm.clearErrors();
    cancelForm.setData('reason', '');
    setCancelling(order);
  }

  function closeCancel(): void {
    cancelForm.reset();
    setCancelling(null);
  }

  function submitCancel(event: FormEvent): void {
    event.preventDefault();

    if (cancelling === null) {
      return;
    }

    cancelForm.post(routes.workspaces.procurementOrderCancel(cancelling.id), {
      preserveScroll: true,
      onSuccess: () => {
        cancelForm.reset();
        setCancelling(null);
      },
    });
  }

  function openCloseShort(order: PurchaseOrder): void {
    closeShortForm.clearErrors();
    closeShortForm.setData('reason', '');
    setClosingShort(order);
  }

  function closeCloseShort(): void {
    closeShortForm.reset();
    setClosingShort(null);
  }

  function submitCloseShort(event: FormEvent): void {
    event.preventDefault();

    if (closingShort === null) {
      return;
    }

    closeShortForm.post(routes.workspaces.procurementOrderCloseShort(closingShort.id), {
      preserveScroll: true,
      onSuccess: () => {
        closeShortForm.reset();
        setClosingShort(null);
      },
    });
  }

  function runAction(order: PurchaseOrder, action: 'submit' | 'approve' | 'issue'): void {
    const url = action === 'submit'
      ? routes.workspaces.procurementOrderSubmit(order.id)
      : action === 'approve'
        ? routes.workspaces.procurementOrderApprove(order.id)
        : routes.workspaces.procurementOrderIssue(order.id);

    setBusyId(order.id);
    router.post(url, {}, {
      preserveScroll: true,
      onFinish: () => setBusyId(null),
    });
  }

  return (
    <section aria-labelledby="procurement-orders-heading">
      <div className="afy-section__heading">
        <div>
          <h2 id="procurement-orders-heading" className="afy-section__title">Purchase orders</h2>
          <p className="afy-section__description">
            Draft → waiting for approval → approved → sent to the supplier. Receiving the goods
            happens in Inventory.
            {typeof approvalLimit === 'string' && approvalLimit !== ''
              ? ` One person may approve and issue an order up to ${formatMoney(approvalLimit, 'TZS')} at this facility — above that, a second person is required.`
              : ''}
          </p>
        </div>
      </div>

      <StatisticsCards
        label="Purchase order summary"
        cards={[
          {
            id: 'order-drafts',
            label: 'Drafts',
            value: draftCount,
            detail: 'Not yet submitted for approval',
            borderTone: 'information',
            icon: <Edit aria-hidden="true" />,
          },
          {
            id: 'order-awaiting-approval',
            label: 'Awaiting approval',
            value: awaitingApprovalCount,
            detail: 'Submitted; a second person signs off above the limit',
            borderTone: awaitingApprovalCount > 0 ? 'caution' : 'information',
            icon: <Pending aria-hidden="true" />,
          },
          {
            id: 'order-with-supplier',
            label: 'With the supplier',
            value: withSupplierCount,
            detail: 'Issued, or partly received in Inventory',
            borderTone: 'information',
            icon: <Send aria-hidden="true" />,
          },
          {
            id: 'order-committed-value',
            label: 'Committed value',
            value: formatMoney(committedTotal.toFixed(2), currency),
            detail: 'All orders except cancelled',
            borderTone: 'information',
            icon: <Money aria-hidden="true" />,
          },
        ]}
      />

      {pageErrors.order ? (
        <div role="alert" className="procurement-alert">
          <InlineNotification
            kind="error"
            lowContrast
            hideCloseButton
            title={String(pageErrors.order)}
          />
        </div>
      ) : null}

      <CarbonDataTable
        rows={rows}
        headers={HEADERS}
        title={`Orders (${filtered.length} of ${orders.length})`}
        ariaLabel="Purchase orders"
        emptyState={(
          <p>
            {orders.length === 0
              ? 'No purchase orders yet.'
              : 'No order matches the selected status.'}
          </p>
        )}
        toolbar={(onInputChange) => (
          <>
            <TableToolbarSearch
              persistent
              onChange={onInputChange}
              placeholder="Search orders"
              aria-label="Search purchase orders"
            />
            <TableToolbarMenu
              renderIcon={Filter}
              iconDescription={`Filter by status: ${statusFilter === '' ? 'All statuses' : formatStatus(statusFilter)}`}
            >
              <OverflowMenuItem
                itemText={`All statuses (${orders.length})`}
                isDelete={false}
                onClick={() => setStatusFilter('')}
              />
              {statuses.map((status) => (
                <OverflowMenuItem
                  key={status}
                  itemText={`${formatStatus(status)} (${orders.filter((order) => order.status === status).length})`}
                  isDelete={false}
                  onClick={() => setStatusFilter(status)}
                />
              ))}
            </TableToolbarMenu>
            {/* Export first, secondary-tier (tertiary outline with its own
                icon) at the same size as the primary action so the toolbar is
                one aligned row — and the create action anchors the right edge. */}
            <Button
              kind="tertiary"
              size="lg"
              renderIcon={Export}
              href={`${routes.workspaces.procurementOrdersExport}${statusFilter === ''
                ? ''
                : `?status=${encodeURIComponent(statusFilter)}`}`}
            >
              Export Excel
            </Button>
            {canCreate ? (
              <Button size="lg" onClick={openCreate}>
                New purchase order
              </Button>
            ) : null}
          </>
        )}
        getExpandRowLabel={(row) => String(row.cells[0]?.value ?? 'order')}
        renderExpandedRow={(row) => {
          const order = ordersById.get(String(row.id));

          if (order === undefined) {
            return null;
          }

          const hint = nextStepHint(order, currentUserId);

          return (
            <Stack gap={4}>
              <div className="procurement-actions">
                <Button
                  kind="tertiary"
                  size="sm"
                  href={routes.workspaces.procurementOrderPdf(order.id)}
                  target="_blank"
                  rel="noreferrer"
                >
                  Print / save PDF
                </Button>
              </div>
              <p>
                <strong>Cost centre:</strong> {order.costCentreRef ?? '—'}
                {' · '}
                <strong>Budget reference:</strong> {order.budgetRef ?? '—'}
              </p>
              <table className="procurement-lines" aria-label={`Lines for ${order.poNumber}`}>
                <thead>
                  <tr>
                    <th scope="col">Item</th>
                    <th scope="col">Ordered</th>
                    <th scope="col">Unit price</th>
                    <th scope="col">Line total</th>
                    <th scope="col">Received</th>
                  </tr>
                </thead>
                <tbody>
                  {order.lines.map((line) => (
                    <tr key={line.id}>
                      <td>
                        {line.itemName !== null
                          ? (
                            <>
                              <span>{line.itemName}</span>
                              {line.itemCode !== null ? <code className="procurement-code"> {line.itemCode}</code> : null}
                            </>
                          )
                          : line.description ?? '—'}
                      </td>
                      <td>
                        {line.packSize !== null
                          ? `${line.orderedQty} ${line.unit} (${line.orderedBaseQty} ${line.uom})`
                          : `${line.orderedQty} ${line.uom}`}
                      </td>
                      <td>{`${formatMoney(line.unitPrice, order.currency)} / ${line.unit}`}</td>
                      <td>{formatMoney(line.lineTotal, order.currency)}</td>
                      <td>{`${line.receivedQty} ${line.uom}`}</td>
                    </tr>
                  ))}
                </tbody>
              </table>

              {order.notes ? <p><strong>Notes:</strong> {order.notes}</p> : null}
              {order.cancellationReason ? (
                <p><strong>Cancelled:</strong> {order.cancellationReason}</p>
              ) : null}
              {order.closedShortReason ? (
                <p><strong>Closed short:</strong> {order.closedShortReason}</p>
              ) : null}
              {hint !== null ? <p className="procurement-hint">{hint}</p> : null}
            </Stack>
          );
        }}
        renderCell={(cell, row) => {
          if (cell.info.header === 'poNumber') {
            return <code className="procurement-code">{String(cell.value)}</code>;
          }

          if (cell.info.header === 'status') {
            const status = String(cell.value);

            return (
              <Tag type={STATUS_TAG[status] ?? 'gray'} size="sm">
                {formatStatus(status)}
              </Tag>
            );
          }

          if (cell.info.header === 'total') {
            return <span className="procurement-strong">{String(cell.value)}</span>;
          }

          if (cell.info.header === 'actions') {
            const order = ordersById.get(String(row.id));

            if (order === undefined) {
              return null;
            }

            const busy = busyId === order.id;

            // Carbon hierarchy, per row: `tertiary` outlines THE next step in
            // the order's workflow (one is available at a time); the stop
            // action stays a `danger--ghost` so cancelling reads as the
            // separate, warning-coloured thing it is.
            return (
              <div className="procurement-actions">
                {order.allowedActions.includes('submit') ? (
                  <Button
                    kind="tertiary"
                    size="sm"
                    renderIcon={Send}
                    disabled={busy}
                    onClick={() => runAction(order, 'submit')}
                  >
                    Submit for approval
                  </Button>
                ) : null}
                {order.allowedActions.includes('approve') ? (
                  <Button
                    kind="tertiary"
                    size="sm"
                    renderIcon={Checkmark}
                    disabled={busy}
                    onClick={() => runAction(order, 'approve')}
                  >
                    Approve
                  </Button>
                ) : null}
                {order.allowedActions.includes('issue') ? (
                  <Button
                    kind="tertiary"
                    size="sm"
                    renderIcon={Send}
                    disabled={busy}
                    onClick={() => runAction(order, 'issue')}
                  >
                    Issue to supplier
                  </Button>
                ) : null}
                {order.allowedActions.includes('close-short') ? (
                  <Button
                    kind="danger--ghost"
                    size="sm"
                    disabled={busy}
                    onClick={() => openCloseShort(order)}
                  >
                    Close short
                  </Button>
                ) : null}
                {order.allowedActions.includes('cancel') ? (
                  <Button
                    kind="danger--ghost"
                    size="sm"
                    renderIcon={Close}
                    disabled={busy}
                    onClick={() => openCancel(order)}
                  >
                    Cancel
                  </Button>
                ) : null}
              </div>
            );
          }

          return cell.value;
        }}
      />

      <DockedPanel
        heading="New purchase order"
        ariaLabel="New purchase order"
        hidden={!creating}
        onClose={closeCreate}
        onSubmit={submitCreate}
        primaryLabel={createForm.processing ? 'Creating…' : 'Create draft order'}
        primaryDisabled={createForm.processing || draftIncomplete || activeSuppliers.length === 0}
        width="min(56rem, 100%)"
        expandedWidth="min(75rem, 100%)"
        expandable
      >
        <div className="procurement-panel-form">
          <FormErrors
            errors={createForm.errors}
            fields={['supplier_id', 'requisition_id', 'priority', 'notes']}
          />

          {orderError ? (
            <InlineNotification
              kind="error"
              lowContrast
              hideCloseButton
              title={Array.isArray(orderError) ? orderError.join(' ') : orderError}
            />
          ) : null}

          <SearchableComboBox
            id="order-source-request"
            titleText="Internal request (optional)"
            items={[
              { id: 'direct-order', label: 'Direct purchase order — no internal request' },
              ...requestOptions.map((request) => ({
                id: request.id,
                label: `${request.requisitionNumber}${request.departmentName ? ` — ${request.departmentName}` : ''}`,
              })),
            ]}
            selectedId={createForm.data.requisition_id || 'direct-order'}
            onChange={selectRequest}
            showAllOnOpen
            helperText="Choose an approved request to copy its department and lines, or leave this as a direct order."
          />

          {selectedRequest !== null ? (
            <InlineNotification
              kind="info"
              lowContrast
              hideCloseButton
              title={`Using request ${selectedRequest.requisitionNumber}${selectedRequest.departmentName ? ` — ${selectedRequest.departmentName}` : ''}`}
              subtitle="Its department and lines are copied into this order. Set the supplier and price each line."
            />
          ) : null}

          {selectedRequest !== null && selectedRequest.unorderableLineCount > 0 ? (
            <InlineNotification
              kind="warning"
              lowContrast
              hideCloseButton
              title={`${selectedRequest.unorderableLineCount} line(s) cannot be ordered here`}
              subtitle="This screen orders stock items; add any service lines separately."
            />
          ) : null}

          {activeSuppliers.length === 0 ? (
            <InlineNotification
              kind="info"
              lowContrast
              hideCloseButton
              title="No active supplier"
              subtitle="Register a supplier on the Suppliers screen before raising an order."
            />
          ) : null}

          <section className="procurement-panel-form__section" aria-labelledby="order-details-heading">
            <div className="procurement-panel-form__section-head">
              <h3 id="order-details-heading" className="procurement-panel-form__section-title">
                Order details
              </h3>
            </div>

            <div className="procurement-panel-form__grid procurement-panel-form__grid--details">
              <Select
                id="order-supplier"
                labelText="Supplier"
                size="sm"
                value={createForm.data.supplier_id}
                onChange={(event) => createForm.setData('supplier_id', event.currentTarget.value)}
                invalid={Boolean(createForm.errors.supplier_id)}
                invalidText={createForm.errors.supplier_id}
              >
                <SelectItem value="" text="Choose a supplier" />
                {activeSuppliers.map((supplier) => (
                  <SelectItem
                    key={supplier.id}
                    value={supplier.id}
                    text={`${supplier.name} (${supplier.code})`}
                  />
                ))}
              </Select>

              <Select
                id="order-priority"
                labelText="Priority"
                size="sm"
                value={createForm.data.priority}
                onChange={(event) => createForm.setData('priority', event.currentTarget.value)}
              >
                {PRIORITIES.map((priority) => (
                  <SelectItem key={priority} value={priority} text={formatStatus(priority)} />
                ))}
              </Select>
            </div>
          </section>

          <section className="procurement-panel-form__section" aria-labelledby="order-lines-heading">
            <div className="procurement-panel-form__section-head">
              <div className="procurement-panel-form__section-heading">
                <h3 id="order-lines-heading" className="procurement-panel-form__section-title">
                  Line items
                </h3>
                <p className="procurement-panel-form__line-summary">
                  <span>{`${createForm.data.lines.length} of ${MAX_LINES} lines`}</span>
                  <span aria-hidden="true">·</span>
                  <span>
                    Draft total: <strong>{formatMoney(draftTotal.toFixed(2), 'TZS')}</strong>
                  </span>
                </p>
              </div>

              <div className="procurement-panel-form__line-actions">
                <Button
                  kind="tertiary"
                  size="sm"
                  renderIcon={Add}
                  onClick={addLine}
                  disabled={createForm.data.lines.length >= MAX_LINES}
                >
                  Add line
                </Button>
                <Button
                  kind="tertiary"
                  size="sm"
                  onClick={() => {
                    setLinesImportError(null);
                    setLinesImportOpen(true);
                  }}
                  disabled={items.length === 0}
                >
                  Paste lines
                </Button>
              </div>
            </div>

          <div className="procurement-line-editor">
            {createForm.data.lines.map((line, index) => {
              const item = itemsById.get(line.item_id);
              const priceKey = line.item_id === '' || createForm.data.supplier_id === ''
                ? ''
                : priceReferenceKey(createForm.data.supplier_id, line.item_id);
              const priceReference = priceReferences[priceKey];
              const priceError = priceReferenceErrors[priceKey];
              const baseUnit = item?.uom ?? '';
              const isPackLine = item !== undefined && item.packUnit !== null && line.unit === item.packUnit;
              const packSize = isPackLine && line.pack_size !== '' ? line.pack_size : null;
              const baseQty = packSize !== null && Number.isFinite(Number(line.quantity))
                ? (Number(line.quantity) * Number(packSize)).toFixed(4)
                : null;

              return (
                <div key={index} className="procurement-line-editor__row">
                  <SearchableComboBox
                    id={`order-line-${index}-item`}
                    titleText="Item"
                    size="sm"
                    items={items.map((option) => ({
                      id: option.id,
                      label: `${option.code} — ${option.name} (${option.uom})`,
                    }))}
                    selectedId={line.item_id}
                    onChange={(itemId) => selectItem(index, itemId)}
                    required
                  />

                  <Select
                    id={`order-line-${index}-unit`}
                    labelText="Order in"
                    size="sm"
                    value={line.unit}
                    disabled={item === undefined || item.packUnit === null}
                    onChange={(event) => changeUnit(index, event.currentTarget.value)}
                  >
                    <SelectItem
                      value=""
                      text={item === undefined ? 'Choose an item' : `${item.uom} — the base unit`}
                    />
                    {item !== undefined && item.packUnit !== null && item.packSize !== null ? (
                      <SelectItem
                        value={item.packUnit}
                        text={`${item.packUnit} — ${item.packSize} ${item.uom}`}
                      />
                    ) : null}
                  </Select>

                  <TextInput
                    id={`order-line-${index}-quantity`}
                    labelText="Quantity"
                    size="sm"
                    type="number"
                    min="0"
                    step="any"
                    value={line.quantity}
                    onChange={(event) => updateLine(index, { quantity: event.currentTarget.value })}
                  />

                  <TextInput
                    id={`order-line-${index}-price`}
                    labelText="Unit price (TZS)"
                    size="sm"
                    type="number"
                    min="0"
                    step="any"
                    value={line.unit_price}
                    onChange={(event) => updateLine(index, { unit_price: event.currentTarget.value })}
                  />

                  <div className="procurement-line-editor__actions">
                    {item !== undefined ? (
                      <IconButton
                        kind="ghost"
                        size="sm"
                        align="bottom"
                        label={
                          loadingPriceReference[priceKey] === true
                            ? 'Checking price references'
                            : 'Check price references'
                        }
                        disabled={createForm.data.supplier_id === '' || loadingPriceReference[priceKey] === true}
                        onClick={() => void loadPriceReference(index)}
                      >
                        <Money />
                      </IconButton>
                    ) : null}

                    <IconButton
                      kind="ghost"
                      size="sm"
                      align="bottom"
                      label={`Remove line ${index + 1}`}
                      disabled={createForm.data.lines.length === 1}
                      onClick={() => removeLine(index)}
                    >
                      <TrashCan />
                    </IconButton>
                  </div>

                  {item !== undefined && (priceError !== undefined || priceReference !== undefined) ? (
                    <div className="procurement-line-editor__prices">
                      {priceError ? (
                        <InlineNotification
                          kind="error"
                          lowContrast
                          hideCloseButton
                          title={priceError}
                        />
                      ) : null}
                      {priceReference !== undefined ? (
                        <>
                          {priceReference.lastSupplierPrice !== null ? (
                            <p>
                              {`Last from this supplier: ${formatMoney(priceReference.lastSupplierPrice.unitPrice, priceReference.currency)} per ${priceReference.lastSupplierPrice.unit} (${priceReference.lastSupplierPrice.poNumber}).`}
                            </p>
                          ) : null}
                          {priceReference.lastAnySupplierPrice !== null ? (
                            <p>
                              {`Last from any supplier: ${formatMoney(priceReference.lastAnySupplierPrice.unitPrice, priceReference.currency)} per ${priceReference.lastAnySupplierPrice.unit}.`}
                            </p>
                          ) : null}
                          {priceReference.contractPrice !== null ? (
                            <p>
                              {`Active contract ${priceReference.contractPrice.contractNumber}: ${formatMoney(priceReference.contractPrice.unitPrice, priceReference.currency)} per ${priceReference.contractPrice.uom}${priceReference.contractPrice.remaining === null ? '' : `; ${priceReference.contractPrice.remaining} remaining under ceiling`}. Use contract sourcing to enforce this price and ceiling.`}
                            </p>
                          ) : null}
                          {priceReference.lastSupplierPrice === null
                            && priceReference.lastAnySupplierPrice === null
                            && priceReference.contractPrice === null ? (
                              <p>No previous or active contract price is available.</p>
                            ) : null}
                        </>
                      ) : null}
                    </div>
                  ) : null}

                  {isPackLine ? (
                    <div className="procurement-line-editor__pack">
                      <TextInput
                        id={`order-line-${index}-pack-size`}
                        labelText={`${baseUnit} in one ${line.unit}`}
                        size="sm"
                        type="number"
                        min="0"
                        step="any"
                        value={line.pack_size}
                        onChange={(event) => updateLine(index, { pack_size: event.currentTarget.value })}
                        invalid={line.pack_size.trim() === '' || Number(line.pack_size) <= 0}
                        invalidText={`Say how many ${baseUnit} are in one ${line.unit}.`}
                      />
                      {baseQty !== null ? (
                        <p className="procurement-line-editor__hint">
                          {`${line.quantity} ${line.unit} = ${baseQty} ${baseUnit} in stock`}
                        </p>
                      ) : null}
                    </div>
                  ) : null}
                </div>
              );
            })}
          </div>
          </section>

          <section className="procurement-panel-form__section" aria-labelledby="order-references-heading">
            <div className="procurement-panel-form__section-head">
              <h3 id="order-references-heading" className="procurement-panel-form__section-title">
                References &amp; notes
              </h3>
              <p className="procurement-panel-form__section-meta">Optional</p>
            </div>

            <div className="procurement-panel-form__grid procurement-panel-form__grid--notes">
              <TextInput
                id="order-cost-centre"
                labelText="Cost centre"
                size="sm"
                placeholder="e.g. PHARMACY"
                value={createForm.data.cost_centre_ref}
                onChange={(event) => createForm.setData('cost_centre_ref', event.currentTarget.value)}
                maxLength={100}
              />

              <TextInput
                id="order-budget-reference"
                labelText="Budget reference"
                size="sm"
                placeholder="e.g. FY26-MED-01"
                value={createForm.data.budget_ref}
                onChange={(event) => createForm.setData('budget_ref', event.currentTarget.value)}
                maxLength={100}
              />

              <TextArea
                id="order-notes"
                labelText="Notes"
                placeholder="Anything the supplier or approver should know."
                rows={2}
                value={createForm.data.notes}
                onChange={(event) => createForm.setData('notes', event.currentTarget.value)}
              />

              <TextArea
                id="order-duplicate-override-reason"
                labelText="Repeat-order reason"
                placeholder="Only needed for an exact repeat of an active order."
                rows={2}
                value={createForm.data.duplicate_override_reason}
                onChange={(event) => createForm.setData('duplicate_override_reason', event.currentTarget.value)}
              />
            </div>
          </section>
        </div>
      </DockedPanel>

      <DockedPanel
        heading={cancelling ? `Cancel ${cancelling.poNumber}` : 'Cancel purchase order'}
        ariaLabel="Cancel purchase order"
        hidden={cancelling === null}
        onClose={closeCancel}
        onSubmit={submitCancel}
        primaryLabel={cancelForm.processing ? 'Cancelling…' : 'Cancel order'}
        primaryDisabled={cancelForm.processing || cancelForm.data.reason.trim() === ''}
        danger
        width="min(32rem, 100%)"
      >
        <Stack gap={5}>
          <FormErrors errors={cancelForm.errors} fields={[]} />

          <InlineNotification
            kind="warning"
            lowContrast
            hideCloseButton
            title="This cannot be undone"
            subtitle="The order stops here. Goods already received stay in stock."
          />

          <TextArea
            id="order-cancel-reason"
            labelText="Reason"
            helperText="Stored on the order and in the audit trail."
            rows={3}
            value={cancelForm.data.reason}
            onChange={(event) => cancelForm.setData('reason', event.currentTarget.value)}
            invalid={Boolean(cancelForm.errors.reason)}
            invalidText={cancelForm.errors.reason}
            required
          />
        </Stack>
      </DockedPanel>

      <DockedPanel
        heading={closingShort ? `Close short ${closingShort.poNumber}` : 'Close purchase order short'}
        ariaLabel="Close purchase order short"
        hidden={closingShort === null}
        onClose={closeCloseShort}
        onSubmit={submitCloseShort}
        primaryLabel={closeShortForm.processing ? 'Closing…' : 'Close short'}
        primaryDisabled={closeShortForm.processing || closeShortForm.data.reason.trim() === ''}
        danger
        width="min(32rem, 100%)"
      >
        <Stack gap={5}>
          <FormErrors errors={closeShortForm.errors} fields={[]} />

          <InlineNotification
            kind="warning"
            lowContrast
            hideCloseButton
            title="The remaining balance will not arrive"
            subtitle="Goods already received stay in stock. The order closes as CLOSED_SHORT and cannot receive more."
          />

          <TextArea
            id="order-close-short-reason"
            labelText="Reason"
            helperText="For example: supplier confirmed the balance is unavailable. Stored on the order and in the audit trail."
            rows={3}
            value={closeShortForm.data.reason}
            onChange={(event) => closeShortForm.setData('reason', event.currentTarget.value)}
            invalid={Boolean(closeShortForm.errors.reason)}
            invalidText={closeShortForm.errors.reason}
            required
          />
        </Stack>
      </DockedPanel>

      <Modal
        open={linesImportOpen}
        modalHeading="Paste purchase-order lines"
        primaryButtonText="Use these lines"
        secondaryButtonText="Cancel"
        onRequestClose={() => {
          setLinesImportOpen(false);
          setLinesImportError(null);
        }}
        onRequestSubmit={applyImportedOrderLines}
        size="md"
      >
        <p style={{ marginBlockEnd: '0.75rem' }}>
          One row per line: <code>item code, quantity, unit price</code>. Quantities are in the
          item’s base unit; switch a line to its buying unit afterwards if the supplier quotes packs.
          This replaces the current draft lines.
        </p>
        <TextArea
          id="purchase-order-import-lines"
          labelText="Order lines"
          hideLabel
          rows={9}
          value={linesImportText}
          onChange={(event) => setLinesImportText(event.currentTarget.value)}
          placeholder={'PARA-500, 1000, 50\nGLOVE-M, 20, 12000'}
        />
        {linesImportError !== null ? (
          <InlineNotification
            kind="error"
            lowContrast
            hideCloseButton
            title="Some rows could not be imported"
            subtitle={linesImportError}
          />
        ) : null}
      </Modal>
    </section>
  );
}
