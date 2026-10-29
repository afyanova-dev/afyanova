import {
  Button,
  Search,
} from '@carbon/react';
import { CarbonDataTable, EmptyState, ErrorState } from '@/components/ui';
import { Link } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { useState } from 'react';
import { routes } from '@/app/routes';
import type { PatientSearchResult } from '../types/PatientSearchBrowseProps';
import { Search as SearchIcon } from '@/icons';

const HEADERS = [
  { key: 'patientNumber', header: 'Patient number' },
  { key: 'displayName', header: 'Name' },
  { key: 'sex', header: 'Sex' },
  { key: 'status', header: 'Status' },
];

interface PatientTableRow {
  id: string;
  patientNumber: string;
  displayName: string;
  sex: string;
  status: string;
}

/** Row photo with initial placeholder — same convention as the roster and
 * quick-search rows. Endpoint 404s (no photo) fall back via onError. */
function RowPhoto({ patientId, displayName }: { patientId: string; displayName: string }): ReactNode {
  const [failed, setFailed] = useState(false);
  if (failed) {
    return (
      <span
        aria-hidden="true"
        style={{
          inlineSize: '2rem',
          blockSize: '2rem',
          flexShrink: 0,
          display: 'grid',
          placeItems: 'center',
        }}
      >
        {displayName.charAt(0).toUpperCase()}
      </span>
    );
  }
  return (
    <img
      src={routes.patient.photo(patientId)}
      alt=""
      width={32}
      height={32}
      loading="lazy"
      style={{ flexShrink: 0 }}
      onError={() => setFailed(true)}
    />
  );
}

/**
 * Full Carbon DataTable for patient results: toolbar search, table, and
 * pagination inside one `TableContainer`. Search and paging stay
 * server-driven (same `q`/`page`/`per_page` contract as the parent) —
 * headers are deliberately not sortable: row order is backend-owned
 * (newest first / relevance) and client sorting would lie across pages.
 */
export function PatientResultsTable({
  patients,
  total,
  mode,
  searchError,
  searchValue,
  onSearchChange,
  onSubmitSearch,
  onClearSearch,
  isLoading,
}: {
  patients: PatientSearchResult[];
  total: number;
  mode: 'browse' | 'search';
  searchError: string | null;
  searchValue: string;
  onSearchChange: (value: string) => void;
  onSubmitSearch: (event: FormEvent) => void;
  onClearSearch: () => void;
  isLoading: boolean;
}): ReactNode {
  const rows: PatientTableRow[] = patients.map((patient) => ({
    id: patient.patientId,
    patientNumber: patient.patientNumber,
    displayName: patient.displayName,
    sex: patient.sex,
    status: patient.status,
  }));

  return (
    <CarbonDataTable
      rows={rows}
      headers={HEADERS}
      title={(
        <span style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', inlineSize: '100%', gap: '1rem' }}>
          <span>Patients</span>
          <span role="status" style={{ whiteSpace: 'nowrap',  }}>
            {total} {total === 1 ? 'patient' : 'patients'}
          </span>
        </span>
      )}
      ariaLabel="Patients"
      ariaBusy={isLoading}
      toolbarLabel="Patient results toolbar"
      toolbar={(
        <form
          aria-label="Patient search"
          onSubmit={onSubmitSearch}
          style={{ display: 'flex', alignItems: 'end', gap: '0.5rem', flex: 1 }}
        >
          <div style={{ flex: 1 }}>
            <Search
              id="patient-search"
              labelText="Search patients"
              placeholder="Name, patient number, phone, or identifier"
              value={searchValue}
              onChange={(event) => onSearchChange(event.currentTarget.value)}
              onClear={onClearSearch}
              disabled={isLoading}
              size="lg"
            />
          </div>
          <Button type="submit" disabled={isLoading} size="lg" renderIcon={SearchIcon}>
            Search
          </Button>
        </form>
      )}
      emptyState={searchError ? (
        <div className="afyanova-full-alert">
          <ErrorState title="Search needs more detail" message={searchError} />
        </div>
      ) : (
        <EmptyState
          title={mode === 'browse' ? 'No patients have been registered yet.' : 'No patients found'}
          description={mode === 'search' ? 'Try a different name, patient number, phone number, or identifier.' : undefined}
        />
      )}
      renderCell={(cell, row) => (
        cell.info.header === 'patientNumber' ? (
          <Link className="cds--link" href={routes.patient.chart(row.id)}>
            {cell.value}
          </Link>
        ) : cell.info.header === 'displayName' ? (
          <span style={{ display: 'flex', alignItems: 'center', gap: '0.75rem' }}>
            <RowPhoto patientId={row.id} displayName={String(cell.value ?? '')} />
            <Link className="cds--link" href={routes.patient.chart(row.id)}>
              {cell.value}
            </Link>
          </span>
        ) : cell.value
      )}
    />
  );
}