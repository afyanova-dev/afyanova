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
import { Add, Checkmark, Close, Edit, Export, Filter, Pending, Send, TrashCan } from '@/icons';
import { useMemo, useState, type FormEvent, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import { DockedPanel } from '@/components/panels';
import { CarbonDataTable, FormErrors, SearchableComboBox, StatisticsCards } from '@/components/ui';
import {
  formatStatus,
  type Department,
  type ProcurementItem,
  type PurchaseRequisition,
} from './types';

export interface RequestsSectionProps {
  requests: PurchaseRequisition[];
  departments: Department[];
  canCreateForAnyDepartment: boolean;
  items: ProcurementItem[];
  currentUserId: string | null;
  canCreate: boolean;
}

type RequestLineDraft = {
  item_id: string;
  unit: string;
  quantity: string;
  pack_size: string;
  required_date?: string;
};
type CreateRequestForm = {
  requesting_department_ref: string;
  priority: string;
  reason: string;
  cost_centre_ref: string;
  budget_ref: string;
  lines: RequestLineDraft[];
};

const HEADERS = [
  { key: 'requisitionNumber', header: 'Request' },
  { key: 'department', header: 'Department' },
  { key: 'status', header: 'Status' },
  { key: 'lineCount', header: 'Lines' },
  { key: 'raisedBy', header: 'Raised by' },
  { key: 'createdAt', header: 'Raised' },
  { key: 'actions', header: 'Actions' },
];

const STATUS_TAG: Record<string, 'gray' | 'blue' | 'green' | 'red'> = {
  DRAFT: 'gray',
  SUBMITTED: 'blue',
  APPROVED: 'green',
  REJECTED: 'red',
  CANCELLED: 'gray',
};

const EMPTY_LINE: RequestLineDraft = { item_id: '', unit: '', quantity: '1', pack_size: '' };
const MAX_LINES = 500;

function formatDate(value: string | null): string {
  if (value === null) {
    return '—';
  }

  const date = new Date(value);

  return Number.isNaN(date.getTime()) ? '—' : date.toLocaleDateString('en-GB');
}

/**
 * The request's own rule in plain words. The money limit is NOT relaxed here:
 * whoever raises a request never approves it.
 */
function nextStepHint(request: PurchaseRequisition, currentUserId: string | null): string | null {
  const mine = currentUserId !== null && request.createdByUserId === currentUserId;

  if (request.status === 'DRAFT') {
    return mine
      ? 'Still a draft. Submit it when the lines are right — it is only visible to your facility.'
      : 'Still a draft. The person who raised it can submit it.';
  }

  if (request.status === 'SUBMITTED') {
    return mine
      ? 'You raised this request, so someone else has to approve or reject it.'
      : 'Waiting for an approver. The person who raised a request can never approve it.';
  }

  if (request.status === 'APPROVED') {
    return 'Approved. Turn it into a purchase order — that is where the money limit applies.';
  }

  if (request.status === 'REJECTED') {
    return 'Rejected. Nothing more can happen to this request.';
  }

  return null;
}

export function RequestsSection({
  requests,
  departments,
  canCreateForAnyDepartment,
  items,
  currentUserId,
  canCreate,
}: RequestsSectionProps): ReactNode {
  const page = usePage();
  const pageErrors = (page.props.errors ?? {}) as Record<string, string | string[] | undefined>;

  const [statusFilter, setStatusFilter] = useState('');
  const [creating, setCreating] = useState(false);
  const [editingRequest, setEditingRequest] = useState<PurchaseRequisition | null>(null);
  const [rejecting, setRejecting] = useState<PurchaseRequisition | null>(null);
  const [cancelling, setCancelling] = useState<PurchaseRequisition | null>(null);
  const [linesImportOpen, setLinesImportOpen] = useState(false);
  const [linesImportText, setLinesImportText] = useState('');
  const [linesImportError, setLinesImportError] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<string | null>(null);

  const createForm = useForm<CreateRequestForm>({
    requesting_department_ref: '',
    priority: 'ROUTINE',
    reason: '',
    cost_centre_ref: '',
    budget_ref: '',
    lines: [{ ...EMPTY_LINE }],
  });

  const rejectForm = useForm({ reason: '' });

  const requestsById = useMemo(
    () => new Map(requests.map((request) => [request.id, request])),
    [requests],
  );

  const statuses = useMemo(() => {
    const present = new Set(requests.map((request) => request.status));

    return Object.keys(STATUS_TAG).filter((status) => present.has(status));
  }, [requests]);

  const filtered = useMemo(
    () => requests.filter((request) => statusFilter === '' || request.status === statusFilter),
    [requests, statusFilter],
  );

  const rows = useMemo(
    () => filtered.map((request) => ({
      id: request.id,
      requisitionNumber: request.requisitionNumber,
      department: request.departmentName ?? 'No department',
      status: request.status,
      lineCount: String(request.lines.length),
      raisedBy: request.createdByUserId === currentUserId ? 'You' : 'Someone else',
      createdAt: formatDate(request.createdAt),
      actions: '',
    })),
    [filtered, currentUserId],
  );

  const draftCount = requests.filter((request) => request.status === 'DRAFT').length;
  const awaitingApprovalCount = requests.filter((request) => request.status === 'SUBMITTED').length;
  const approvedCount = requests.filter((request) => request.status === 'APPROVED').length;
  const rejectedCount = requests.filter((request) => request.status === 'REJECTED').length;

  const draftIncomplete = createForm.data.lines.some((line) => {
    const item = items.find((candidate) => candidate.id === line.item_id);
    const packed = item !== undefined && item.packUnit !== null && line.unit === item.packUnit;

    return line.item_id === ''
      || line.quantity.trim() === ''
      || Number(line.quantity) <= 0
      || (packed && (line.pack_size.trim() === '' || Number(line.pack_size) <= 0));
  });

  function openCreate(): void {
    setEditingRequest(null);
    createForm.clearErrors();
    createForm.setData({
      requesting_department_ref: !canCreateForAnyDepartment && departments.length === 1
        ? departments[0]!.id
        : '',
      priority: 'ROUTINE',
      reason: '',
      cost_centre_ref: '',
      budget_ref: '',
      lines: [{ ...EMPTY_LINE }],
    });
    setCreating(true);
  }

  function openEdit(request: PurchaseRequisition): void {
    setEditingRequest(request);
    createForm.clearErrors();
    createForm.setData({
      requesting_department_ref: request.departmentRef ?? '',
      priority: request.priority,
      reason: request.reason ?? '',
      cost_centre_ref: request.costCentreRef ?? '',
      budget_ref: request.budgetRef ?? '',
      lines: request.lines.map((line) => ({
        item_id: line.itemRef ?? '',
        unit: line.unit === line.uom ? '' : line.unit,
        quantity: line.quantity,
        pack_size: line.packSize ?? '',
        required_date: line.requiredDate ?? '',
      })),
    });
    setCreating(true);
  }

  function closeCreate(): void {
    createForm.reset();
    setEditingRequest(null);
    setCreating(false);
  }

  function submitCreate(event: FormEvent): void {
    event.preventDefault();
    const onSuccess = () => {
      createForm.reset();
      setEditingRequest(null);
      setCreating(false);
    };
    const options = {
      preserveScroll: true,
      onSuccess,
    };

    if (editingRequest === null) {
      createForm.post(routes.workspaces.procurementRequestsStore, options);
    } else {
      createForm.patch(routes.workspaces.procurementRequestUpdate(editingRequest.id), options);
    }
  }

  function updateLine(index: number, patch: Partial<RequestLineDraft>): void {
    createForm.setData('lines', createForm.data.lines.map((line, position) => (
      position === index ? { ...line, ...patch } : line
    )));
  }

  function selectItem(index: number, itemId: string): void {
    if (createForm.data.lines[index]?.item_id === itemId) {
      return;
    }

    const item = items.find((candidate) => candidate.id === itemId);

    updateLine(index, {
      item_id: itemId,
      unit: item?.packUnit ?? '',
      pack_size: item?.packUnit === null || item?.packUnit === undefined ? '' : item.packSize ?? '',
    });
  }

  function changeUnit(index: number, unit: string): void {
    const line = createForm.data.lines[index];
    const item = items.find((candidate) => candidate.id === line?.item_id);

    updateLine(index, {
      unit,
      pack_size: item !== undefined && item.packUnit === unit ? item.packSize ?? '' : '',
    });
  }

  /**
   * P1-12 — paste a requisition sheet: `item code, quantity` in base units.
   * It replaces the draft lines, so the result is exactly what was pasted.
   */
  function applyImportedRequestLines(): void {
    const rows = linesImportText
      .split(/\r?\n/)
      .map((row) => row.trim())
      .filter((row) => row !== '');

    if (rows.length === 0) {
      setLinesImportError('Paste at least one item code and quantity.');

      return;
    }

    const itemsByCode = new Map(items.map((item) => [item.code.toUpperCase(), item]));
    const drafts: RequestLineDraft[] = [];
    const errors: string[] = [];

    rows.forEach((row, rowIndex) => {
      const parts = row.split(/[,\t;]/).map((part) => part.trim());

      if (rowIndex === 0 && /^item(\s*code)?$/i.test(parts[0] ?? '')) {
        return;
      }

      if (parts.length < 2 || (parts[0] ?? '') === '') {
        errors.push(`Row ${rowIndex + 1}: expected item code, quantity.`);

        return;
      }

      const code = parts[0] ?? '';
      const item = itemsByCode.get(code.toUpperCase());

      if (item === undefined) {
        errors.push(`Row ${rowIndex + 1}: “${code}” is not in the item catalogue.`);

        return;
      }

      const quantity = Number(parts[1]);

      if (!Number.isFinite(quantity) || quantity <= 0) {
        errors.push(`Row ${rowIndex + 1}: quantity must be more than zero.`);

        return;
      }

      drafts.push({
        item_id: item.id,
        unit: '',
        quantity: String(quantity),
        pack_size: '',
      });
    });

    if (errors.length > 0) {
      setLinesImportError(errors.slice(0, 3).join(' '));

      return;
    }

    if (drafts.length > MAX_LINES) {
      setLinesImportError(`A request can contain at most ${MAX_LINES} lines.`);

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
    createForm.setData(
      'lines',
      createForm.data.lines.filter((_line, position) => position !== index),
    );
  }

  function runAction(
    request: PurchaseRequisition,
    action: 'submit' | 'approve' | 'cancel',
  ): void {
    const url = action === 'submit'
      ? routes.workspaces.procurementRequestSubmit(request.id)
      : action === 'approve'
        ? routes.workspaces.procurementRequestApprove(request.id)
        : routes.workspaces.procurementRequestCancel(request.id);

    setBusyId(request.id);
    router.post(url, {}, {
      preserveScroll: true,
      onFinish: () => setBusyId(null),
    });
  }

  function openOrder(request: PurchaseRequisition): void {
    router.get(`${routes.workspaces.procurement}?section=orders&request=${request.id}`, {}, {
      preserveScroll: true,
      preserveState: false,
    });
  }

  function openReject(request: PurchaseRequisition): void {
    rejectForm.clearErrors();
    rejectForm.setData('reason', '');
    setRejecting(request);
  }

  function closeReject(): void {
    rejectForm.reset();
    setRejecting(null);
  }

  function submitReject(event: FormEvent): void {
    event.preventDefault();

    if (rejecting === null) {
      return;
    }

    rejectForm.post(routes.workspaces.procurementRequestReject(rejecting.id), {
      preserveScroll: true,
      onSuccess: () => {
        rejectForm.reset();
        setRejecting(null);
      },
    });
  }

  return (
    <section aria-labelledby="procurement-requests-heading">
      <div className="afy-section__heading">
        <div>
          <h2 id="procurement-requests-heading" className="afy-section__title">Requests</h2>
          <p className="afy-section__description">
            A department asks for what it needs. Someone else — never the person who raised it —
            approves it, then it becomes a purchase order. The one-person money limit applies when
            it does, and a department can have its own.
          </p>
        </div>
      </div>

      <StatisticsCards
        label="Request summary"
        cards={[
          {
            id: 'request-drafts',
            label: 'Drafts',
            value: draftCount,
            detail: 'Editable by the person who raised them',
            borderTone: 'information',
            icon: <Edit aria-hidden="true" />,
          },
          {
            id: 'request-awaiting-approval',
            label: 'Awaiting approval',
            value: awaitingApprovalCount,
            detail: 'Another person approves — never the requester',
            borderTone: awaitingApprovalCount > 0 ? 'caution' : 'information',
            icon: <Pending aria-hidden="true" />,
          },
          {
            id: 'request-approved',
            label: 'Approved',
            value: approvedCount,
            detail: 'Ready to become purchase orders',
            borderTone: 'positive',
            icon: <Checkmark aria-hidden="true" />,
          },
          {
            id: 'request-rejected',
            label: 'Rejected',
            value: rejectedCount,
            detail: 'Closed — raise a new request instead',
            borderTone: rejectedCount > 0 ? 'negative' : 'information',
            icon: <Close aria-hidden="true" />,
          },
        ]}
      />

      {pageErrors.request ? (
        <div role="alert" className="procurement-alert">
          <InlineNotification
            kind="error"
            lowContrast
            hideCloseButton
            title={String(pageErrors.request)}
          />
        </div>
      ) : null}

      <CarbonDataTable
        rows={rows}
        headers={HEADERS}
        title={`Requests (${filtered.length} of ${requests.length})`}
        ariaLabel="Purchase requests"
        emptyState={(
          <p>
            {requests.length === 0
              ? 'No requests yet.'
              : 'No request matches the selected status.'}
          </p>
        )}
        toolbar={(onInputChange) => (
          <>
            <TableToolbarSearch
              persistent
              onChange={onInputChange}
              placeholder="Search requests"
              aria-label="Search purchase requests"
            />
            <TableToolbarMenu
              renderIcon={Filter}
              iconDescription={`Filter by status: ${statusFilter === '' ? 'All statuses' : formatStatus(statusFilter)}`}
            >
              <OverflowMenuItem
                itemText={`All statuses (${requests.length})`}
                isDelete={false}
                onClick={() => setStatusFilter('')}
              />
              {statuses.map((status) => (
                <OverflowMenuItem
                  key={status}
                  itemText={`${formatStatus(status)} (${requests.filter((request) => request.status === status).length})`}
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
              href={`${routes.workspaces.procurementRequestsExport}${statusFilter === ''
                ? ''
                : `?status=${encodeURIComponent(statusFilter)}`}`}
            >
              Export Excel
            </Button>
            {canCreate ? (
              <Button size="lg" onClick={openCreate}>
                New request
              </Button>
            ) : null}
          </>
        )}
        getExpandRowLabel={(row) => String(row.cells[0]?.value ?? 'request')}
        renderExpandedRow={(row) => {
          const request = requestsById.get(String(row.id));

          if (request === undefined) {
            return null;
          }

          const hint = nextStepHint(request, currentUserId);

          return (
            <Stack gap={4}>
              <div className="procurement-actions">
                <Button
                  kind="tertiary"
                  size="sm"
                  href={routes.workspaces.procurementRequestPdf(request.id)}
                  target="_blank"
                  rel="noreferrer"
                >
                  Print / save PDF
                </Button>
              </div>
              <table className="procurement-lines" aria-label={`Lines for ${request.requisitionNumber}`}>
                <thead>
                  <tr>
                    <th scope="col">Item</th>
                    <th scope="col">Quantity</th>
                    <th scope="col">Unit</th>
                    <th scope="col">Base quantity</th>
                  </tr>
                </thead>
                <tbody>
                  {request.lines.map((line) => (
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
                      <td>{line.quantity}</td>
                      <td>{line.unit}</td>
                      <td>
                        {line.packSize !== null
                          ? `${(Number(line.quantity) * Number(line.packSize)).toFixed(4)} ${line.uom}`
                          : `${line.quantity} ${line.uom}`}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>

              {request.reason ? <p><strong>For:</strong> {request.reason}</p> : null}
              <p>
                <strong>Cost centre:</strong> {request.costCentreRef ?? '—'}
                {' · '}
                <strong>Budget reference:</strong> {request.budgetRef ?? '—'}
              </p>
              {request.rejectionReason ? (
                <p><strong>Rejected:</strong> {request.rejectionReason}</p>
              ) : null}
              {hint !== null ? <p className="procurement-hint">{hint}</p> : null}
            </Stack>
          );
        }}
        renderCell={(cell, row) => {
          if (cell.info.header === 'requisitionNumber') {
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

          if (cell.info.header === 'actions') {
            const request = requestsById.get(String(row.id));

            if (request === undefined) {
              return null;
            }

            const busy = busyId === request.id;

            // Carbon hierarchy, per row: `tertiary` outlines the single most
            // important permitted step (ordering an approved request beats
            // approving, approving beats submitting); `ghost` covers neutral
            // editing; the two one-way, confirm-panel actions (reject, cancel)
            // are `danger--ghost` so they never read as the regular flow.
            return (
              <div className="procurement-actions">
                {request.hasActivePurchaseOrder ? (
                  <Tag type="green" size="sm">Purchase order created</Tag>
                ) : null}
                {request.allowedActions.includes('order') ? (
                  <Button
                    kind="tertiary"
                    size="sm"
                    renderIcon={Add}
                    disabled={busy}
                    onClick={() => openOrder(request)}
                  >
                    Create purchase order
                  </Button>
                ) : null}
                {request.allowedActions.includes('approve') ? (
                  <Button
                    kind="tertiary"
                    size="sm"
                    renderIcon={Checkmark}
                    disabled={busy}
                    onClick={() => runAction(request, 'approve')}
                  >
                    Approve
                  </Button>
                ) : null}
                {request.allowedActions.includes('submit') ? (
                  <Button
                    kind="tertiary"
                    size="sm"
                    renderIcon={Send}
                    disabled={busy}
                    onClick={() => runAction(request, 'submit')}
                  >
                    Submit
                  </Button>
                ) : null}
                {request.allowedActions.includes('edit') ? (
                  <Button
                    kind="ghost"
                    size="sm"
                    renderIcon={Edit}
                    disabled={busy}
                    onClick={() => openEdit(request)}
                  >
                    Edit draft
                  </Button>
                ) : null}
                {request.allowedActions.includes('reject') ? (
                  <Button
                    kind="danger--ghost"
                    size="sm"
                    renderIcon={Close}
                    disabled={busy}
                    onClick={() => openReject(request)}
                  >
                    Reject
                  </Button>
                ) : null}
                {request.allowedActions.includes('cancel') ? (
                  <Button
                    kind="danger--ghost"
                    size="sm"
                    renderIcon={Close}
                    disabled={busy}
                    onClick={() => setCancelling(request)}
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
        heading={editingRequest === null ? 'New request' : `Edit ${editingRequest.requisitionNumber}`}
        ariaLabel={editingRequest === null ? 'New request' : `Edit ${editingRequest.requisitionNumber}`}
        hidden={!creating}
        onClose={closeCreate}
        onSubmit={submitCreate}
        primaryLabel={createForm.processing
          ? (editingRequest === null ? 'Creating…' : 'Saving…')
          : (editingRequest === null ? 'Create draft request' : 'Save draft changes')}
        primaryDisabled={createForm.processing || draftIncomplete}
        width="min(56rem, 100%)"
        expandedWidth="min(75rem, 100%)"
        expandable
      >
        <div className="procurement-panel-form">
          <FormErrors
            errors={createForm.errors}
            fields={['requesting_department_ref', 'priority', 'reason', 'cost_centre_ref', 'budget_ref']}
          />

          <section className="procurement-panel-form__section" aria-labelledby="request-details-heading">
            <div className="procurement-panel-form__section-head">
              <h3 id="request-details-heading" className="procurement-panel-form__section-title">
                Request details
              </h3>
            </div>

            <div className="procurement-panel-form__grid procurement-panel-form__grid--details">
              <Select
                id="request-department"
                labelText="Department"
                size="sm"
                value={createForm.data.requesting_department_ref}
                onChange={(event) => createForm.setData(
                  'requesting_department_ref',
                  event.currentTarget.value,
                )}
                invalid={Boolean(createForm.errors.requesting_department_ref)}
                invalidText={createForm.errors.requesting_department_ref}
                disabled={!canCreateForAnyDepartment && departments.length <= 1}
              >
                {canCreateForAnyDepartment ? (
                  <SelectItem value="" text="No department (facility-wide)" />
                ) : null}
                {departments.map((department) => (
                  <SelectItem
                    key={department.id}
                    value={department.id}
                    text={`${department.name} (${department.code})`}
                  />
                ))}
              </Select>

              <Select
                id="request-priority"
                labelText="Priority"
                size="sm"
                value={createForm.data.priority}
                onChange={(event) => createForm.setData('priority', event.currentTarget.value)}
              >
                <SelectItem value="ROUTINE" text="Routine" />
                <SelectItem value="URGENT" text="Urgent" />
              </Select>

              <TextArea
                id="request-reason"
                labelText="What is it for?"
                className="procurement-panel-form__span-2"
                placeholder="What should the approver know?"
                rows={2}
                value={createForm.data.reason}
                onChange={(event) => createForm.setData('reason', event.currentTarget.value)}
              />
            </div>
          </section>

          <section className="procurement-panel-form__section" aria-labelledby="request-lines-heading">
            <div className="procurement-panel-form__section-head">
              <div className="procurement-panel-form__section-heading">
                <h3 id="request-lines-heading" className="procurement-panel-form__section-title">
                  Line items
                </h3>
                <p className="procurement-panel-form__line-summary">
                  <span>{`${createForm.data.lines.length} of ${MAX_LINES} lines`}</span>
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
              const item = items.find((candidate) => candidate.id === line.item_id);
              const baseUnit = item?.uom ?? '';
              const isPackLine = item !== undefined && item.packUnit !== null && line.unit === item.packUnit;
              const packSize = isPackLine && line.pack_size !== '' ? Number(line.pack_size) : null;
              const baseQty = packSize !== null && Number.isFinite(Number(line.quantity))
                ? (Number(line.quantity) * packSize).toFixed(4)
                : null;

              return (
                <div key={index} className="procurement-line-editor__row procurement-line-editor__row--request">
                  <SearchableComboBox
                    id={`request-line-${index}-item`}
                    titleText="Item"
                    size="sm"
                    items={items.map((option) => ({ id: option.id, label: `${option.code} — ${option.name} (${option.uom})` }))}
                    selectedId={line.item_id}
                    onChange={(itemId) => selectItem(index, itemId)}
                    required
                  />

                  <Select
                    id={`request-line-${index}-unit`}
                    labelText="Request in"
                    size="sm"
                    value={line.unit}
                    disabled={item === undefined || item.packUnit === null}
                    onChange={(event) => changeUnit(index, event.currentTarget.value)}
                  >
                    <SelectItem
                      value=""
                      text={item === undefined ? 'Choose an item' : `${item.uom} — the base unit`}
                    />
                    {item !== undefined && item.packUnit !== null ? (
                      <SelectItem
                        value={item.packUnit}
                        text={item.packSize === null
                          ? item.packUnit
                          : `${item.packUnit} — ${item.packSize} ${item.uom}`}
                      />
                    ) : null}
                  </Select>

                  <TextInput
                    id={`request-line-${index}-quantity`}
                    labelText="Quantity"
                    size="sm"
                    type="number"
                    min="0"
                    step="any"
                    value={line.quantity}
                    onChange={(event) => updateLine(index, { quantity: event.currentTarget.value })}
                  />

                  <div className="procurement-line-editor__actions">
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

                  {isPackLine ? (
                    <div className="procurement-line-editor__pack">
                      <TextInput
                        id={`request-line-${index}-pack-size`}
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
                          {`${line.quantity} ${line.unit} = ${baseQty} ${baseUnit} requested`}
                        </p>
                      ) : null}
                    </div>
                  ) : null}
                </div>
              );
            })}
          </div>
          </section>

          <section className="procurement-panel-form__section" aria-labelledby="request-references-heading">
            <div className="procurement-panel-form__section-head">
              <h3 id="request-references-heading" className="procurement-panel-form__section-title">
                References
              </h3>
              <p className="procurement-panel-form__section-meta">Optional</p>
            </div>

            <div className="procurement-panel-form__grid procurement-panel-form__grid--notes">
              <TextInput
                id="request-cost-centre"
                labelText="Cost centre"
                size="sm"
                placeholder="e.g. LAB"
                value={createForm.data.cost_centre_ref}
                onChange={(event) => createForm.setData('cost_centre_ref', event.currentTarget.value)}
                maxLength={100}
              />

              <TextInput
                id="request-budget-reference"
                labelText="Budget reference"
                size="sm"
                placeholder="e.g. FY26-LAB"
                value={createForm.data.budget_ref}
                onChange={(event) => createForm.setData('budget_ref', event.currentTarget.value)}
                maxLength={100}
              />
            </div>
          </section>
        </div>
      </DockedPanel>

      <DockedPanel
        heading={rejecting ? `Reject ${rejecting.requisitionNumber}` : 'Reject request'}
        ariaLabel="Reject request"
        hidden={rejecting === null}
        onClose={closeReject}
        onSubmit={submitReject}
        primaryLabel={rejectForm.processing ? 'Rejecting…' : 'Reject request'}
        primaryDisabled={rejectForm.processing || rejectForm.data.reason.trim() === ''}
        danger
        width="min(32rem, 100%)"
      >
        <Stack gap={5}>
          <FormErrors errors={rejectForm.errors} fields={['reason']} />

          <TextArea
            id="request-reject-reason"
            labelText="Reason"
            helperText="The requester sees this. Stored in the audit trail."
            rows={3}
            value={rejectForm.data.reason}
            onChange={(event) => rejectForm.setData('reason', event.currentTarget.value)}
            invalid={Boolean(rejectForm.errors.reason)}
            invalidText={rejectForm.errors.reason}
            required
          />
        </Stack>
      </DockedPanel>

      <DockedPanel
        heading={cancelling ? `Cancel ${cancelling.requisitionNumber}` : 'Cancel request'}
        ariaLabel="Cancel request"
        hidden={cancelling === null}
        onClose={() => setCancelling(null)}
        onSubmit={(event) => {
          event.preventDefault();

          if (cancelling === null) {
            return;
          }

          setBusyId(cancelling.id);
          router.post(routes.workspaces.procurementRequestCancel(cancelling.id), {}, {
            preserveScroll: true,
            onFinish: () => {
              setBusyId(null);
              setCancelling(null);
            },
          });
        }}
        primaryLabel="Cancel request"
        danger
        width="min(30rem, 100%)"
      >
        <Stack gap={5}>
          <InlineNotification
            kind="warning"
            lowContrast
            hideCloseButton
            title="This cannot be undone"
            subtitle="The request stops here. It can still be raised again as a new request."
          />
        </Stack>
      </DockedPanel>

      <Modal
        open={linesImportOpen}
        modalHeading="Paste requisition lines"
        primaryButtonText="Use these lines"
        secondaryButtonText="Cancel"
        onRequestClose={() => {
          setLinesImportOpen(false);
          setLinesImportError(null);
        }}
        onRequestSubmit={applyImportedRequestLines}
        size="md"
      >
        <p style={{ marginBlockEnd: '0.75rem' }}>
          One row per line: <code>item code, quantity</code> in the item’s base unit. Prices are
          added later when the request becomes an order. This replaces the current draft lines.
        </p>
        <TextArea
          id="purchase-request-import-lines"
          labelText="Request lines"
          hideLabel
          rows={9}
          value={linesImportText}
          onChange={(event) => setLinesImportText(event.currentTarget.value)}
          placeholder={'PARA-500, 1000\nGLOVE-M, 20'}
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
