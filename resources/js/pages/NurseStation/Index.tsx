import { Link } from '@inertiajs/react';
import { Button, DismissibleTag, Search, Select, SelectItem, Tag, Tile } from '@carbon/react';
import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { EmptyState, PageHeader } from '@/components/ui';
import { AppLayout } from '@/layouts/AppLayout';
import type { NurseStationEntry } from '@/features/nurse-station/types';
import { routes } from '@/app/routes';
import { ArrowRight, UserProfile } from '@/icons';

interface NurseStationIndexProps {
  entries: NurseStationEntry[];
}

type QueueSort = 'ACUITY' | 'LONGEST_WAIT' | 'NEWEST';

const PRIORITY_RANK: Record<string, number> = {
  EMERGENCY: 0,
  URGENT: 1,
  ROUTINE: 2,
};

const PRIORITY_LABELS: Record<string, string> = {
  ALL: 'All priorities',
  EMERGENCY: 'Emergency',
  URGENT: 'Urgent',
  ROUTINE: 'Routine',
};

function waitLabel(minutes: number): string {
  if (minutes < 60) return `${minutes} min`;
  const hours = Math.floor(minutes / 60);
  const remaining = minutes % 60;
  return remaining === 0 ? `${hours} hr` : `${hours} hr ${remaining} min`;
}

function priorityRank(priority: string): number {
  return PRIORITY_RANK[priority.toUpperCase()] ?? 3;
}

function statusLabel(status: string): string {
  return status.replaceAll('_', ' ').toLowerCase().replace(/\b\w/g, (letter) => letter.toUpperCase());
}

