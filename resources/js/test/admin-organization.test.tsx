import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import AdminOrganizationIndex from '@/pages/Admin/Organization/Index';

const { router, formPost, formPut } = vi.hoisted(() => ({
  router: { get: vi.fn(), post: vi.fn() },
  formPost: vi.fn(),
  formPut: vi.fn(),
}));

vi.mock('@inertiajs/react', async () => {
  const { useState } = await import('react');
  return {
    Link: ({ children, href }: { children: React.ReactNode; href: string }) => <a href={href}>{children}</a>,
    router,
    useForm: (initialData: Record<string, string>) => {
      const [data, setState] = useState(initialData);
      return {
        data,
        errors: {},
        processing: false,
        setData: (key: string, value: string) => setState((current) => ({ ...current, [key]: value })),
        post: (url: string, options: Record<string, unknown>) => formPost(url, data, options),
        put: (url: string, options: Record<string, unknown>) => formPut(url, data, options),
        reset: vi.fn(),
      };
    },
  };
});

vi.mock('@/layouts/AppLayout/AppLayout', () => ({
  AppLayout: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

const facility = {
  id: 'facility-1',
  name: 'Central Clinic',
  code: 'CENTRAL',
  type: 'DISPENSARY',
  careLevel: 'PRIMARY',
  ownership: 'PUBLIC',
  status: 'ACTIVE',
  organizationName: 'Central Health',
  capabilityCount: 3,
  enabledCapabilityCount: 2,
};

const rooms: {
  id: string;
  unitId: string | null;
  name: string;
  code: string;
  type: string;
  status: string;
}[] = [
  { id: 'room-1', unitId: 'unit-1', name: 'Triage Room', code: 'TRIAGE_ROOM', type: 'CONSULTATION', status: 'ACTIVE' },
];

const props = {
  facilities: [facility],
  facilityStructures: {
    [facility.id]: {
      facility,
      canManageStructure: true,
      canManageCapabilities: true,
      departments: [{ id: 'dept-1', name: 'Outpatient', code: 'OUTPATIENT', kind: 'CLINICAL', status: 'ACTIVE' }],
      units: [{ id: 'unit-1', departmentId: 'dept-1', name: 'Triage', code: 'TRIAGE', type: 'GENERIC', status: 'ACTIVE' }],
      rooms,
      capabilities: [
        { code: 'PHARMACY', name: 'Pharmacy', category: 'MEDICATION', state: 'ENABLED', suspendedReason: null },
        { code: 'LABORATORY', name: 'Laboratory', category: 'DIAGNOSTICS', state: null, suspendedReason: null },
      ],
    },
  },
  canCreateFacility: true,
  facilityTypes: [{ code: 'DISPENSARY', name: 'Dispensary' }],
  careLevels: [{ code: 'PRIMARY', name: 'Community / Primary' }],
  ownershipTypes: [{ code: 'PUBLIC', name: 'Public' }],
  totalFacilities: 1,
  activeFacilities: 1,
};

describe('Admin organization facility workspace', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.stubGlobal('ResizeObserver', class {
      observe(): void {}
      unobserve(): void {}
      disconnect(): void {}
    });
    vi.stubGlobal('matchMedia', vi.fn(() => ({
      matches: false,
      media: '',
      onchange: null,
      addListener: vi.fn(),
      removeListener: vi.fn(),
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
      dispatchEvent: vi.fn(),
    })));
  });
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  function expandTreeNode(id: string): void {
    const toggle = document.getElementById(id)?.querySelector('.cds--tree-parent-node__toggle');
    if (!toggle) throw new Error(`Missing expand control: ${id}`);
    fireEvent.click(toggle);
  }

  function openLocations(
    structure: Partial<(typeof props.facilityStructures)[string]> = {},
  ): void {
    render(
      <AdminOrganizationIndex
        {...props}
        facilityStructures={{
          ...props.facilityStructures,
          [facility.id]: { ...props.facilityStructures[facility.id]!, ...structure },
        }}
      />,
    );
    fireEvent.click(screen.getByRole('button', { name: /locations$/ }));
  }

  function openServices(
    structure: Partial<(typeof props.facilityStructures)[string]> = {},
  ): void {
    render(
      <AdminOrganizationIndex
        {...props}
        facilityStructures={{
          ...props.facilityStructures,
          [facility.id]: { ...props.facilityStructures[facility.id]!, ...structure },
        }}
      />,
    );
    fireEvent.click(screen.getByRole('button', { name: /services$/ }));
  }

  it('renders the facility directory as a Carbon DataTable below the summary cards', () => {
    render(<AdminOrganizationIndex {...props} />);

    expect(screen.getByRole('region', { name: 'Facilities' })).toBeInTheDocument();
    expect(screen.getByRole('table', { name: '1 facility' })).toBeInTheDocument();
    expect(screen.getByRole('columnheader', { name: 'Facility' })).toBeInTheDocument();
    expect(screen.getByRole('columnheader', { name: 'Departments · units · rooms' })).toBeInTheDocument();
    const summary = screen.getByRole('region', { name: 'Facility summary' });
    const directory = screen.getByRole('region', { name: 'Facilities' });
    expect(summary.compareDocumentPosition(directory) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Manage locations' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Manage services' })).toBeInTheDocument();
    expect(screen.getByText('1 department · 1 unit · 1 room')).toBeInTheDocument();
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  });

  it('opens locations and services in separate panels from their own actions', () => {
    render(<AdminOrganizationIndex {...props} />);

    fireEvent.click(screen.getByRole('button', { name: 'Manage locations' }));
    expect(screen.getByRole('dialog', { name: 'Locations at Central Clinic' })).toBeInTheDocument();
    expect(screen.queryByRole('dialog', { name: 'Services at Central Clinic' })).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Manage services' }));
    expect(screen.getByRole('dialog', { name: 'Services at Central Clinic' })).toBeInTheDocument();
    expect(screen.queryByRole('dialog', { name: 'Locations at Central Clinic' })).not.toBeInTheDocument();
  });

  it('opens the location details in the Workspace panel from the facility directory', () => {
    openLocations();

    expect(router.get).not.toHaveBeenCalled();
    expect(screen.getByRole('dialog', { name: 'Locations at Central Clinic' })).toBeInTheDocument();
    expect(screen.queryByText('Location structure')).not.toBeInTheDocument();
    expect(screen.queryByText('Facility locations')).not.toBeInTheDocument();
    expect(screen.getByRole('tree', { name: 'Facility locations' })).toBeInTheDocument();
  });

  it('opens the Workspace panel for a facility selected by an existing location link', () => {
    render(
      <AdminOrganizationIndex
        {...props}
        selectedFacility={props.facilityStructures[facility.id]}
      />,
    );

    expect(screen.getByRole('dialog', { name: 'Locations at Central Clinic' })).toBeInTheDocument();
  });

  it('opens the add-unit form within the selected department context', () => {
    openLocations();
    const locationsPanel = screen.getByRole('dialog', { name: 'Locations at Central Clinic' });
    fireEvent.click(screen.getByRole('button', { name: 'Add unit to Outpatient' }));

    expect(screen.getByRole('dialog', { name: 'Add unit' })).toHaveStyle({ width: 'min(32rem, 100%)' });
    expect(screen.getByLabelText('Department')).toHaveValue('dept-1');
    expect(document.querySelector('[aria-label="Locations at Central Clinic"]')).toBe(locationsPanel);
    expect(locationsPanel).toHaveAttribute('aria-hidden', 'true');
    expect(locationsPanel).toHaveStyle({ display: 'none' });
  });

  it('opens a department editor prefilled with current values and submits an update', () => {
    openLocations();

    fireEvent.click(screen.getByRole('button', { name: 'Edit department: Outpatient' }));
    expect(screen.getByRole('dialog', { name: 'Edit department' })).toBeInTheDocument();
    expect(screen.getByLabelText('Department name')).toHaveValue('Outpatient');
    expect(screen.getByLabelText('Department code')).toHaveValue('OUTPATIENT');
    fireEvent.click(screen.getByRole('button', { name: 'Save department' }));
    expect(formPut).toHaveBeenCalledWith(
      '/workspaces/admin/organization/departments/dept-1',
      expect.objectContaining({ name: 'Outpatient', code: 'OUTPATIENT', kind: 'CLINICAL' }),
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('confirms archiving a department and posts its inactive status', () => {
    vi.stubGlobal('ResizeObserver', class {
      observe(): void {}
      unobserve(): void {}
      disconnect(): void {}
    });
    openLocations();

    fireEvent.click(screen.getByRole('button', { name: 'Archive department: Outpatient' }));
    expect(screen.getByRole('dialog', { name: 'Archive department?' })).toBeInTheDocument();
    fireEvent.click(within(screen.getByRole('dialog', { name: 'Archive department?' })).getByRole('button', { name: 'Archive' }));

    expect(router.post).toHaveBeenCalledWith(
      '/workspaces/admin/organization/departments/dept-1/status',
      { facility_id: facility.id, status: 'INACTIVE' },
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('enables and submits a unit after all required fields are entered', () => {
    openLocations();
    fireEvent.click(screen.getByRole('button', { name: 'Add unit to Outpatient' }));
    const addUnitButton = within(screen.getByRole('dialog', { name: 'Add unit' })).getByRole('button', { name: 'Add unit' });
    expect(addUnitButton).toBeEnabled();
    expect(screen.getByLabelText('Department')).toBeRequired();
    expect(screen.getByLabelText('Unit name')).toBeRequired();
    expect(screen.getByLabelText('Unit code')).toBeRequired();
    expect(screen.getByLabelText('Unit type')).toBeRequired();

    fireEvent.change(screen.getByLabelText('Department'), { target: { value: 'dept-1' } });
    fireEvent.change(screen.getByLabelText('Unit name'), { target: { value: 'Consultation' } });
    fireEvent.change(screen.getByLabelText('Unit code'), { target: { value: 'CONSULTATION' } });
    fireEvent.change(screen.getByLabelText('Unit type'), { target: { value: 'CLINICAL' } });

    expect(addUnitButton).toBeEnabled();
    fireEvent.click(addUnitButton);
    expect(formPost).toHaveBeenCalledWith(
      '/workspaces/admin/organization/units',
      expect.objectContaining({
        facility_id: facility.id,
        department_id: 'dept-1',
        name: 'Consultation',
        code: 'CONSULTATION',
        unit_type: 'CLINICAL',
      }),
      expect.objectContaining({ preserveState: true, preserveScroll: true }),
    );
  });

  it('submits a room to its selected unit and reopens that unit after success', () => {
    openLocations();
    const locationsPanel = document.querySelector('[aria-label="Locations at Central Clinic"]');
    expandTreeNode('department-dept-1');
    fireEvent.click(screen.getByRole('button', { name: 'Add room to Triage' }));

    fireEvent.change(screen.getByLabelText('Room name'), { target: { value: 'Consultation Room 2' } });
    fireEvent.change(screen.getByLabelText('Room code'), { target: { value: 'CONSULTATION_2' } });
    fireEvent.change(screen.getByLabelText('Room type'), { target: { value: 'CONSULTATION' } });
    fireEvent.click(screen.getByRole('button', { name: 'Add room to Triage' }));

    expect(formPost).toHaveBeenCalledWith(
      '/workspaces/admin/organization/rooms',
      expect.objectContaining({
        facility_id: facility.id,
        unit_id: 'unit-1',
        name: 'Consultation Room 2',
        code: 'CONSULTATION_2',
        room_type: 'CONSULTATION',
      }),
      expect.objectContaining({ preserveState: true, preserveScroll: true, onSuccess: expect.any(Function) }),
    );

    const options = formPost.mock.calls[0]![2] as { onSuccess: () => void };
    act(() => options.onSuccess());
    expect(screen.queryByRole('dialog', { name: 'Add room to Triage' })).not.toBeInTheDocument();
    expect(document.getElementById('unit-unit-1')).toHaveAttribute('aria-expanded', 'true');
    expect(document.querySelector('[aria-label="Locations at Central Clinic"]')).toBe(locationsPanel);
    expect(locationsPanel).not.toHaveAttribute('aria-hidden', 'true');
  });

  it('keeps structure actions visible but prevents adding a unit before a department exists', () => {
    openLocations({ departments: [], units: [], rooms: [] });

    expect(screen.getByRole('button', { name: 'Add department' })).toBeEnabled();
    expect(screen.getByRole('button', { name: 'Add unassigned room' })).toBeInTheDocument();
  });

  it('does not show structure management actions without structure permission', () => {
    openLocations({ canManageStructure: false });

    expect(screen.queryByRole('button', { name: 'Add department' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Add unit to Outpatient' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Add room to Triage' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Add unassigned room' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Edit department: Outpatient' })).not.toBeInTheDocument();
  });

  it('does not expose facility creation without organization-scoped authority', () => {
    render(<AdminOrganizationIndex {...props} canCreateFacility={false} />);

    expect(screen.queryByRole('button', { name: 'Add facility' })).not.toBeInTheDocument();
    expect(screen.getByRole('region', { name: 'Facilities' })).toBeInTheDocument();
  });

  it('opens facility creation in the shared workspace panel', () => {
    render(<AdminOrganizationIndex {...props} />);

    fireEvent.click(screen.getByRole('button', { name: 'Add facility' }));

    expect(screen.getByRole('dialog', { name: 'Add facility' })).toBeInTheDocument();
    expect(screen.getByLabelText('Facility name')).toBeInTheDocument();
    expect(within(screen.getByRole('dialog', { name: 'Add facility' }))
      .getByRole('button', { name: 'Add facility' }).closest('.afy-dock__form-actions')).not.toBeNull();
  });

  it('filters the directory by facility name and code', () => {
    render(<AdminOrganizationIndex {...props} />);

    fireEvent.change(screen.getByRole('searchbox', { name: 'Search facilities' }), {
      target: { value: 'no-such-facility' },
    });

    expect(screen.getByRole('heading', { name: 'No facilities match your search' })).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'View' })).not.toBeInTheDocument();
  });

  it('organizes units and rooms in a uniform nested hierarchy', () => {
    openLocations();

    const department = document.getElementById('department-dept-1');
    const unit = document.getElementById('unit-unit-1');
    const room = document.getElementById('room-room-1');
    expect(department).not.toBeNull();
    expect(unit).not.toBeNull();
    expect(room).not.toBeNull();
    expect(department).toHaveAttribute('aria-owns', 'department-dept-1-subtree');
    expect(unit).toHaveAttribute('aria-owns', 'unit-unit-1-subtree');
    expect(room).toHaveAttribute('id', 'room-room-1');
    expect(department?.querySelector('.cds--tree-node__icon')).toBeInTheDocument();
    expect(unit?.querySelector('.cds--tree-node__icon')).toBeInTheDocument();
    expect(room?.querySelector('.cds--tree-node__icon')).toBeInTheDocument();
    expect(screen.getByRole('tree', { name: 'Facility locations' })).toBeInTheDocument();
    expect(within(department!).getByRole('button', { name: 'Edit department: Outpatient' })).toBeVisible();
    expect(within(department!).getByRole('button', { name: 'Archive department: Outpatient' })).toBeVisible();
  });

  it('searches room names and codes and expands the matching department and unit path', () => {
    openLocations();

    fireEvent.change(screen.getByRole('searchbox', { name: 'Search locations' }), {
      target: { value: 'TRIAGE_ROOM' },
    });

    expect(document.getElementById('department-dept-1')).toHaveAttribute('aria-expanded', 'true');
    expect(document.getElementById('unit-unit-1')).toHaveAttribute('aria-expanded', 'true');
    expect(document.getElementById('room-room-1')).toBeInTheDocument();
  });

  it('groups unit and room actions in Carbon ButtonSets at the end of their tree rows', () => {
    openLocations();
    expandTreeNode('department-dept-1');

    const unit = document.getElementById('unit-unit-1');
    expect(unit).not.toBeNull();
    const unitActionGroup = unit!.querySelector('.admin-organization__tree-actions');
    expect(unitActionGroup).not.toBeNull();
    const unitButtonSet = unitActionGroup!.querySelector('.cds--btn-set');
    expect(unitButtonSet).toBeInTheDocument();
    expect(unitButtonSet).toHaveClass('admin-organization__tree-button-set');
    const unitActions = Array.from(unitActionGroup!.querySelectorAll<HTMLButtonElement>('.cds--btn'));
    expect(unitActions).toHaveLength(3);
    expect(within(unitActionGroup as HTMLElement).getByRole('button', { name: 'Add room to Triage' })).toBeVisible();
    expect(within(unitActionGroup as HTMLElement).getByRole('button', { name: 'Edit unit: Triage' })).toHaveTextContent('Edit');
    expect(within(unitActionGroup as HTMLElement).getByRole('button', { name: 'Archive unit: Triage' })).toBeVisible();
    expect(within(unit!).queryByText('Edit Unit')).not.toBeInTheDocument();

    expandTreeNode('unit-unit-1');
    const room = document.getElementById('room-room-1');
    expect(room).not.toBeNull();
    const roomActionGroup = room!.querySelector('.admin-organization__tree-actions');
    expect(roomActionGroup).not.toBeNull();
    const roomButtonSet = roomActionGroup!.querySelector('.cds--btn-set');
    expect(roomButtonSet).toBeInTheDocument();
    expect(roomButtonSet).toHaveClass('admin-organization__tree-button-set');
    const roomActions = Array.from(roomActionGroup!.querySelectorAll<HTMLButtonElement>('.cds--btn'));
    expect(roomActions).toHaveLength(2);
    expect(within(roomActionGroup as HTMLElement).getByRole('button', { name: 'Edit room: Triage Room' })).toHaveTextContent('Edit');
    expect(within(roomActionGroup as HTMLElement).getByRole('button', { name: 'Archive room: Triage Room' })).toBeVisible();
    expect(within(room!).queryByText('Edit Room')).not.toBeInTheDocument();
  });

  it('prefills new units and rooms from the surrounding parent context', () => {
    openLocations();
    fireEvent.click(screen.getByRole('button', { name: 'Add unit to Outpatient' }));
    expect(screen.getByLabelText('Department')).toHaveValue('dept-1');
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));

    expandTreeNode('department-dept-1');
    fireEvent.click(screen.getByRole('button', { name: 'Add room to Triage' }));
    expect(screen.getByRole('dialog', { name: 'Add room to Triage' })).toBeInTheDocument();
    expect(screen.getByLabelText('Assigned unit (optional)')).toHaveValue('unit-1');
    expect(screen.getByText('Assigned to Triage.')).toBeInTheDocument();
  });

  it('keeps facility-level rooms available outside the department hierarchy', () => {
    openLocations({
      rooms: [
        ...props.facilityStructures[facility.id]!.rooms,
        { id: 'room-direct', unitId: null, name: 'Records Room', code: 'RECORDS', type: 'STORAGE', status: 'ACTIVE' },
      ],
    });

    expect(document.getElementById('room-room-direct')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Add unassigned room' }));
    expect(screen.getByRole('dialog', { name: 'Add unassigned room' })).toBeInTheDocument();
    expect(screen.getByLabelText('Assigned unit (optional)')).toHaveValue('');
    expect(screen.getByText('Unassigned at facility level.')).toBeInTheDocument();
  });

  it('opens the add department panel from the facility workspace', () => {
    openLocations();

    fireEvent.click(screen.getByRole('button', { name: 'Add department' }));
    expect(screen.getByRole('dialog', { name: 'Add department' })).toBeInTheDocument();
    expect(screen.getByLabelText('Department name')).toBeInTheDocument();
  });

  it('lists facility services with states in the services panel', () => {
    openServices();

    const services = screen.getByRole('region', { name: 'Services' });
    expect(within(services).getByText('Pharmacy')).toBeInTheDocument();
    expect(within(services).getByText('Laboratory')).toBeInTheDocument();
    expect(within(services).getByText('Enabled')).toBeInTheDocument();
    expect(within(services).getByText('Not configured')).toBeInTheDocument();
  });

  it('enables an unconfigured service with a direct action', () => {
    openServices();

    fireEvent.click(screen.getByRole('button', { name: 'Enable service: Laboratory' }));
    expect(router.post).toHaveBeenCalledWith(
      '/workspaces/admin/organization/capabilities/LABORATORY/status',
      { facility_id: 'facility-1', state: 'ENABLED' },
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('disables an enabled service with a direct action', () => {
    openServices();

    fireEvent.click(screen.getByRole('button', { name: 'Disable service: Pharmacy' }));
    expect(router.post).toHaveBeenCalledWith(
      '/workspaces/admin/organization/capabilities/PHARMACY/status',
      { facility_id: 'facility-1', state: 'DISABLED' },
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('suspends a service through a reason modal', () => {
    openServices();

    fireEvent.click(screen.getByRole('button', { name: 'Suspend service: Pharmacy' }));
    const dialog = screen.getByRole('dialog', { name: 'Suspend Pharmacy?' });
    expect(within(dialog).getByRole('button', { name: 'Suspend service' })).toBeDisabled();

    fireEvent.change(within(dialog).getByLabelText('Reason for suspension'), {
      target: { value: 'Stock audit in progress.' },
    });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Suspend service' }));
    expect(router.post).toHaveBeenCalledWith(
      '/workspaces/admin/organization/capabilities/PHARMACY/status',
      { facility_id: 'facility-1', state: 'SUSPENDED', reason: 'Stock audit in progress.' },
      expect.objectContaining({ preserveScroll: true }),
    );
  });

  it('hides service actions without capability permission', () => {
    openServices({ canManageCapabilities: false });

    const services = screen.getByRole('region', { name: 'Services' });
    expect(within(services).queryByRole('button', { name: /service:/ })).not.toBeInTheDocument();
    expect(within(services).getByText('Enabled')).toBeInTheDocument();
  });
});
