import { Button, Select, SelectItem, TextInput } from '@carbon/react';
import { useForm } from '@inertiajs/react';
import { useMemo, useState, type ReactNode, type SyntheticEvent } from 'react';
import { routes } from '@/app/routes';
import { CarbonDataTable, EmptyState, FormErrors, PageHeader, StatisticsCards } from '@/components/ui';
import { DockedPanel } from '@/components/panels';
import { AppLayout } from '@/layouts/AppLayout/AppLayout';

interface DepartmentLimit {
  id: string;
  name: string;
  code: string;
  /** The department's own row, or null when it falls back to the facility. */
  limit: string | null;
  effectiveLimit: string;
}

interface FacilityLimit {
  id: string;
  name: string;
  type: string | null;
  /** The facility's own row, or null when the facility-type default applies. */
  limit: string | null;
  effectiveLimit: string;
  departments: DepartmentLimit[];
}

interface ProcurementLimitsProps {
  facilities: FacilityLimit[];
  typeDefaults: Record<string, string>;
}

const FACILITY_HEADERS = [
  { key: 'name', header: 'Facility' },
  { key: 'type', header: 'Type' },
  { key: 'ownLimit', header: 'Own limit' },
  { key: 'effectiveLimit', header: 'Effective' },
  { key: 'departments', header: 'Departments' },
  { key: 'actions', header: 'Actions' },
];

const amountFormat = new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 });

function formatAmount(value: string): string {
  const amount = Number(value);

  return Number.isFinite(amount) ? amountFormat.format(amount) : value;
}

function formatType(type: string | null): string {
  return (type ?? 'unknown')
    .toLowerCase()
    .split('_')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(' ');
}

/**
 * The per-facility editor, docked like the organization page's panels. The
 * caller owns the lifecycle; this component owns the form state for exactly
 * one facility.
 */
function FacilityLimitPanel({
  facility,
  typeDefaults,
  onClose,
}: {
  facility: FacilityLimit;
  typeDefaults: Record<string, string>;
  onClose: () => void;
}): ReactNode {
  const form = useForm({
    facility_id: facility.id,
    department_id: '',
    limit: '',
    clear: false,
  });

  const department = facility.departments.find((item) => item.id === form.data.department_id);
  const scopeLimit = department ? department.limit : facility.limit;
  const scopeEffective = department ? department.effectiveLimit : facility.effectiveLimit;
  const typeDefault = facility.type ? typeDefaults[facility.type] : undefined;
  const departmentsWithOwnLimit = facility.departments.filter((item) => item.limit !== null);

  function submit(event: SyntheticEvent, clear: boolean): void {
    event.preventDefault();
    form.transform((data) => ({ ...data, clear }));
    form.post(routes.workspaces.adminProcurementLimits, {
      preserveScroll: true,
      onSuccess: () => form.reset(),
    });
  }

  return (
    <DockedPanel
      heading={facility.name}
      description={formatType(facility.type)}
      onClose={onClose}
      onSubmit={(event) => submit(event, false)}
      primaryLabel={form.processing ? 'Saving…' : 'Save limit'}
      primaryDisabled={form.processing}
      width="min(30rem, 100%)"
    >
      <p
        className="admin-procurement-limits__panel-intro"
        data-testid={`approval-limit-summary-${facility.id}`}
      >
        {department ? department.name : 'Whole facility'}: <strong>TZS {formatAmount(scopeEffective)}</strong>
        {scopeLimit !== null
          ? ''
          : department
            ? ' (inherited)'
            : typeDefault !== undefined ? ' (type default)' : ' (none)'}
      </p>

      <div className="admin-procurement-limits__panel-form">
        <FormErrors errors={form.errors} fields={['facility_id', 'department_id', 'limit']} />

        <Select
          id={`scope-${facility.id}`}
          labelText="Applies to"
          value={form.data.department_id}
          onChange={(event) => form.setData('department_id', event.currentTarget.value)}
        >
          <SelectItem value="" text="Whole facility" />
          {facility.departments.map((item) => (
            <SelectItem key={item.id} value={item.id} text={`${item.name} (${item.code})`} />
          ))}
        </Select>

        <TextInput
          id={`limit-${facility.id}`}
          labelText="One person may approve up to (TZS)"
          helperText="Above this amount, a second person must approve."
          type="number"
          min={0}
          step="0.01"
          value={form.data.limit}
          onChange={(event) => form.setData('limit', event.currentTarget.value)}
          invalid={Boolean(form.errors.limit)}
          invalidText={form.errors.limit}
        />
      </div>

      <Button
        kind="danger--ghost"
        type="button"
        disabled={form.processing || scopeLimit === null}
        onClick={(event) => submit(event, true)}
      >
        Remove this rule
      </Button>

      {departmentsWithOwnLimit.length > 0 ? (
        <div className="admin-procurement-limits__departments">
          <h3>Departments with their own limit</h3>
          <ul>
            {departmentsWithOwnLimit.map((item) => (
              <li key={item.id}>
                {item.name}: TZS {formatAmount(item.limit ?? item.effectiveLimit)}
              </li>
            ))}
          </ul>
        </div>
      ) : null}
    </DockedPanel>
  );
}

