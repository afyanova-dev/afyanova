import { Add } from '@carbon/icons-react';
import {
  Button,
  Modal,
  Search,
  Select,
  SelectItem,
  TextInput,
  Tag,
} from '@carbon/react';
import { router, useForm } from '@inertiajs/react';
import { useMemo, useState, type FormEvent, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import { CarbonDataTable, EmptyState, PageHeader, StatisticsCards } from '@/components/ui';
import { DockedPanel } from '@/components/panels';
import { AppLayout } from '@/layouts/AppLayout/AppLayout';
import { AdminOrganizationFacilityPanel } from './Facility';
import { FacilityWorkspace } from './Workspace';

interface ReferenceOption {
  code: string;
  name: string;
}

interface Facility {
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

interface SelectedFacility {
  facility: Facility;
  departments: {
    id: string;
    name: string;
    code: string;
    kind: string | null;
    status: string;
  }[];
  units: {
    id: string;
    departmentId: string;
    name: string;
    code: string;
    type: string;
    status: string;
  }[];
  rooms: {
    id: string;
    unitId: string | null;
    name: string;
    code: string;
    type: string;
    status: string;
  }[];
  capabilities: {
    code: string;
    name: string;
    category: string;
    state: string | null;
    suspendedReason: string | null;
  }[];
  canManageStructure: boolean;
  canManageCapabilities: boolean;
}

type StructureFormMode = 'department' | 'unit' | 'room' | 'edit-department' | 'edit-unit' | 'edit-room';
type StructureTarget = {
  kind: 'department' | 'unit' | 'room';
  id: string;
  name: string;
  status: string;
};

interface AdminOrganizationProps {
  facilities: Facility[];
  facilityStructures: Record<string, SelectedFacility>;
  canCreateFacility: boolean;
  facilityTypes: ReferenceOption[];
  careLevels: ReferenceOption[];
  ownershipTypes: ReferenceOption[];
  totalFacilities: number;
  activeFacilities: number;
  selectedFacility?: SelectedFacility | null;
  errors?: Record<string, string>;
}

const FACILITY_HEADERS = [
  { key: 'name', header: 'Facility' },
  { key: 'type', header: 'Facility type' },
  { key: 'careLevel', header: 'Care level' },
  { key: 'locations', header: 'Departments · units · rooms' },
  { key: 'status', header: 'Status' },
  { key: 'actions', header: 'Actions' },
];

function formatReference(value: string): string {
  return value.replaceAll('_', ' ').toLocaleLowerCase().replace(/\b[a-z]/g, (letter) => letter.toLocaleUpperCase());
}

export default function AdminOrganizationIndex({
  facilities,
  facilityStructures,
  canCreateFacility,
  facilityTypes,
  careLevels,
  ownershipTypes,
  totalFacilities,
  activeFacilities,
  selectedFacility = null,
  errors = {},
}: AdminOrganizationProps): ReactNode {
  const [search, setSearch] = useState('');
  const [facilityPanelOpen, setFacilityPanelOpen] = useState(false);
  const [locationsFacilityId, setLocationsFacilityId] = useState<string | null>(
    selectedFacility?.facility.id ?? null,
  );
  const [servicesFacilityId, setServicesFacilityId] = useState<string | null>(null);
  const [expandedLocationId, setExpandedLocationId] = useState<string | null>(null);
  const [structurePanel, setStructurePanel] = useState<{
    data: SelectedFacility;
    mode: StructureFormMode;
    target?: StructureTarget;
    parentId?: string;
  } | null>(null);
  const [statusTarget, setStatusTarget] = useState<{ data: SelectedFacility; target: StructureTarget } | null>(null);
  const [suspendTarget, setSuspendTarget] = useState<{
    data: SelectedFacility;
    capability: SelectedFacility['capabilities'][number];
  } | null>(null);
  const [suspendReason, setSuspendReason] = useState('');
  const organizationName = facilities[0]?.organizationName ?? 'Organization';
  const totalDepartments = Object.values(facilityStructures)
    .reduce((count, structure) => count + structure.departments.length, 0);
  const facilityForm = useForm({
    name: '',
    code: '',
    facility_type: facilityTypes[0]?.code ?? '',
    care_level: careLevels[0]?.code ?? '',
    ownership_type: ownershipTypes[0]?.code ?? '',
  });
  const filteredFacilities = useMemo(() => {
    const query = search.trim().toLocaleLowerCase();
    if (!query) return facilities;
    return facilities.filter((facility) =>
      `${facility.name} ${facility.code} ${facility.type} ${facility.status}`.toLocaleLowerCase().includes(query),
    );
  }, [facilities, search]);
  const locationsFacility = locationsFacilityId ? facilityStructures[locationsFacilityId] : null;
  const servicesFacility = servicesFacilityId ? facilityStructures[servicesFacilityId] : null;
  const facilityRows = useMemo(() => filteredFacilities.map((facility) => ({
    id: facility.id,
    name: facility.name,
    type: facilityTypes.find((option) => option.code === facility.type)?.name ?? formatReference(facility.type),
    careLevel: careLevels.find((option) => option.code === facility.careLevel)?.name ?? formatReference(facility.careLevel),
    locations: (() => {
      const structure = facilityStructures[facility.id];
      if (!structure) return 'Location counts unavailable';
      const departmentCount = structure.departments.length;
      const unitCount = structure.units.length;
      const roomCount = structure.rooms.length;
      return `${departmentCount} ${departmentCount === 1 ? 'department' : 'departments'} · ${unitCount} ${unitCount === 1 ? 'unit' : 'units'} · ${roomCount} ${roomCount === 1 ? 'room' : 'rooms'}`;
    })(),
    status: facility.status,
    actions: (
      <div className="admin-organization__row-actions">
        <Button
          kind="tertiary"
          size="sm"
          onClick={() => openLocations(facility.id)}
        >
          {facilityStructures[facility.id]?.canManageStructure ? 'Manage locations' : 'View locations'}
        </Button>
        <Button
          kind="tertiary"
          size="sm"
          onClick={() => openServices(facility.id)}
        >
          {facilityStructures[facility.id]?.canManageCapabilities ? 'Manage services' : 'View services'}
        </Button>
      </div>
    ),
  })), [careLevels, facilityStructures, facilityTypes, filteredFacilities]);

  function createFacility(event: FormEvent): void {
    event.preventDefault();
    facilityForm.post(routes.workspaces.adminOrganizationFacilities, {
      preserveScroll: true,
      onSuccess: () => {
        setFacilityPanelOpen(false);
        facilityForm.reset();
      },
    });
  }

  function closeFacilityPanel(): void {
    setStructurePanel(null);
  }

  function openLocations(facilityId: string): void {
    setServicesFacilityId(null);
    setLocationsFacilityId(facilityId);
  }

  function openServices(facilityId: string): void {
    setLocationsFacilityId(null);
    setServicesFacilityId(facilityId);
  }

  function submitStructureStatus(): void {
    if (!statusTarget) return;
    const { data, target } = statusTarget;
    const plural = target.kind === 'department' ? 'departments' : target.kind === 'unit' ? 'units' : 'rooms';
    router.post(routes.workspaces.adminOrganizationStructureStatus(plural, target.id), {
      facility_id: data.facility.id,
      status: target.status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE',
    }, { preserveScroll: true, onSuccess: () => setStatusTarget(null) });
  }

  function submitCapabilityState(facilityId: string, code: string, state: 'ENABLED' | 'DISABLED'): void {
    router.post(routes.workspaces.adminOrganizationCapabilityStatus(code), {
      facility_id: facilityId,
      state,
    }, { preserveScroll: true });
  }

  function submitCapabilitySuspend(): void {
    if (!suspendTarget || suspendReason.trim() === '') return;
    router.post(routes.workspaces.adminOrganizationCapabilityStatus(suspendTarget.capability.code), {
      facility_id: suspendTarget.data.facility.id,
      state: 'SUSPENDED',
      reason: suspendReason.trim(),
    }, {
      preserveScroll: true,
      onSuccess: () => {
        setSuspendTarget(null);
        setSuspendReason('');
      },
    });
  }

  function capabilityStateTag(state: string | null): ReactNode {
    if (state === 'ENABLED') return <Tag type="green">Enabled</Tag>;
    if (state === 'SUSPENDED') return <Tag type="purple">Suspended</Tag>;
    if (state === 'DISABLED') return <Tag type="gray">Disabled</Tag>;
    return <Tag type="cool-gray">Not configured</Tag>;
  }

  function renderCapabilityActions(data: SelectedFacility, capability: SelectedFacility['capabilities'][number]): ReactNode {
    if (!data.canManageCapabilities) return null;
    const facilityId = data.facility.id;

    if (capability.state === 'ENABLED') {
      return (
        <>
          <Button
            kind="secondary"
            size="sm"
            aria-label={`Suspend service: ${capability.name}`}
            onClick={() => {
              setSuspendReason('');
              setSuspendTarget({ data, capability });
            }}
          >
            Suspend
          </Button>
          <Button
            kind="danger--tertiary"
            size="sm"
            aria-label={`Disable service: ${capability.name}`}
            onClick={() => submitCapabilityState(facilityId, capability.code, 'DISABLED')}
          >
            Disable
          </Button>
        </>
      );
    }

    return (
      <Button
        kind="secondary"
        size="sm"
        aria-label={`Enable service: ${capability.name}`}
        onClick={() => submitCapabilityState(facilityId, capability.code, 'ENABLED')}
      >
        Enable
      </Button>
    );
  }

  function renderStructureActions(data: SelectedFacility, kind: StructureTarget['kind'], id: string): ReactNode {
    const record = kind === 'department'
      ? data.departments.find((item) => item.id === id)
      : kind === 'unit'
        ? data.units.find((item) => item.id === id)
        : data.rooms.find((item) => item.id === id);
    if (!record) return null;
    const target = { kind, id, name: record.name, status: record.status };
    const statusAction = record.status === 'ACTIVE' ? 'Archive' : 'Reactivate';

    return (
      <>
        <Button
          kind="secondary"
          size="sm"
          aria-label={`Edit ${kind}: ${record.name}`}
          onClick={() => setStructurePanel({ data, mode: `edit-${kind}`, target })}
        >
          Edit
        </Button>
        <Button
          kind={record.status === 'ACTIVE' ? 'danger' : 'secondary'}
          size="sm"
          aria-label={`${statusAction} ${kind}: ${record.name}`}
          onClick={() => setStatusTarget({ data, target })}
        >
          {statusAction} {kind}
        </Button>
      </>
    );
  }

  return (
    <AppLayout>
      <main className="afyanova-page admin-organization">
        <PageHeader
          title="Organization & facilities"
          description={`${organizationName} · ${canCreateFacility
            ? 'Choose a facility to manage its locations and services.'
            : 'Choose a facility to view its locations and services.'}`}
          actions={canCreateFacility ? (
            <Button size="lg" renderIcon={Add} onClick={() => {
              setLocationsFacilityId(null);
              setServicesFacilityId(null);
              setFacilityPanelOpen(true);
            }}>
              Add facility
            </Button>
          ) : null}
        />

        {Object.keys(errors).length > 0 ? <p role="alert">{Object.values(errors).join(' ')}</p> : null}

        <StatisticsCards
          label="Facility summary"
          className="admin-organization__statistics"
          cards={[
            { id: 'facilities-total', label: 'Total facilities', value: totalFacilities },
            { id: 'facilities-active', label: 'Active facilities', value: activeFacilities },
            { id: 'facilities-departments', label: 'Departments', value: totalDepartments },
          ]}
        />

        <section aria-label="Facilities" className="admin-organization__facilities-section">
          <CarbonDataTable
            rows={facilityRows}
            headers={FACILITY_HEADERS}
            title={`${filteredFacilities.length} ${filteredFacilities.length === 1 ? 'facility' : 'facilities'}`}
            ariaLabel="Organization facilities"
            className="admin-organization__facility-directory"
            toolbarLabel="Facility table toolbar"
            toolbar={(
              <Search
                id="facility-search"
                labelText="Search facilities"
                placeholder="Search by name, code, type, or status"
                value={search}
                onChange={(event) => setSearch(event.currentTarget.value)}
                onClear={() => setSearch('')}
              />
            )}
            emptyState={(
              <EmptyState
                title={facilities.length === 0 ? 'No facilities available' : 'No facilities match your search'}
                description={facilities.length === 0
                  ? 'No facilities are available to your account.'
                  : 'Try a different facility name, code, type, or status.'}
              />
            )}
            renderCell={(cell) => cell.info.header === 'status'
              ? (
                <Tag type={cell.value === 'ACTIVE' ? 'green' : 'gray'}>
                  {typeof cell.value === 'string' ? formatReference(cell.value) : cell.value}
                </Tag>
              )
              : cell.value}
          />
        </section>
      </main>

      {facilityPanelOpen && canCreateFacility ? (
        <DockedPanel
          heading="Add facility"
          onClose={() => setFacilityPanelOpen(false)}
          onSubmit={createFacility}
          primaryLabel={facilityForm.processing ? 'Adding…' : 'Add facility'}
          primaryDisabled={
            facilityForm.processing
            || !facilityForm.data.name.trim()
            || !facilityForm.data.code.trim()
            || !facilityForm.data.facility_type
            || !facilityForm.data.care_level
            || !facilityForm.data.ownership_type
          }
          width="min(30rem, 100%)"
        >
          <p className="admin-organization__panel-intro">
            New facilities start active. Capabilities can be configured after creation.
          </p>
          {facilityTypes.length === 0 || careLevels.length === 0 || ownershipTypes.length === 0 ? (
            <p role="alert">Facility reference data is incomplete. Contact an administrator before creating a facility.</p>
          ) : (
            <div className="admin-organization__panel-form">
              <TextInput
                id="facility-name"
                labelText="Facility name"
                value={facilityForm.data.name}
                onChange={(event) => facilityForm.setData('name', event.target.value)}
                invalid={Boolean(facilityForm.errors.name)}
                invalidText={facilityForm.errors.name}
                required
                maxLength={255}
              />
              <TextInput
                id="facility-code"
                labelText="Facility code"
                helperText="Use uppercase letters, numbers, hyphens, or underscores."
                value={facilityForm.data.code}
                onChange={(event) => facilityForm.setData('code', event.target.value)}
                invalid={Boolean(facilityForm.errors.code)}
                invalidText={facilityForm.errors.code}
                required
                maxLength={64}
              />
              <Select
                id="facility-type"
                labelText="Facility type"
                value={facilityForm.data.facility_type}
                onChange={(event) => facilityForm.setData('facility_type', event.target.value)}
                invalid={Boolean(facilityForm.errors.facility_type)}
                invalidText={facilityForm.errors.facility_type}
                required
              >
                {facilityTypes.map((option) => <SelectItem key={option.code} value={option.code} text={option.name} />)}
              </Select>
              <Select
                id="facility-care-level"
                labelText="Care level"
                value={facilityForm.data.care_level}
                onChange={(event) => facilityForm.setData('care_level', event.target.value)}
                invalid={Boolean(facilityForm.errors.care_level)}
                invalidText={facilityForm.errors.care_level}
                required
              >
                {careLevels.map((option) => <SelectItem key={option.code} value={option.code} text={option.name} />)}
              </Select>
              <Select
                id="facility-ownership"
                labelText="Ownership"
                value={facilityForm.data.ownership_type}
                onChange={(event) => facilityForm.setData('ownership_type', event.target.value)}
                invalid={Boolean(facilityForm.errors.ownership_type)}
                invalidText={facilityForm.errors.ownership_type}
                required
              >
                {ownershipTypes.map((option) => <SelectItem key={option.code} value={option.code} text={option.name} />)}
              </Select>
            </div>
          )}
        </DockedPanel>
      ) : null}

      {locationsFacility ? (
        <DockedPanel
          heading={`Locations at ${locationsFacility.facility.name}`}
          onClose={() => setLocationsFacilityId(null)}
          hidden={Boolean(structurePanel)}
          width="min(56rem, 100%)"
          expandedWidth="min(80rem, 100%)"
          expandable
        >
          <FacilityWorkspace
            departments={locationsFacility.departments}
            units={locationsFacility.units}
            rooms={locationsFacility.rooms}
            canManageStructure={locationsFacility.canManageStructure}
            expandedLocationId={expandedLocationId}
            onAdd={(mode, parentId) => setStructurePanel({
              data: locationsFacility,
              mode,
              parentId,
            })}
            renderActions={(kind, id) => renderStructureActions(locationsFacility, kind, id)}
          />
        </DockedPanel>
      ) : null}

      {servicesFacility ? (
        <DockedPanel
          heading={`Services at ${servicesFacility.facility.name}`}
          onClose={() => setServicesFacilityId(null)}
          width="min(48rem, 100%)"
          expandedWidth="min(72rem, 100%)"
          expandable
        >
          <section aria-label="Services" className="admin-organization__services">
            {servicesFacility.capabilities.length === 0 ? (
              <p>No services are configured for this facility.</p>
            ) : (
              <ul className="admin-organization__services-list">
                {servicesFacility.capabilities.map((capability) => (
                  <li key={capability.code}>
                    <div>
                      <strong>{capability.name}</strong>
                      <span>{capability.category}</span>
                      {capability.state === 'SUSPENDED' && capability.suspendedReason ? (
                        <span>Suspended: {capability.suspendedReason}</span>
                      ) : null}
                    </div>
                    {capabilityStateTag(capability.state)}
                    <div className="admin-organization__services-actions">
                      {renderCapabilityActions(servicesFacility, capability)}
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </section>
        </DockedPanel>
      ) : null}

      {structurePanel ? (
        <AdminOrganizationFacilityPanel
          key={`${structurePanel.data.facility.id}-${structurePanel.mode}-${structurePanel.target?.id ?? 'new'}`}
          data={structurePanel.data}
          initialMode={structurePanel.mode}
          target={structurePanel.target}
          initialParentId={structurePanel.parentId}
          onClose={closeFacilityPanel}
          onRoomSaved={(unitId) => setExpandedLocationId(unitId ?? 'unassigned-rooms')}
        />
      ) : null}
      {statusTarget ? (
        <Modal
          open
          modalHeading={`${statusTarget.target.status === 'ACTIVE' ? 'Archive' : 'Reactivate'} ${statusTarget.target.kind}?`}
          primaryButtonText={statusTarget.target.status === 'ACTIVE' ? 'Archive' : 'Reactivate'}
          secondaryButtonText="Cancel"
          danger={statusTarget.target.status === 'ACTIVE'}
          onRequestClose={() => setStatusTarget(null)}
          onRequestSubmit={submitStructureStatus}
        >
          <p>{statusTarget.target.status === 'ACTIVE'
            ? `Archive ${statusTarget.target.name}? The record will be retained. Archive active child records first.`
            : `Reactivate ${statusTarget.target.name}?`}</p>
        </Modal>
      ) : null}
      {suspendTarget ? (
        <Modal
          open
          modalHeading={`Suspend ${suspendTarget.capability.name}?`}
          primaryButtonText="Suspend service"
          secondaryButtonText="Cancel"
          danger
          primaryButtonDisabled={suspendReason.trim() === ''}
          onRequestClose={() => {
            setSuspendTarget(null);
            setSuspendReason('');
          }}
          onRequestSubmit={submitCapabilitySuspend}
        >
          <p>Suspended services stop new work but preserve records.</p>
          <TextInput
            id="capability-suspend-reason"
            labelText="Reason for suspension"
            helperText="Required when suspending."
            value={suspendReason}
            onChange={(event) => setSuspendReason(event.currentTarget.value)}
            required
          />
        </Modal>
      ) : null}
    </AppLayout>
  );
}
