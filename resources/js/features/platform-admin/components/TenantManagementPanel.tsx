import { router, useForm } from '@inertiajs/react';
import { Button, Checkbox, Form, InlineNotification, Tag, TextArea, TextInput } from '@carbon/react';
import { Fragment, useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { DockedPanel } from '@/components/panels';
import { FormErrors } from '@/components/ui';
import { routes } from '@/app/routes';
import type { TenantDetails } from '../types/platform-admin';
import { UserMinus } from '@/icons';

/**
 * Tenant administration dock. Organization and facility details live in the
 * registry's expanded row; this panel only carries the governed access work
 * (invitations, revocations, and emergency reviews) so nothing is repeated.
 */
export function TenantManagementPanel({
  tenant,
  onClose,
}: {
  tenant: TenantDetails;
  onClose: () => void;
}): ReactNode {
  const [confirmedRevocations, setConfirmedRevocations] = useState<Record<string, boolean>>({});
  const [revocationReasons, setRevocationReasons] = useState<Record<string, string>>({});
  const [revocationErrors, setRevocationErrors] = useState<Record<string, string>>({});
  const [revokingAssignmentId, setRevokingAssignmentId] = useState<string | null>(null);
  const [reviewNotes, setReviewNotes] = useState<Record<string, string>>({});
  const [reviewErrors, setReviewErrors] = useState<Record<string, string>>({});
  const [reviewingId, setReviewingId] = useState<string | null>(null);
  const [tenantStatusReason, setTenantStatusReason] = useState('');
  const [tenantStatusConfirmed, setTenantStatusConfirmed] = useState(false);
  const [tenantStatusError, setTenantStatusError] = useState('');
  const [updatingTenantStatus, setUpdatingTenantStatus] = useState(false);

  const canInviteAdmin = !tenant.hasActiveTenantAdmin && tenant.canInviteAdmin;
  const nextTenantStatus = tenant.status === 'ACTIVE' ? 'SUSPENDED' : 'ACTIVE';

  const invitationForm = useForm({
    name: '',
    email: tenant.pendingAdminEmail ?? '',
    change_reference: '',
    resend: Boolean(tenant.pendingAdminEmail),
  });

  useEffect(() => {
    if (tenant.pendingAdminEmail) {
      invitationForm.setData((current) => ({
        ...current,
        email: tenant.pendingAdminEmail ?? '',
        resend: true,
      }));
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps -- form identity keys off pendingAdminEmail
  }, [tenant.pendingAdminEmail]);

  function requestRevocation(event: FormEvent, assignmentId: string): void {
    event.preventDefault();
    setRevokingAssignmentId(assignmentId);
    router.post(routes.platformAdmin.revokeTenantAdmin(tenant.id), {
      assignment_id: assignmentId,
      reason: revocationReasons[assignmentId] ?? '',
    }, {
      preserveScroll: true,
      onSuccess: () => {
        setConfirmedRevocations((current) => ({ ...current, [assignmentId]: false }));
        setRevocationErrors((current) => ({ ...current, [assignmentId]: '' }));
      },
      onError: (errors) => setRevocationErrors((current) => ({
        ...current,
        [assignmentId]: Object.values(errors)[0] ?? 'The Tenant Admin revocation could not be submitted.',
      })),
      onFinish: () => setRevokingAssignmentId(null),
    });
  }

  function reviewEmergencyRevocation(event: FormEvent, reviewId: string): void {
    event.preventDefault();
    setReviewingId(reviewId);
    router.post(routes.platformAdmin.reviewTenantAdminRevocation(tenant.id, reviewId), {
      review_notes: reviewNotes[reviewId] ?? '',
    }, {
      preserveScroll: true,
      onSuccess: () => {
        setReviewNotes((current) => ({ ...current, [reviewId]: '' }));
        setReviewErrors((current) => ({ ...current, [reviewId]: '' }));
      },
      onError: (errors) => setReviewErrors((current) => ({
        ...current,
        [reviewId]: Object.values(errors)[0] ?? 'The emergency action review could not be completed.',
      })),
      onFinish: () => setReviewingId(null),
    });
  }

  function submitInvitation(event: FormEvent): void {
    event.preventDefault();
    invitationForm.post(routes.platformAdmin.initialTenantAdmin(tenant.id), {
      preserveScroll: true,
      onSuccess: () => invitationForm.reset('name', 'email', 'change_reference', 'resend'),
    });
  }

  function updateTenantStatus(event: FormEvent): void {
    event.preventDefault();
    setUpdatingTenantStatus(true);
    setTenantStatusError('');
    router.post(routes.platformAdmin.updateTenantStatus(tenant.id), {
      status: nextTenantStatus,
      reason: tenantStatusReason,
    }, {
      preserveScroll: true,
      onSuccess: () => {
        setTenantStatusReason('');
        setTenantStatusConfirmed(false);
      },
      onError: (errors) => setTenantStatusError(
        Object.values(errors)[0] ?? 'The tenant status change could not be completed.',
      ),
      onFinish: () => setUpdatingTenantStatus(false),
    });
  }

  return (
    <DockedPanel
      heading={tenant.name}
      description="Tenant administrator access changes."
      placement="right"
      width="min(48rem, 100%)"
      expandedWidth="min(72rem, 100%)"
      expandable
      onClose={onClose}
      actions={(
        <Fragment>
          <Button size="lg" kind="secondary" onClick={onClose}>Close</Button>
          {canInviteAdmin ? (
            <Button
              size="lg"
              type="submit"
              form="tenant-admin-invitation"
              disabled={invitationForm.processing}
            >
              {invitationForm.processing
                ? 'Sending invitation…'
                : tenant.pendingAdminEmail
                  ? 'Resend setup invitation'
                  : 'Send setup invitation'}
            </Button>
          ) : null}
        </Fragment>
      )}
    >
      <section aria-label="Tenant administrator access">
        <p className="platform-admin__section-note">
          Emergency revocations take effect immediately and require independent post-incident review.
        </p>
        <div className="platform-admin__access-flow">
          <section aria-label="Tenant lifecycle">
            <h3 className="platform-admin__expansion-title">Tenant lifecycle</h3>
            <p className="platform-admin__section-note">
              Current status: <strong>{tenant.status}</strong>. Suspension blocks tenant access,
              revokes tracked sessions, and preserves tenant records. Reactivation does not restore
              revoked sessions; users must sign in again.
            </p>
            {tenantStatusError ? (
              <InlineNotification
                kind="error"
                lowContrast
                title="Tenant status change failed"
                subtitle={tenantStatusError}
              />
            ) : null}
            <Form noValidate onSubmit={updateTenantStatus}>
              <TextArea
                id="tenant-status-reason"
                labelText="Reason for status change (do not include patient or clinical information)"
                value={tenantStatusReason}
                onChange={(event) => setTenantStatusReason(event.target.value)}
                rows={2}
                maxLength={500}
                required
              />
              <Checkbox
                id="tenant-status-confirm"
                labelText={`I confirm this tenant ${nextTenantStatus === 'SUSPENDED' ? 'suspension' : 'reactivation'}.`}
                checked={tenantStatusConfirmed}
                onChange={(_, { checked }) => setTenantStatusConfirmed(checked)}
              />
              <div className="platform-admin__form-actions">
                <Button
                  size="sm"
                  type="submit"
                  kind={nextTenantStatus === 'SUSPENDED' ? 'danger' : 'primary'}
                  disabled={updatingTenantStatus || !tenantStatusConfirmed || tenantStatusReason.trim().length < 8}
                >
                  {updatingTenantStatus
                    ? 'Updating tenant…'
                    : nextTenantStatus === 'SUSPENDED' ? 'Suspend tenant' : 'Reactivate tenant'}
                </Button>
              </div>
            </Form>
          </section>

          {tenant.administrators.length > 0 ? (
            <section aria-label="Active administrators">
              <h3 className="platform-admin__expansion-title">Active administrators</h3>
              <div className="platform-admin__access-stack">
                {tenant.administrators.map((administrator) => (
                  <Form
                    key={administrator.assignmentId}
                    noValidate
                    onSubmit={(event) => requestRevocation(event, administrator.assignmentId)}
                  >
                    <div className="platform-admin__access-person">
                      <strong>{administrator.name}</strong>
                      <span>{administrator.email}</span>
                      {administrator.accountStatus !== 'ACTIVE' ? (
                        <Tag type="red">
                          {administrator.accountStatus === 'HR_BLOCKED'
                            ? 'Blocked by HR status'
                            : `Account ${administrator.accountStatus.toLowerCase()}`}
                        </Tag>
                      ) : null}
                    </div>
                    {revocationErrors[administrator.assignmentId] ? (
                      <InlineNotification
                        kind="error"
                        lowContrast
                        title="Revocation could not be submitted"
                        subtitle={revocationErrors[administrator.assignmentId]}
                      />
                    ) : null}
                    <TextArea
                      id={`revoke-tenant-admin-reason-${administrator.assignmentId}`}
                      labelText={`Reason to revoke ${administrator.name}`}
                      value={revocationReasons[administrator.assignmentId] ?? ''}
                      onChange={(event) => setRevocationReasons((current) => ({
                        ...current,
                        [administrator.assignmentId]: event.target.value,
                      }))}
                      rows={2}
                      maxLength={500}
                      required
                    />
                    <Checkbox
                      id={`confirm-tenant-admin-revoke-${administrator.assignmentId}`}
                      labelText="I confirm this emergency revocation; it takes effect immediately and requires post-incident review."
                      checked={Boolean(confirmedRevocations[administrator.assignmentId])}
                      onChange={(_, { checked }) => setConfirmedRevocations((current) => ({
                        ...current,
                        [administrator.assignmentId]: checked,
                      }))}
                    />
                    <div className="platform-admin__form-actions">
                      <Button
                        size="sm"
                        kind="danger"
                        type="submit"
                        renderIcon={UserMinus}
                        disabled={!confirmedRevocations[administrator.assignmentId]
                          || (revocationReasons[administrator.assignmentId] ?? '').trim().length < 8
                          || revokingAssignmentId !== null}
                      >
                        Request revocation
                      </Button>
                    </div>
                  </Form>
                ))}
              </div>
            </section>
          ) : null}

          {tenant.pendingRevocations.length > 0 ? (
            <section aria-label="Revocations awaiting approval">
              <h3 className="platform-admin__expansion-title">Revocations awaiting approval</h3>
              <ul className="platform-admin__flat-list">
                {tenant.pendingRevocations.map((request) => (
                  <li key={request.id}>
                    <div>
                      <strong>{request.name}</strong>
                      <span>{request.email}</span>
                    </div>
                    <Tag type="cool-gray">Awaiting approval</Tag>
                  </li>
                ))}
              </ul>
            </section>
          ) : null}

          {tenant.emergencyReviews.length > 0 ? (
            <section aria-label="Emergency revocation reviews">
              <h3 className="platform-admin__expansion-title">Emergency revocation reviews</h3>
              <div className="platform-admin__access-stack">
                {tenant.emergencyReviews.map((review) => (
                  <Form
                    key={review.id}
                    noValidate
                    onSubmit={(event) => reviewEmergencyRevocation(event, review.id)}
                  >
                    <div className="platform-admin__access-person">
                      <strong>Emergency revocation: {review.targetName}</strong>
                      <span>Requested by {review.initiatorName} · {review.createdAt}</span>
                      <span>Reason: {review.reason}</span>
                    </div>
                    {reviewErrors[review.id] ? (
                      <InlineNotification
                        kind="error"
                        lowContrast
                        title="Review could not be recorded"
                        subtitle={reviewErrors[review.id]}
                      />
                    ) : null}
                    <TextArea
                      id={`review-emergency-revocation-${review.id}`}
                      labelText="Post-incident review notes"
                      value={reviewNotes[review.id] ?? ''}
                      onChange={(event) => setReviewNotes((current) => ({
                        ...current,
                        [review.id]: event.target.value,
                      }))}
                      rows={2}
                      maxLength={1000}
                      required
                    />
                    <div className="platform-admin__form-actions">
                      <Button
                        size="sm"
                        type="submit"
                        disabled={reviewingId === review.id || (reviewNotes[review.id] ?? '').trim().length < 8}
                      >
                        {reviewingId === review.id ? 'Saving review…' : 'Record review'}
                      </Button>
                    </div>
                  </Form>
                ))}
              </div>
            </section>
          ) : null}

          {canInviteAdmin ? (
            <section aria-label="Initial Tenant Admin">
              <h3 className="platform-admin__expansion-title">Initial Tenant Admin</h3>
              {tenant.pendingAdminEmail ? (
                <p className="platform-admin__section-note">
                  {tenant.pendingAdminInvitationExpired ? 'Invitation expired' : 'Invitation pending'} for {tenant.pendingAdminEmail}
                  {tenant.pendingAdminExpiresAt
                    ? ` · expires ${new Date(tenant.pendingAdminExpiresAt).toLocaleString()}`
                    : ''}.
                </p>
              ) : null}
              <FormErrors
                errors={invitationForm.errors}
                fields={['name', 'email', 'change_reference', 'resend']}
              />
              <Form
                id="tenant-admin-invitation"
                noValidate
                onSubmit={submitInvitation}
                aria-label="Invite initial Tenant Admin"
              >
                <TextInput
                  id="tenant-admin-name"
                  labelText="Administrator name"
                  value={invitationForm.data.name}
                  onChange={(event) => invitationForm.setData('name', event.target.value)}
                  invalid={Boolean(invitationForm.errors.name)}
                  invalidText={invitationForm.errors.name}
                  required
                />
                <TextInput
                  id="tenant-admin-email"
                  type="email"
                  labelText="Administrator email"
                  value={invitationForm.data.email}
                  onChange={(event) => invitationForm.setData('email', event.target.value)}
                  invalid={Boolean(invitationForm.errors.email)}
                  invalidText={invitationForm.errors.email}
                  required
                />
                <TextInput
                  id="tenant-admin-change-reference"
                  labelText="Non-secret change/ticket reference"
                  value={invitationForm.data.change_reference}
                  onChange={(event) => invitationForm.setData('change_reference', event.target.value)}
                  invalid={Boolean(invitationForm.errors.change_reference)}
                  invalidText={invitationForm.errors.change_reference}
                  required
                />
                {tenant.pendingAdminEmail ? (
                  <Checkbox
                    id="tenant-admin-resend"
                    labelText="Rotate and resend the pending invitation to this same email"
                    checked={invitationForm.data.resend}
                    onChange={(_, { checked }) => invitationForm.setData('resend', checked)}
                  />
                ) : null}
              </Form>
            </section>
          ) : (
            <p className="platform-admin__section-note">
              {tenant.hasActiveTenantAdmin
                ? 'A Tenant Admin is active; later access changes follow the audited role-promotion workflow.'
                : 'Complete the organization setup before inviting an administrator.'}
            </p>
          )}
        </div>
      </section>
    </DockedPanel>
  );
}
