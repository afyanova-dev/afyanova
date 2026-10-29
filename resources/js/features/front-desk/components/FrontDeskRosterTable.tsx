import {
  Button,
  Search,
  Tag,
} from '@carbon/react';
import type { ReactNode } from 'react';
import { CarbonDataTable, EmptyState } from '@/components/ui';
import { FrontDeskRosterFilter } from './FrontDeskRosterFilter';

export interface FrontDeskRosterRow {
  id: string;
  time: ReactNode;
  patient: ReactNode;
  details: ReactNode;
  status: ReactNode;
  actions: ReactNode;
}

const HEADERS = [
  { key: 'time', header: 'Time' },
  { key: 'patient', header: 'Patient' },
  { key: 'details', header: 'Details' },
  { key: 'status', header: 'Status' },
  { key: 'actions', header: 'Actions' },
];

const STATUS_LABELS: Record<string, string> = {
  BOOKED: 'Booked',
  ARRIVED: 'Arrived',
  ACTIVE: 'In service',
  COMPLETED: 'Completed',
};

export function FrontDeskRosterTable({
  rows,
  title,
  isToday,
  dateLabel,
  status,
  filter,
  query,
  navigating,
  showRegisterAction,
  onQueryChange,
  onClearSearch,
  onApplyStatuses,
  onResetFilters,
  onRegister,
}: {
  rows: FrontDeskRosterRow[];
  title: string;
  isToday: boolean;
  dateLabel: string;
  status: string;
  filter: string;
  query: string;
  navigating: boolean;
  showRegisterAction: boolean;
  onQueryChange: (value: string) => void;
  onClearSearch: () => void;
  onApplyStatuses: (statuses: string[]) => void;
  onResetFilters: () => void;
  onRegister: () => void;
}): ReactNode {
  const selectedStatuses = status === 'ALL'
    ? []
    : status.split(',').filter((value) => value in STATUS_LABELS);
  const statusLabel = selectedStatuses.length === 0
    ? 'All statuses'
    : selectedStatuses.map((value) => STATUS_LABELS[value]).join(', ');
  const hasFilters = selectedStatuses.length > 0 || filter !== '' || query.trim() !== '';

  return (
    <CarbonDataTable
      rows={rows}
      headers={HEADERS}
      title={
        <span className="front-desk-roster__heading">
          <span>{title}</span>
          <span className="front-desk-roster__summary" role="status">
            {rows.length} {rows.length === 1 ? 'roster entry' : 'roster entries'}
            <Tag type="blue">Status: {statusLabel}</Tag>
          </span>
        </span>
      }
      ariaLabel={title}
      ariaBusy={navigating}
      className="front-desk-roster__table"
      toolbarLabel="Roster table toolbar"
      toolbar={(
        <>
          <Search
            id="front-desk-roster-search"
            labelText={`Search roster for ${isToday ? 'today' : dateLabel}`}
            placeholder="Name, patient number, or appointment number"
            value={query}
            onChange={(event) => onQueryChange(event.currentTarget.value)}
            onClear={onClearSearch}
          />
          <FrontDeskRosterFilter
            selectedStatuses={selectedStatuses}
            onApply={onApplyStatuses}
            onReset={onResetFilters}
          />
        </>
      )}
      emptyState={(
        <EmptyState
          title={hasFilters ? 'No roster entries match these filters' : `No patients expected for ${dateLabel}`}
          description={hasFilters ? 'Clear one or more filters to see more roster entries.' : undefined}
          action={showRegisterAction ? (
            <Button
              kind="primary"
              size="sm"
              type="button"
              onClick={onRegister}
            >
              Register new patient
            </Button>
          ) : undefined}
        />
      )}
    />
  );
}
