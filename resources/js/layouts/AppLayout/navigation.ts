import { routes } from '@/app/routes';
import { permissions, type Permission } from '@/lib/authorization';

export type WorkspaceId =
  | 'dashboard'
  | 'admin'
  | 'patients'
  | 'appointments'
  | 'reception'
  | 'serviceQueues'
  | 'nurseStation'
  | 'clinician'
  | 'attention'
  | 'billing'
  | 'pharmacy'
  | 'inventory'
  | 'procurement'
  | 'hrStaff';

export interface NavigationItem {
  id: string;
  label: string;
  href: string;
  active: boolean;
}

interface WorkspaceDefinition {
  id: WorkspaceId;
  label: string;
  href: string;
  permissions?: readonly Permission[];
  allPermissions?: readonly Permission[];
}

/**
 * Workspaces with a real frontend page. Nav must never offer what the resolver
 * cannot render (app.tsx throws on missing pages). Move an id here when its
 * slice lands.
 */
export const BUILT_WORKSPACES: readonly WorkspaceId[] = ['dashboard', 'admin', 'patients', 'appointments', 'reception', 'serviceQueues', 'nurseStation', 'clinician', 'hrStaff', 'billing', 'pharmacy', 'inventory', 'procurement'];
const PLATFORM_ADMIN_WORKSPACES = [
  { id: 'platform-overview', label: 'Dashboard', href: routes.platformAdmin.index },
  { id: 'platform-tenants', label: 'Tenant registry', href: routes.platformAdmin.tenants },
  { id: 'platform-audit', label: 'Platform audit', href: routes.platformAdmin.audit },
  { id: 'platform-super-admins', label: 'Super Admin access', href: routes.platformAdmin.superAdmins },
] as const;

const WORKSPACES: readonly WorkspaceDefinition[] = [
  { id: 'dashboard', label: 'Dashboard', href: routes.dashboard },
  {
    id: 'admin',
    label: 'Admin',
    href: routes.workspaces.admin,
    permissions: [permissions.admin.workspaceAccess],
  },
  {
    id: 'hrStaff',
    label: 'Staff directory',
    href: routes.workspaces.hrStaff,
    permissions: [permissions.hr.staffRead, permissions.hr.staffManage],
  },
  {
    id: 'patients',
    label: 'Patients',
    href: routes.workspaces.patientIndex,
    permissions: [permissions.patient.read, permissions.patient.create],
  },
  {
    id: 'appointments',
    label: 'Appointments',
    href: routes.workspaces.appointmentCalendar,
    permissions: [permissions.scheduling.access],
  },
  {
    id: 'reception',
    label: 'Front Desk',
    href: routes.workspaces.reception,
    // Merged board: entry needs ANY section permission (backend composes
    // denied sections empty with `restricted` flags instead of leaking rows).
    permissions: [permissions.encounter.read, permissions.scheduling.access, permissions.patient.read],
  },
  {
    id: 'serviceQueues',
    label: 'Service Queues',
    href: routes.workspaces.serviceQueues,
    permissions: [permissions.queue.ticketView],
  },
  {
    id: 'nurseStation',
    label: 'Nurse Station',
    href: routes.workspaces.nurseStation,
    allPermissions: [permissions.clinical.triageRecord, permissions.queue.ticketView],
  },
  {
    id: 'clinician',
    label: 'Clinician',
    href: routes.workspaces.clinician,
    allPermissions: [permissions.clinical.consultationsRead, permissions.queue.ticketView],
  },
  {
    id: 'attention',
    label: 'Attention',
    href: routes.workspaces.attention,
    permissions: [permissions.patient.read],
  },
  {
    id: 'billing',
    label: 'Billing',
    href: routes.workspaces.billing,
    permissions: [permissions.billing.access],
  },
  {
    id: 'pharmacy',
    label: 'Pharmacy',
    href: routes.workspaces.pharmacy,
    permissions: [permissions.pharmacy.access],
  },
  {
    id: 'inventory',
    label: 'Inventory',
    href: routes.workspaces.inventory,
    permissions: [permissions.inventory.access],
  },
  {
    id: 'procurement',
    label: 'Procurement',
    href: routes.workspaces.procurement,
    permissions: [permissions.procurement.access],
  },
];

type Can = (permission: Permission) => boolean;

