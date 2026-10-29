import { Pagination } from '@carbon/react';
import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { ErrorState, TableLoadingState } from '@/components/ui';
import { applyListQuery } from '@/lib/lists';
import type { PatientSearchBrowseProps } from '../types/PatientSearchBrowseProps';
import { PatientResultsTable } from './PatientResultsTable';

export function PatientSearchBrowse({
  q,
  mode,
  patients,
  total,
  currentPage,
  lastPage,
  perPage,
  searchError,
}: PatientSearchBrowseProps): ReactNode {
  const [searchValue, setSearchValue] = useState(q);
  const [isLoading, setIsLoading] = useState(false);
  const [requestError, setRequestError] = useState<string | null>(null);

  // Keep the box in sync when `q` changes elsewhere (back/forward).
  // Skipped while focused so a mid-typing echo never clobbers keystrokes.
  const prevQueryRef = useRef(q);
  useEffect(() => {
    if (prevQueryRef.current === q) return;
    prevQueryRef.current = q;
    const active = typeof document === 'undefined' ? null : document.activeElement;
    const box = typeof document === 'undefined' ? null : document.getElementById('patient-search');
    if (active !== null && box !== null && active === box) return;
    setSearchValue(q);
  }, [q]);

  function visit(query: { q?: string; page?: number; per_page?: number }): void {
    setRequestError(null);
    applyListQuery(query, {
      events: {
        onStart: () => setIsLoading(true),
        onFinish: () => setIsLoading(false),
        onNetworkError: () => {
          setRequestError('Could not reach the server. Check your connection and try again.');
        },
        onHttpException: (status) => {
          setRequestError(`The patient request failed with status ${status}.`);
        },
      },
    });
  }

  function queryFor(value: string, page = 1, pageSize = perPage): { q?: string; page?: number; per_page: number } {
    const cleaned = value.trim();

    return {
      ...(cleaned ? { q: cleaned } : {}),
      ...(page > 1 ? { page } : {}),
      per_page: pageSize,
    };
  }

  // Live visits are wrong here: the backend rejects 2-character name
  // fragments, so per-keystroke visits would nuke the table mid-typing
  // (Rule 05: page search applies explicitly). Typing only fills the box —
  // except deleting back to empty, which instantly restores browse: an
  // empty query can never error, so no explicit apply is needed for it.
  const appliedRef = useRef(q.trim());

  function applyNow(value: string): void {
    appliedRef.current = value.trim();
    visit(queryFor(value));
  }

  function submitSearch(event: FormEvent): void {
    event.preventDefault();
    applyNow(searchValue);
  }

  function handleSearchChange(value: string): void {
    setSearchValue(value);
    if (value.trim() === '' && appliedRef.current !== '') {
      applyNow('');
    }
  }

  function clearSearch(): void {
    setSearchValue('');
    if (appliedRef.current !== '') {
      applyNow('');
    }
  }

  const hasRows = patients.length > 0;
  // First load (nothing to keep) skeletons; refresh keeps stale rows with a
  // pending indicator instead of swapping content away (Rule 07).
  const firstLoad = isLoading && !hasRows && !requestError;

  // Table renders bare like the Front Desk roster: TableContainer is
  // already a framed container — nesting it in a Tile adds side gaps.
  return (
    <>
      {requestError ? (
        <ErrorState title="Unable to load patients" message={requestError} />
      ) : firstLoad ? (
        <TableLoadingState label="Loading patient results" />
      ) : (
        <>
          <PatientResultsTable
            patients={patients}
            total={total}
            mode={mode}
            searchError={searchError}
            searchValue={searchValue}
            onSearchChange={handleSearchChange}
            onSubmitSearch={submitSearch}
            onClearSearch={clearSearch}
            isLoading={isLoading}
          />
          {hasRows ? (
            <Pagination
              id="patient-pagination"
              aria-label="Patient result pages"
              page={currentPage}
              pageSize={perPage}
              pageSizes={[15, 30, 50, 100]}
              totalItems={total}
              isLastPage={currentPage >= lastPage}
              disabled={isLoading}
              size="lg"
              onChange={({ page, pageSize }) => visit(queryFor(q, page, pageSize))}
            />
          ) : null}
        </>
      )}
    </>
  );
}
