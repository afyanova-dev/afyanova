import {
  OverflowMenuItem,
  Pagination,
  TableToolbarMenu,
  TableToolbarSearch,
} from '@carbon/react';
import { router } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import { CarbonDataTable, EmptyState, PageHeader } from '@/components/ui';
import { Filter } from '@/icons';
import { AppLayout } from '@/layouts/AppLayout/AppLayout';

interface Diagnosis {
  id: string;
  code: string;
  codeSystem: string;
  name: string;
  aliases: string;
  category: string;
}

interface DiagnosisCatalogProps {
  diagnoses: {
    data: Diagnosis[];
    total: number;
    currentPage: number;
    lastPage: number;
    perPage: number;
  };
  categories: string[];
  filters: {
    search: string;
    category: string;
  };
}

const HEADERS = [
  { key: 'name', header: 'Diagnosis' },
  { key: 'code', header: 'Code' },
  { key: 'category', header: 'Category' },
  { key: 'aliases', header: 'Search aliases' },
];

export default function DiagnosisCatalog({ diagnoses, categories, filters }: DiagnosisCatalogProps): ReactNode {
  const [form, setForm] = useState(filters);

  useEffect(() => {
    setForm(filters);
  }, [filters]);

  useEffect(() => {
    if (form.search === filters.search && form.category === filters.category) {
      return;
    }

    const timeout = window.setTimeout(() => {
      router.get(routes.workspaces.clinicianDiagnoses, {
        ...form,
        page: 1,
      }, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
      });
    }, 300);

    return () => window.clearTimeout(timeout);
  }, [filters.category, filters.search, form]);

  function visit(page = 1): void {
    router.get(routes.workspaces.clinicianDiagnoses, {
      ...form,
      page,
    }, {
      preserveScroll: true,
      preserveState: true,
      replace: true,
    });
  }

  const rows = diagnoses.data.map((diagnosis) => ({
    id: diagnosis.id,
    name: diagnosis.name,
    code: `${diagnosis.codeSystem} · ${diagnosis.code}`,
    category: diagnosis.category,
    aliases: diagnosis.aliases || '—',
  }));

  return (
    <AppLayout>
      <div className="afyanova-page">
        <PageHeader
          title="Diagnosis catalogue"
          description="Browse the active ICD-10-based Tanzania diagnosis catalogue used by clinician search. Code assignments are provisional pending validation against an approved national or WHO release."
        />
        <div className="afy-page__content">
          <CarbonDataTable
            rows={rows}
            headers={HEADERS}
            title={(
              <div className="diagnosis-catalog__table-title">
                <span className="diagnosis-catalog__table-heading">Diagnosis catalogue</span>
                <span>{diagnoses.total} {diagnoses.total === 1 ? 'diagnosis' : 'diagnoses'}</span>
              </div>
            )}
            ariaLabel="Diagnosis catalogue"
            toolbarLabel="Diagnosis catalogue table toolbar"
            toolbar={(onInputChange) => (
              <>
                <TableToolbarSearch
                  persistent
                  value={form.search}
                  onChange={(event) => {
                    const search = event === '' ? '' : event.currentTarget.value;
                    setForm((current) => ({ ...current, search }));
                    onInputChange(event);
                  }}
                  placeholder="Search diagnoses, codes, and aliases"
                  aria-label="Search diagnosis catalogue"
                />
                <TableToolbarMenu
                  renderIcon={Filter}
                  iconDescription={`Filter by category: ${form.category || 'All categories'}`}
                >
                  <OverflowMenuItem
                    itemText="All categories"
                    onClick={() => setForm((current) => ({ ...current, category: '' }))}
                  />
                  {categories.map((category) => (
                    <OverflowMenuItem
                      key={category}
                      itemText={category}
                      onClick={() => setForm((current) => ({ ...current, category }))}
                    />
                  ))}
                </TableToolbarMenu>
              </>
            )}
            emptyState={(
              <EmptyState
                title="No diagnoses match these filters."
                description="Try another search term or select all categories."
              />
            )}
          />

          {diagnoses.total > diagnoses.perPage ? (
            <Pagination
              id="diagnosis-catalog-pagination"
              aria-label="Diagnosis catalogue pages"
              page={diagnoses.currentPage}
              pageSize={diagnoses.perPage}
              pageSizes={[diagnoses.perPage]}
              totalItems={diagnoses.total}
              isLastPage={diagnoses.currentPage >= diagnoses.lastPage}
              size="lg"
              onChange={({ page }) => visit(page)}
            />
          ) : null}
        </div>
      </div>
    </AppLayout>
  );
}