function hasWorkspacePermission(workspace: WorkspaceDefinition, can: Can): boolean {
  return (!workspace.permissions || workspace.permissions.some(can))
    && (!workspace.allPermissions || workspace.allPermissions.every(can));
}

function pathname(url: string): string {
  return new URL(url, 'http://afyanova.local').pathname.replace(/\/$/, '') || '/';
}

function patientIdFromPath(path: string): string | null {
  const chartMatch = path.match(/^\/patient\/([^/]+)\/chart(?:\/[^/]+)?$/);
  if (chartMatch) return chartMatch[1] ?? null;

  const legacyMatch = path.match(/^\/workspaces\/patient\/([^/]+)$/);
  const legacyPatientId = legacyMatch?.[1];
  if (legacyPatientId && !['api', 'register'].includes(legacyPatientId)) return legacyPatientId;

  return null;
}

export function workspaceForUrl(url: string): WorkspaceId | null {
  const path = pathname(url);
  if (path === routes.dashboard) return 'dashboard';
  if (path === routes.workspaces.admin || path.startsWith(`${routes.workspaces.admin}/`)) return 'admin';
  if (path.startsWith('/workspaces/hr/')) return 'hrStaff';
  if (path === routes.workspaces.patientIndex || path.startsWith(`${routes.workspaces.patientIndex}/`) || path.startsWith('/patient/')) return 'patients';
  if (path === routes.workspaces.appointmentCalendar) return 'appointments';
  if (path === routes.workspaces.reception) return 'reception';
  if (path === routes.workspaces.serviceQueues || path.startsWith(`${routes.workspaces.serviceQueues}/`)) return 'serviceQueues';
  if (path === routes.workspaces.nurseStation || path.startsWith(`${routes.workspaces.nurseStation}/`)) return 'nurseStation';
  if (path === routes.workspaces.clinician || path.startsWith(`${routes.workspaces.clinician}/`)) return 'clinician';
  if (path === routes.workspaces.attention) return 'attention';
  if (path === routes.workspaces.billing || path.startsWith(`${routes.workspaces.billing}/`)) return 'billing';
  if (path === routes.workspaces.pharmacy || path.startsWith(`${routes.workspaces.pharmacy}/`)) return 'pharmacy';
  if (path === routes.workspaces.inventory || path.startsWith(`${routes.workspaces.inventory}/`)) return 'inventory';
  if (path === routes.workspaces.procurement || path.startsWith(`${routes.workspaces.procurement}/`)) return 'procurement';

  return null;
}

/**
 * Patient chart sections. Mirrors the content sections in
 * features/patients/components/PatientChart.tsx (same ids, same labels) —
 * layouts may not import features (Rule 01), so the pair is kept in sync by
 * convention and pinned by navigation.test.tsx. The backend ignores chart
 * tabs; unknown tabs fall back to overview on both sides.
 */
export interface ChartSectionLink {
  id: string;
  label: string;
}

export const PATIENT_CHART_SECTIONS: readonly ChartSectionLink[] = [
  { id: 'overview', label: 'Patient Summary' },
  { id: 'allergies', label: 'Allergies' },
  { id: 'conditions', label: 'Conditions' },
  { id: 'procedures', label: 'Procedures' },
  { id: 'plans', label: 'Plans' },
  { id: 'vitals', label: 'Vitals & Biometrics' },
  { id: 'medications', label: 'Medications' },
  { id: 'lab-results', label: 'Results' },
  { id: 'imaging', label: 'Orders' },
  { id: 'appointments', label: 'Appointments' },
  { id: 'visits', label: 'Visits' },
  { id: 'programs', label: 'Programs' },
  { id: 'referrals', label: 'Referrals' },
  { id: 'billing-history', label: 'Billing History' },
];

export function chartSectionFromPath(path: string): string {
  const match = path.match(/^\/patient\/[^/]+\/chart(?:\/([^/]+))?\/?$/);
  const requested = match?.[1];
  return PATIENT_CHART_SECTIONS.some((section) => section.id === requested)
    ? (requested as string)
    : 'overview';
}

export const STAFF_PROFILE_SECTIONS: readonly ChartSectionLink[] = [
  { id: 'overview', label: 'Staff Summary' },
  { id: 'personal', label: 'Personal details' },
  { id: 'employment', label: 'Employment' },
  { id: 'qualifications', label: 'Qualifications' },
  { id: 'access', label: 'System access' },
];

