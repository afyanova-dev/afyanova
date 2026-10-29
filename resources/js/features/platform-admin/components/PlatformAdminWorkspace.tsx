import { type ReactNode, useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { Button } from '@carbon/react';
import { routes } from '@/app/routes';
import { OverviewLinkList, PageHeader, StatisticsCards } from '@/components/ui';
import { AppLayout } from '@/layouts/AppLayout/AppLayout';
import type { FacilityReferences, TenantDetails, TenantSummary } from '../types/platform-admin';
import { tenantAttentionReason, tenantStage } from '../lifecycle';
import { Add, Building, Checkmark, Document, Hospital, Pending, UserMultiple, UserSettings } from '@/icons';
import { CreateTenantWorkflow } from './CreateTenantWorkflow';
import { TenantManagementPanel } from './TenantManagementPanel';
import { TenantRegistry } from './TenantRegistry';

export type PlatformAdminSection = 'overview' | 'tenants' | 'create';

/**
 * Platform administration workspace. The Dashboard is the super-admin
 * landing page; the tenant registry is its own destination in the app menu.
 * Tenant creation opens in a right-side dock from either page, and the
 * create route still deep-links straight into that dock.
 */
export function PlatformAdminWorkspace({
  tenants,
  facilityReferences,
  section,
  managedTenant = null,
}: {
  tenants: TenantSummary[];
  facilityReferences: FacilityReferences;
  section: PlatformAdminSection;
  /** Present on the tenant show route: opens the management dock over the registry. */
  managedTenant?: TenantDetails | null;
}): ReactNode {
  const [createOpen, setCreateOpen] = useState(section === 'create');
  const showingRegistry = section === 'tenants' || section === 'create';

  // The create route still deep-links: arriving on it opens the dock.
  useEffect(() => {
    setCreateOpen(section === 'create');
  }, [section]);

  function closeCreate(): void {
    setCreateOpen(false);
    if (section === 'create') {
      router.visit(routes.platformAdmin.tenants, { preserveScroll: true });
    }
  }

  function closeManage(): void {
    router.visit(routes.platformAdmin.tenants, { preserveScroll: true });
  }

  const attentionTenants = tenants
    .map((tenant) => ({ tenant, reason: tenantAttentionReason(tenant) }))
    .filter((entry) => entry.reason !== null);

  const operationalTenants = tenants.filter((tenant) => tenantStage(tenant) === 'operational').length;
  const facilities = tenants.reduce((count, tenant) => count + tenant.facilityCount, 0);

  const createAction = (
    <Button size="lg" renderIcon={Add} onClick={() => setCreateOpen(true)}>
      Create tenant
    </Button>
  );

  return (
    <AppLayout>
      <main className="afyanova-page platform-admin">
        {showingRegistry ? (
          <>
            <PageHeader
              title="Tenant registry"
              description="Browse every tenant, its organizations, and facilities."
              badge={<span className="afy-eyebrow">Platform control plane</span>}
              actions={createAction}
            />
            <div className="afy-page__content">
              <TenantRegistry tenants={tenants} />
            </div>
          </>
        ) : (
          <>
            <PageHeader
              title="Dashboard"
              description="Platform tenant lifecycle, onboarding, and registry."
              actions={createAction}
            />
            <div className="afy-page__content">
              <StatisticsCards
                label="Platform summary"
                cards={[
                  {
                    id: 'tenants-total',
                    label: 'Tenants',
                    value: tenants.length,
                    detail: 'Registered on the platform',
                    icon: <Building />,
                  },
                  {
                    id: 'tenants-operational',
                    label: 'Operational tenants',
                    value: operationalTenants,
                    detail: 'Active with a tenant administrator',
                    icon: <Checkmark />,
                    borderTone: operationalTenants > 0 ? 'positive' : undefined,
                  },
                  {
                    id: 'facilities-total',
                    label: 'Facilities',
                    value: facilities,
                    detail: 'Across all tenants',
                    icon: <Hospital />,
                  },
                  {
                    id: 'tenants-attention',
                    label: 'Requires attention',
                    value: attentionTenants.length,
                    detail: 'Onboarding gaps to review',
                    icon: <Pending />,
                    borderTone: attentionTenants.length > 0 ? 'caution' : 'positive',
                  },
                ]}
              />

              <OverviewLinkList
                title="Workspaces"
                emptyMessage="No platform workspaces are available."
                items={[
                  {
                    id: 'platform-tenants',
                    href: routes.platformAdmin.tenants,
                    title: 'Tenant registry',
                    renderIcon: UserMultiple,
                  },
                  {
                    id: 'platform-audit',
                    href: routes.platformAdmin.audit,
                    title: 'Platform audit',
                    renderIcon: Document,
                  },
                  {
                    id: 'platform-super-admins',
                    href: routes.platformAdmin.superAdmins,
                    title: 'Super Admin access',
                    renderIcon: UserSettings,
                  },
                ]}
              />
            </div>
          </>
        )}

        {managedTenant ? (
          <TenantManagementPanel tenant={managedTenant} onClose={closeManage} />
        ) : createOpen ? (
          <CreateTenantWorkflow facilityReferences={facilityReferences} onClose={closeCreate} />
        ) : null}
      </main>
    </AppLayout>
  );
}
