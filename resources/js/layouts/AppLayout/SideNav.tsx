import { Link } from '@inertiajs/react';
import { SideNav, SideNavItems, SideNavMenuItem } from '@carbon/react';
import type { ReactNode } from 'react';
import {
  ArrowRight,
  ArrowsHorizontal,
  Building,
  Calendar,
  ChartLine,
  Dashboard,
  Document,
  Edit,
  Floorplan,
  ImageMedical,
  List,
  Medication,
  Microscope,
  Money,
  Notification,
  Pending,
  Pills,
  Report,
  SettingsAdjust,
  Task,
  Time,
  Timer,
  UserMultiple,
  UserProfile,
  UserSettings,
  WarningAlt,
} from '@/icons';
import type { NavigationItem, WorkspaceId } from './navigation';

const chartSectionIcons: Record<string, ReactNode> = {
  'chart-overview': <Task size={16} aria-hidden="true" />,
  'chart-allergies': <Notification size={16} aria-hidden="true" />,
  'chart-conditions': <Pending size={16} aria-hidden="true" />,
  'chart-procedures': <Edit size={16} aria-hidden="true" />,
  'chart-plans': <Report size={16} aria-hidden="true" />,
  'chart-vitals': <ChartLine size={16} aria-hidden="true" />,
  'chart-medications': <Pills size={16} aria-hidden="true" />,
  'chart-lab-results': <Microscope size={16} aria-hidden="true" />,
  'chart-imaging': <ImageMedical size={16} aria-hidden="true" />,
  'chart-appointments': <Calendar size={16} aria-hidden="true" />,
  'chart-visits': <Time size={16} aria-hidden="true" />,
  'chart-programs': <UserMultiple size={16} aria-hidden="true" />,
  'chart-referrals': <ArrowRight size={16} aria-hidden="true" />,
  'chart-billing-history': <Money size={16} aria-hidden="true" />,
};

const staffSectionIcons: Record<string, ReactNode> = {
  'staff-overview': <Task size={16} aria-hidden="true" />,
  'staff-personal': <UserProfile size={16} aria-hidden="true" />,
  'staff-employment': <Building size={16} aria-hidden="true" />,
  'staff-qualifications': <Document size={16} aria-hidden="true" />,
  'staff-access': <UserSettings size={16} aria-hidden="true" />,
};

const inventorySectionIcons: Record<string, ReactNode> = {
  'inventory-overview': <Dashboard size={16} aria-hidden="true" />,
  'inventory-stock': <Pills size={16} aria-hidden="true" />,
  'inventory-stock-on-hand': <List size={16} aria-hidden="true" />,
  'inventory-expiry': <Timer size={16} aria-hidden="true" />,
  'inventory-low-stock': <WarningAlt size={16} aria-hidden="true" />,
  'inventory-transfers': <ArrowsHorizontal size={16} aria-hidden="true" />,
  'inventory-counts': <Task size={16} aria-hidden="true" />,
  'inventory-adjustments': <SettingsAdjust size={16} aria-hidden="true" />,
  'inventory-reservations': <Pending size={16} aria-hidden="true" />,
  'inventory-catalogue': <Medication size={16} aria-hidden="true" />,
  'inventory-locations': <Floorplan size={16} aria-hidden="true" />,
  'inventory-receipts': <Task size={16} aria-hidden="true" />,
};

const procurementSectionIcons: Record<string, ReactNode> = {
  'procurement-requests': <Document size={16} aria-hidden="true" />,
  'procurement-orders': <Task size={16} aria-hidden="true" />,
  'procurement-suppliers': <Building size={16} aria-hidden="true" />,
};

interface AppSideNavProps {
  expanded: boolean;
  isDesktop: boolean;
  workspace: WorkspaceId | null;
  items: NavigationItem[];
  activeItemId?: string;
  onItemClick?: (item: NavigationItem, event: React.MouseEvent) => void;
  contextualContent?: ReactNode;
  onNavigate: () => void;
}

export function AppSideNav({
  expanded,
  isDesktop,
  workspace,
  items,
  activeItemId,
  onItemClick,
  contextualContent,
  onNavigate,
}: AppSideNavProps): ReactNode {
  if (!workspace || (items.length === 0 && !contextualContent)) return null;
  const isPatientChart = items.some((item) => item.id.startsWith('chart-'));
  // Staff profile, inventory, and procurement sections use the same
  // record-sidebar treatment as the patient chart (15rem width, right border)
  // — full-height contextual record nav.
  const isRecordNav = isPatientChart
    || items.some((item) => item.id.startsWith('staff-'))
    || items.some((item) => item.id.startsWith('inventory-'))
    || items.some((item) => item.id.startsWith('procurement-'));
  const sideNavClassName = [
    isRecordNav ? 'afyanova-chart-side-nav' : null,
  ].filter(Boolean).join(' ') || undefined;

  return (
    <SideNav
      aria-label="Workspace navigation"
      className={sideNavClassName}
      expanded={expanded}
      isFixedNav={isDesktop}
      isPersistent={isDesktop || expanded}
    >
      <div className={contextualContent ? 'afyanova-app-side-nav__content' : undefined}>
        {items.length > 0 ? (
          <SideNavItems>
            {items.map((item) => {
              const isActive = activeItemId ? item.id === activeItemId : item.active;
              return (
                <SideNavMenuItem
                  key={item.id}
                  href={item.href}
                  as={onItemClick ? 'a' : Link}
                  isActive={isActive}
                  onClick={(e: React.MouseEvent) => {
                    if (onItemClick && !e.metaKey && !e.ctrlKey && !e.shiftKey) {
                      e.preventDefault();
                      onItemClick(item, e);
                    }
                    onNavigate();
                  }}
                >
                  {isRecordNav ? (
                    <span className="afyanova-side-nav-item">
                      <span className="afyanova-side-nav-item__icon">
                        {chartSectionIcons[item.id]
                          ?? staffSectionIcons[item.id]
                          ?? inventorySectionIcons[item.id]
                          ?? procurementSectionIcons[item.id]}
                      </span>
                      <span>{item.label}</span>
                    </span>
                  ) : item.label}
                </SideNavMenuItem>
              );
            })}
          </SideNavItems>
        ) : null}
        {contextualContent}
      </div>
    </SideNav>
  );
}
