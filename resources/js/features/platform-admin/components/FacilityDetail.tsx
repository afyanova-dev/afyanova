import { Link, router, useForm } from '@inertiajs/react';
import {
  Button,
  Checkbox,
  Form,
  InlineNotification,
  Select,
  SelectItem,
  TabPanel,
  TabPanels,
  Tabs,
  Tag,
  TextArea,
  TextInput,
  Tile,
} from '@carbon/react';
import { useState, type FormEvent, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import { ContainedTabList, FormErrors, IconTab, PageHeader, Status, EmptyState } from '@/components/ui';
import { AppLayout } from '@/layouts/AppLayout/AppLayout';
import type { FacilityDetails, FacilityReferences } from '../types/platform-admin';
import { ArrowLeft, Checkmark, Hospital, UserMinus, UserMultiple } from '@/icons';

interface FacilityDetailProps {
  facility: FacilityDetails;
  facilityReferences: FacilityReferences;
}

export function FacilityDetail({ facility, facilityReferences }: FacilityDetailProps): ReactNode {
  const [confirmedRevocations, setConfirmedRevocations] = useState<Record<string, boolean>>({});
  const [revocationReasons, setRevocationReasons] = useState<Record<string, string>>({});
  const [revocationErrors, setRevocationErrors] = useState<Record<string, string>>({});
  const [revokingAssignmentId, setRevokingAssignmentId] = useState<string | null>(null);
  const editForm = useForm({
    name: facility.name,
    type: facility.type,
    careLevel: facility.careLevel,
    ownershipType: facility.ownershipType,
    status: facility.status,
  });
  const statusKind = facility.status === 'ACTIVE' ? 'active' : 'inactive';

  function update(event: FormEvent): void {
    event.preventDefault();
    editForm.patch(routes.platformAdmin.updateFacility(facility.tenantId, facility.id), {
      preserveScroll: true,
    });
  }

  function requestRevocation(event: FormEvent, assignmentId: string): void {
    event.preventDefault();
    setRevokingAssignmentId(assignmentId);
    router.post(routes.platformAdmin.revokeFacilityAdmin(facility.tenantId, facility.id), {
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
        [assignmentId]: Object.values(errors)[0] ?? 'The Facility Admin revocation could not be submitted.',
      })),
      onFinish: () => setRevokingAssignmentId(null),
    });
  }

  const facilityTypes = withCurrent(facilityReferences.facilityTypes, facility.type);
  const careLevels = withCurrent(facilityReferences.careLevels, facility.careLevel);
  const ownershipTypes = withCurrent(facilityReferences.ownershipTypes, facility.ownershipType);

  return (
    <AppLayout>
      <main className="afyanova-page platform-admin">
        <PageHeader
          title={facility.name}
          description={`${facility.organizationName} · ${facility.code}`}
          badge={<Status kind={statusKind}>{facility.status}</Status>}
          actions={
            <Button size="lg" renderIcon={ArrowLeft} as={Link} href={routes.platformAdmin.tenant(facility.tenantId)} kind="tertiary">
              Back to tenant
            </Button>
          }
        />
        <Tabs>
          <ContainedTabList aria-label="Facility detail sections">
            <IconTab icon={Hospital}>Facility profile</IconTab>
            <IconTab icon={UserMultiple}>Administrators</IconTab>
          </ContainedTabList>
          <TabPanels>
          <TabPanel>
            <section className="platform-admin__section" aria-labelledby="facility-profile-heading">
              <header className="platform-admin__section-heading">
                <div>
                  <p className="platform-admin__eyebrow">Facility profile</p>
                  <h2 id="facility-profile-heading">Details and operational status</h2>
                </div>
              </header>
              <Tile className="platform-admin__facility-panel">
            <Form noValidate onSubmit={update} aria-label="Update facility details">
              <FormErrors errors={editForm.errors} fields={['name', 'type', 'careLevel', 'ownershipType', 'status']} />
              <div className="platform-admin__form-grid">
                <TextInput
                  id="facility-name"
                  labelText="Facility name"
                  value={editForm.data.name}
                  onChange={(event) => editForm.setData('name', event.target.value)}
                  invalid={Boolean(editForm.errors.name)}
                  invalidText={editForm.errors.name}
                  required
                />
                <TextInput id="facility-code" labelText="Facility code" value={facility.code} readOnly />
                <Select
                  id="facility-type"
                  labelText="Facility type"
                  value={editForm.data.type}
                  onChange={(event) => editForm.setData('type', event.target.value)}
                >
                  {facilityTypes.map((item) => <SelectItem key={item.code} value={item.code} text={item.name} />)}
                </Select>
                <Select
                  id="facility-care-level"
                  labelText="Care level"
                  value={editForm.data.careLevel}
                  onChange={(event) => editForm.setData('careLevel', event.target.value)}
                >
                  {careLevels.map((item) => <SelectItem key={item.code} value={item.code} text={item.name} />)}
                </Select>
                <Select
                  id="facility-ownership"
                  labelText="Ownership type"
                  value={editForm.data.ownershipType}
                  onChange={(event) => editForm.setData('ownershipType', event.target.value)}
                >
                  {ownershipTypes.map((item) => <SelectItem key={item.code} value={item.code} text={item.name} />)}
                </Select>
                <Select
                  id="facility-status"
                  labelText="Operational status"
                  value={editForm.data.status}
                  onChange={(event) => editForm.setData('status', event.target.value)}
                >
                  <SelectItem value="ACTIVE" text="Active" />
                  <SelectItem value="SUSPENDED" text="Suspended" />
                  <SelectItem value="CLOSED" text="Closed" />
                </Select>
              </div>
              <div className="platform-admin__form-actions">
                <Button size="lg" renderIcon={Checkmark} type="submit" disabled={editForm.processing}>Save facility changes</Button>
              </div>
            </Form>
          </Tile>
          {facility.status !== 'ACTIVE' ? (
            <InlineNotification
              kind="warning"
              title="Facility is not active"
              subtitle="Staff cannot select this facility as an active workspace until it is reactivated."
              lowContrast
              hideCloseButton
            />
          ) : null}
          </section>
          </TabPanel>

          <TabPanel>
          <section className="platform-admin__section" aria-labelledby="facility-admins-heading">
          <header className="platform-admin__section-heading">
            <div>
              <p className="platform-admin__eyebrow">Governed access</p>
              <h2 id="facility-admins-heading">Facility Administrators</h2>
            </div>
          </header>
          <p className="platform-admin__section-note">
            Facility Admin invitations are managed by a Tenant Admin in the Admin workspace.
            The Platform Super Admin can{' '}
            <Link href={routes.platformAdmin.tenant(facility.tenantId)}>invite the first Tenant Admin</Link>.
          </p>
          {facility.administrators.length === 0 ? (
            <EmptyState
              title="No active Facility Admin"
              description="The facility has no active administrator assignments. Facility Admin invitations are issued by a Tenant Admin."
            />
          ) : (
            <div className="platform-admin__admin-list">
              {facility.administrators.map((administrator) => (
                <Tile key={administrator.assignmentId} className="platform-admin__admin-card">
                  <div>
                    <h3>{administrator.name}</h3>
                    <p>{administrator.email}</p>
                    <Tag type={administrator.accountStatus === 'ACTIVE' ? 'green' : 'red'}>
                      {administrator.accountStatus === 'HR_BLOCKED'
                        ? 'Access blocked by HR status'
                        : administrator.accountStatus === 'ACTIVE'
                          ? 'Account active'
                          : `Account ${administrator.accountStatus.toLowerCase()}`}
                    </Tag>
                  </div>
                  <Form noValidate onSubmit={(event) => requestRevocation(event, administrator.assignmentId)}>
                    {revocationErrors[administrator.assignmentId] ? (
                      <InlineNotification
                        kind="error"
                        lowContrast
                        title="Revocation could not be submitted"
                        subtitle={revocationErrors[administrator.assignmentId]}
                      />
                    ) : null}
                    <TextArea
                      id={`revoke-reason-${administrator.assignmentId}`}
                      labelText={`Reason to revoke ${administrator.name}`}
                      value={revocationReasons[administrator.assignmentId] ?? ''}
                      onChange={(event) => {
                        setRevocationReasons((current) => ({
                          ...current,
                          [administrator.assignmentId]: event.target.value,
                        }));
                      }}
                      rows={2}
                      maxLength={500}
                      required
                    />
                    <Checkbox
                      id={`confirm-revoke-${administrator.assignmentId}`}
                      labelText="I confirm this request; if no other authorized administrator can approve for this facility, it will take effect immediately for recovery."
                      checked={Boolean(confirmedRevocations[administrator.assignmentId])}
                      onChange={(_, { checked }) => setConfirmedRevocations((current) => ({
                        ...current,
                        [administrator.assignmentId]: checked,
                      }))}
                    />
                    <Button size="lg" renderIcon={UserMinus}
                      type="submit"
                      kind="danger"
                      disabled={!confirmedRevocations[administrator.assignmentId]
                        || (revocationReasons[administrator.assignmentId] ?? '').trim().length < 8
                        || revokingAssignmentId !== null}
                    >
                      Request Facility Admin revocation
                    </Button>
                  </Form>
                </Tile>
              ))}
            </div>
          )}
          {facility.pendingRevocations.map((request) => (
            <InlineNotification
              key={request.id}
              kind="info"
              title={`Revocation awaiting approval: ${request.name}`}
              subtitle={request.email}
              lowContrast
              hideCloseButton
            />
          ))}
          {facility.pendingInvitations.map((invitation) => (
            <InlineNotification
              key={invitation.email}
              kind={invitation.expired ? 'warning' : 'info'}
              title={`${invitation.expired ? 'Setup invitation expired' : 'Setup invitation pending'}: ${invitation.name}`}
              subtitle={invitation.expired
                ? `${invitation.email} · enter the same email below to rotate the invitation`
                : `${invitation.email} · expires ${new Date(invitation.expiresAt).toLocaleString()}`}
              lowContrast
              hideCloseButton
            />
          ))}
          {facility.pendingPromotions.map((request) => (
            <InlineNotification
              key={request.id}
              kind="info"
              title={`Approval pending: ${request.name}`}
              subtitle={`${request.email} · ${request.setupComplete ? 'Account is set up; awaiting administrator approval.' : 'Complete setup before approval can be granted.'}`}
              lowContrast
              hideCloseButton
            />
          ))}
        </section>
          </TabPanel>
          </TabPanels>
        </Tabs>
      </main>
    </AppLayout>
  );
}

function withCurrent(options: FacilityReferences['facilityTypes'], current: string): FacilityReferences['facilityTypes'] {
  return options.some((option) => option.code === current)
    ? options
    : [...options, { code: current, name: `${current} (current)` }];
}