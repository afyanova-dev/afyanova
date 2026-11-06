import { Pagination } from '@carbon/react';
import { router } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { routes } from '@/app/routes';
import { CarbonDataTable, EmptyState, PageHeader } from '@/components/ui';
import { AppLayout } from '@/layouts/AppLayout/AppLayout';

interface RolePermission {
  key: string;
  layer: string;
}

interface RoleEntry {
  id: string;
  name: string;
  key: string;
  scope: string;
  status: string;
  isProtected: boolean;
  permissions: RolePermission[];
}

interface RoleCatalogProps {
  roles: {
    data: RoleEntry[];
    total: number;
    currentPage: number;
    lastPage: number;
    perPage: number;
  };
}

const HEADERS = [
  { key: 'name', header: 'Role' },
  { key: 'key', header: 'Key' },
  { key: 'scope', header: 'Scope' },
  { key: 'status', header: 'Status' },
  { key: 'protection', header: 'Definition' },
  { key: 'permissions', header: 'Permissions' },
];

export default function AdminRoleCatalog({ roles }: RoleCatalogProps): ReactNode {
  const rows = roles.data.map((role) => ({
    id: role.id,
    name: role.name,
    key: role.key,
    scope: role.scope,
    status: role.status,
    protection: role.isProtected ? 'Protected' : 'Custom',
    permissions: role.permissions.length > 0
      ? role.permissions.map(({ key, layer }) => `${key} (${layer})`).join(', ')
      : 'No permissions assigned',
  }));

  return (
    <AppLayout>
      <div className="afyanova-page">
        <PageHeader
          title="Role catalogue"
          description="Review role scope, status, protection, and assigned permissions. This catalogue is read-only; it does not grant or change access."
        />
        <div className="afy-page__content">
          <CarbonDataTable
            rows={rows}
            headers={HEADERS}
            title={`${roles.total} ${roles.total === 1 ? 'role' : 'roles'}`}
            ariaLabel="Role catalogue"
            emptyState={
              <EmptyState
                title="No roles are configured."
                description="Roles will appear here after they are provisioned for this tenant."
              />
            }
          />
          {roles.total > roles.perPage ? (
            <Pagination
              id="admin-role-catalog-pagination"
              aria-label="Role catalogue pages"
              page={roles.currentPage}
              pageSize={roles.perPage}
              pageSizes={[roles.perPage]}
              totalItems={roles.total}
              isLastPage={roles.currentPage >= roles.lastPage}
              size="lg"
              onChange={({ page }) => {
                router.get(routes.workspaces.adminRoleCatalog, { page }, {
                  preserveScroll: true,
                  preserveState: true,
                  replace: true,
                });
              }}
            />
          ) : null}
        </div>
      </div>
    </AppLayout>
  );
}
