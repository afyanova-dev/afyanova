/**
 * Dashboard landing contracts. The backend composes one count widget per
 * workspace section the role may open — counts link into workspaces, never
 * duplicate their interactions. Denied widgets arrive in `restricted`, never
 * as data (same convention as the chart and front-desk sections).
 */
export interface DashboardWidget {
  id: string;
  title: string;
  count: number;
  href: string;
  section?: 'today' | 'work';
}

export interface QuickAction {
  id: string;
  title: string;
  href: string;
}

export interface DashboardPageProps {
  date: string;
  quickActions: QuickAction[];
  widgets: DashboardWidget[];
  restricted: string[];
  unavailable: string[];
  pendingApprovals: Array<{
    roleLabel: string;
    facilityName: string | null;
  }>;
}
