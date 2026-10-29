import { Button, Modal, Select, SelectItem, TableToolbarSearch, TextInput } from '@carbon/react';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { routes } from '@/app/routes';
import { CarbonDataTable, EmptyState } from '@/components/ui';
import type {
  DepartmentOption,
  PendingAssignmentRequest,
  RejectedAssignmentRequest,
  UnitOption,
} from '../types';

interface AssignmentRequestsPanelProps {
  pendingRequests: PendingAssignmentRequest[];
  rejectedRequests: RejectedAssignmentRequest[];
  departments: DepartmentOption[];
  units: UnitOption[];
  canReviewAssignments: boolean;
}

const PENDING_HEADERS = [
  { key: 'staff', header: 'Staff member' },
  { key: 'placement', header: 'Requested placement' },
  { key: 'effectiveFrom', header: 'Effective from' },
  { key: 'actions', header: 'Actions' },
];

const REJECTED_HEADERS = [
  { key: 'staff', header: 'Staff member' },
  { key: 'reviewNote', header: 'Review note' },
  { key: 'department', header: 'Department' },
  { key: 'unit', header: 'Unit' },
  { key: 'effectiveFrom', header: 'Effective from' },
  { key: 'actions', header: 'Actions' },
];

export function AssignmentRequestsPanel({
  pendingRequests,
  rejectedRequests,
  departments,
  units,
  canReviewAssignments,
}: AssignmentRequestsPanelProps): React.JSX.Element {
  const [rejectTarget, setRejectTarget] = useState<PendingAssignmentRequest | null>(null);
  const [rejectReason, setRejectReason] = useState('');
  const [rejecting, setRejecting] = useState(false);
  const [resubmitDepartments, setResubmitDepartments] = useState<Record<string, string>>({});
  const [resubmitUnits, setResubmitUnits] = useState<Record<string, string>>({});
  const [resubmitDates, setResubmitDates] = useState<Record<string, string>>({});

  function approve(requestId: string): void {
    router.post(routes.workspaces.hrStaffAssignmentDecision(requestId), {
      decision: 'approve',
      reason: '',
    }, { preserveScroll: true });
  }

  function openReject(request: PendingAssignmentRequest): void {
    setRejectTarget(request);
    setRejectReason('');
  }

  function closeReject(): void {
    setRejectTarget(null);
    setRejectReason('');
  }

  function submitReject(): void {
    if (rejectTarget === null || rejectReason.trim() === '' || rejecting) return;
    setRejecting(true);
    router.post(routes.workspaces.hrStaffAssignmentDecision(rejectTarget.id), {
      decision: 'reject',
      reason: rejectReason.trim(),
    }, {
      preserveScroll: true,
      onFinish: () => {
        setRejecting(false);
        closeReject();
      },
    });
  }

  function resubmit(request: RejectedAssignmentRequest): void {
    router.post(routes.workspaces.hrStaffAssignmentResubmit(request.id), {
      department_id: resubmitDepartments[request.id] ?? request.departmentId ?? '',
      unit_id: resubmitUnits[request.id] ?? request.unitId ?? '',
      effective_from: resubmitDates[request.id] ?? request.effectiveFrom,
    }, { preserveScroll: true });
  }

  const pendingRows = pendingRequests.map((request) => ({
    id: request.id,
    staff: (
      <div className="facility-staff__table-primary-cell">
        <strong>{request.name}</strong>
        <span>{request.staffNumber}</span>
      </div>
    ),
    placement: request.unitName ?? request.departmentName ?? 'Facility-wide',
    effectiveFrom: request.effectiveFrom,
    actions: (
      <div className="facility-staff__table-actions">
        <Button size="sm" onClick={() => approve(request.id)}>Confirm</Button>
        <Button
          kind="danger--tertiary"
          size="sm"
          onClick={() => openReject(request)}
        >
          Reject
        </Button>
      </div>
    ),
  }));

  const rejectedRows = rejectedRequests.map((request) => {
    const departmentId = resubmitDepartments[request.id] ?? request.departmentId ?? '';
    const unitId = resubmitUnits[request.id] ?? request.unitId ?? '';

    return {
      id: request.id,
      staff: (
        <div className="facility-staff__table-primary-cell">
          <strong>{request.name}</strong>
          <span>{request.staffNumber}</span>
        </div>
      ),
      reviewNote: request.decisionReason ?? 'No review note provided',
      department: (
        <Select
          id={`resubmit-department-${request.id}`}
          labelText="Department"
          hideLabel
          value={departmentId}
          onChange={(event) => {
            const selectedDepartment = event.currentTarget.value;
            setResubmitDepartments((current) => ({ ...current, [request.id]: selectedDepartment }));
            setResubmitUnits((current) => ({ ...current, [request.id]: '' }));
          }}
        >
          <SelectItem value="" text="Facility-wide" />
          {departments.map((department) => (
            <SelectItem key={department.id} value={department.id} text={department.name} />
          ))}
        </Select>
      ),
      unit: (
        <Select
          id={`resubmit-unit-${request.id}`}
          labelText="Unit"
          hideLabel
          value={unitId}
          disabled={!departmentId}
          onChange={(event) => setResubmitUnits((current) => ({ ...current, [request.id]: event.currentTarget.value }))}
        >
          <SelectItem value="" text="No unit" />
          {units.filter((unit) => unit.departmentId === departmentId).map((unit) => (
            <SelectItem key={unit.id} value={unit.id} text={unit.name} />
          ))}
        </Select>
      ),
      effectiveFrom: (
        <TextInput
          id={`resubmit-start-${request.id}`}
          type="date"
          labelText="Assignment start"
          hideLabel
          value={resubmitDates[request.id] ?? request.effectiveFrom}
          onChange={(event) => setResubmitDates((current) => ({ ...current, [request.id]: event.currentTarget.value }))}
          required
        />
      ),
      actions: (
        <Button size="sm" onClick={() => resubmit(request)}>Update</Button>
      ),
    };
  });

  return (
    <div className="facility-staff__assignment-tables">
      {canReviewAssignments ? (
        <div className="facility-staff__assignment-table">
          <p>Authorized HR staff or a Facility Admin can confirm facility, department, or unit placement.</p>
          <CarbonDataTable
            rows={pendingRows}
            headers={PENDING_HEADERS}
            title={`Pending confirmation (${pendingRequests.length})`}
            ariaLabel="Pending placement requests"
            emptyState={<EmptyState title="No assignment requests" description="Requests awaiting confirmation will appear here." />}
            toolbar={(onInputChange) => (
              <TableToolbarSearch
                persistent
                onChange={onInputChange}
                placeholder="Search requests"
                aria-label="Search pending requests"
              />
            )}
          />
        </div>
      ) : null}
      {rejectedRequests.length > 0 ? (
        <div className="facility-staff__assignment-table">
          <CarbonDataTable
            rows={rejectedRows}
            headers={REJECTED_HEADERS}
            title={`Rejected requests (${rejectedRequests.length})`}
            ariaLabel="Rejected placement requests"
            emptyState={<EmptyState title="No rejected requests" description="Rejected requests will appear here." />}
            toolbar={(onInputChange) => (
              <TableToolbarSearch
                persistent
                onChange={onInputChange}
                placeholder="Search requests"
                aria-label="Search rejected requests"
              />
            )}
          />
        </div>
      ) : null}
      {rejectTarget ? (
        <Modal
          open
          modalHeading="Reject placement request"
          primaryButtonText={rejecting ? 'Rejecting…' : 'Reject request'}
          secondaryButtonText="Cancel"
          danger
          primaryButtonDisabled={rejectReason.trim() === '' || rejecting}
          onRequestClose={closeReject}
          onRequestSubmit={submitReject}
        >
          <p>
            Reject placement for {rejectTarget.name} ({rejectTarget.staffNumber}) at{' '}
            {rejectTarget.unitName ?? rejectTarget.departmentName ?? 'Facility-wide'} effective{' '}
            {rejectTarget.effectiveFrom}. The requester can correct and resubmit it.
          </p>
          <TextInput
            id="staff-assignment-reject-reason"
            labelText="Decision note"
            helperText="Required when rejecting."
            value={rejectReason}
            onChange={(event) => setRejectReason(event.currentTarget.value)}
            maxLength={500}
            required
          />
        </Modal>
      ) : null}
    </div>
  );
}
