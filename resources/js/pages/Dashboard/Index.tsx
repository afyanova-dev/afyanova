import { InlineNotification, Tag } from '@carbon/react';
import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { AppLayout } from '@/layouts/AppLayout';
import { PageHeader, OverviewLinkList, StatisticsCards, type OverviewLinkItem } from '@/components/ui';
import { DashboardQuickActions, DashboardWidgets, type DashboardPageProps } from '@/features/dashboard';
import type { SharedProps } from '@/types';
import { UserFollow, UserMultiple, UserSettings, Document } from '@/icons';

/**
 * Post-login landing (rendered by DashboardController for every
 * authenticated user). Shows role-specific work queues and quick actions;
 * navigation to other workspaces stays in the global app menu.
 */
export default function Dashboard({
  quickActions,
  widgets,
  unavailable,
  pendingApprovals,
}: DashboardPageProps): ReactNode {
  const { auth } = usePage<SharedProps>().props;
  const description = auth.contextMode === 'general'
    ? 'General context: tenant-level work is shared. Select a facility for facility-specific operations.'
    : auth.contextMode === 'unresolved'
      ? 'Select an assigned facility to open facility-specific workspaces.'
      : 'Your role-specific overview and current work.';
  const approvalItems: OverviewLinkItem[] = pendingApprovals.map((approval, index) => ({
    id: `pending-${approval.roleLabel}-${approval.facilityName ?? 'organization'}-${index}`,
    title: approval.roleLabel,
    description: approval.facilityName ?? 'Organization-wide access',
    status: 'Under review',
    disabled: true,
  }));
  const workforceWidgets = widgets.filter((widget) => widget.id.startsWith('hr-'));
  const todayWidgets = widgets.filter((widget) => widget.section !== 'work');
  const workWidgets = widgets.filter((widget) => widget.section === 'work' && !widget.id.startsWith('hr-'));
  const workforceIcons: Record<string, ReactNode> = {
    'hr-assigned-staff': <UserMultiple />,
    'hr-pending-assignment-requests': <UserFollow />,
    'hr-pending-access-requests': <UserSettings />,
    'hr-expired-invitations': <Document />,
  };

  return (
    <AppLayout>
      <PageHeader
        title="Dashboard"
        description={description}
        actions={quickActions.length > 0 ? <DashboardQuickActions actions={quickActions} /> : undefined}
      />
      <div className="afy-page__content">
        {unavailable.length > 0 ? (
          <InlineNotification
            kind="error"
            lowContrast
            hideCloseButton
            title="Some dashboard summaries could not be loaded"
            subtitle="Refresh the page to try again. Other available dashboard information remains visible."
          />
        ) : null}
        {workforceWidgets.length > 0 ? (
          <section aria-labelledby="dashboard-workforce" className="afy-section">
            <div className="afy-section__heading">
              <h2 id="dashboard-workforce" className="afy-section__title">Workforce overview</h2>
            </div>
            <StatisticsCards
              label="Workforce overview"
              className="dashboard__stats"
              cards={workforceWidgets.map((widget) => ({
                id: widget.id,
                label: widget.title,
                value: widget.count,
                icon: workforceIcons[widget.id],
                borderTone: widget.id === 'hr-assigned-staff'
                  ? 'information'
                  : widget.count > 0 ? 'caution' : 'positive',
                href: widget.href,
                ariaLabel: `Open ${widget.title}`,
                actionLabel: widget.id === 'hr-assigned-staff' ? 'View directory' : 'Review',
              }))}
            />
          </section>
        ) : null}
        {todayWidgets.filter((widget) => !widget.id.startsWith('hr-')).length > 0 ? (
          <section aria-labelledby="dashboard-today" className="afy-section">
            <div className="afy-section__heading">
              <h2 id="dashboard-today" className="afy-section__title">Today</h2>
            </div>
            <DashboardWidgets widgets={todayWidgets.filter((widget) => !widget.id.startsWith('hr-'))} />
          </section>
        ) : null}
        {workWidgets.length > 0 ? (
          <section aria-labelledby="dashboard-work" className="afy-section">
            <div className="afy-section__heading">
              <h2 id="dashboard-work" className="afy-section__title">Work queues</h2>
            </div>
            <DashboardWidgets widgets={workWidgets} />
          </section>
        ) : null}
        {approvalItems.length > 0 ? (
          <OverviewLinkList
            title="Role approvals"
            eyebrow="Your requests"
            emptyMessage=""
            items={approvalItems}
            action={(
              <Tag type="blue">
                {pendingApprovals.length} {pendingApprovals.length === 1 ? 'request' : 'requests'}
              </Tag>
            )}
          />
        ) : null}
      </div>
    </AppLayout>
  );
}
