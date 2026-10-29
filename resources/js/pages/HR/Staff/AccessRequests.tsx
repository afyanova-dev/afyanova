import { Button, Modal, TableToolbarSearch } from '@carbon/react';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { routes } from '@/app/routes';
import { CarbonDataTable, EmptyState, PageHeader } from '@/components/ui';
import { HRWorkflowFeedback } from '@/features/hr/components/HRWorkflowFeedback';
import type { FacilitySummary } from '@/features/hr/types';
import { formatDateTimeInZone } from '@/lib/formatting';
import { AppLayout } from '@/layouts/AppLayout/AppLayout';

interface AccessRequest {
  id: string;
  staffId: string;
  staffNumber: string;
  staffName: string;
  accountEmail: string;
  roleKey: string;
  roleLabel: string;
  reason: string;
  requestedAt: string;
  requesterName: string;
}

interface AccessRequestsPageProps {
  facility: FacilitySummary;
  timezone: string;
  requests: AccessRequest[];
  pendingAccessRequestCount?: number;
  errors?: Record<string, string>;
}

interface StaffAccessRequests {
  staffId: string;
  staffNumber: string;
  staffName: string;
  accountEmail: string;
  requests: AccessRequest[];
}

const REQUEST_HEADERS = [
  { key: 'staff', header: 'Staff member' },
  { key: 'account', header: 'Account' },
  { key: 'roles', header: 'Requested roles' },
  { key: 'request', header: 'Request details' },
];

export default function AccessRequestsPage({
  facility,
  timezone = 'Africa/Dar_es_Salaam',
  requests = [],
  errors,
}: AccessRequestsPageProps): React.JSX.Element {
  const [rejectTarget, setRejectTarget] = useState<AccessRequest | null>(null);
  const [decisionReason, setDecisionReason] = useState('');
  const [processing, setProcessing] = useState(false);

  function approve(accessRequest: AccessRequest): void {
    router.post(
      routes.workspaces.hrStaffAccessRequestApprove(accessRequest.staffId, accessRequest.id),
      { return_to: 'access-requests' },
      { preserveScroll: true },
    );
  }

  function withdraw(): void {
    if (rejectTarget === null || decisionReason.trim().length < 8 || processing) return;
    setProcessing(true);
    router.post(
      routes.workspaces.hrStaffAccessRequestReject(rejectTarget.staffId, rejectTarget.id),
      { decision_reason: decisionReason.trim() },
      {
        preserveScroll: true,
        onFinish: () => {
          setProcessing(false);
          setRejectTarget(null);
          setDecisionReason('');
        },
      },
    );
  }

  const groupedRequests = requests.reduce<StaffAccessRequests[]>((groups, accessRequest) => {
    const group = groups.find((candidate) => candidate.staffId === accessRequest.staffId);
    if (group) {
      group.requests.push(accessRequest);
    } else {
      groups.push({
        staffId: accessRequest.staffId,
        staffNumber: accessRequest.staffNumber,
        staffName: accessRequest.staffName,
        accountEmail: accessRequest.accountEmail,
        requests: [accessRequest],
      });
    }

    return groups;
  }, []);

  const rows = groupedRequests.map((staffRequests) => ({
    id: staffRequests.staffId,
    staff: (
      <div className="facility-staff__table-primary-cell">
        <Link href={routes.workspaces.hrStaffDetail(staffRequests.staffId)}>
          <strong>{staffRequests.staffName}</strong>
        </Link>
        <span>{staffRequests.staffNumber}</span>
      </div>
    ),
    account: staffRequests.accountEmail,
    roles: staffRequests.requests.map((accessRequest) => accessRequest.roleLabel).join(', '),
    request: `${staffRequests.requests.length} role ${staffRequests.requests.length === 1 ? 'request' : 'requests'} pending`,
  }));

  return (
    <AppLayout>
      <main className="afyanova-page facility-staff">
        <PageHeader
          title="Role approvals"
          description={`Review facility access requested for invited staff at ${facility.name}.`}
          actions={(
            <Button as={Link} kind="ghost" size="sm" href={routes.workspaces.hrStaffOnboarding}>
              Back to onboarding
            </Button>
          )}
        />
        <HRWorkflowFeedback errors={errors} />
        <p>Each staff member appears once. Review each requested role and decide on it individually.</p>
        <section className="facility-staff__directory" aria-label="Pending staff access requests">
          <CarbonDataTable
            rows={rows}
            headers={REQUEST_HEADERS}
            title={`Pending access requests (${requests.length} roles · ${groupedRequests.length} staff)`}
            ariaLabel="Pending staff access requests"
            emptyState={(
              <EmptyState
                title="No access requests to review"
                description="Completed account setups with pending facility-role requests will appear here."
              />
            )}
            toolbar={(onInputChange) => (
              <TableToolbarSearch
                persistent
                onChange={onInputChange}
                placeholder="Search access requests"
                aria-label="Search access requests"
              />
            )}
            getExpandRowLabel={(row) => {
              const staffRequests = groupedRequests.find((group) => group.staffId === row.id);
              return `access requests for ${staffRequests?.staffName ?? 'staff member'}`;
            }}
            renderExpandedRow={(row) => {
              const staffRequests = groupedRequests.find((group) => group.staffId === row.id);
              if (!staffRequests) return null;

              return (
                <div className="facility-staff__access-request-details">
                  {staffRequests.requests.map((accessRequest) => (
                    <section className="facility-staff__access-request-detail" key={accessRequest.id}>
                      <p className="facility-staff__access-request-summary">
                        <strong>{accessRequest.roleLabel}</strong>
                        {' '}{accessRequest.reason}{' '}
                        <span>
                          Requested by {accessRequest.requesterName} ·{' '}
                          <time dateTime={accessRequest.requestedAt}>
                            {formatDateTimeInZone(accessRequest.requestedAt, timezone) ?? accessRequest.requestedAt}
                          </time>
                        </span>
                      </p>
                      <div className="facility-staff__table-actions">
                        <Button
                          size="sm"
                          aria-label={`Approve ${accessRequest.roleLabel}`}
                          onClick={() => approve(accessRequest)}
                        >
                          Approve
                        </Button>
                        <Button
                          kind="danger--tertiary"
                          size="sm"
                          aria-label={`Withdraw ${accessRequest.roleLabel}`}
                          onClick={() => {
                            setRejectTarget(accessRequest);
                            setDecisionReason('');
                          }}
                        >
                          Withdraw
                        </Button>
                      </div>
                    </section>
                  ))}
                </div>
              );
            }}
          />
        </section>
        <Modal
          open={rejectTarget !== null}
          modalHeading="Withdraw access request"
          primaryButtonText={processing ? 'Withdrawing…' : 'Withdraw request'}
          secondaryButtonText="Cancel"
          primaryButtonDisabled={processing || decisionReason.trim().length < 8}
          onRequestClose={() => {
            if (!processing) setRejectTarget(null);
          }}
          onRequestSubmit={withdraw}
        >
          <p>
            Withdraw {rejectTarget?.roleLabel} access for {rejectTarget?.staffName}? This does not alter the staff record.
          </p>
          <label htmlFor="access-request-withdraw-reason">Reason (at least 8 characters)</label>
          <textarea
            id="access-request-withdraw-reason"
            value={decisionReason}
            maxLength={500}
            rows={4}
            onChange={(event) => setDecisionReason(event.currentTarget.value)}
          />
        </Modal>
      </main>
    </AppLayout>
  );
}
