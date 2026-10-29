import {
  Button,
  DismissibleTag,
  Layer,
  Pagination,
  Popover,
  PopoverContent,
  Select,
  SelectItem,
  TableToolbarSearch,
  Tabs,
  Tag,
} from '@carbon/react';
import { Link, router } from '@inertiajs/react';
import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { routes } from '@/app/routes';
import { CarbonDataTable, ContainedTabList, EmptyState, IconTab, PageHeader } from '@/components/ui';
import { HRWorkflowFeedback } from '@/features/hr/components/HRWorkflowFeedback';
import type { FacilitySummary, StaffDirectoryPagination, StaffDirectoryRow } from '@/features/hr/types';
import { staffStatusLabel, staffStatusTagType } from '@/lib/staffStatus';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import { Checkmark, Filter, Pending, Task, UserMinus, UserMultiple, WarningAlt } from '@/icons';
import { AppLayout } from '@/layouts/AppLayout/AppLayout';

const STAFF_HEADERS = [
  { key: 'staff', header: 'Staff member' },
  { key: 'staffNumber', header: 'Staff number' },
  { key: 'status', header: 'Status' },
  { key: 'account', header: 'Account' },
  { key: 'department', header: 'Department' },
  { key: 'unit', header: 'Unit' },
  { key: 'position', header: 'Position' },
  { key: 'contact', header: 'Contact' },
  { key: 'record', header: 'Record' },
];

const STATUS_TABS = [
  { key: 'ALL', label: 'All', icon: UserMultiple },
  { key: 'ACTIVE', label: 'Active', icon: Checkmark },
  { key: 'PROSPECT', label: 'Pending onboarding', icon: Pending },
  { key: 'ONBOARDING', label: 'Onboarding', icon: Task },
  { key: 'SUSPENDED', label: 'Suspended', icon: WarningAlt },
  { key: 'TERMINATED', label: 'Terminated', icon: UserMinus },
] as const;

const ACCOUNT_LABELS: Record<NonNullable<StaffDirectoryRow['accountState']>, string> = {
  NO_ACCOUNT: 'No account',
  INVITED: 'Invitation pending',
  INVITATION_EXPIRED: 'Invitation expired',
  INVITATION_REVOKED: 'Invitation revoked',
  INVITATION_REPLACED: 'Invitation replaced',
  SETUP_PENDING: 'Setup not completed',
  ACTIVE: 'Active account',
};

interface StaffDirectoryFilters {
  department: string;
  unit: string;
  position: string;
}

interface FacilityStaffIndexProps {
  facility: FacilitySummary;
  staff: StaffDirectoryRow[];
  pagination?: StaffDirectoryPagination;
  statusCounts?: Record<string, number>;
  activeStatus?: string;
  search?: string;
  errors?: Record<string, string>;
}