export function staffProfileSectionFromUrl(url: string): string {
  const parsed = new URL(url, 'http://afyanova.local');
  const match = parsed.pathname.match(/^\/workspaces\/hr\/staff\/([^/]+)\/?$/);
  if (!match) return 'overview';

  const section = parsed.searchParams.get('section');
  return STAFF_PROFILE_SECTIONS.some((item) => item.id === section) ? section as string : 'overview';
}

export const INVENTORY_SECTIONS: readonly ChartSectionLink[] = [
  { id: 'overview', label: 'Overview' },
  { id: 'stock', label: 'Stock lookup' },
  { id: 'stock-on-hand', label: 'Stock on hand' },
  { id: 'receipts', label: 'Goods receipts' },
  { id: 'expiry', label: 'Expiry' },
  { id: 'low-stock', label: 'Running low' },
  { id: 'transfers', label: 'Transfers' },
  { id: 'counts', label: 'Stock counts' },
  { id: 'adjustments', label: 'Adjustments' },
  { id: 'reservations', label: 'Reservations' },
  { id: 'catalogue', label: 'Item catalogue' },
  { id: 'locations', label: 'Locations & types' },
];

export function inventorySectionFromUrl(url: string): string {
  const parsed = new URL(url, 'http://afyanova.local');
  const path = parsed.pathname.replace(/\/$/, '') || '/';
  if (path !== routes.workspaces.inventory && !path.startsWith(`${routes.workspaces.inventory}/`)) return 'overview';

  const section = parsed.searchParams.get('section');
  return INVENTORY_SECTIONS.some((item) => item.id === section) ? section as string : 'overview';
}

/**
 * Procurement sections. Grows as each Shape B screen lands — purchase orders
 * and requests are added here (and in the controller) when they are built, so
 * the nav never offers a screen that cannot render.
 */
export const PROCUREMENT_SECTIONS: readonly ChartSectionLink[] = [
  { id: 'requests', label: 'Requests' },
  { id: 'orders', label: 'Purchase orders' },
  { id: 'suppliers', label: 'Suppliers' },
];

export function procurementSectionFromUrl(url: string): string {
  const parsed = new URL(url, 'http://afyanova.local');
  const path = parsed.pathname.replace(/\/$/, '') || '/';
  if (path !== routes.workspaces.procurement && !path.startsWith(`${routes.workspaces.procurement}/`)) return 'requests';

  const section = parsed.searchParams.get('section');
  return PROCUREMENT_SECTIONS.some((item) => item.id === section) ? section as string : 'requests';
}

export function visibleWorkspaces(can: Can, isPlatformSuperAdmin = false): NavigationItem[] {
  if (isPlatformSuperAdmin) {
    return PLATFORM_ADMIN_WORKSPACES.map(({ id, label, href }) => ({
      id,
      label,
      href,
      active: false,
    }));
  }

  const available = WORKSPACES.filter(
    (workspace) => BUILT_WORKSPACES.includes(workspace.id) && hasWorkspacePermission(workspace, can),
  );

  const hasOperationalWorkspace = available.some(
    (workspace) => workspace.id !== 'dashboard' && workspace.id !== 'admin',
  );
  const isAdminOnly = available.some((workspace) => workspace.id === 'admin') && !hasOperationalWorkspace;

  // The Dashboard redirects Admin-only users back to Admin, so don't present
  // it as a separate destination until an operational workspace is available.
  return available
    .filter((workspace) => !(workspace.id === 'dashboard' && isAdminOnly))
    .map((workspace) => ({
      id: workspace.id,
      label: workspace.label,
      href: workspace.id === 'patients' && !can(permissions.patient.read)
        ? routes.workspaces.patientRegister
        : workspace.href,
      active: false,
    }));
}

/**
 * Global-header primary tasks (Carbon §3 header actions). Keep only the
 * patient fast paths here; HR destinations live in the app menu.
 *
 * - Patients → search & browse (needs patient.read), active on index + chart.
 * - Register → registration form (needs patient.create), active on register.
 */
export function headerPrimaryLinks(url: string, can: Can, isPlatformSuperAdmin = false): NavigationItem[] {
  const path = pathname(url);
  if (isPlatformSuperAdmin) {
    return [];
  }

  const links: NavigationItem[] = [];

  if (can(permissions.patient.read)) {
    links.push({
      id: 'primary-patients',
      label: 'Patients',
      href: routes.workspaces.patientIndex,
      active: path === routes.workspaces.patientIndex || path.startsWith('/patient/'),
    });
  }
  if (can(permissions.patient.create)) {
    links.push({
      id: 'primary-register',
      label: 'Register',
      href: routes.workspaces.patientRegister,
      active: path === routes.workspaces.patientRegister,
    });
  }
  return links;
}