export default function AdminProcurementLimitsIndex({
  facilities,
  typeDefaults,
}: ProcurementLimitsProps): ReactNode {
  const [managedFacilityId, setManagedFacilityId] = useState<string | null>(null);
  const managedFacility = facilities.find((facility) => facility.id === managedFacilityId) ?? null;

  const facilitiesWithOwnLimit = facilities.filter((facility) => facility.limit !== null).length;
  const departmentsWithOwnLimit = facilities.reduce(
    (count, facility) => count + facility.departments.filter((item) => item.limit !== null).length,
    0,
  );

  const facilityRows = useMemo(() => facilities.map((facility) => ({
    id: facility.id,
    name: facility.name,
    type: formatType(facility.type),
    ownLimit: facility.limit !== null ? `TZS ${formatAmount(facility.limit)}` : '—',
    effectiveLimit: `TZS ${formatAmount(facility.effectiveLimit)}`,
    departments: facility.departments.length === 0
      ? 'None'
      : `${facility.departments.filter((item) => item.limit !== null).length} of ${facility.departments.length} set`,
    actions: (
      <Button
        kind="tertiary"
        size="sm"
        aria-label={`Manage approval limits for ${facility.name}`}
        onClick={() => setManagedFacilityId(facility.id)}
      >
        Manage limits
      </Button>
    ),
  })), [facilities]);

  return (
    <AppLayout>
      <main className="afyanova-page admin-procurement-limits">
        <PageHeader
          title="Procurement limits"
          description="The most one person may approve alone, per facility and department. Above it, a second person must approve."
          badge={<span className="afy-eyebrow">Administration</span>}
        />

        <StatisticsCards
          label="Approval limit summary"
          className="admin-procurement-limits__statistics"
          cards={[
            { id: 'limits-facilities', label: 'Facilities', value: facilities.length },
            { id: 'limits-facility-rules', label: 'Facility limits set', value: facilitiesWithOwnLimit },
            { id: 'limits-department-rules', label: 'Department limits set', value: departmentsWithOwnLimit },
          ]}
        />

        <section aria-label="Facility approval limits" className="admin-procurement-limits__facilities-section">
          <CarbonDataTable
            rows={facilityRows}
            headers={FACILITY_HEADERS}
            title={`${facilities.length} ${facilities.length === 1 ? 'facility' : 'facilities'}`}
            ariaLabel="Facility approval limits"
            className="admin-procurement-limits__directory"
            emptyState={(
              <EmptyState
                title="No facilities to configure"
                description="You are not assigned to any facility where you can set an approval limit."
              />
            )}
          />
        </section>
      </main>

      {managedFacility ? (
        <FacilityLimitPanel
          key={managedFacility.id}
          facility={managedFacility}
          typeDefaults={typeDefaults}
          onClose={() => setManagedFacilityId(null)}
        />
      ) : null}
    </AppLayout>
  );
}
