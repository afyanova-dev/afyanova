import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { StatisticsCards } from '@/components/ui';
import { ArrowRight, Calendar, Document, Medication, Money, Time, UserAvatar, UserMultiple, UserFollow, UserSettings } from '@/icons';
import type { DashboardWidget } from '@/features/dashboard/types/dashboard';

/** Icon per count widget id, mirroring the workspace glyph language. */
function widgetIcon(id: string): ReactNode {
  switch (id) {
    case 'scheduled-today':
      return <Calendar />;
    case 'arrived-today':
      return <Time />;
    case 'active-visits':
      return <UserAvatar />;
    case 'hr-assigned-staff':
      return <UserMultiple />;
    case 'hr-pending-assignment-requests':
      return <UserFollow />;
    case 'hr-pending-access-requests':
      return <UserSettings />;
    case 'hr-expired-invitations':
      return <Document />;
    case 'triage-queue':
      return <UserMultiple />;
    case 'consultation-queue':
      return <UserFollow />;
    case 'prescriptions-to-review':
    case 'pharmacy-orders-to-process':
      return <Medication />;
    case 'my-cash-session':
      return <Money />;
    case 'pending-billing-events':
      return <Document />;
    default:
      return null;
  }
}

/** Colored top-accent border per metric family — modern EMR stat-card language. */
function widgetBorderTone(id: string): 'positive' | 'caution' | 'negative' | 'information' | undefined {
  switch (id) {
    case 'scheduled-today':
      return 'information';   // planned — brand blue
    case 'arrived-today':
      return 'positive';      // patients arrived — green
    case 'active-visits':
      return 'caution';       // active workload — yellow
    case 'triage-queue':
    case 'consultation-queue':
    case 'hr-pending-assignment-requests':
    case 'hr-pending-access-requests':
    case 'hr-expired-invitations':
    case 'prescriptions-to-review':
    case 'pharmacy-orders-to-process':
      return 'caution';
    case 'my-cash-session':
    case 'pending-billing-events':
      return 'information';
    default:
      return undefined;
  }
}

function widgetDetail(id: string, count: number): string | undefined {
  if (id === 'scheduled-today') {
    return count === 0 ? 'No appointments scheduled' : 'Appointments scheduled today';
  }
  if (id === 'arrived-today') {
    return count === 0 ? 'No patients checked in' : 'Patients checked in today';
  }
  if (id === 'active-visits') {
    return count === 0 ? 'No visits in progress' : 'Visits currently in progress';
  }
  if (id === 'hr-pending-assignment-requests') {
    return count === 0 ? 'No placements awaiting review' : 'Placements awaiting review';
  }
  if (id === 'hr-pending-access-requests') {
    return count === 0 ? 'No role approvals awaiting review' : 'Role approvals awaiting review';
  }
  if (id === 'hr-expired-invitations') {
    return count === 0 ? 'No expired invitations' : 'Invitations needing follow-up';
  }

  return undefined;
}

/**
 * Today's statistics link to their role-accessible workspace. Renders nothing
 * when every widget is restricted.
 */
export function DashboardWidgets({ widgets }: { widgets: DashboardWidget[] }): ReactNode {
  if (widgets.length === 0) return null;

  if (widgets[0]?.section === 'work') {
    return (
      <ul className="dashboard__queue-list" aria-label="Current work queues">
        {widgets.map((widget) => (
          <li key={widget.id}>
            <Link
              className={`dashboard__queue dashboard__queue--${widgetBorderTone(widget.id) ?? 'information'}`}
              href={widget.href}
              aria-label={`Open ${widget.title}`}
            >
              <span className="dashboard__queue-icon" aria-hidden="true">
                {widgetIcon(widget.id)}
              </span>
              <span className="dashboard__queue-main">
                <span className="dashboard__queue-title">{widget.title}</span>
                <span className="dashboard__queue-detail">
                  {widget.count === 0 ? 'No items are waiting in this queue.' : `${widget.count} ${widget.count === 1 ? 'item' : 'items'} waiting`}
                </span>
              </span>
              <strong className="dashboard__queue-count">{widget.count}</strong>
              <span className="dashboard__queue-action">
                View queue <ArrowRight size={16} aria-hidden="true" />
              </span>
            </Link>
          </li>
        ))}
      </ul>
    );
  }

  return (
    <StatisticsCards
      label="Today's statistics"
      className="dashboard__stats"
      cards={widgets.map((widget) => ({
        id: widget.id,
        label: widget.title,
        value: widget.count,
        detail: widgetDetail(widget.id, widget.count),
        icon: widgetIcon(widget.id) ?? undefined,
        borderTone: widgetBorderTone(widget.id),
        href: widget.href,
        ariaLabel: `Open ${widget.title}`,
        actionLabel: 'View',
      }))}
    />
  );
}
