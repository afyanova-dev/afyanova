import { Link, router } from '@inertiajs/react';
import { SideNav, SideNavItems, SideNavMenuItem, TreeView } from '@carbon/react';
import {
  useState,
  type ComponentType,
  type MouseEvent,
  type ReactNode,
  type SyntheticEvent,
} from 'react';
import {
  ArrowRight,
  ArrowsHorizontal,
  Building,
  Calendar,
  ChartLine,
  Checkmark,
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

const chartSectionIcons: Record<string, ComponentType> = {
  'chart-overview': Task,
  'chart-timeline': Time,
  'chart-allergies': Notification,
  'chart-conditions': Pending,
  'chart-procedures': Edit,
  'chart-plans': Report,
  'chart-vitals': ChartLine,
  'chart-medications': Pills,
  'chart-lab-results': Microscope,
  'chart-imaging': ImageMedical,
  'chart-appointments': Calendar,
  'chart-visits': Time,
  'chart-programs': UserMultiple,
  'chart-referrals': ArrowRight,
  'chart-billing-history': Money,
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

const adminSectionIcons: Record<string, ReactNode> = {
  'admin-overview': <Dashboard size={16} aria-hidden="true" />,
  'admin-organization': <Building size={16} aria-hidden="true" />,
  'admin-role-promotions': <UserSettings size={16} aria-hidden="true" />,
  'admin-role-catalog': <UserSettings size={16} aria-hidden="true" />,
  'admin-procurement-limits': <Money size={16} aria-hidden="true" />,
  'admin-audit': <Document size={16} aria-hidden="true" />,
  'admin-staff-assignments': <Checkmark size={16} aria-hidden="true" />,
  'admin-lab-catalog-editors': <UserSettings size={16} aria-hidden="true" />,
};

interface AppSideNavProps {
  expanded: boolean;
  isDesktop: boolean;
  workspace: WorkspaceId | null;
  items: NavigationItem[];
  activeItemId?: string;
  onItemClick?: (item: NavigationItem, event: MouseEvent) => void;
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
  const [chartGroupExpansion, setChartGroupExpansion] = useState<Record<string, boolean>>({});

  if (!workspace || (items.length === 0 && !contextualContent)) return null;
  const isPatientChart = items.some((item) => item.id.startsWith('chart-'));
  // Staff profile, inventory, procurement, and admin sections use the same
  // record-sidebar treatment as the patient chart (15rem width)
  // — full-height contextual record nav.
  const isRecordNav = isPatientChart
    || items.some((item) => item.id.startsWith('staff-'))
    || items.some((item) => item.id.startsWith('inventory-'))
    || items.some((item) => item.id.startsWith('procurement-'))
    || items.some((item) => item.id.startsWith('admin-'));
  const sideNavClassName = [
    isRecordNav ? 'afyanova-chart-side-nav' : null,
  ].filter(Boolean).join(' ') || undefined;
  const isItemActive = (item: NavigationItem): boolean => (
    activeItemId ? item.id === activeItemId : item.active
  );
  const hasGroupedItems = items.some((item) => item.group);

  const handleFlatItemClick = (item: NavigationItem, event: MouseEvent): void => {
    if (event.metaKey || event.ctrlKey || event.shiftKey) {
      onNavigate();
      return;
    }

    if (onItemClick) {
      event.preventDefault();
      onItemClick(item, event);
    } else if (isPatientChart) {
      event.preventDefault();
      router.visit(item.href);
    }

    onNavigate();
  };

  const renderFlatItem = (item: NavigationItem): ReactNode => (
    <SideNavMenuItem
      key={item.id}
      href={item.href}
      as={onItemClick ? 'a' : Link}
      isActive={isItemActive(item)}
      aria-current={isItemActive(item) ? 'page' : undefined}
      onClick={(event: MouseEvent) => handleFlatItemClick(item, event)}
    >
      {isRecordNav ? (
        <span className="afyanova-side-nav-item">
          <span className="afyanova-side-nav-item__icon" aria-hidden="true">
            {staffSectionIcons[item.id]
              ?? inventorySectionIcons[item.id]
              ?? procurementSectionIcons[item.id]
              ?? adminSectionIcons[item.id]}
          </span>
          <span>{item.label}</span>
        </span>
      ) : item.label}
    </SideNavMenuItem>
  );

  const renderChartItem = (item: NavigationItem): ReactNode => {
    const Icon = chartSectionIcons[item.id] ?? Task;

    return (
      <TreeView.TreeNode
        key={item.id}
        id={item.id}
        value={item.id}
        href={item.href}
        label={item.label}
        renderIcon={Icon}
        onClick={(event: MouseEvent) => handleFlatItemClick(item, event)}
      >
      </TreeView.TreeNode>
    );
  };

  const renderChartSections = (): ReactNode => {
    const renderedGroups = new Set<string>();

    return (
      <div className="afyanova-chart-nav">
        <TreeView
          label="Patient chart sections"
          hideLabel
          active={items.find(isItemActive)?.id}
        >
          {items.map((item) => {
            if (!item.group) {
              return renderChartItem(item);
            }

            if (renderedGroups.has(item.group)) {
              return null;
            }

            const group = item.group;
            renderedGroups.add(group);

            return (
              <TreeView.TreeNode
                key={group}
                id={`chart-group-${group.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`}
                label={group}
                isExpanded={chartGroupExpansion[group] ?? true}
                onToggle={(
                  event: boolean | SyntheticEvent<HTMLElement> | undefined,
                  node?: { isExpanded?: boolean },
                ) => {
                  const isExpanded = typeof event === 'boolean' ? event : node?.isExpanded;

                  if (isExpanded !== undefined) {
                    setChartGroupExpansion((current) => ({
                      ...current,
                      [group]: isExpanded,
                    }));
                  }
                }}
              >
                {items.filter((groupItem) => groupItem.group === group).map(renderChartItem)}
              </TreeView.TreeNode>
            );
          })}
        </TreeView>
      </div>
    );
  };

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
          hasGroupedItems ? renderChartSections() : (
            <SideNavItems>
              {items.map(renderFlatItem)}
            </SideNavItems>
          )
        ) : null}
        {contextualContent}
      </div>
    </SideNav>
  );
}
