import { Select, SelectItem, TextInput } from '@carbon/react';
import { useForm } from '@inertiajs/react';
import { useState, type FormEvent, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import { DockedPanel } from '@/components/panels';

export interface Facility {
  id: string;
  name: string;
  code: string;
  type: string;
  careLevel: string;
  ownership: string;
  status: string;
  organizationName: string;
  capabilityCount: number;
  enabledCapabilityCount: number;
}

export interface Department {
  id: string;
  name: string;
  code: string;
  kind: string | null;
  status: string;
}

export interface Unit {
  id: string;
  departmentId: string;
  name: string;
  code: string;
  type: string;
  status: string;
}

export interface Room {
  id: string;
  unitId: string | null;
  name: string;
  code: string;
  type: string;
  status: string;
}

export interface FacilityPanelData {
  facility: Facility;
  departments: Department[];
  units: Unit[];
  rooms: Room[];
  canManageStructure: boolean;
}

export type StructureFormMode = 'department' | 'unit' | 'room' | 'edit-department' | 'edit-unit' | 'edit-room';
export type StructureTarget = { kind: 'department' | 'unit' | 'room'; id: string; name: string; status: string };

export function AdminOrganizationFacilityPanel({
  data,
  initialMode,
  target,
  initialParentId,
  onClose,
  onRoomSaved,
}: {
  data: FacilityPanelData;
  initialMode: StructureFormMode;
  target?: StructureTarget;
  initialParentId?: string;
  onClose: () => void;
  onRoomSaved?: (unitId: string | null) => void;
}): ReactNode {
  const { facility, departments, units, rooms } = data;
  const [mode] = useState(initialMode);
  const isEditing = mode.startsWith('edit-');
  const formMode = isEditing ? mode.slice(5) : mode;
  const targetDepartment = target?.kind === 'department' ? departments.find((item) => item.id === target.id) : undefined;
  const targetUnit = target?.kind === 'unit' ? units.find((item) => item.id === target.id) : undefined;
  const targetRoom = target?.kind === 'room' ? rooms.find((item) => item.id === target.id) : undefined;
  const departmentForm = useForm({
    facility_id: facility.id,
    name: targetDepartment?.name ?? '',
    code: targetDepartment?.code ?? '',
    kind: targetDepartment?.kind ?? '',
  });
  const unitForm = useForm({
    facility_id: facility.id,
    department_id: targetUnit?.departmentId ?? initialParentId ?? departments.find((item) => item.status === 'ACTIVE')?.id ?? '',
    name: targetUnit?.name ?? '',
    code: targetUnit?.code ?? '',
    unit_type: targetUnit?.type ?? 'GENERIC',
  });
  const roomForm = useForm({
    facility_id: facility.id,
    unit_id: targetRoom?.unitId ?? initialParentId ?? '',
    name: targetRoom?.name ?? '',
    code: targetRoom?.code ?? '',
    room_type: targetRoom?.type ?? 'GENERIC',
  });
  const form = formMode === 'department' ? departmentForm : formMode === 'unit' ? unitForm : roomForm;
  const assignedUnit = units.find((item) => item.id === roomForm.data.unit_id);
  const heading = formMode === 'room' && !isEditing
    ? assignedUnit ? `Add room to ${assignedUnit.name}` : 'Add unassigned room'
    : `${isEditing ? 'Edit' : 'Add'} ${formMode}`;
  const activeDepartments = departments.filter((item) => item.status === 'ACTIVE');

  function submitDepartment(event: FormEvent): void {
    event.preventDefault();
    const options = { preserveState: true, preserveScroll: true, onSuccess: onClose };
    if (target && mode === 'edit-department') {
      departmentForm.put(routes.workspaces.adminOrganizationStructureRecord('departments', target.id), options);
    } else {
      departmentForm.post(routes.workspaces.adminOrganizationDepartments, options);
    }
  }

  function submitUnit(event: FormEvent): void {
    event.preventDefault();
    const options = { preserveState: true, preserveScroll: true, onSuccess: onClose };
    if (target && mode === 'edit-unit') {
      unitForm.put(routes.workspaces.adminOrganizationStructureRecord('units', target.id), options);
    } else {
      unitForm.post(routes.workspaces.adminOrganizationUnits, options);
    }
  }

  function submitRoom(event: FormEvent): void {
    event.preventDefault();
    const options = {
      preserveState: true,
      preserveScroll: true,
      onSuccess: () => {
        onRoomSaved?.(roomForm.data.unit_id || null);
        onClose();
      },
    };
    if (target && mode === 'edit-room') {
      roomForm.put(routes.workspaces.adminOrganizationStructureRecord('rooms', target.id), options);
    } else {
      roomForm.post(routes.workspaces.adminOrganizationRooms, options);
    }
  }

  return (
    <DockedPanel
      heading={heading}
      onClose={onClose}
      onSubmit={formMode === 'department' ? submitDepartment : formMode === 'unit' ? submitUnit : submitRoom}
      primaryLabel={form.processing
        ? 'Saving'
        : formMode === 'room' && !isEditing
          ? assignedUnit ? `Add room to ${assignedUnit.name}` : 'Add unassigned room'
          : `${isEditing ? 'Save' : 'Add'} ${formMode}`}
      primaryDisabled={form.processing}
      secondaryLabel="Cancel"
      onSecondary={onClose}
      width="min(32rem, 100%)"
    >
      {formMode === 'department' ? (
        <div className="admin-organization-panel__form">
          <p>{isEditing ? `Update the department in ${facility.name}.` : `Add an active department to ${facility.name}.`}</p>
          <TextInput id="department-name" labelText="Department name" value={departmentForm.data.name}
            onChange={(event) => departmentForm.setData('name', event.currentTarget.value)}
            invalid={Boolean(departmentForm.errors.name)} invalidText={departmentForm.errors.name} required maxLength={255} />
          <TextInput id="department-code" labelText="Department code"
            helperText="Must be unique in this facility. Use uppercase letters, numbers, hyphens, or underscores."
            value={departmentForm.data.code} onChange={(event) => departmentForm.setData('code', event.currentTarget.value)}
            invalid={Boolean(departmentForm.errors.code)} invalidText={departmentForm.errors.code} required maxLength={64} />
          <TextInput id="department-kind" labelText="Kind (optional)" value={departmentForm.data.kind}
            onChange={(event) => departmentForm.setData('kind', event.currentTarget.value)}
            invalid={Boolean(departmentForm.errors.kind)} invalidText={departmentForm.errors.kind} maxLength={100} />
        </div>
      ) : formMode === 'unit' ? (
        <div className="admin-organization-panel__form">
          <p>{isEditing ? `Update the unit in ${facility.name}.` : `Add an active unit to ${facility.name}.`}</p>
          {activeDepartments.length === 0 && !isEditing ? <p>Create an active department before adding a unit.</p> : (
            <>
              <Select id="unit-department" labelText="Department" value={unitForm.data.department_id}
                onChange={(event) => unitForm.setData('department_id', event.currentTarget.value)}
                invalid={Boolean(unitForm.errors.department_id)} invalidText={unitForm.errors.department_id} required>
                {departments.filter((item) => item.status === 'ACTIVE' || (isEditing && item.id === unitForm.data.department_id))
                  .map((item) => <SelectItem key={item.id} value={item.id} text={`${item.name} (${item.code})`} />)}
              </Select>
              <TextInput id="unit-name" labelText="Unit name" value={unitForm.data.name}
                onChange={(event) => unitForm.setData('name', event.currentTarget.value)}
                invalid={Boolean(unitForm.errors.name)} invalidText={unitForm.errors.name} required maxLength={255} />
              <TextInput id="unit-code" labelText="Unit code"
                helperText="Must be unique in this facility. Use uppercase letters, numbers, hyphens, or underscores."
                value={unitForm.data.code} onChange={(event) => unitForm.setData('code', event.currentTarget.value)}
                invalid={Boolean(unitForm.errors.code)} invalidText={unitForm.errors.code} required maxLength={64} />
              <TextInput id="unit-type" labelText="Unit type" value={unitForm.data.unit_type}
                onChange={(event) => unitForm.setData('unit_type', event.currentTarget.value)}
                invalid={Boolean(unitForm.errors.unit_type)} invalidText={unitForm.errors.unit_type} required maxLength={100} />
            </>
          )}
        </div>
      ) : (
        <div className="admin-organization-panel__form">
          <p>
            {assignedUnit
              ? `Assigned to ${assignedUnit.name}.`
              : 'Unassigned at facility level.'}
          </p>
          <Select id="room-unit" labelText="Assigned unit (optional)" value={roomForm.data.unit_id}
            onChange={(event) => roomForm.setData('unit_id', event.currentTarget.value)}
            invalid={Boolean(roomForm.errors.unit_id)} invalidText={roomForm.errors.unit_id}>
            <SelectItem value="" text="Unassigned (facility level)" />
            {units.filter((item) => item.status === 'ACTIVE' || (isEditing && item.id === roomForm.data.unit_id))
              .map((item) => {
                const department = departments.find((parent) => parent.id === item.departmentId);
                return <SelectItem key={item.id} value={item.id} text={`${department?.name ?? 'Department'} / ${item.name}`} />;
              })}
          </Select>
          <TextInput id="room-name" labelText="Room name" value={roomForm.data.name}
            onChange={(event) => roomForm.setData('name', event.currentTarget.value)}
            invalid={Boolean(roomForm.errors.name)} invalidText={roomForm.errors.name} required maxLength={255} />
          <TextInput id="room-code" labelText="Room code"
            helperText="Must be unique in this facility. Use uppercase letters, numbers, hyphens, or underscores."
            value={roomForm.data.code} onChange={(event) => roomForm.setData('code', event.currentTarget.value)}
            invalid={Boolean(roomForm.errors.code)} invalidText={roomForm.errors.code} required maxLength={64} />
          <TextInput id="room-type" labelText="Room type" value={roomForm.data.room_type}
            onChange={(event) => roomForm.setData('room_type', event.currentTarget.value)}
            invalid={Boolean(roomForm.errors.room_type)} invalidText={roomForm.errors.room_type} required maxLength={100} />
        </div>
      )}
    </DockedPanel>
  );
}