export default function NurseStationIndex({ entries }: NurseStationIndexProps): ReactNode {
  const [query, setQuery] = useState('');
  const [priority, setPriority] = useState('ALL');
  const [sort, setSort] = useState<QueueSort>('ACUITY');

  function resetFilters(): void {
    setQuery('');
    setPriority('ALL');
    setSort('ACUITY');
  }

  const hasActiveFilters = query.trim() !== '' || priority !== 'ALL' || sort !== 'ACUITY';

  const filteredEntries = useMemo(() => {
    const term = query.trim().toLowerCase();
    const filtered = entries.filter((entry) => {
      if (priority !== 'ALL' && entry.priority.toUpperCase() !== priority) {
        return false;
      }

      return term === '' || [entry.patientName, entry.patientNumber, entry.ticketNumber, entry.queue]
        .some((value) => value.toLowerCase().includes(term));
    });

    return [...filtered].sort((left, right) => {
      if (sort === 'ACUITY') {
        const byPriority = priorityRank(left.priority) - priorityRank(right.priority);
        if (byPriority !== 0) return byPriority;
        return right.waitMinutes - left.waitMinutes;
      }

      if (sort === 'LONGEST_WAIT') {
        return right.waitMinutes - left.waitMinutes;
      }

      return left.waitMinutes - right.waitMinutes;
    });
  }, [entries, priority, query, sort]);

  const badge = filteredEntries.length === entries.length
    ? `${entries.length} in queue`
    : `${filteredEntries.length} of ${entries.length}`;

  return (
    <AppLayout>
      <PageHeader
        title="Nurse Station"
        description="Triage queue and nursing assessment workspace."
        badge={<Tag type="blue">{badge}</Tag>}
      />
      {entries.length === 0 ? (
        <EmptyState
          title="No patients are waiting for nursing"
          description="Open triage queue tickets will appear here when reception routes patients to nursing."
        />
      ) : (
        <>
          <section className="clinician-queue-toolbar" aria-label="Nursing queue filters">
            <Search
              id="nurse-station-queue-search"
              size="md"
              labelText="Search nursing queue"
              placeholder="Patient, number, ticket, or queue"
              value={query}
              onChange={(event) => setQuery(event.currentTarget.value)}
            />
            <Select
              id="nurse-station-queue-priority"
              size="md"
              labelText="Priority"
              value={priority}
              onChange={(event) => setPriority(event.currentTarget.value)}
            >
              <SelectItem value="ALL" text="All priorities" />
              <SelectItem value="EMERGENCY" text="Emergency" />
              <SelectItem value="URGENT" text="Urgent" />
              <SelectItem value="ROUTINE" text="Routine" />
            </Select>
            <Select
              id="nurse-station-queue-sort"
              size="md"
              labelText="Sort"
              value={sort}
              onChange={(event) => setSort(event.currentTarget.value as QueueSort)}
            >
              <SelectItem value="ACUITY" text="Acuity, then longest wait" />
              <SelectItem value="LONGEST_WAIT" text="Longest wait" />
              <SelectItem value="NEWEST" text="Newest arrival" />
            </Select>
          </section>

          {hasActiveFilters ? (
            <div className="clinician-queue-active-filters" aria-label="Active queue filters">
              <span className="clinician-queue-active-filters__label">Active filters:</span>
              <div className="clinician-queue-active-filters__tags">
                {query.trim() !== '' ? (
                  <DismissibleTag
                    size="sm"
                    type="blue"
                    text={`Search: "${query.trim()}"`}
                    title="Clear search filter"
                    onClose={() => setQuery('')}
                  />
                ) : null}
                {priority !== 'ALL' ? (
                  <DismissibleTag
                    size="sm"
                    type="purple"
                    text={`Priority: ${PRIORITY_LABELS[priority] ?? priority}`}
                    title="Clear priority filter"
                    onClose={() => setPriority('ALL')}
                  />
                ) : null}
                {sort !== 'ACUITY' ? (
                  <DismissibleTag
                    size="sm"
                    type="cool-gray"
                    text={`Sort: ${sort === 'LONGEST_WAIT' ? 'Longest wait' : 'Newest arrival'}`}
                    title="Reset sort"
                    onClose={() => setSort('ACUITY')}
                  />
                ) : null}
                <Button
                  kind="ghost"
                  size="sm"
                  type="button"
                  onClick={resetFilters}
                  className="clinician-queue-active-filters__reset"
                >
                  Reset filters
                </Button>
              </div>
            </div>
          ) : null}

          {filteredEntries.length === 0 ? (
            <EmptyState
              title="No matching patients"
              description="Adjust the search or priority filter to see the rest of the nursing queue."
              action={(
                <Button kind="secondary" size="md" onClick={resetFilters}>
                  Reset filters
                </Button>
              )}
            />
          ) : (
            <div className="clinician-queue-list">
              {filteredEntries.map((entry) => (
                <Tile
                  key={entry.id}
                  className={`afy-card clinician-queue-card clinician-queue-card--${entry.priority.toLowerCase()}`}
                >
                  <div className="clinician-queue-card__main">
                    <div className="clinician-queue-card__heading">
                      <h2>{entry.patientName}</h2>
                      <Tag type={entry.priority === 'EMERGENCY' ? 'red' : entry.priority === 'URGENT' ? 'purple' : 'gray'}>
                        {entry.priority}
                      </Tag>
                      <Tag type="blue">{statusLabel(entry.status)}</Tag>
                    </div>
                    <p className="clinician-queue-card__meta">
                      <span>{entry.ticketNumber}</span>
                      <span>{entry.patientNumber}</span>
                      <span>{entry.queue}</span>
                      <span>Waiting {waitLabel(entry.waitMinutes)}</span>
                    </p>
                  </div>
                  <div className="clinician-queue-card__actions">
                    <Button
                      className="clinician-queue-card__chart-action"
                      kind="ghost"
                      size="lg"
                      hasIconOnly
                      renderIcon={UserProfile}
                      iconDescription="Open patient chart"
                      tooltipPosition="bottom"
                      as={Link}
                      href={routes.patient.chart(entry.patientId)}
                    />
                    <Button
                      className="clinician-queue-card__action"
                      size="lg"
                      renderIcon={ArrowRight}
                      as={Link}
                      href={routes.workspaces.nurseStationEntry(entry.id)}
                    >
                      {entry.status === 'WAITING' ? 'Review queue ticket' : 'Open assessment'}
                    </Button>
                  </div>
                </Tile>
              ))}
            </div>
          )}
        </>
      )}
    </AppLayout>
  );
}