export function workspaceNavigation(url: string, can: Can, isPlatformSuperAdmin = false): {
  activeWorkspace: WorkspaceId | null;
  workspaces: NavigationItem[];
  appMenuItems: NavigationItem[];
  primaryLinks: NavigationItem[];
  sidebar: NavigationItem[];
} {
  const activeWorkspace = workspaceForUrl(url);
  const path = pathname(url);
  const selectedPatientId = patientIdFromPath(path);
  const staffRouteMatch = path.match(/^\/workspaces\/hr\/staff\/([^/]+)\/?$/);
  const staffProfileMatch = staffRouteMatch
    && !['create', 'onboarding', 'assignment-requests', 'account-invitations', 'access-requests', 'positions'].includes(staffRouteMatch[1] ?? '')
    ? staffRouteMatch
    : null;
  const platformPath = pathname(url);
  const workspaces = visibleWorkspaces(can, isPlatformSuperAdmin).map((item) => ({
    ...item,
    active: isPlatformSuperAdmin
      ? item.id === 'platform-overview'
        ? platformPath === routes.platformAdmin.index
        : item.id === 'platform-audit'
          ? platformPath === routes.platformAdmin.audit
          : item.id === 'platform-super-admins'
            ? platformPath === routes.platformAdmin.superAdmins
            : platformPath.startsWith(`${routes.platformAdmin.tenants}/`) || platformPath === routes.platformAdmin.tenants
      : item.id === activeWorkspace,
  }));
  const primaryLinks = headerPrimaryLinks(url, can, isPlatformSuperAdmin);
  const adminMenuItems: NavigationItem[] = [
    {
      id: 'admin-overview',
      label: 'Admin overview',
      href: routes.workspaces.admin,
      active: path === routes.workspaces.admin,
    },
    ...(can(permissions.organization.facilitiesRead) ? [{
      id: 'admin-organization',
      label: 'Organization & facilities',
      href: routes.workspaces.adminOrganization,
      active: path === routes.workspaces.adminOrganization,
    }] : []),
    ...(can(permissions.audit.eventsRead) ? [{
      id: 'admin-audit',
      label: 'Audit log',
      href: routes.workspaces.adminAudit,
      active: path === routes.workspaces.adminAudit,
    }] : []),
    ...(can(permissions.identity.assignmentsRead) ? [{
      id: 'admin-role-promotions',
      label: 'Role approvals',
      href: routes.workspaces.adminRolePromotions,
      active: path === routes.workspaces.adminRolePromotions,
    }] : []),
    ...(can(permissions.identity.assignmentsManage) ? [{
      id: 'admin-staff-assignments',
      label: 'Staff placements to review',
      href: `${routes.workspaces.hrStaffOnboarding}?focus=placements`,
      active: path === routes.workspaces.hrStaffOnboarding,
    }] : []),
  ];
  const withAdminNavigation = can(permissions.admin.workspaceAccess)
    ? workspaces.flatMap((item) => item.id === 'admin' ? adminMenuItems : [item])
    : workspaces;
  const canReviewStaffPlacements = (can(permissions.admin.workspaceAccess) && can(permissions.identity.assignmentsManage))
    || (can(permissions.hr.staffManage) && can(permissions.hr.assignmentManage));
  const canManageStaffAccess = (can(permissions.hr.staffManage) && can(permissions.hr.staffUserLinkManage))
    || (can(permissions.admin.workspaceAccess) && can(permissions.identity.assignmentsManage));
  const hrMenuItems: NavigationItem[] = [
    ...(can(permissions.hr.staffRead) || can(permissions.hr.staffManage) ? [{
      id: 'hr-directory',
      label: 'Staff directory',
      href: routes.workspaces.hrStaff,
      active: path === routes.workspaces.hrStaff || Boolean(staffRouteMatch && staffProfileMatch),
    }] : []),
    ...(can(permissions.hr.staffRead)
      || can(permissions.hr.staffManage)
      || canReviewStaffPlacements
      || canManageStaffAccess ? [{
        id: 'hr-onboarding',
        label: 'Staff onboarding',
        href: routes.workspaces.hrStaffOnboarding,
        active: ([
          routes.workspaces.hrStaffOnboarding,
          routes.workspaces.hrStaffAssignments,
          routes.workspaces.hrStaffAccessRequests,
          routes.workspaces.hrStaffAccountInvitations,
        ] as string[]).includes(path),
      }] : []),
    ...(can(permissions.hr.personManage)
      && can(permissions.hr.staffManage)
      && can(permissions.hr.assignmentManage) ? [{
        id: 'hr-add-staff',
        label: 'Add staff',
        href: routes.workspaces.hrStaffNew,
        active: path === routes.workspaces.hrStaffNew,
      }] : []),
    ...(can(permissions.hr.positionManage) ? [{
      id: 'hr-positions',
      label: 'Cadres & positions',
      href: routes.workspaces.hrStaffPositionSetup,
      active: path === routes.workspaces.hrStaffPositionSetup,
    }] : []),
  ];
  const staffProfileSection = staffProfileSectionFromUrl(url);
  const appMenuItems = [
    ...withAdminNavigation.filter((item) => item.id !== 'hrStaff'),
    ...hrMenuItems,
  ];
  let sidebar: NavigationItem[] = [];

  switch (activeWorkspace) {
    case 'admin':
      sidebar = [];
      break;

    case 'hrStaff':
      if (staffProfileMatch?.[1]) {
        const staffId = staffProfileMatch[1];
        sidebar = STAFF_PROFILE_SECTIONS.map((section) => ({
          id: `staff-${section.id}`,
          label: section.label,
          href: `${routes.workspaces.hrStaffDetail(staffId)}?section=${section.id}`,
          active: section.id === staffProfileSection,
        }));
      }
      break;

    case 'reception':
      // Roster status views (Today / Arrived / In service / Completed) live
      // on the metric cards, never in the sidebar — no contextual nav here.
      // Unscheduled arrivals go through the header quick search + register.
      sidebar = [];
      break;

    case 'patients':
      // Chart context: the sidebar IS the section list (OpenMRS-style). The
      // content area renders only the active section — no duplicated nav.
      if (selectedPatientId) {
        const activeSection = chartSectionFromPath(path);
        sidebar = PATIENT_CHART_SECTIONS.map((section) => ({
          id: `chart-${section.id}`,
          label: section.label,
          href: routes.patient.chart(selectedPatientId, section.id),
          active: section.id === activeSection,
        }));
        break;
      }
      // Search/browse + register live as header icon buttons (primaryLinks)
      // and in the app-menu Switcher. A two-item sidebar here only repeats
      // the header choice — no contextual nav on list pages.
      sidebar = [];
      break;

    case 'attention':
      sidebar = [{
        id: 'attention-board',
        label: 'Attention lists',
        href: routes.workspaces.attention,
        active: true,
      }];
      break;

    case 'billing':
      sidebar = [
        {
          id: 'billing-cashier',
          label: 'Cashier',
          href: routes.workspaces.billing,
          active: path === routes.workspaces.billing,
        },
        {
          id: 'billing-catalog',
          label: 'Catalog',
          href: routes.workspaces.billingCatalog,
          active: path === routes.workspaces.billingCatalog,
        },
      ];
      break;

    case 'pharmacy':
      // No contextual sidebar: the pharmacy workspace switches views
      // through tabs inside the page (orders / prescriptions, plus a
      // contextual patient tab when a patient is selected).
      sidebar = [];
      break;

    case 'inventory': {
      const activeSection = inventorySectionFromUrl(url);
      sidebar = INVENTORY_SECTIONS.map((section) => ({
        id: `inventory-${section.id}`,
        label: section.label,
        href: section.id === 'overview'
          ? routes.workspaces.inventory
          : `${routes.workspaces.inventory}?section=${section.id}`,
        active: section.id === activeSection,
      }));
      break;
    }

    case 'procurement': {
      const activeSection = procurementSectionFromUrl(url);
      sidebar = PROCUREMENT_SECTIONS.map((section) => ({
        id: `procurement-${section.id}`,
        label: section.label,
        href: `${routes.workspaces.procurement}?section=${section.id}`,
        active: section.id === activeSection,
      }));
      break;
    }
  }

  return { activeWorkspace, workspaces, appMenuItems, primaryLinks, sidebar };
}
