import { Button, InlineNotification, Pagination, Select, SelectItem, TextInput } from '@carbon/react';
import { router } from '@inertiajs/react';
import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import { CarbonDataTable, EmptyState, PageHeader } from '@/components/ui';
import { AppLayout } from '@/layouts/AppLayout/AppLayout';
import { Filter } from '@/icons';

interface PlatformAuditEntry {
  id: string;
  tenantId: string | null;
  tenantName: string;
  facilityId: string | null;
  actorId: string | null;
  action: string;
  targetType: string | null;
  targetId: string | null;
  metadata: Record<string, string | number | boolean | string[]>;
  createdAt: string;
}

interface TenantOption {
  id: string;
  name: string;
  status: string;
}

interface AuditFilters {
  from: string;
  to: string;
  tenant_id: string;
  action: string;
  actor_id: string;
  target: string;
}

interface PlatformAuditProps {
  entries: PlatformAuditEntry[];
  tenants: TenantOption[];
  filters: AuditFilters;
  total: number;
  currentPage: number;
  lastPage: number;
  resultLimit: number;
  perPage: number;
}

const HEADERS = [
  { key: 'createdAt', header: 'Date and time' },
  { key: 'tenant', header: 'Tenant' },
  { key: 'action', header: 'Action' },
  { key: 'actorId', header: 'Actor ID' },
  { key: 'target', header: 'Target' },
  { key: 'metadata', header: 'Governance details' },
];

export default function PlatformAuditIndex({
  entries,
  tenants,
  filters,
  total,
  currentPage,
  lastPage,
  resultLimit,
  perPage,
}: PlatformAuditProps): ReactNode {
  const [form, setForm] = useState(filters);

  useEffect(() => {
    setForm(filters);
  }, [filters]);

  function visit(next: AuditFilters, page = 1, pageSize = perPage): void {
    router.get(routes.platformAdmin.audit, {
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

  const rows = entries.map((entry) => ({
    id: entry.id,
    createdAt: new Date(entry.createdAt).toLocaleString(),
    tenant: entry.tenantName,
    action: entry.action,
    actorId: entry.actorId ?? 'Unattributed',
    target: `${entry.targetType ?? '—'}${entry.targetId ? ` · ${entry.targetId}` : ''}`,
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
      <main className="afyanova-page platform-admin">
        <PageHeader
          title="Platform audit"
          description="Review cross-tenant governance and access events. Patient and clinical content is excluded."
        />
        <form className="platform-admin__audit-filters" onSubmit={submit}>
          <TextInput
            id="platform-audit-from"
            labelText="From"
            type="date"
            value={form.from}
            onChange={(event) => setForm({ ...form, from: event.target.value })}
          />
          <TextInput
            id="platform-audit-to"
            labelText="To"
            type="date"
            value={form.to}
            onChange={(event) => setForm({ ...form, to: event.target.value })}
          />
          <Select
            id="platform-audit-tenant"
            labelText="Tenant"
            value={form.tenant_id}
            onChange={(event) => setForm({ ...form, tenant_id: event.target.value })}
          >
            <SelectItem value="" text="All tenants" />
            {tenants.map((tenant) => (
              <SelectItem
                key={tenant.id}
                value={tenant.id}
                text={`${tenant.name}${tenant.status === 'SUSPENDED' ? ' (suspended)' : ''}`}
              />
            ))}
          </Select>
          <TextInput
            id="platform-audit-action"
            labelText="Action"
            value={form.action}
            onChange={(event) => setForm({ ...form, action: event.target.value })}
          />
          <TextInput
            id="platform-audit-actor"
            labelText="Actor ID"
            value={form.actor_id}
            onChange={(event) => setForm({ ...form, actor_id: event.target.value })}
          />
          <TextInput
            id="platform-audit-target"
            labelText="Target"
            value={form.target}
            onChange={(event) => setForm({ ...form, target: event.target.value })}
          />
          <Button size="lg" renderIcon={Filter} type="submit">Apply filters</Button>
        </form>

        <CarbonDataTable
          rows={rows}
          headers={HEADERS}
          title={`${total} governance ${total === 1 ? 'event' : 'events'}`}
          ariaLabel="Platform governance audit events"
          emptyState={<EmptyState title="No governance events match these filters." />}
        />
        {total > resultLimit ? (
          <InlineNotification
            kind="info"
            lowContrast
            title={`Showing the newest ${resultLimit.toLocaleString()} matching events`}
            subtitle="Narrow the date range or filters to review older events."
          />
        ) : null}
        {entries.length > 0 ? (
          <Pagination
            id="platform-audit-pagination"
            aria-label="Platform audit event pages"
            page={currentPage}
            pageSize={perPage}
            pageSizes={[25]}
            totalItems={Math.min(total, resultLimit)}
            isLastPage={currentPage >= lastPage}
            size="lg"
            onChange={({ page, pageSize }) => visit(filters, page, pageSize)}
          />
        ) : null}
      </main>
    </AppLayout>
  );
}
