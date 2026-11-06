import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CarbonThemeProvider } from '@/app/CarbonThemeProvider';
import { permissions } from '@/lib/authorization';
import { FacilitySwitcher } from '@/components/navigation';
import { AppHeader } from '@/layouts/AppLayout/Header';
import {
  visibleWorkspaces,
  workspaceNavigation,
  workspaceForUrl,
  staffProfileSectionFromUrl,
  inventorySectionFromUrl,
  procurementSectionFromUrl,
} from '@/layouts/AppLayout/navigation';
import { AppSideNav } from '@/layouts/AppLayout/SideNav';
import { workspaceIcon } from '@/layouts/AppLayout/workspaceIcon';
import { Flow, HealthCross, List, Microscope, UserSettings } from '@/icons';

const state = vi.hoisted(() => ({
  post: vi.fn(),
  visit: vi.fn(),
  auth: {
    facilities: [{ id: 'facility-1', name: 'Central Clinic' }, { id: 'facility-2', name: 'West Clinic' }],
    activeFacility: null as string | null,
    contextMode: 'facility' as 'general' | 'facility' | 'unresolved',
    canUseGeneral: false,
    can: {
      'admin.workspace.access': false,
      'audit.events.read': false,
      'patient.patients.read': true,
      'patient.patients.create': true,
      'encounter.encounters.read': true,
      'sch.access': true,
      'queue.ticket.view': true,
      'queue.definition.manage': false,
      'clinical.access': true,
      'clinical.triage.record': true,
      'clinical.consultations.read': true,
      'clinical.diagnoses.read': false,
      'clinical.notes.create': true,
      'billing.access': true,
      'pharmacy.access': true,
      'inventory.access': true,
      'procurement.access': true,
      'laboratory.catalog.read': false,
      'laboratory.catalog.manage': false,
      'hr.person.manage': false,
      'hr.staff.read': false,
      'hr.staff.manage': false,
      'hr.assignment.manage': false,
      'hr.staff_user_link.manage': false,
      'hr.cadre_position.manage': false,
    },
  },
}));

beforeEach(() => {
  window.localStorage.clear();
  document.documentElement.classList.remove('cds--white', 'cds--g10', 'cds--g90', 'cds--g100');
  state.auth.facilities = [
    { id: 'facility-1', name: 'Central Clinic' },
    { id: 'facility-2', name: 'West Clinic' },
  ];
  state.auth.activeFacility = 'facility-1';
  state.auth.contextMode = 'facility';
  state.auth.canUseGeneral = false;
  state.auth.can['hr.person.manage'] = false;
  state.auth.can['hr.staff.read'] = false;
  state.auth.can['hr.staff.manage'] = false;
  state.auth.can['hr.assignment.manage'] = false;
  state.auth.can['hr.staff_user_link.manage'] = false;
  state.auth.can['hr.cadre_position.manage'] = false;
});

vi.mock('@inertiajs/react', () => ({
  Link: 'a',
  router: { post: state.post, visit: state.visit },
  usePage: () => ({ props: { auth: state.auth } }),
}));

const allPermissions = [
  permissions.admin.workspaceAccess,
  permissions.organization.facilitiesRead,
  permissions.patient.read,
  permissions.patient.create,
  permissions.encounter.read,
  permissions.scheduling.access,
  permissions.queue.ticketView,
  permissions.queue.definitionManage,
  permissions.clinical.access,
  permissions.clinical.triageRecord,
  permissions.clinical.consultationsRead,
  permissions.clinical.noteCreate,
  permissions.billing.access,
  permissions.pharmacy.access,
  permissions.inventory.access,
  permissions.procurement.access,
];

function canWith(...held: string[]): (permission: string) => boolean {
  return (permission) => held.includes(permission);
}

function installMatchMedia(): void {
  Object.defineProperty(window, 'matchMedia', {
    configurable: true,
    value: vi.fn().mockImplementation((media: string) => ({
      matches: false,
      media,
      onchange: null,
      addListener: vi.fn(),
      removeListener: vi.fn(),
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
      dispatchEvent: vi.fn(),
    })),
  });
}

function installResizeObserver(): void {
  globalThis.ResizeObserver = class ResizeObserver {
    observe(): void {}
    unobserve(): void {}
    disconnect(): void {}
  };
}

