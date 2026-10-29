import { InlineNotification, Tag } from '@carbon/react';
import { PageHeader } from '@/components/ui';
import { AppLayout } from '@/layouts/AppLayout/AppLayout';

interface UserAccount {
  id: string;
  name: string;
  email: string;
  status: string;
  createdAt: string;
}

interface AccountAssignment {
  id: string;
  role: string;
  scope?: string;
  status: string;
  effectiveFrom: string | null;
  effectiveTo: string | null;
  isEffective: boolean;
  isLifecycleSuspended: boolean;
}

interface AccessHistoryEntry {
  action: string;
  role: string;
  actorName: string;
  reason: string | null;
  occurredAt: string;
}

interface RoleRequest {
  role: string;
  status: string;
  reason: string;
  requestedAt: string;
}

interface LinkedStaff {
  id: string;
  staffNumber: string;
  name: string;
}

interface UserDetailsProps {
  facility: { id: string; name: string };
  account: UserAccount;
  assignments: AccountAssignment[];
  accessHistory?: AccessHistoryEntry[];
  requests: RoleRequest[];
  linkedStaff: LinkedStaff | null;
}

function tagType(status: string): 'green' | 'red' | 'blue' | 'cool-gray' {
  if (status === 'ACTIVE' || status === 'APPROVED') return 'green';
  if (status === 'SUSPENDED' || status === 'REJECTED' || status === 'REVOKED') return 'red';
  if (status === 'PENDING' || status === 'SETUP PENDING') return 'blue';

  return 'cool-gray';
}

export default function UserDetailsPage({
  facility,
  account,
  assignments,
  accessHistory = [],
  requests,
  linkedStaff,
}: UserDetailsProps): React.JSX.Element {
  return (
    <AppLayout>
      <main className="afyanova-page facility-staff">
        <PageHeader
          title={account.name}
          description={`AfyaNova user account at ${facility.name}.`}
        />

        {account.status === 'SETUP PENDING' ? (
          <InlineNotification
            kind="info"
            lowContrast
            hideCloseButton
            title="Account setup not complete"
            subtitle="This user needs to finish account setup before signing in."
          />
        ) : null}

        <section className="facility-staff__section" aria-labelledby="hr-user-account-heading">
          <div className="facility-staff__section-heading">
            <div>
              <p className="facility-staff__eyebrow">User account</p>
              <h2 id="hr-user-account-heading">Account details</h2>
            </div>
            <Tag type={tagType(account.status)}>{account.status}</Tag>
          </div>
          <dl className="facility-staff__profile-grid">
            <div><dt>Name</dt><dd>{account.name}</dd></div>
            <div><dt>Email</dt><dd>{account.email}</dd></div>
            <div><dt>Account created</dt><dd>{account.createdAt}</dd></div>
            <div><dt>Facility</dt><dd>{facility.name}</dd></div>
          </dl>
        </section>

        <section className="facility-staff__section" aria-labelledby="hr-user-access-heading">
          <div className="facility-staff__section-heading">
            <div>
              <p className="facility-staff__eyebrow">Facility access</p>
              <h2 id="hr-user-access-heading">Role assignments</h2>
            </div>
            <Tag type="gray">{assignments.length} assignments</Tag>
          </div>
          {assignments.length === 0 ? (
            <p>No facility roles are assigned to this account yet.</p>
          ) : (
            <ul className="facility-staff__user-list">
              {assignments.map((assignment) => (
                <li key={assignment.id}>
                  <div>
                    <strong>{assignment.role}</strong>
                    <small>
                      {assignment.status === 'ACTIVE' && assignment.isEffective
                        ? 'Currently effective'
                        : assignment.status === 'ACTIVE'
                          ? 'Not currently effective'
                          : assignment.status}
                      {assignment.isLifecycleSuspended ? ' · Managed by staff suspension' : ''}
                      {' · '}
                      {assignment.scope === 'ORGANIZATION' ? 'Organization-wide · ' : ''}
                      {assignment.effectiveFrom ? `From ${assignment.effectiveFrom}` : 'Effective immediately'}
                      {assignment.effectiveTo ? ` · Until ${assignment.effectiveTo}` : ''}
                    </small>
                  </div>
                  <Tag type={tagType(assignment.status)}>{
                    assignment.status === 'ACTIVE' && !assignment.isEffective
                      ? 'NOT EFFECTIVE'
                      : assignment.status
                  }</Tag>
                </li>
              ))}
            </ul>
          )}
        </section>

        <section className="facility-staff__section" aria-labelledby="hr-user-access-history-heading">
          <div className="facility-staff__section-heading">
            <div>
              <p className="facility-staff__eyebrow">Audit trail</p>
              <h2 id="hr-user-access-history-heading">Access changes</h2>
            </div>
            <Tag type="gray">{accessHistory.length} changes</Tag>
          </div>
          {accessHistory.length === 0 ? (
            <p>No access changes are recorded for this account at this facility.</p>
          ) : (
            <ul className="facility-staff__user-list">
              {accessHistory.map((entry, index) => (
                <li key={`${entry.action}-${entry.role}-${entry.occurredAt}-${index}`}>
                  <div>
                    <strong>{entry.role}</strong>
                    <small>
                      {entry.action.replaceAll('.', ' ')} · {new Date(entry.occurredAt).toLocaleString()}
                      {' · by '}{entry.actorName}{entry.reason ? ` · ${entry.reason}` : ''}
                    </small>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </section>

        <section className="facility-staff__section" aria-labelledby="hr-user-requests-heading">
          <div className="facility-staff__section-heading">
            <div>
              <p className="facility-staff__eyebrow">Access history</p>
              <h2 id="hr-user-requests-heading">Role requests</h2>
            </div>
            <Tag type="gray">{requests.length} requests</Tag>
          </div>
          {requests.length === 0 ? (
            <p>No role requests recorded for this facility.</p>
          ) : (
            <ul className="facility-staff__user-list">
              {requests.map((request) => (
                <li key={`${request.role}-${request.requestedAt}`}>
                  <div>
                    <strong>{request.role}</strong>
                    <small>{request.requestedAt} · {request.reason}</small>
                  </div>
                  <Tag type={tagType(request.status)}>{request.status}</Tag>
                </li>
              ))}
            </ul>
          )}
        </section>

        {linkedStaff ? (
          <section className="facility-staff__section" aria-labelledby="hr-user-employee-heading">
            <div className="facility-staff__section-heading">
              <div>
                <p className="facility-staff__eyebrow">HR record link</p>
                <h2 id="hr-user-employee-heading">Linked employee</h2>
              </div>
              <Tag type="green">Linked</Tag>
            </div>
            <p>{linkedStaff.name} · {linkedStaff.staffNumber}</p>
          </section>
        ) : null}
      </main>
    </AppLayout>
  );
}
