import { router } from '@inertiajs/react';
import { ExpandableSearch, InlineLoading, Tag } from '@carbon/react';
import { useEffect, useRef, useState, type KeyboardEvent, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import { ErrorState } from '@/components/ui';
import {
  MIN_SEARCH_CHARACTERS,
  PatientSearchPhoto,
  PatientSearchText,
  usePatientSearch,
} from '@/components/patient-search';
import { permissions, useCan } from '@/lib/authorization';

const RESULT_LIMIT = 5;

/**
 * Global patient quick search (header utility, Carbon §5). OpenMRS-style
 * typeahead over the single shared patient-search contract: debounced,
 * minimum-length gated, capped, keyboard-navigable, no client cache, no
 * tracking store. Carbon ExpandableSearch: collapsed to an icon until
 * needed, so the header never crowds facility/account/Switcher.
 */
export function PatientQuickSearch(): ReactNode {
  const can = useCan();
  const [term, setTerm] = useState('');
  const [open, setOpen] = useState(false);
  const [expanded, setExpanded] = useState(false);
  const [focusedIndex, setFocusedIndex] = useState(-1);
  const containerRef = useRef<HTMLDivElement>(null);

  const { rows, loading, errorMessage } = usePatientSearch(term, { enabled: open, perPage: RESULT_LIMIT });
  const trimmed = term.trim();

  useEffect(() => {
    setFocusedIndex(-1);
  }, [rows]);

  useEffect(() => {
    if (!open) return;
    function onPointerDown(event: PointerEvent): void {
      if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
        setOpen(false);
      }
    }
    document.addEventListener('pointerdown', onPointerDown);
    return () => document.removeEventListener('pointerdown', onPointerDown);
  }, [open ]);

  if (!can(permissions.patient.read)) return null;

  const showResults = open && (loading || errorMessage !== null || rows.length > 0 || trimmed.length >= MIN_SEARCH_CHARACTERS);

  function openChart(patientId: string): void {
    setOpen(false);
    setTerm('');
    setExpanded(false);
    setFocusedIndex(-1);
    router.visit(routes.patient.chart(patientId));
  }

  function openBrowse(): void {
    setOpen(false);
    setExpanded(false);
    setFocusedIndex(-1);
    router.visit(`${routes.workspaces.patientIndex}?q=${encodeURIComponent(trimmed)}`);
  }

  function collapse(): void {
    setTerm('');
    setOpen(false);
    setExpanded(false);
    setFocusedIndex(-1);
  }

  function onInputKeyDown(event: KeyboardEvent<HTMLInputElement>): void {
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      setOpen(true);
      setFocusedIndex((index) => Math.min(index + 1, rows.length - 1));
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      setFocusedIndex((index) => (index <= 0 ? -1 : index - 1));
    } else if (event.key === 'Enter') {
      if (focusedIndex >= 0 && focusedIndex < rows.length) {
        event.preventDefault();
        const row = rows[focusedIndex];
        if (row) openChart(row.patientId);
      } else if (trimmed !== '') {
        event.preventDefault();
        openBrowse();
      }
    }
  }

  return (
    <div
      ref={containerRef}
      className="afyanova-header-search"
      style={{ position: 'relative' }}
      onKeyDownCapture={(event: KeyboardEvent<HTMLDivElement>) => {
        // Carbon Search consumes Escape for its own clear behavior, so close
        // on capture; clearing here matches the O3 close-and-reset contract.
        if (event.key === 'Escape') {
          collapse();
        }
      }}
    >
      <ExpandableSearch
        id="global-patient-search"
        labelText="Search patients"
        placeholder="Search patients by name or ID"
        value={term}
        size="lg"
        isExpanded={expanded}
        onExpand={() => {
          setExpanded(true);
          setOpen(true);
        }}
        onBlur={() => {
          if (term.trim() === '') {
            setExpanded(false);
            setOpen(false);
          }
        }}
        onChange={(event) => {
          // Ignore Carbon's synthetic clear event (programmatic call with a
          // detached target carrying the stale DOM value); the onClear prop
          // below owns that path. Trust only real input events, so a cleared
          // field can never resurrect a stale term and reopen the dropdown.
          if (event.target !== event.currentTarget) return;
          const value = event.currentTarget.value;
          setTerm(value);
          setOpen(value !== '');
          setExpanded(true);
        }}
        onFocus={() => setOpen(true)}
        onClear={() => {
          setTerm('');
          setOpen(false);
        }}
        onKeyDown={onInputKeyDown}
        role="combobox"
        aria-expanded={showResults}
        aria-controls="global-patient-search-results"
        aria-activedescendant={focusedIndex >= 0 ? `quick-search-option-${focusedIndex}` : undefined}
      />
      {showResults ? (
        <div
          id="global-patient-search-results"
          role="listbox"
          aria-label="Patient results"
          className="afyanova-header-search__results"
        >
          {rows.length > 0 ? (
            <>
              {loading ? (
                <div className="afyanova-header-search__message">
                  <InlineLoading status="active" description="Searching patients" />
                </div>
              ) : null}
              <ul className="afyanova-header-search__list">
                {rows.map((row, index) => (
                  <li
                    key={row.patientId}
                    id={`quick-search-option-${index}`}
                    role="option"
                    aria-selected={index === focusedIndex}
                  >
                    <a
                      href={routes.patient.chart(row.patientId)}
                      className="afyanova-header-search__link"
                      onMouseDown={(event) => {
                        // Keep focus in the search input: without this the
                        // mousedown blurs the input and any state churn from
                        // that (or from an in-flight refetch) can detach the
                        // link before click fires — a dead click with no
                        // navigation and no fallback.
                        event.preventDefault();
                      }}
                      onClick={(event) => {
                        event.preventDefault();
                        openChart(row.patientId);
                      }}
                      onMouseEnter={() => setFocusedIndex(index)}
                    >
                      <PatientSearchPhoto row={row} className="afyanova-header-search__photo" />
                      <span className="afyanova-header-search__identity">
                        <PatientSearchText row={row} />
                      </span>
                      {row.activeEncounter ? <Tag type="blue">Visiting</Tag> : null}
                    </a>
                  </li>
                ))}
              </ul>
            </>
          ) : loading ? (
            <div className="afyanova-header-search__message">
              <InlineLoading status="active" description="Searching patients" />
            </div>
          ) : errorMessage !== null ? (
            <div className="afyanova-header-search__message">
              <ErrorState title="Patient search failed" message={errorMessage} />
            </div>
          ) : (
            <p className="afyanova-header-search__message">
              {trimmed === '' ? 'Start typing to find a patient.' : `No patients found for “${trimmed}”.`}
            </p>
          )}
        </div>
      ) : null}
    </div>
  );
}