describe('two-level workspace navigation', () => {
  it('shows only built top-level areas and selects active area from the URL', () => {
    const navigation = workspaceNavigation('/workspaces/patient', canWith(...allPermissions));

    // Only slices with real pages are offered; unbuilt workspaces stay
    // hidden until their slice lands (BUILT_WORKSPACES). Full list lives in
    // the app-menu Switcher; registration remains the direct header shortcut.
    expect(navigation.workspaces.map((item) => item.label)).toEqual([
      'Dashboard',
      'Admin',
      'Patients',
      'Appointments',
      'Front Desk',
      'Patient Flow',
      'Service Queues',
      'Nurse Station',
      'Clinician',
      'Billing',
      'Pharmacy',
      'Inventory',
      'Procurement',
    ]);
    expect(navigation.activeWorkspace).toBe('patients');
    expect(navigation.workspaces.find((item) => item.id === 'patients')?.active).toBe(true);
    expect(navigation.primaryLinks.map((item) => item.label)).toEqual(['Register']);
    // No two-item sidebar on list pages — header + Switcher own that choice.
    expect(navigation.sidebar).toEqual([]);
  });

  it('shows platform-only navigation instead of the tenant dashboard for a platform Super Admin', () => {
    const navigation = workspaceNavigation('/platform-admin/tenants/tenant-1/facilities/facility-1', canWith(), true);

    expect(navigation.workspaces.map((item) => item.label)).toEqual([
      'Dashboard',
      'Tenant registry',
      'Platform audit',
      'Super Admin access',
    ]);
    expect(navigation.workspaces.find((item) => item.id === 'platform-tenants')?.active).toBe(true);
    expect(navigation.workspaces.some((item) => item.href === '/dashboard')).toBe(false);
    expect(navigation.primaryLinks).toEqual([]);
  });

  it('selects the matching platform workspace for audit and Super Admin access routes', () => {
    const audit = workspaceNavigation('/platform-admin/audit', canWith(), true);
    const superAdmins = workspaceNavigation('/platform-admin/super-admins', canWith(), true);

    expect(audit.workspaces.find((item) => item.id === 'platform-audit')?.active).toBe(true);
    expect(superAdmins.workspaces.find((item) => item.id === 'platform-super-admins')?.active).toBe(true);
  });

  it('resolves retired workspace URLs to no workspace', () => {
    for (const url of ['/workspaces/appointments']) {
      const navigation = workspaceNavigation(url, canWith(...allPermissions));

      expect(navigation.activeWorkspace).toBeNull();
      expect(navigation.sidebar).toEqual([]);
    }
    expect(workspaceForUrl('/workspaces/reception')).toBe('reception');
    expect(workspaceForUrl('/workspaces/appointments/calendar')).toBe('appointments');
  });
  it('keeps patient read and create permissions independent', () => {
    const createOnly = workspaceNavigation('/workspaces/patient/register', canWith(permissions.patient.create));
    expect(createOnly.workspaces.find((item) => item.id === 'patients')?.href).toBe('/workspaces/patient/register');
    expect(createOnly.primaryLinks.map((item) => item.id)).toEqual(['primary-register']);
    expect(createOnly.primaryLinks[0]).toMatchObject({ href: '/workspaces/patient/register', active: true });
    expect(createOnly.sidebar).toEqual([]);
    expect(createOnly.workspaces.some((item) => item.id === 'attention')).toBe(false);

    const readOnly = workspaceNavigation('/workspaces/patient', canWith(permissions.patient.read));
    expect(readOnly.primaryLinks).toEqual([]);
    expect(readOnly.sidebar).toEqual([]);
    expect(readOnly.workspaces.find((item) => item.id === 'patients')?.href).toBe('/workspaces/patient');
  });

  it('shows the laboratory catalog to read-only catalog users', () => {
    const navigation = workspaceNavigation(
      '/workspaces/laboratory/catalog',
      canWith(permissions.laboratory.catalogRead),
    );

    expect(navigation.activeWorkspace).toBe('laboratory');
    expect(navigation.workspaces.map((item) => item.id)).toEqual(['dashboard', 'laboratory']);
  });

  it('requires consultation access separately from clinical note creation', () => {
    const nursePermissions = [
      permissions.queue.ticketView,
      permissions.clinical.triageRecord,
      permissions.clinical.noteCreate,
    ];
    const nurseNavigation = workspaceNavigation('/workspaces/clinician', canWith(...nursePermissions));

    expect(nurseNavigation.workspaces.some((item) => item.id === 'nurseStation')).toBe(true);
    expect(nurseNavigation.workspaces.some((item) => item.id === 'clinician')).toBe(false);

    const clinicianNavigation = workspaceNavigation('/workspaces/clinician', canWith(
      ...nursePermissions,
      permissions.clinical.consultationsRead,
    ));
    expect(clinicianNavigation.workspaces.some((item) => item.id === 'clinician')).toBe(true);
  });

  it('shows diagnosis catalogue in the app menu with read access even without diagnosis creation access', () => {
    const navigation = workspaceNavigation('/workspaces/clinician', canWith(
      permissions.clinical.access,
      permissions.clinical.diagnosesRead,
    ));

    expect(navigation.appMenuItems.find((item) => item.id === 'clinician-diagnosis-catalog')).toMatchObject({
      label: 'Diagnosis catalogue',
      href: '/workspaces/clinician/diagnoses',
      active: false,
    });
  });

  it('hides diagnosis catalogue from the app menu without diagnosis read access', () => {
    const navigation = workspaceNavigation('/workspaces/clinician', canWith(
      permissions.clinical.access,
    ));

    expect(navigation.appMenuItems.some((item) => item.id === 'clinician-diagnosis-catalog')).toBe(false);
  });

  it('turns the sidebar into the chart section list on chart pages', () => {
    expect(workspaceNavigation('/workspaces/patient', canWith(permissions.patient.read)).sidebar).toEqual([]);
    expect(workspaceNavigation('/workspaces/patient/register', canWith(permissions.patient.create)).primaryLinks[0]).toMatchObject({
      href: '/workspaces/patient/register',
      active: true,
    });

    const chart = workspaceNavigation('/patient/patient-123/chart/lab-results', canWith(permissions.patient.read));
    expect(chart.activeWorkspace).toBe('patients');
    expect(chart.sidebar).toHaveLength(15);
    expect(chart.sidebar.map((item) => item.id)).toEqual([
      'chart-overview',
      'chart-timeline',
      'chart-conditions',
      'chart-allergies',
      'chart-medications',
      'chart-vitals',
      'chart-plans',
      'chart-procedures',
      'chart-lab-results',
      'chart-imaging',
      'chart-appointments',
      'chart-visits',
      'chart-programs',
      'chart-referrals',
      'chart-billing-history',
    ]);
    expect(chart.sidebar.find((item) => item.id === 'chart-lab-results')).toMatchObject({
      href: '/patient/patient-123/chart/lab-results',
      active: true,
      group: 'Diagnostics',
    });
    expect(chart.sidebar.find((item) => item.id === 'chart-conditions')).toMatchObject({
      label: 'Problems',
      group: 'Clinical',
    });
    expect(chart.sidebar.find((item) => item.id === 'chart-timeline')).toMatchObject({
      href: '/patient/patient-123/chart/timeline',
      group: undefined,
    });
    expect(chart.sidebar.find((item) => item.id === 'chart-overview')).toMatchObject({
      href: '/patient/patient-123/chart/overview',
      active: false,
    });
  });

  it('falls back to overview for unknown chart tabs', () => {
    const chart = workspaceNavigation('/patient/patient-123/chart/foobar', canWith(permissions.patient.read));
    expect(chart.sidebar.find((item) => item.id === 'chart-overview')).toMatchObject({ active: true });
    expect(chart.sidebar.filter((item) => item.active)).toHaveLength(1);
  });

  it('uses staff profile sections as contextual sidebar navigation', () => {
    const profile = workspaceNavigation(
      '/workspaces/hr/staff/staff-123?section=employment',
      canWith(permissions.hr.staffManage),
    );

    expect(profile.sidebar).toEqual([
      {
        id: 'staff-overview',
        label: 'Staff Summary',
        href: '/workspaces/hr/staff/staff-123?section=overview',
        active: false,
      },
      {
        id: 'staff-personal',
        label: 'Personal details',
        href: '/workspaces/hr/staff/staff-123?section=personal',
        active: false,
      },
      {
        id: 'staff-employment',
        label: 'Employment',
        href: '/workspaces/hr/staff/staff-123?section=employment',
        active: true,
      },
      {
        id: 'staff-qualifications',
        label: 'Qualifications',
        href: '/workspaces/hr/staff/staff-123?section=qualifications',
        active: false,
      },
      {
        id: 'staff-access',
        label: 'System access',
        href: '/workspaces/hr/staff/staff-123?section=access',
        active: false,
      },
    ]);
    expect(staffProfileSectionFromUrl('/workspaces/hr/staff/staff-123?section=unknown')).toBe('overview');
    expect(workspaceNavigation('/workspaces/hr/staff/create', canWith(permissions.hr.staffManage)).sidebar).toEqual([]);
    const accessRequests = workspaceNavigation('/workspaces/hr/staff/access-requests', canWith(
      permissions.hr.staffManage,
      permissions.hr.staffUserLinkManage,
    ));
    expect(accessRequests.sidebar).toEqual([]);
    expect(accessRequests.appMenuItems).toContainEqual(expect.objectContaining({
      id: 'hr-directory',
      label: 'Staff directory',
      href: '/workspaces/hr/staff',
      active: false,
    }));
    expect(accessRequests.appMenuItems).toContainEqual(expect.objectContaining({
      id: 'hr-onboarding',
      label: 'Staff onboarding',
      href: '/workspaces/hr/staff/onboarding',
      active: true,
    }));

    const accountInvitations = workspaceNavigation('/workspaces/hr/staff/account-invitations', canWith(
      permissions.hr.staffManage,
      permissions.hr.staffUserLinkManage,
    ));
    expect(accountInvitations.sidebar).toEqual([]);
    expect(accountInvitations.appMenuItems).toContainEqual(expect.objectContaining({
      id: 'hr-directory',
      label: 'Staff directory',
      href: '/workspaces/hr/staff',
      active: false,
    }));
    expect(accountInvitations.appMenuItems).toContainEqual(expect.objectContaining({
      id: 'hr-onboarding',
      label: 'Staff onboarding',
      href: '/workspaces/hr/staff/onboarding',
      active: true,
    }));
  });

  it('gives the staff profile sidebar the same chart navigation treatment', () => {
    installMatchMedia();
    const profile = workspaceNavigation(
      '/workspaces/hr/staff/staff-123?section=employment',
      canWith(permissions.hr.staffManage),
    );
    const { unmount } = render(
      <AppSideNav
        expanded
        isDesktop
        workspace={profile.activeWorkspace}
        items={profile.sidebar}
        onNavigate={vi.fn()}
      />,
    );

    expect(screen.getByRole('navigation', { name: 'Workspace navigation' }))
      .toHaveClass('afyanova-chart-side-nav');
    for (const item of profile.sidebar) {
      expect(screen.getByRole('link', { name: item.label }).querySelector('svg')).not.toBeNull();
    }
    unmount();

    const chart = workspaceNavigation('/patient/patient-123/chart', canWith(permissions.patient.read));
    const onChartNavigate = vi.fn();
    const { unmount: unmountChart } = render(
      <AppSideNav
        expanded
        isDesktop
        workspace={chart.activeWorkspace}
        items={chart.sidebar}
        onNavigate={onChartNavigate}
      />,
    );
    expect(screen.getByRole('navigation', { name: 'Workspace navigation' }))
      .toHaveClass('afyanova-chart-side-nav');
    const chartNavigation = screen.getByRole('tree', { name: 'Patient chart sections' });
    for (const group of ['Clinical', 'Diagnostics', 'Care']) {
      const groupNode = chartNavigation.querySelector(`#chart-group-${group.toLowerCase()}`);
      expect(groupNode)
        .toHaveAttribute('role', 'treeitem');
      expect(groupNode)
        .toHaveAttribute('aria-expanded', 'true');
    }
    for (const item of chart.sidebar) {
      expect(within(chartNavigation).getByRole('treeitem', { name: item.label }))
        .toBeVisible();
    }
    const summary = within(chartNavigation).getByRole('treeitem', { name: 'Patient Summary' });
    expect(summary).toHaveAttribute('href', '/patient/patient-123/chart/overview');
    expect(summary).toHaveAttribute('aria-current', 'page');
    const timeline = within(chartNavigation).getByRole('treeitem', { name: 'Timeline' });
    expect(timeline).toHaveAttribute('href', '/patient/patient-123/chart/timeline');
    const billing = within(chartNavigation).getByRole('treeitem', { name: 'Billing' });
    expect(billing).toHaveAttribute('href', '/patient/patient-123/chart/billing-history');
    expect(billing.closest('.cds--tree-parent-node')).toBeNull();
    expect(chartNavigation.querySelector('#chart-group-record')).toBeNull();

    const clinicalGroup = chartNavigation.querySelector('#chart-group-clinical');
    const clinicalToggle = clinicalGroup!.querySelector('.cds--tree-parent-node__toggle');
    expect(clinicalToggle).not.toBeNull();
    fireEvent.click(clinicalToggle!);
    expect(clinicalGroup).toHaveAttribute('aria-expanded', 'false');
    const clinicalSubtree = chartNavigation.querySelector('#chart-group-clinical-subtree');
    expect(clinicalSubtree).toHaveClass('cds--tree-node--hidden');
    fireEvent.click(clinicalToggle!);
    expect(clinicalGroup).toHaveAttribute('aria-expanded', 'true');
    expect(clinicalSubtree).not.toHaveClass('cds--tree-node--hidden');

    timeline.addEventListener('click', (event) => event.preventDefault(), { once: true });
    fireEvent.click(timeline);
    expect(onChartNavigate).toHaveBeenCalledOnce();
    unmountChart();

    const problemsChart = workspaceNavigation('/patient/patient-123/chart/conditions', canWith(permissions.patient.read));
    const { unmount: unmountProblemsChart } = render(
      <AppSideNav
        expanded
        isDesktop
        workspace={problemsChart.activeWorkspace}
        items={problemsChart.sidebar}
        onNavigate={vi.fn()}
      />,
    );
    const problemsNavigation = screen.getByRole('tree', { name: 'Patient chart sections' });
    expect(problemsNavigation.querySelector('#chart-group-clinical'))
      .toHaveAttribute('aria-expanded', 'true');
    expect(within(problemsNavigation).getByRole('treeitem', { name: 'Problems' }))
      .toHaveAttribute('aria-current', 'page');
    unmountProblemsChart();

    render(
      <AppSideNav
        expanded
        isDesktop
        workspace="billing"
        items={[{
          id: 'billing-cashier',
          label: 'Cashier',
          href: '/workspaces/billing',
          active: true,
        }]}
        onNavigate={vi.fn()}
      />,
    );
    expect(screen.getByRole('navigation', { name: 'Workspace navigation' }))
      .not.toHaveClass('afyanova-chart-side-nav');
  });

  it('navigates patient chart tree links through Inertia', () => {
    state.visit.mockReset();
    const chart = workspaceNavigation('/patient/patient-123/chart', canWith(permissions.patient.read));
    const onNavigate = vi.fn();
    render(
      <AppSideNav
        expanded
        isDesktop
        workspace={chart.activeWorkspace}
        items={chart.sidebar}
        onNavigate={onNavigate}
      />,
    );

    fireEvent.click(screen.getByRole('treeitem', { name: 'Timeline' }));

    expect(state.visit).toHaveBeenCalledWith('/patient/patient-123/chart/timeline');
    expect(onNavigate).toHaveBeenCalledOnce();
  });

  it('keeps the pharmacy workspace free of sidebar navigation — views switch through tabs', () => {
    for (const url of [
      '/workspaces/pharmacy',
      '/workspaces/pharmacy?view=orders',
      '/workspaces/pharmacy?view=prescriptions',
      '/workspaces/pharmacy?view=patient&patient_id=patient-1',
    ]) {
      const navigation = workspaceNavigation(url, canWith(permissions.pharmacy.access));
      expect(navigation.activeWorkspace).toBe('pharmacy');
      expect(navigation.sidebar).toEqual([]);
    }
  });

  it('changes contextual navigation with URL state and hides inaccessible areas', () => {
    const pharmacy = workspaceNavigation('/workspaces/pharmacy?view=prescriptions', canWith(permissions.pharmacy.access));
    expect(pharmacy.activeWorkspace).toBe('pharmacy');
    expect(pharmacy.sidebar).toEqual([]);

    expect(visibleWorkspaces(canWith(permissions.billing.access)).map((item) => item.id))
      .toEqual(['dashboard', 'billing']);
    expect(visibleWorkspaces(canWith(permissions.pharmacy.access)).map((item) => item.id))
      .toEqual(['dashboard', 'pharmacy']);
    expect(visibleWorkspaces(canWith(...allPermissions)).map((item) => item.id))
      .toEqual(['dashboard', 'admin', 'patients', 'appointments', 'reception', 'patientFlow', 'serviceQueues', 'nurseStation', 'clinician', 'billing', 'pharmacy', 'inventory', 'procurement']);
    expect(visibleWorkspaces(canWith(permissions.admin.workspaceAccess)).map((item) => item.id))
      .toEqual(['admin']);
    expect(visibleWorkspaces(canWith(permissions.admin.workspaceAccess), false, true).map((item) => item.id))
      .toEqual(['admin', 'laboratoryCatalogEditors']);
    expect(workspaceNavigation(
      '/workspaces/admin',
      canWith(permissions.admin.workspaceAccess),
      false,
      true,
    ).workspaces.map((item) => item.id)).toEqual(['admin', 'laboratoryCatalogEditors']);
    expect(visibleWorkspaces(canWith(permissions.hr.staffManage)).map((item) => item.id))
      .toEqual(['dashboard', 'hrStaff']);
    expect(workspaceNavigation('/workspaces/hr/staff', canWith(permissions.hr.staffManage))).toMatchObject({
      activeWorkspace: 'hrStaff',
      workspaces: expect.arrayContaining([
        expect.objectContaining({ id: 'hrStaff', label: 'Staff directory', href: '/workspaces/hr/staff', active: true }),
      ]),
      sidebar: [],
      appMenuItems: expect.arrayContaining([
        expect.objectContaining({ id: 'hr-directory', href: '/workspaces/hr/staff', active: true }),
      ]),
    });
    expect(workspaceNavigation('/workspaces/hr/staff', canWith(permissions.hr.staffManage)).appMenuItems)
      .not.toContainEqual(expect.objectContaining({ id: 'hr-invite-user' }));
    expect(workspaceNavigation('/workspaces/admin', canWith(
      permissions.admin.workspaceAccess,
      permissions.identity.assignmentsManage,
    )).sidebar).toContainEqual(expect.objectContaining({
      id: 'admin-staff-assignments',
      label: 'Staff placements to review',
      href: '/workspaces/hr/staff/onboarding?focus=placements',
    }));

    const hrNavigationPermissions = [
      permissions.hr.staffRead,
      permissions.hr.staffManage,
      permissions.hr.personManage,
      permissions.hr.assignmentManage,
      permissions.hr.staffUserLinkManage,
      permissions.hr.positionManage,
    ];
    const dashboardMenu = workspaceNavigation('/dashboard', canWith(...hrNavigationPermissions)).appMenuItems;
    const appointmentsMenu = workspaceNavigation(
      '/workspaces/appointments/calendar',
      canWith(...hrNavigationPermissions),
    ).appMenuItems;
    const staffMenu = workspaceNavigation(
      '/workspaces/hr/staff',
      canWith(...hrNavigationPermissions),
    ).appMenuItems;
    expect(dashboardMenu.map(({ id }) => id)).toEqual(appointmentsMenu.map(({ id }) => id));
    expect(dashboardMenu.map(({ id }) => id)).toEqual(staffMenu.map(({ id }) => id));
    expect(dashboardMenu).toEqual(expect.arrayContaining([
      expect.objectContaining({ id: 'hr-directory', href: '/workspaces/hr/staff' }),
      expect.objectContaining({ id: 'hr-add-staff', href: '/workspaces/hr/staff/create' }),
      expect.objectContaining({ id: 'hr-onboarding', href: '/workspaces/hr/staff/onboarding' }),
      expect.objectContaining({ id: 'hr-positions', href: '/workspaces/hr/staff/positions' }),
    ]));
    expect(workspaceNavigation('/workspaces/admin', canWith(permissions.admin.workspaceAccess))).toMatchObject({
      activeWorkspace: 'admin',
      workspaces: [
        expect.objectContaining({ id: 'admin', label: 'Admin', href: '/workspaces/admin', active: true }),
      ],
      appMenuItems: [
        expect.objectContaining({ id: 'admin', label: 'Admin', active: true }),
      ],
      sidebar: [
        expect.objectContaining({ id: 'admin-overview', label: 'Overview', active: true }),
      ],
    });
    const auditNavigation = workspaceNavigation('/workspaces/admin/audit', canWith(
      permissions.admin.workspaceAccess,
      permissions.audit.eventsRead,
    ));
    expect(auditNavigation.sidebar).toContainEqual(
      expect.objectContaining({ id: 'admin-audit', href: '/workspaces/admin/audit', active: true }),
    );
    expect(auditNavigation.appMenuItems).toContainEqual(
      expect.objectContaining({ id: 'admin', href: '/workspaces/admin', active: true }),
    );
    const organizationNavigation = workspaceNavigation('/workspaces/admin/organization', canWith(
      permissions.admin.workspaceAccess,
      permissions.organization.facilitiesRead,
    ));
    expect(organizationNavigation.sidebar).toContainEqual(expect.objectContaining({
      id: 'admin-organization',
      href: '/workspaces/admin/organization',
      active: true,
    }));
    expect(organizationNavigation.appMenuItems).toContainEqual(
      expect.objectContaining({ id: 'admin', href: '/workspaces/admin', active: true }),
    );
    expect(workspaceNavigation('/workspaces/admin/organization', canWith(
      permissions.admin.workspaceAccess,
    )).sidebar).not.toContainEqual(expect.objectContaining({ id: 'admin-organization' }));
    const approvalsNavigation = workspaceNavigation('/workspaces/admin/identity/role-promotions', canWith(
      permissions.admin.workspaceAccess,
      permissions.identity.assignmentsRead,
    ));
    expect(approvalsNavigation.sidebar).toContainEqual(
      expect.objectContaining({ id: 'admin-role-promotions', href: '/workspaces/admin/identity/role-promotions', active: true }),
    );
    expect(workspaceNavigation('/workspaces/admin/audit', canWith(
      permissions.admin.workspaceAccess,
    )).sidebar).toEqual([
      expect.objectContaining({ id: 'admin-overview', label: 'Overview' }),
    ]);
    expect(workspaceForUrl('/settings/sessions')).toBeNull();
  });

  it('resolves the Procurement workspace for procurement viewers', () => {
    const procurement = workspaceNavigation(
      '/workspaces/procurement?section=suppliers',
      canWith(permissions.procurement.access),
    );

    expect(procurement.activeWorkspace).toBe('procurement');
    expect(procurement.workspaces).toContainEqual(expect.objectContaining({
      id: 'procurement',
      label: 'Procurement',
      href: '/workspaces/procurement',
      active: true,
    }));
    expect(procurement.sidebar.map((item) => item.label))
      .toEqual(['Requests', 'Purchase orders', 'Suppliers']);
    expect(procurement.sidebar.find((item) => item.id === 'procurement-suppliers')?.active).toBe(true);

    const orders = workspaceNavigation(
      '/workspaces/procurement?section=orders',
      canWith(permissions.procurement.access),
    );
    expect(orders.sidebar.find((item) => item.id === 'procurement-orders')?.active).toBe(true);

    const requests = workspaceNavigation(
      '/workspaces/procurement?section=requests',
      canWith(permissions.procurement.access),
    );
    expect(requests.sidebar.find((item) => item.id === 'procurement-requests')?.active).toBe(true);

    expect(procurementSectionFromUrl('/workspaces/procurement')).toBe('requests');
    expect(procurementSectionFromUrl('/workspaces/procurement?section=orders')).toBe('orders');
    expect(procurementSectionFromUrl('/workspaces/procurement?section=suppliers')).toBe('suppliers');
    expect(procurementSectionFromUrl('/workspaces/procurement?section=unknown')).toBe('requests');
    expect(procurementSectionFromUrl('/workspaces/inventory')).toBe('requests');
    expect(workspaceForUrl('/workspaces/procurement/suppliers')).toBe('procurement');
  });

  it('resolves Patient Flow and Service Queues by their route, permission, and icon', () => {
    expect(workspaceForUrl('/workspaces/service-queues')).toBe('patientFlow');
    expect(workspaceForUrl('/workspaces/service-queues/manage')).toBe('serviceQueues');
    expect(workspaceIcon('patientFlow')).toBe(Flow);
    expect(workspaceIcon('serviceQueues')).toBe(List);
  });

  it('uses a clinical icon for the diagnosis catalogue app menu entry', () => {
    expect(workspaceIcon('clinician-diagnosis-catalog')).toBe(HealthCross);
  });

  it('uses a staff-access icon for Laboratory Test Setup Access', () => {
    expect(workspaceIcon('laboratoryCatalogEditors')).toBe(UserSettings);
  });

  it('uses a microscope icon for Lab Tests & Panels', () => {
    expect(workspaceIcon('laboratory')).toBe(Microscope);
  });

  it('resolves the Inventory workspace for inventory viewers', () => {
    const inventory = workspaceNavigation('/workspaces/inventory', canWith(permissions.inventory.access));
    expect(inventory.activeWorkspace).toBe('inventory');
    expect(inventory.workspaces).toContainEqual(expect.objectContaining({
      id: 'inventory',
      label: 'Inventory',
      active: true,
    }));
    expect(inventory.sidebar.map((item) => item.label)).toEqual([
      'Overview',
      'Stock lookup',
      'Stock on hand',
      'Goods receipts',
      'Expiry',
      'Running low',
      'Transfers',
      'Stock counts',
      'Adjustments',
      'Reservations',
      'Inventory Items',
      'Locations & types',
    ]);
    expect(inventory.sidebar.find((item) => item.id === 'inventory-overview')?.active).toBe(true);

    const withReceipts = workspaceNavigation(
      '/workspaces/inventory?section=receipts',
      canWith(permissions.inventory.access, permissions.inventory.receiptsCreate),
    );
    expect(withReceipts.sidebar.map((item) => item.label)).toEqual(inventory.sidebar.map((item) => item.label));
    expect(withReceipts.sidebar.find((item) => item.id === 'inventory-receipts')?.active).toBe(true);
    expect(inventorySectionFromUrl('/workspaces/inventory?section=receipts')).toBe('receipts');
    const queues = workspaceNavigation('/workspaces/service-queues', canWith(permissions.queue.ticketView));
    expect(queues.activeWorkspace).toBe('patientFlow');
    expect(queues.workspaces).toContainEqual(expect.objectContaining({
      id: 'patientFlow',
      label: 'Patient Flow',
      href: '/workspaces/service-queues',
      active: true,
    }));
    expect(workspaceNavigation('/workspaces/service-queues', canWith(permissions.queue.ticketPlace)).workspaces)
      .not.toContainEqual(expect.objectContaining({ id: 'patientFlow' }));

    const configuration = workspaceNavigation(
      '/workspaces/service-queues/manage',
      canWith(permissions.queue.definitionManage),
    );
    expect(configuration.activeWorkspace).toBe('serviceQueues');
    expect(configuration.workspaces).toContainEqual(expect.objectContaining({
      id: 'serviceQueues',
      label: 'Service Queues',
      href: '/workspaces/service-queues/manage',
      active: true,
    }));
    expect(configuration.workspaces).not.toContainEqual(expect.objectContaining({ id: 'patientFlow' }));
  });

  it('resolves the Front Desk workspace with no contextual sidebar', () => {
    expect(workspaceForUrl('/workspaces/reception')).toBe('reception');

    const navigation = workspaceNavigation('/workspaces/reception', canWith(...allPermissions));
    expect(navigation.activeWorkspace).toBe('reception');
    expect(navigation.workspaces.find((item) => item.id === 'reception')).toMatchObject({
      label: 'Front Desk',
      href: '/workspaces/reception',
      active: true,
    });
    // Roster status views live on the metric cards, not sidebar nav.
    expect(navigation.sidebar).toEqual([]);
  });

  it('renders the selected sidebar item from the current route', () => {
    installMatchMedia();
    const navigation = workspaceNavigation('/workspaces/billing/catalog', canWith(permissions.billing.access));
    render(
      <AppSideNav
        expanded
        isDesktop
        workspace={navigation.activeWorkspace}
        items={navigation.sidebar}
        onNavigate={vi.fn()}
      />,
    );

    expect(screen.getByRole('link', { name: 'Catalog' })).toHaveClass('cds--side-nav__link--current');
  });

  it('uses Carbon fixed navigation on desktop and a collapsible overlay on mobile', () => {
    installMatchMedia();
    const { rerender } = render(
      <AppSideNav
        expanded
        isDesktop
        workspace="billing"
        items={[{
          id: 'calendar',
          label: 'Calendar',
          href: '/workspaces/appointments/calendar',
          active: true,
        }]}
        onNavigate={vi.fn()}
      />,
    );

    const desktopNav = screen.getByRole('navigation', { name: 'Workspace navigation' });
    expect(desktopNav).toHaveClass('cds--side-nav--ux', 'cds--side-nav--expanded');

    rerender(
      <AppSideNav
        expanded={false}
        isDesktop={false}
        workspace="billing"
        items={[{
          id: 'calendar',
          label: 'Calendar',
          href: '/workspaces/appointments/calendar',
          active: true,
        }]}
        onNavigate={vi.fn()}
      />,
    );
    expect(screen.getByRole('navigation', { name: 'Workspace navigation' }))
      .toHaveClass('cds--side-nav--hidden');
  });
});

