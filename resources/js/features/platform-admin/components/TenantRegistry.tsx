import { Fragment, type ReactNode, useState } from 'react';
import { Link } from '@inertiajs/react';
import { Button, TableToolbarSearch, Tag } from '@carbon/react';
import { routes } from '@/app/routes';
import { CarbonDataTable, EmptyState, StatisticsCards } from '@/components/ui';
import type { TenantSummary } from '../types/platform-admin';
import { STAGE_LABELS, STAGE_ORDER, tenantAttentionReason, tenantStage } from '../lifecycle';
import { Add, Building, Checkmark, Hospital, Pending } from '@/icons';

const REGISTRY_HEADERS = [
  { key: 'name', header: 'Tenant' },
  { key: 'status', header: 'Status' },
  { key: 'organizations', header: 'Organizations' },
  { key: 'facilities', header: 'Facilities' },
  { key: 'administrator', header: 'Tenant admin' },
  { key: 'onboarding', header: 'Onboarding' },
  { key: 'attention', header: 'Attention' },
  { key: 'actions', header: 'Actions' },
];

export function TenantRegistry({
  tenants,
}: {
  tenants: TenantSummary[];
}): ReactNode {
  const [query, setQuery] = useState('');
  const needle = query.trim().toLowerCase();

  const needsAttention = tenants.filter((tenant) => tenantAttentionReason(tenant) !== null).length;
  const facilities = tenants.reduce((count, tenant) => count + tenant.facilityCount, 0);

  const visibleTenants = tenants.filter((tenant) =>
    needle === '' || tenant.name.toLowerCase().includes(needle) || tenant.slug.toLowerCase().includes(needle),
  );
  const tenantsById = new Map(visibleTenants.map((tenant) => [tenant.id, tenant]));

  const rows = visibleTenants.map((tenant) => {
    const stage = tenantStage(tenant);
    const reason = tenantAttentionReason(tenant);
    return {
      id: tenant.id,
      name: `${tenant.name} ${tenant.slug}`,
      slug: tenant.slug,
      status: tenant.status,
      organizations: tenant.organizationCount,
      facilities: tenant.facilityCount,
      administrator: tenant.pendingAdminEmail ?? (tenant.hasActiveAdmin ? 'active' : 'none'),
      onboarding: String(STAGE_ORDER.indexOf(stage)),
      attention: reason ? 'requires' : 'clear',
      actions: 'Manage access',
    };
  });

  return (
    <section aria-label="Tenant registry" className="platform-admin--registry">

        <StatisticsCards
          label="Tenant registry summary"
          cards={[
            { id: 'registry-total', label: 'Tenants', value: tenants.length, icon: <Building /> },
            {
              id: 'registry-operational',
              label: 'Operational',
              value: tenants.filter((tenant) => tenantStage(tenant) === 'operational').length,
              icon: <Checkmark />,
              borderTone: 'positive',
            },
            { id: 'registry-facilities', label: 'Facilities', value: facilities, icon: <Hospital /> },
            {
              id: 'registry-attention',
              label: 'Requires attention',
              value: needsAttention,
              icon: <Pending />,
              borderTone: needsAttention > 0 ? 'caution' : 'positive',
            },
          ]}
        />
        {tenants.length === 0 ? (
          <EmptyState
            title="No tenants yet"
            description="Create a tenant and its first facility to start governed onboarding."
            action={<Button size="lg" renderIcon={Add} as={Link} href={routes.platformAdmin.createTenant}>Create first tenant</Button>}
          />
        ) : (
          <section aria-label="Registered tenants" className="platform-admin__section">
            <CarbonDataTable
              rows={rows}
              headers={REGISTRY_HEADERS}
              isSortable
              title={`Registered tenants (${tenants.length})`}
              ariaLabel="Tenant registry"
              emptyState={(
                <EmptyState
                  title="No matching tenants"
                  description="No tenant name or identifier matches the current search."
                />
              )}
              getExpandRowLabel={(row) => tenantsById.get(row.id)?.name ?? 'tenant'}
              renderExpandedRow={(row) => {
                const tenant = tenantsById.get(row.id);
                if (!tenant) return null;

                const attention = tenantAttentionReason(tenant);
                const currentStage = STAGE_ORDER.indexOf(tenantStage(tenant));

                return (
                  <div className="platform-admin__tenant-expansion">
                    <div className="platform-admin__expansion-grid">
                      <section aria-labelledby={`tenant-attention-${tenant.id}`}>
                        <h3 id={`tenant-attention-${tenant.id}`} className="platform-admin__expansion-title">
                          Attention
                        </h3>
                        {attention ? (
                          <div className="platform-admin__expansion-flag">
                            <p>{attention.label}</p>
                            <Link
                              href={routes.platformAdmin.tenant(tenant.id)}
                              className="platform-admin__expansion-action"
                            >
                              {attention.action}
                            </Link>
                          </div>
                        ) : (
                          <p className="platform-admin__expansion-clear">
                            <Checkmark aria-hidden="true" />
                            All requirements met — tenant is operational.
                          </p>
                        )}
                      </section>
                      <section aria-labelledby={`tenant-onboarding-${tenant.id}`}>
                        <h3 id={`tenant-onboarding-${tenant.id}`} className="platform-admin__expansion-title">
                          Onboarding
                        </h3>
                        <ol className="platform-admin__expansion-steps" aria-label="Onboarding progress">
                          {STAGE_ORDER.map((stage, index) => (
                            <li
                              key={stage}
                              data-state={index < currentStage ? 'done' : index === currentStage ? 'current' : 'todo'}
                              aria-current={index === currentStage ? 'step' : undefined}
                            >
                              {STAGE_LABELS[stage]}
                            </li>
                          ))}
                        </ol>
                      </section>
                    </div>
                    <p className="platform-admin__expansion-structure">
                      <span>Structure</span>
                      {tenant.organizations.length > 0
                        ? tenant.organizations.map((organization) => organization.name).join(', ')
                        : <span className="platform-admin__expansion-missing">No organization yet</span>}
                      <span aria-hidden="true">·</span>
                      {tenant.facilities.length > 0
                        ? tenant.facilities.map((facility, index) => (
                          <Fragment key={facility.id}>
                            {index > 0 ? ', ' : null}
                            <Link
                              href={routes.platformAdmin.facility(tenant.id, facility.id)}
                              className="platform-admin__facility-link"
                            >
                              {facility.name}
                            </Link>
                          </Fragment>
                        ))
                        : <span className="platform-admin__expansion-missing">No facilities yet</span>}
                    </p>
                  </div>
                );
              }}
              renderCell={(cell, row) => {
                if (cell.info.header === 'name') {
                  const tenant = visibleTenants.find((entry) => entry.id === row.id);
                  return tenant ? (
                    <div>
                      <Link href={routes.platformAdmin.tenant(tenant.id)} className="platform-admin__facility-link">
                        {tenant.name}
                      </Link>
                      <p className="platform-admin__tenant-slug">{tenant.slug}</p>
                    </div>
                  ) : cell.value;
                }
                if (cell.info.header === 'status') {
                  return <Tag type={cell.value === 'ACTIVE' ? 'green' : 'red'}>{cell.value}</Tag>;
                }
                if (cell.info.header === 'administrator') {
                  const tenant = visibleTenants.find((entry) => entry.id === row.id);
                  if (tenant?.pendingAdminEmail) {
                    return (
                      <div>
                        <Tag type={tenant.pendingAdminInvitationExpired ? 'red' : 'blue'}>
                          {tenant.pendingAdminInvitationExpired ? 'Invitation expired' : 'Invitation pending'}
                        </Tag>
                        <p className="platform-admin__tenant-slug">{tenant.pendingAdminEmail}</p>
                        {tenant.pendingAdminExpiresAt ? (
                          <p className="platform-admin__tenant-slug">
                            Expires {new Date(tenant.pendingAdminExpiresAt).toLocaleString()}
                          </p>
                        ) : null}
                      </div>
                    );
                  }
                  return cell.value === 'active'
                    ? <Tag type="green">Active</Tag>
                    : <Tag type="cool-gray">Not invited</Tag>;
                }
                if (cell.info.header === 'onboarding') {
                  const tenant = visibleTenants.find((entry) => entry.id === row.id);
                  if (tenant && tenantStage(tenant) === 'operational') {
                    return <Tag type="green">{STAGE_LABELS.operational}</Tag>;
                  }
                  return <Tag type="cool-gray">{STAGE_LABELS[STAGE_ORDER[Number(cell.value)] ?? 'registered']}</Tag>;
                }
                if (cell.info.header === 'attention') {
                  return cell.value === 'requires'
                    ? <Tag type="red">Review</Tag>
                    : <Tag type="green">Clear</Tag>;
                }
                if (cell.info.header === 'actions') {
                  return (
                    <Button as={Link} href={routes.platformAdmin.tenant(row.id)} kind="tertiary" size="sm">
                      Manage access
                    </Button>
                  );
                }
                return cell.value;
              }}
              toolbar={(onInputChange) => (
                <TableToolbarSearch
                  persistent
                  onChange={(event) => {
                    setQuery((event as unknown as { target: { value: string } }).target.value);
                    onInputChange?.(event);
                  }}
                  placeholder="Search tenant name or identifier"
                  aria-label="Search tenants"
                />
              )}
            />
          </section>
        )}
    </section>
  );
}
