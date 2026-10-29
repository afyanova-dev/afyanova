import type { ComponentType, ReactNode } from 'react';
import { PageHeader, OverviewLinkList } from '@/components/ui';
import { routes } from '@/app/routes';
import { AppLayout } from '@/layouts/AppLayout/AppLayout';
import { Building, Checkmark, Document, Money, UserSettings } from '@/icons';

interface AdminIndexProps {
  canManageOrganization: boolean;
  canReviewRoleRequests: boolean;
  canReviewAudit: boolean;
  canManageAssignments: boolean;
  canManageProcurementLimits: boolean;
}

interface AdministrationArea {
  id: string;
  title: string;
  href: string;
  permission: 'organization' | 'procurementLimits' | 'identity' | 'audit' | 'assignments';
  icon: ComponentType;
}

const administrationAreas: AdministrationArea[] = [
  {
    id: 'organization',
    title: 'Organization & facilities',
    href: routes.workspaces.adminOrganization,
    permission: 'organization',
    icon: Building,
  },
  {
    id: 'procurement-limits',
    title: 'Procurement limits',
    href: routes.workspaces.adminProcurementLimits,
    permission: 'procurementLimits',
    icon: Money,
  },
  {
    id: 'identity',
    title: 'Identity & access',
    href: routes.workspaces.adminRolePromotions,
    permission: 'identity',
    icon: UserSettings,
  },
  {
    id: 'audit',
    title: 'Audit log',
    href: routes.workspaces.adminAudit,
    permission: 'audit',
    icon: Document,
  },
  {
    id: 'staff-assignments',
    title: 'Staff assignment approvals',
    href: routes.workspaces.hrStaff,
    permission: 'assignments',
    icon: Checkmark,
  },
];

export default function AdminIndex({
  canManageOrganization,
  canReviewRoleRequests,
  canReviewAudit,
  canManageAssignments,
  canManageProcurementLimits,
}: AdminIndexProps): ReactNode {
  const canAccessArea = {
    organization: canManageOrganization,
    procurementLimits: canManageProcurementLimits,
    identity: canReviewRoleRequests,
    audit: canReviewAudit,
    assignments: canManageAssignments,
  };
  const availableAreas = administrationAreas.filter((area) => canAccessArea[area.permission]);

  return (
    <AppLayout>
      <PageHeader
        title="Admin workspace"
        description="Manage your organization and administrative access."
      />
      <div className="afy-page__content">
        <OverviewLinkList
          title="Management areas"
          emptyMessage="Your current access does not include any Admin management pages. Contact your Tenant Admin."
          items={availableAreas.map((area) => ({
            id: area.id,
            href: area.href,
            title: area.title,
            renderIcon: area.icon,
          }))}
        />
      </div>
    </AppLayout>
  );
}