describe('application header context', () => {
  it('offers General and facility choices for a Tenant Admin, including with one facility', () => {
    installResizeObserver();
    state.post.mockClear();
    const both = state.auth.facilities;

    // A Tenant Admin can always return to General, even with one facility.
    state.auth.facilities = [{ id: 'facility-1', name: 'Central Clinic' }];
    state.auth.activeFacility = 'facility-1';
    state.auth.canUseGeneral = true;
    const { unmount } = render(<FacilitySwitcher />);
    fireEvent.click(screen.getByRole('button', { name: 'Central Clinic' }));
    const singleFacilityDialog = screen.getByRole('dialog', { name: 'Switch facility' });
    fireEvent.click(within(singleFacilityDialog).getByRole('radio', { name: 'General' }));
    fireEvent.click(within(singleFacilityDialog).getByRole('button', { name: 'Switch facility' }));
    expect(state.post).toHaveBeenCalledWith('/facility-context', { mode: 'general' });
    unmount();

    // Several assigned facilities: button opens the dedicated switch dialog.
    state.auth.facilities = both;
    state.auth.activeFacility = 'facility-1';
    state.auth.contextMode = 'facility';
    render(<FacilitySwitcher />);
    fireEvent.click(screen.getByRole('button', { name: 'Central Clinic' }));

    const dialog = screen.getByRole('dialog', { name: 'Switch facility' });
    expect(within(dialog).getByRole('radio', { name: 'Central Clinic (current)' })).toBeChecked();
    expect(within(dialog).getByRole('radio', { name: 'General' })).toBeInTheDocument();
    expect(within(dialog).getByRole('button', { name: 'Switch facility' })).toBeDisabled();

    fireEvent.click(within(dialog).getByRole('radio', { name: 'West Clinic' }));
    const confirm = within(dialog).getByRole('button', { name: 'Switch facility' });
    expect(confirm).toBeEnabled();
    fireEvent.click(confirm);

    expect(state.post).toHaveBeenCalledWith('/facility-context', { facility_id: 'facility-2' });
  });

  it('prompts for a facility when General is unavailable and none is active', () => {
    installResizeObserver();
    state.post.mockClear();
    state.auth.facilities = [
      { id: 'facility-1', name: 'Central Clinic' },
      { id: 'facility-2', name: 'West Clinic' },
    ];
    state.auth.activeFacility = null;
    state.auth.contextMode = 'unresolved';
    state.auth.canUseGeneral = false;

    render(<FacilitySwitcher />);
    fireEvent.click(screen.getByRole('button', { name: 'Select facility' }));

    const dialog = screen.getByRole('dialog', { name: 'Switch facility' });
    const central = within(dialog).getByRole('radio', { name: 'Central Clinic' });
    expect(central).not.toBeChecked();
    expect(within(dialog).getByRole('button', { name: 'Switch facility' })).toBeDisabled();

    fireEvent.click(within(dialog).getByRole('radio', { name: 'West Clinic' }));
    fireEvent.click(within(dialog).getByRole('button', { name: 'Switch facility' }));
    expect(state.post).toHaveBeenCalledWith('/facility-context', { facility_id: 'facility-2' });
  });

  it('renders primary tasks as right-side icon buttons with facility context on the left', () => {
    installResizeObserver();
    state.post.mockClear();
    state.visit.mockClear();
    const navigation = workspaceNavigation('/workspaces/patient', canWith(...allPermissions));
    render(
      <AppHeader
        appName="AfyaNova"
        userName="Amina"
        menuExpanded={false}
        onMenuClick={vi.fn()}
        hasContextualNav={false}
        isPlatformSuperAdmin={false}
        primaryLinks={navigation.primaryLinks}
        workspaces={navigation.workspaces}
        appMenuItems={navigation.appMenuItems}
      />,
      { wrapper: CarbonThemeProvider },
    );

    const header = screen.getByRole('link', { name: 'AfyaNova' }).closest('header');
    if (!header) throw new Error('Header name is not in the header.');
    // Patients is available in the app menu; Register remains a header icon
    // shortcut in the utility cluster, not a Primary nav.
    expect(screen.queryByRole('navigation', { name: 'Primary' })).not.toBeInTheDocument();
    expect(header.querySelector('.afyanova-primary-link')).toBeNull();
    const searchBox = screen.getByRole('combobox', { name: 'Search patients' });
    if (!header.contains(searchBox)) throw new Error('Patient quick search is not in the header.');

    expect(screen.queryByRole('button', { name: 'Patients' })).not.toBeInTheDocument();
    const registerAction = screen.getByRole('button', { name: 'Register' });
    expect(registerAction).not.toHaveClass('cds--header__action--active');

    // Left-to-right order: facility context sits beside the app name, then
    // the expandable search first in the utility cluster (expanding grows
    // leftward without shoving the registration shortcut).
    const facilityButton = screen.getByRole('button', { name: 'Central Clinic' });
    expect(facilityButton).toBeInTheDocument();
    expect(facilityButton.compareDocumentPosition(searchBox) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    fireEvent.click(registerAction);
    expect(state.visit).toHaveBeenCalledWith('/workspaces/patient/register');

    const appMenu = screen.getByRole('button', { name: 'App menu' });
    expect(appMenu).toBeInTheDocument();
    fireEvent.click(appMenu);
    expect(screen.getByRole('link', { name: 'Dashboard' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Patients' })).toHaveAttribute('href', '/workspaces/patient');
    expect(screen.getByRole('link', { name: 'Appointments' })).toHaveAttribute('href', '/workspaces/appointments/calendar');
    const profileMenu = screen.getByRole('button', { name: 'Account menu for Amina' });
    expect(profileMenu).toBeInTheDocument();
    expect(profileMenu.querySelector('svg')).toBeInTheDocument();
    expect(profileMenu).not.toHaveTextContent('Account menu for Amina');
    expect(screen.queryByRole('link', { name: 'My sessions' })).not.toBeInTheDocument();
    fireEvent.click(profileMenu);
    expect(screen.getByRole('menuitem', { name: 'Amina' })).toBeInTheDocument();
    expect(screen.getByRole('menuitem', { name: 'My sessions' })).toBeInTheDocument();
    expect(screen.getByRole('menuitem', { name: 'Log out' })).toBeInTheDocument();
    fireEvent.click(screen.getByRole('menuitem', { name: /Appearance/ }));
    fireEvent.click(screen.getByRole('menuitem', { name: 'Gray 90' }));
    expect(document.documentElement).toHaveClass('cds--g90');
    expect(window.localStorage.getItem('afyanova-theme')).toBe('g90');
  });

  it('keeps HR navigation in the app menu instead of duplicating header actions', () => {
    installResizeObserver();
    const directory = workspaceNavigation(
      '/workspaces/hr/staff',
      canWith(
        permissions.hr.staffRead,
        permissions.hr.personManage,
        permissions.hr.staffManage,
        permissions.hr.assignmentManage,
      ),
    );
    expect(directory.primaryLinks.map(({ id }) => id)).not.toContain('primary-staff');
    expect(directory.primaryLinks.map(({ id }) => id)).not.toContain('primary-add-staff');

    render(
      <AppHeader
        appName="AfyaNova"
        userName="Amina"
        menuExpanded={false}
        onMenuClick={vi.fn()}
        hasContextualNav={false}
        primaryLinks={directory.primaryLinks}
        workspaces={directory.workspaces}
        appMenuItems={directory.appMenuItems}
      />,
      { wrapper: CarbonThemeProvider },
    );

    fireEvent.click(screen.getByRole('button', { name: 'App menu' }));
    expect(screen.getByRole('link', { name: 'Staff directory' })).toHaveAttribute('href', '/workspaces/hr/staff');
    expect(screen.getByRole('link', { name: 'Add staff' })).toHaveAttribute('href', '/workspaces/hr/staff/create');
    expect(screen.queryByRole('button', { name: 'Staff' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Add staff' })).not.toBeInTheDocument();
  });

  it('shows an icon beside every HR destination in the app menu', () => {
    installResizeObserver();
    const hrPermissions = [
      permissions.hr.staffRead,
      permissions.hr.staffManage,
      permissions.hr.personManage,
      permissions.hr.assignmentManage,
      permissions.hr.staffUserLinkManage,
      permissions.hr.positionManage,
    ];
    for (const permission of hrPermissions) state.auth.can[permission] = true;
    const navigation = workspaceNavigation('/workspaces/hr/staff', canWith(...hrPermissions));

    render(
      <AppHeader
        appName="AfyaNova"
        userName="Amina"
        menuExpanded={false}
        onMenuClick={vi.fn()}
        hasContextualNav={false}
        primaryLinks={navigation.primaryLinks}
        workspaces={navigation.workspaces}
        appMenuItems={navigation.appMenuItems}
      />,
      { wrapper: CarbonThemeProvider },
    );

    fireEvent.click(screen.getByRole('button', { name: 'App menu' }));
    for (const item of navigation.appMenuItems.filter(({ id }) => id.startsWith('hr-'))) {
      expect(screen.getByRole('link', { name: item.label }).querySelector('svg')).not.toBeNull();
    }
  });

  it('keeps Patients in the app menu instead of rendering a header icon button', () => {
    installResizeObserver();
    const readOnly = workspaceNavigation('/workspaces/patient', canWith(permissions.patient.read));
    const { unmount } = render(
      <AppHeader
        appName="AfyaNova"
        userName="Amina"
        menuExpanded={false}
        onMenuClick={vi.fn()}
        hasContextualNav={false}
        isPlatformSuperAdmin={false}
        primaryLinks={readOnly.primaryLinks}
        workspaces={readOnly.workspaces}
        appMenuItems={readOnly.appMenuItems}
      />,
      { wrapper: CarbonThemeProvider },
    );

    expect(screen.queryByRole('button', { name: 'Patients' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Register' })).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'App menu' }));
    expect(screen.getByRole('link', { name: 'Patients' })).toHaveAttribute('href', '/workspaces/patient');
    unmount();
  });

  it('shows Admin in the app menu and permission-filtered destinations in the contextual sidebar', () => {
    installResizeObserver();
    const navigation = workspaceNavigation('/workspaces/admin/organization', canWith(
      permissions.admin.workspaceAccess,
      permissions.organization.facilitiesRead,
    ));
    render(
      <AppHeader
        appName="AfyaNova"
        userName="Amina"
        menuExpanded={false}
        onMenuClick={vi.fn()}
        hasContextualNav={navigation.sidebar.length > 0}
        isPlatformSuperAdmin={false}
        primaryLinks={navigation.primaryLinks}
        workspaces={navigation.workspaces}
        appMenuItems={navigation.appMenuItems}
      />,
      { wrapper: CarbonThemeProvider },
    );

    expect(navigation.workspaces.map((item) => item.label)).toEqual(['Admin']);
    expect(navigation.appMenuItems.map((item) => item.label)).toEqual(['Admin']);
    expect(navigation.sidebar.map((item) => item.label)).toEqual(['Overview', 'Organization & facilities']);
    expect(screen.getByRole('link', { name: 'AfyaNova' })).toHaveAttribute('href', '/workspaces/admin');
    expect(screen.getByRole('button', { name: 'Open contextual navigation' })).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'App menu' }));
    expect(screen.getByRole('list', { name: 'App navigation' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Admin' })).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Organization & facilities' })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Lab Test Setup Access' })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Audit log' })).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('link', { name: 'Admin' }));
    expect(state.visit).toHaveBeenCalledWith('/workspaces/admin');
  });

  it('keeps Admin in the app menu after navigating to Dashboard', () => {
    const permissionsForFacilityAdmin = [
      permissions.admin.workspaceAccess,
      permissions.organization.facilitiesRead,
      permissions.identity.assignmentsRead,
      permissions.patient.read,
      permissions.scheduling.access,
    ];
    const adminNavigation = workspaceNavigation(
      '/workspaces/admin/organization',
      canWith(...permissionsForFacilityAdmin),
    );
    const dashboardNavigation = workspaceNavigation(
      '/dashboard',
      canWith(...permissionsForFacilityAdmin),
    );

    expect(dashboardNavigation.appMenuItems).toEqual(
      expect.arrayContaining([
        expect.objectContaining({ id: 'dashboard', label: 'Dashboard', active: true }),
        expect.objectContaining({ id: 'admin', label: 'Admin', active: false }),
      ]),
    );
    expect(dashboardNavigation.appMenuItems.map(({ id }) => id)).toEqual(
      adminNavigation.appMenuItems.map(({ id }) => id),
    );
    expect(dashboardNavigation.appMenuItems.find((item) => item.id === 'admin')?.active).toBe(false);
    expect(adminNavigation.appMenuItems.find((item) => item.id === 'admin')?.active).toBe(true);
    expect(adminNavigation.sidebar.find((item) => item.id === 'admin-organization')?.active).toBe(true);
  });

  it('renders both Dashboard and Admin in the dashboard app menu', () => {
    installResizeObserver();
    const navigation = workspaceNavigation('/dashboard', canWith(
      permissions.admin.workspaceAccess,
      permissions.organization.facilitiesRead,
      permissions.identity.assignmentsRead,
      permissions.patient.read,
    ));
    render(
      <AppHeader
        appName="AfyaNova"
        userName="Amina"
        menuExpanded={false}
        onMenuClick={vi.fn()}
        hasContextualNav={false}
        primaryLinks={navigation.primaryLinks}
        workspaces={navigation.workspaces}
        appMenuItems={navigation.appMenuItems}
      />,
      { wrapper: CarbonThemeProvider },
    );

    fireEvent.click(screen.getByRole('button', { name: 'App menu' }));
    expect(screen.getByRole('link', { name: 'Dashboard' })).toHaveAttribute('href', '/dashboard');
    expect(screen.getByRole('link', { name: 'Admin' })).toHaveAttribute('href', '/workspaces/admin');
    expect(screen.queryByRole('link', { name: 'Admin overview' })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Organization & facilities' })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Role approvals' })).not.toBeInTheDocument();
  });

  it('routes Switcher selections through the visit endpoint and closes the panel', () => {
    installResizeObserver();
    state.visit.mockClear();
    const navigation = workspaceNavigation('/workspaces/patient', canWith(...allPermissions));
    render(
      <AppHeader
        appName="AfyaNova"
        userName="Amina"
        menuExpanded={false}
        onMenuClick={vi.fn()}
        hasContextualNav={false}
        isPlatformSuperAdmin={false}
        primaryLinks={navigation.primaryLinks}
        workspaces={navigation.workspaces}
        appMenuItems={navigation.appMenuItems}
      />,
      { wrapper: CarbonThemeProvider },
    );

    fireEvent.click(screen.getByRole('button', { name: 'App menu' }));
    fireEvent.click(screen.getByRole('link', { name: 'Appointments' }));
    expect(state.visit).toHaveBeenCalledWith('/workspaces/appointments/calendar');
  });

  it('links the platform logo and app switcher to Inertia platform routes', () => {
    installResizeObserver();
    state.visit.mockClear();
    const navigation = workspaceNavigation('/platform-admin', canWith(), true);
    render(
      <AppHeader
        appName="AfyaNova"
        userName="Platform Operator"
        menuExpanded={false}
        onMenuClick={vi.fn()}
        hasContextualNav={false}
        isPlatformSuperAdmin
        primaryLinks={navigation.primaryLinks}
        workspaces={navigation.workspaces}
        appMenuItems={navigation.appMenuItems}
      />,
      { wrapper: CarbonThemeProvider },
    );

    expect(screen.getByRole('link', { name: 'AfyaNova' })).toHaveAttribute('href', '/platform-admin');
    expect(screen.queryByRole('button', { name: 'Create tenant' })).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'App menu' }));
    expect(screen.getByRole('link', { name: 'Dashboard' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Tenant registry' })).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Create tenant' })).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('link', { name: 'Tenant registry' }));
    expect(state.visit).toHaveBeenCalledWith('/platform-admin/tenants');
  });
});