export default function FacilityStaffIndex({
  facility,
  staff = [],
  pagination = { page: 1, perPage: 25, total: staff.length, lastPage: 1 },
  statusCounts = {},
  activeStatus = 'ALL',
  search: initialSearch = '',
  errors,
}: FacilityStaffIndexProps): React.JSX.Element {
  const [search, setSearch] = useState(initialSearch);
  const debouncedSearch = useDebouncedValue(search, 350);
  const previousSearch = useRef(initialSearch);
  const [filtersOpen, setFiltersOpen] = useState(false);
  const [filters, setFilters] = useState<StaffDirectoryFilters>({
    department: '',
    unit: '',
    position: '',
  });
  const [draftFilters, setDraftFilters] = useState(filters);
  const filterPanelId = useId();
  const departments = useMemo(
    () => [...new Set(staff.map((person) => person.departmentName).filter((value): value is string => Boolean(value)))].sort(),
    [staff],
  );
  const units = useMemo(
    () => [...new Set(staff.map((person) => person.unitName).filter((value): value is string => Boolean(value)))].sort(),
    [staff],
  );
  const positions = useMemo(
    () => [...new Set(staff.map((person) => person.positionTitle).filter((value): value is string => Boolean(value)))].sort(),
    [staff],
  );
  const visibleTabs = STATUS_TABS.filter((tab) => tab.key === 'ALL'
    || (statusCounts[tab.key] ?? 0) > 0);
  const effectiveStatus = visibleTabs.some((tab) => tab.key === activeStatus) ? activeStatus : 'ALL';
  const selectedTabIndex = Math.max(0, visibleTabs.findIndex((tab) => tab.key === effectiveStatus));
  const activeTabLabel = STATUS_TABS.find((tab) => tab.key === effectiveStatus)?.label ?? 'All';

  function loadDirectory(status: string, page: number, query: string): void {
    router.get(
      routes.workspaces.hrStaff,
      {
        ...(status !== '' && status !== 'ALL' ? { status } : {}),
        ...(query.trim() !== '' ? { q: query.trim() } : {}),
        ...(page > 1 ? { page } : {}),
      },
      { preserveState: true, preserveScroll: true, replace: true },
    );
  }

  useEffect(() => {
    if (previousSearch.current === debouncedSearch) return;
    previousSearch.current = debouncedSearch;
    loadDirectory(effectiveStatus, 1, debouncedSearch);
    // The active status is read at the moment the deferred query changes.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debouncedSearch]);

  function matchesLocalFilters(person: StaffDirectoryRow): boolean {
    return (filters.department === '' || person.departmentName === filters.department)
      && (filters.unit === '' || person.unitName === filters.unit)
      && (filters.position === '' || person.positionTitle === filters.position);
  }

  const searchTerm = search.trim().toLocaleLowerCase();
  function matchesSearch(person: StaffDirectoryRow): boolean {
    if (searchTerm === '') return true;
    return [
      person.name,
      person.staffNumber,
      person.phone,
      person.email,
      person.positionTitle,
      person.departmentName,
      person.unitName,
    ].some((value) => value?.toLocaleLowerCase().includes(searchTerm));
  }

  const filteredStaff = staff.filter((person) => matchesLocalFilters(person) && matchesSearch(person));
  const activeFilters = [
    { key: 'department' as const, label: 'Department', value: filters.department },
    { key: 'unit' as const, label: 'Unit', value: filters.unit },
    { key: 'position' as const, label: 'Position', value: filters.position },
  ].filter((filter) => filter.value !== '');
  const activeFilterCount = activeFilters.length;
  const availableUnits = units.filter((unit) => draftFilters.department === ''
    || staff.some((person) => person.unitName === unit && person.departmentName === draftFilters.department));
  function clearFilter(key: keyof StaffDirectoryFilters): void {
    setFilters((current) => ({
      ...current,
      [key]: '',
      ...(key === 'department' ? { unit: '' } : {}),
    }));
    setDraftFilters((current) => ({
      ...current,
      [key]: '',
      ...(key === 'department' ? { unit: '' } : {}),
    }));
  }

  function resetFilters(): void {
    setDraftFilters({ department: '', unit: '', position: '' });
  }

  const staffRows = filteredStaff.map((person) => ({
    id: person.id,
    staff: (
      <Link href={routes.workspaces.hrStaffDetail(person.id)}>
        {person.name}
      </Link>
    ),
    staffNumber: person.staffNumber,
    status: <Tag type={staffStatusTagType(person.status)}>{staffStatusLabel(person.status)}</Tag>,
    account: (
      <Tag type={person.accountState === 'ACTIVE'
        ? 'green'
        : person.accountState === 'NO_ACCOUNT'
          ? 'gray'
          : person.accountState === 'INVITATION_EXPIRED' || person.accountState === 'INVITATION_REVOKED'
            ? 'red'
            : 'blue'}>
        {ACCOUNT_LABELS[person.accountState ?? 'NO_ACCOUNT']}
      </Tag>
    ),
    department: person.departmentName ?? 'Not recorded',
    unit: person.unitName ?? 'Not recorded',
    position: person.positionTitle ?? 'Not recorded',
    contact: person.phone ?? person.email ?? 'Not provided',
    record: (
      <Button
        as={Link}
        href={routes.workspaces.hrStaffDetail(person.id)}
        kind="tertiary"
        size="sm"
      >
        View record
      </Button>
    ),
  }));

  return (
    <AppLayout>
      <main className="afyanova-page facility-staff">
        <PageHeader
          title="Staff directory"
          description={`Manage staff records and facility assignments at ${facility.name}. Use the lifecycle tabs to separate active staff from pending onboarding and suspended records.`}
        />
        <HRWorkflowFeedback errors={errors} />
        <section className="facility-staff__directory" aria-label="Staff directory">
          <Tabs
            selectedIndex={selectedTabIndex}
            onChange={({ selectedIndex }) => {
              const next = visibleTabs[selectedIndex];
              if (next !== undefined) {
                loadDirectory(next.key, 1, search);
              }
            }}
          >
            <ContainedTabList aria-label="Staff lifecycle views">
              {visibleTabs.map((tab) => (
                <IconTab key={tab.key} icon={tab.icon}>
                  {tab.label} ({statusCounts[tab.key] ?? 0})
                </IconTab>
              ))}
            </ContainedTabList>
          </Tabs>
          <CarbonDataTable
                  rows={staffRows}
                  headers={STAFF_HEADERS}
                  title={`${activeTabLabel} staff (${filteredStaff.length} shown of ${pagination.total})`}
                  ariaLabel={`${activeTabLabel} facility staff directory`}
                  emptyState={(
                    <EmptyState
                      title={pagination.total === 0 ? `No ${activeTabLabel.toLocaleLowerCase()} staff` : 'No matching staff on this page'}
                      description={pagination.total === 0
                        ? 'Staff appear here once they have a current active facility assignment and match this lifecycle view.'
                        : 'Try clearing the local department, unit or position filters.'}
                    />
                  )}
                  toolbar={(onInputChange) => (
                    <>
                      <TableToolbarSearch
                        persistent
                        onChange={(event) => {
                          setSearch(typeof event === 'string' ? event : event.currentTarget.value);
                          onInputChange(event);
                        }}
                        placeholder="Search staff"
                        aria-label="Search staff"
                      />
                      <Layer>
                        <Popover
                          open={filtersOpen}
                          isTabTip
                          align="bottom-end"
                          onRequestClose={() => setFiltersOpen(false)}
                        >
                          <Button
                            kind="tertiary"
                            size="md"
                            renderIcon={Filter}
                            aria-expanded={filtersOpen}
                            aria-controls={filterPanelId}
                            onClick={() => {
                              if (filtersOpen) {
                                setFiltersOpen(false);
                                return;
                              }
                              setDraftFilters(filters);
                              setFiltersOpen(true);
                            }}
                          >
                            {activeFilterCount > 0 ? `Filters (${activeFilterCount})` : 'Filters'}
                          </Button>
                          <PopoverContent id={filterPanelId} className="facility-staff__filter-popover">
                            <div className="facility-staff__filter-heading">
                              <h2>Filter staff</h2>
                              <p>Refine the current lifecycle view by assignment.</p>
                            </div>
                            <div className="facility-staff__filter-fields">
                              <Select
                                id={`${filterPanelId}-department`}
                                labelText="Department"
                                size="sm"
                                value={draftFilters.department}
                                onChange={(event) => {
                                  const department = event.currentTarget.value;
                                  setDraftFilters((current) => ({ ...current, department, unit: '' }));
                                }}
                              >
                                <SelectItem value="" text="All departments" />
                                {departments.map((department) => (
                                  <SelectItem key={department} value={department} text={department} />
                                ))}
                              </Select>
                              <Select
                                id={`${filterPanelId}-unit`}
                                labelText="Unit"
                                size="sm"
                                value={draftFilters.unit}
                                onChange={(event) => {
                                  const unit = event.currentTarget.value;
                                  setDraftFilters((current) => ({ ...current, unit }));
                                }}
                              >
                                <SelectItem value="" text="All units" />
                                {availableUnits.map((unit) => <SelectItem key={unit} value={unit} text={unit} />)}
                              </Select>
                              <Select
                                id={`${filterPanelId}-position`}
                                labelText="Position"
                                size="sm"
                                value={draftFilters.position}
                                onChange={(event) => {
                                  const position = event.currentTarget.value;
                                  setDraftFilters((current) => ({ ...current, position }));
                                }}
                              >
                                <SelectItem value="" text="All positions" />
                                {positions.map((position) => (
                                  <SelectItem key={position} value={position} text={position} />
                                ))}
                              </Select>
                            </div>
                            <div className="facility-staff__filter-actions">
                              <Button kind="ghost" size="sm" type="button" onClick={resetFilters}>
                                Reset filters
                              </Button>
                              <Button
                                kind="primary"
                                size="sm"
                                type="button"
                                onClick={() => {
                                  setFilters(draftFilters);
                                  setFiltersOpen(false);
                                }}
                              >
                                Apply filters
                              </Button>
                            </div>
                          </PopoverContent>
                        </Popover>
                      </Layer>
                      {activeFilterCount > 0 ? (
                        <div className="facility-staff__active-filters" aria-label="Active staff filters">
                          {activeFilters.map((filter) => (
                            <DismissibleTag
                              key={filter.key}
                              size="sm"
                              type="blue"
                              text={`${filter.label}: ${filter.value}`}
                              title={`Remove ${filter.label.toLocaleLowerCase()} filter`}
                              onClose={() => clearFilter(filter.key)}
                            />
                          ))}
                          <Button kind="ghost" size="sm" type="button" onClick={() => {
                            setFilters({ department: '', unit: '', position: '' });
                            resetFilters();
                          }}>
                            Clear all
                          </Button>
                        </div>
                      ) : null}
                    </>
                  )}
                />
                {pagination.total > pagination.perPage ? (
                  <Pagination
                    id="facility-staff-pagination"
                    aria-label="Staff directory pages"
                    page={pagination.page}
                    pageSize={pagination.perPage}
                    pageSizes={[pagination.perPage]}
                    totalItems={pagination.total}
                    isLastPage={pagination.page >= pagination.lastPage}
                    size="lg"
                    onChange={({ page }) => loadDirectory(effectiveStatus, page, search)}
                  />
                ) : null}
        </section>
      </main>
    </AppLayout>
  );
}
