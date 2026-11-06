import { Button, InlineNotification, Tag } from '@carbon/react';
import { router } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { PageHeader, StatisticsCards, CarbonDataTable, EmptyState } from '@/components/ui';
import { routes } from '@/app/routes';
import { AppLayout } from '@/layouts/AppLayout/AppLayout';
import { ArrowRight, Building, Document, Money, UserSettings } from '@/icons';

export interface AdminIndexProps {
  canManageOrganization: boolean;
  canReviewRoleRequests: boolean;
  canViewRoleCatalog: boolean;
  canReviewAudit: boolean;
  canManageAssignments: boolean;
  canManageProcurementLimits: boolean;
  stats?: {
    facilities: {
      total: number;
      active: number;
    } | null;
    pendingApprovalsCount: number;
    auditEventsCount: number;
    manageableFacilitiesCount: number;
  };
  pendingApprovals?: {
    id: string;
    roleKey: string;
    roleLabel: string;
    action: string;
    accountEmail: string;
    facilityName: string;
    createdAt: string;
  }[];
}

export default function AdminIndex({
  canManageOrganization,
  canReviewRoleRequests,
  canReviewAudit,
  canManageProcurementLimits,
  stats,
  pendingApprovals = [],
}: AdminIndexProps): ReactNode {
  const statCards = [
    ...(canManageOrganization && stats?.facilities ? [{
      id: 'admin-stat-facilities',
      label: 'Facilities',
      value: `${stats.facilities.active} active / ${stats.facilities.total} total`,
      icon: <Building />,
      href: routes.workspaces.adminOrganization,
      borderTone: 'information' as const,
      ariaLabel: 'Open organization facilities management',
      actionLabel: 'Manage facilities',
    }] : []),
    ...(canReviewRoleRequests ? [{
      id: 'admin-stat-approvals',
      label: 'Pending role approvals',
      value: stats?.pendingApprovalsCount ?? 0,
      icon: <UserSettings />,
      href: routes.workspaces.adminRolePromotions,
      borderTone: (stats?.pendingApprovalsCount ?? 0) > 0 ? 'caution' as const : 'positive' as const,
      ariaLabel: 'Open role approvals',
      actionLabel: 'Review requests',
    }] : []),
    ...(canReviewAudit ? [{
      id: 'admin-stat-audit',
      label: 'Audit events (7 days)',
      value: stats?.auditEventsCount ?? 0,
      icon: <Document />,
      href: routes.workspaces.adminAudit,
      borderTone: 'information' as const,
      ariaLabel: 'Open audit log',
      actionLabel: 'View audit log',
    }] : []),
    ...(canManageProcurementLimits ? [{
      id: 'admin-stat-limits',
      label: 'Procurement limits',
      value: `${stats?.manageableFacilitiesCount ?? 0} facilities`,
      icon: <Money />,
      href: routes.workspaces.adminProcurementLimits,
      borderTone: 'information' as const,
      ariaLabel: 'Open procurement approval limits',
      actionLabel: 'Configure limits',
    }] : []),
  ];

  return (
    <AppLayout>
      <PageHeader
        title="Admin workspace"
        description="Administrative command center: system health, access controls, and pending approvals."
        actions={canReviewRoleRequests && pendingApprovals.length > 0 ? (
          <Button
            size="lg"
            renderIcon={ArrowRight}
            onClick={() => router.visit(routes.workspaces.adminRolePromotions)}
          >
            Review role requests
          </Button>
        ) : undefined}
      />
      <div className="afy-page__content">
        {statCards.length > 0 ? (
          <section aria-labelledby="admin-kpi-heading" className="afy-section">
            <div className="afy-section__heading">
              <h2 id="admin-kpi-heading" className="afy-section__title">Administrative overview</h2>
            </div>
            <StatisticsCards
              label="Administrative overview"
              className="admin-workspace__stats"
              cards={statCards}
            />
          </section>
        ) : null}

        {canReviewRoleRequests ? (
          <section aria-labelledby="admin-pending-approvals-heading" className="afy-section">
            <div className="afy-section__heading">
              <h2 id="admin-pending-approvals-heading" className="afy-section__title">
                Role approvals needing decision
              </h2>
            </div>
            {pendingApprovals.length > 0 ? (
              <CarbonDataTable
                title="Pending administrative requests"
                ariaLabel="Pending administrative role approvals"
                emptyState={<p>No pending role approvals</p>}
                headers={[
                  { key: 'accountEmail', header: 'Staff account' },
                  { key: 'roleLabel', header: 'Requested role' },
                  { key: 'facilityName', header: 'Facility' },
                  { key: 'action', header: 'Action' },
                  { key: 'createdAt', header: 'Date requested' },
                  { key: 'actions', header: '' },
                ]}
                rows={pendingApprovals.map((req) => ({
                  id: req.id,
                  accountEmail: req.accountEmail,
                  roleLabel: req.roleLabel,
                  facilityName: req.facilityName,
                  action: <Tag type={req.action === 'REVOKE' ? 'red' : 'blue'}>{req.action}</Tag>,
                  createdAt: new Date(req.createdAt).toLocaleDateString(),
                  actions: (
                    <Button
                      size="sm"
                      kind="ghost"
                      renderIcon={ArrowRight}
                      onClick={() => router.visit(routes.workspaces.adminRolePromotions)}
                    >
                      Review
                    </Button>
                  ),
                }))}
              />
            ) : (
              <InlineNotification
                kind="success"
                lowContrast
                hideCloseButton
                title="All role promotion requests have been reviewed"
                subtitle="There are currently no administrative access requests awaiting approval."
              />
            )}
          </section>
        ) : null}

        {statCards.length === 0 ? (
          <EmptyState
            title="No administrative areas in scope"
            description="Your current access does not include any Admin management pages. Contact your Tenant Admin."
          />
        ) : null}
      </div>
    </AppLayout>
  );
}
