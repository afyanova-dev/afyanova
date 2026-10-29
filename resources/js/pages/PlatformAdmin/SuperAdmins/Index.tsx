import { Button, InlineNotification, Select, SelectItem, Tag, TextArea } from '@carbon/react';
import { router } from '@inertiajs/react';
import { useState, type FormEvent, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import { PageHeader } from '@/components/ui';
import { AppLayout } from '@/layouts/AppLayout/AppLayout';

interface PlatformAdministrator {
  id: string;
  name: string;
  email: string;
  status: string;
  twoFactorEnabled: boolean;
}

interface AccessRequest {
  id: string;
  targetId: string;
  targetName: string;
  requesterId: string;
  requesterName: string;
  reviewerName: string | null;
  action: string;
  status: string;
  reason: string;
  reviewNotes: string | null;
  createdAt: string;
}

interface SuperAdminAccessProps {
  administrators: PlatformAdministrator[];
  requests: AccessRequest[];
  currentAdminId: string;
}

export default function SuperAdminAccessIndex({
  administrators,
  requests,
  currentAdminId,
}: SuperAdminAccessProps): ReactNode {
  const [selectedActions, setSelectedActions] = useState<Record<string, string>>({});
  const [reasons, setReasons] = useState<Record<string, string>>({});
  const [reviewNotes, setReviewNotes] = useState<Record<string, string>>({});
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busyId, setBusyId] = useState<string | null>(null);

  function requestChange(event: FormEvent, administrator: PlatformAdministrator): void {
    event.preventDefault();
    const action = selectedActions[administrator.id]
      ?? (administrator.status === 'ACTIVE' ? 'SUSPEND' : 'REACTIVATE');
    setBusyId(administrator.id);
    setErrors((current) => ({ ...current, [administrator.id]: '' }));
    router.post(routes.platformAdmin.superAdminAccessRequests, {
      target_id: administrator.id,
      action,
      reason: reasons[administrator.id] ?? '',
    }, {
      preserveScroll: true,
      onError: (validationErrors) => setErrors((current) => ({
        ...current,
        [administrator.id]: Object.values(validationErrors)[0] ?? 'The access request could not be submitted.',
      })),
      onFinish: () => setBusyId(null),
    });
  }

  function reviewRequest(request: AccessRequest, approve: boolean): void {
    setBusyId(request.id);
    setErrors((current) => ({ ...current, [request.id]: '' }));
    router.post(routes.platformAdmin.reviewSuperAdminAccessRequest(request.id), {
      approve,
      review_notes: reviewNotes[request.id] ?? '',
    }, {
      preserveScroll: true,
      onError: (validationErrors) => setErrors((current) => ({
        ...current,
        [request.id]: Object.values(validationErrors)[0] ?? 'The review could not be recorded.',
      })),
      onFinish: () => setBusyId(null),
    });
  }

  return (
    <AppLayout>
      <main className="afyanova-page platform-admin">
        <PageHeader
          title="Super Admin access"
          description="Request guarded account suspension, reactivation, or MFA recovery. A different active administrator must review each request."
        />

        <section className="platform-admin__section" aria-label="Platform Super Admin accounts">
          <h2>Accounts</h2>
          <div className="platform-admin__access-stack">
            {administrators.map((administrator) => (
              <article key={administrator.id} className="platform-admin__access-person">
                <strong>{administrator.name}</strong>
                <span>{administrator.email}</span>
                <Tag type={administrator.status === 'ACTIVE' ? 'green' : 'red'}>{administrator.status}</Tag>
                <Tag type={administrator.twoFactorEnabled ? 'green' : 'red'}>
                  {administrator.twoFactorEnabled ? 'MFA enabled' : 'MFA setup required'}
                </Tag>
                {administrator.id === currentAdminId ? (
                  <p className="platform-admin__section-note">You cannot request a change to your own account.</p>
                ) : administrator.status === 'PENDING' ? (
                  <p className="platform-admin__section-note">
                    This account has not completed its setup invitation; manage it through the bootstrap workflow.
                  </p>
                ) : (
                  <form
                    className="platform-admin__super-admin-request"
                    onSubmit={(event) => requestChange(event, administrator)}
                  >
                    <Select
                      id={`super-admin-action-${administrator.id}`}
                      labelText="Requested action"
                      value={selectedActions[administrator.id]
                        ?? (administrator.status === 'ACTIVE' ? 'SUSPEND' : 'REACTIVATE')}
                      onChange={(event) => setSelectedActions((current) => ({
                        ...current,
                        [administrator.id]: event.target.value,
                      }))}
                    >
                      {administrator.status === 'ACTIVE' ? (
                        <>
                          <SelectItem value="SUSPEND" text="Suspend account" />
                          {administrator.twoFactorEnabled ? (
                            <SelectItem value="RESET_MFA" text="Reset MFA (re-enrollment required)" />
                          ) : null}
                        </>
                      ) : <SelectItem value="REACTIVATE" text="Reactivate account" />}
                    </Select>
                    <TextArea
                      id={`super-admin-reason-${administrator.id}`}
                      labelText="Reason (do not include patient or clinical information)"
                      value={reasons[administrator.id] ?? ''}
                      onChange={(event) => setReasons((current) => ({
                        ...current,
                        [administrator.id]: event.target.value,
                      }))}
                      rows={2}
                      maxLength={500}
                      required
                    />
                    {errors[administrator.id] ? (
                      <InlineNotification kind="error" lowContrast title="Request failed" subtitle={errors[administrator.id]} />
                    ) : null}
                    <Button
                      size="sm"
                      type="submit"
                      disabled={busyId === administrator.id || (reasons[administrator.id] ?? '').trim().length < 8}
                    >
                      {busyId === administrator.id ? 'Submitting…' : 'Request action'}
                    </Button>
                  </form>
                )}
              </article>
            ))}
          </div>
        </section>

        <section className="platform-admin__section" aria-label="Super Admin access requests">
          <h2>Access requests</h2>
          {requests.length === 0 ? (
            <p className="platform-admin__section-note">No access requests have been recorded.</p>
          ) : (
            <div className="platform-admin__access-stack">
              {requests.map((request) => {
                const canReview = request.status === 'PENDING'
                  && request.requesterId !== currentAdminId
                  && request.targetId !== currentAdminId;

                return (
                  <article key={request.id} className="platform-admin__access-person">
                    <strong>{request.action.replaceAll('_', ' ')}</strong>
                    <span>Target: {request.targetName}</span>
                    <span>Requested by {request.requesterName} · {new Date(request.createdAt).toLocaleString()}</span>
                    <span>Reason: {request.reason}</span>
                    <Tag type={request.status === 'PENDING' ? 'blue' : request.status === 'APPROVED' ? 'green' : 'red'}>
                      {request.status}
                    </Tag>
                    {request.reviewNotes ? <span>Review notes: {request.reviewNotes}</span> : null}
                    {canReview ? (
                      <div className="platform-admin__super-admin-review">
                        <TextArea
                          id={`super-admin-review-${request.id}`}
                          labelText="Independent review notes (do not include patient or clinical information)"
                          value={reviewNotes[request.id] ?? ''}
                          onChange={(event) => setReviewNotes((current) => ({
                            ...current,
                            [request.id]: event.target.value,
                          }))}
                          rows={2}
                          maxLength={1000}
                          required
                        />
                        {errors[request.id] ? (
                          <InlineNotification kind="error" lowContrast title="Review failed" subtitle={errors[request.id]} />
                        ) : null}
                        <div className="platform-admin__form-actions">
                          <Button
                            size="sm"
                            disabled={busyId === request.id || (reviewNotes[request.id] ?? '').trim().length < 8}
                            onClick={() => reviewRequest(request, true)}
                          >
                            Approve
                          </Button>
                          <Button
                            size="sm"
                            kind="secondary"
                            disabled={busyId === request.id || (reviewNotes[request.id] ?? '').trim().length < 8}
                            onClick={() => reviewRequest(request, false)}
                          >
                            Reject
                          </Button>
                        </div>
                      </div>
                    ) : null}
                  </article>
                );
              })}
            </div>
          )}
        </section>
      </main>
    </AppLayout>
  );
}
