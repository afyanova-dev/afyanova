import {
  Button,
  Pagination,
  Select,
  SelectItem,
  TextInput,
} from '@carbon/react';
import { router } from '@inertiajs/react';
import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import { CarbonDataTable, EmptyState, PageHeader } from '@/components/ui';
import { AppLayout } from '@/layouts/AppLayout/AppLayout';
import { Filter } from '@/icons';

interface AuditEntry {
  id: string;
  facilityId: string | null;
  actorId: string | null;
  action: string;
  targetType: string | null;
  targetId: string | null;
  metadata: Record<string, string | number | boolean | string[]>;
  createdAt: string;
}

interface FacilityOption {
  id: string;
  name: string;
}

interface AuditFilters {
  from: string;
  to: string;
  action: string;
  actor_id: string;
  target: string;
  facility_id: string;
}

interface AuditIndexProps {
  entries: AuditEntry[];
  facilities: FacilityOption[];
  filters: AuditFilters;
  errors?: Record<string, string>;
  total: number;
  currentPage: number;
  lastPage: number;
  perPage: number;
}

const HEADERS = [
  { key: 'createdAt', header: 'Date and time' },
  { key: 'action', header: 'Action' },
  { key: 'actorId', header: 'Actor ID' },
  { key: 'target', header: 'Target' },
  { key: 'facilityId', header: 'Facility' },
  { key: 'metadata', header: 'Details' },
];

export default function AdminAuditIndex({
  entries,
  facilities,
  filters,
  errors = {},
  total,
  currentPage,
  lastPage,
  perPage,
}: AuditIndexProps): ReactNode {
  const [form, setForm] = useState(filters);

  useEffect(() => {
    setForm(filters);
  }, [filters]);

  function visit(next: AuditFilters, page = 1, pageSize = perPage): void {
    router.get(routes.workspaces.adminAudit, {
      ...next,
      page,
      per_page: pageSize,
    }, {
      preserveScroll: true,
      preserveState: true,
      replace: true,
    });
  }

  function submit(event: FormEvent<HTMLFormElement>): void {
    event.preventDefault();
    visit(form);
  }

  const facilityName = new Map(facilities.map((facility) => [facility.id, facility.name]));
  const rows = entries.map((entry) => ({
    id: entry.id,
    createdAt: new Date(entry.createdAt).toLocaleString(),
    action: entry.action,
    actorId: entry.actorId ?? 'Unattributed',
    target: `${entry.targetType ?? '—'}${entry.targetId ? ` · ${entry.targetId}` : ''}`,
    facilityId: entry.facilityId === null
      ? 'Organization'
      : facilityName.get(entry.facilityId) ?? 'Assigned facility',
    metadata: (
      <>
        {Object.entries(entry.metadata).map(([key, value]) => (
          <div key={key}><strong>{key}:</strong> {Array.isArray(value) ? value.join(', ') : String(value)}</div>
        ))}
      </>
    ),
  }));

  return (
    <AppLayout>
      <div className="afyanova-page">
        <PageHeader
          title="Audit log"
          description="Review organization configuration, access-control, and designated security events."
        />

        <form
          onSubmit={submit}
          style={{
            display: 'grid',
            gridTemplateColumns: 'repeat(auto-fit, minmax(12rem, 1fr))',
            gap: '1rem',
            alignItems: 'end',
            marginBlock: '1rem',
          }}
        >
          <TextInput
            id="audit-from"
            labelText="From"
            type="date"
            value={form.from}
            onChange={(event) => setForm({ ...form, from: event.target.value })}
          />
          <TextInput
            id="audit-to"
            labelText="To"
            type="date"
            value={form.to}
            onChange={(event) => setForm({ ...form, to: event.target.value })}
          />
          <TextInput
            id="audit-action"
            labelText="Action"
            value={form.action}
            onChange={(event) => setForm({ ...form, action: event.target.value })}
          />
          <TextInput
            id="audit-actor"
            labelText="Actor ID"
            value={form.actor_id}
            onChange={(event) => setForm({ ...form, actor_id: event.target.value })}
          />
          <TextInput
            id="audit-target"
            labelText="Target"
            value={form.target}
            onChange={(event) => setForm({ ...form, target: event.target.value })}
          />
          <Select
            id="audit-facility"
            labelText="Facility scope"
            value={form.facility_id}
            onChange={(event) => setForm({ ...form, facility_id: event.target.value })}
          >
            <SelectItem value="" text="Default scope" />
            {facilities.map((facility) => (
              <SelectItem key={facility.id} value={facility.id} text={facility.name} />
            ))}
          </Select>
          <Button size="lg" renderIcon={Filter} type="submit">Apply filters</Button>
        </form>
        {Object.keys(errors).length > 0 ? (
          <p role="alert">{Object.values(errors).join(' ')}</p>
        ) : null}

        <CarbonDataTable
          rows={rows}
          headers={HEADERS}
          title={`${total} audit ${total === 1 ? 'event' : 'events'}`}
          ariaLabel="Audit events"
          emptyState={<EmptyState title="No audit events match these filters." />}
        />
        {entries.length > 0 ? (
          <Pagination
            id="admin-audit-pagination"
            aria-label="Audit event pages"
            page={currentPage}
            pageSize={perPage}
            pageSizes={[25, 50, 100]}
            totalItems={total}
            isLastPage={currentPage >= lastPage}
            size="lg"
            onChange={({ page, pageSize }) => visit(filters, page, pageSize)}
          />
        ) : null}
      </div>
    </AppLayout>
  );
}