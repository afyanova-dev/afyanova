import { router } from '@inertiajs/react';
import { ExpandableSearch, InlineLoading, Tag } from '@carbon/react';
import { useEffect, useRef, useState, type KeyboardEvent, type ReactNode } from 'react';
import { permissions, useCan } from '@/lib/authorization';
import { ErrorState } from '@/components/ui';
import { getJson } from '@/lib/http';
import { staffStatusLabel } from '@/lib/staffStatus';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import { routes } from '@/app/routes';

const MIN_SEARCH_CHARACTERS = 2;

interface PeopleSearchResult {
  type: 'employee' | 'user';
  id: string;
  name: string;
  detail: string;
  status: string;
  accountEmail: string | null;
  photoUrl: string | null;
  href: string;
}

interface PeopleSearchResponse {
  results: PeopleSearchResult[];
}

function statusTag(status: string): 'green' | 'red' | 'blue' | 'cool-gray' {
  if (status === 'ACTIVE') return 'green';
  if (status === 'TERMINATED' || status === 'SUSPENDED') return 'red';
  if (status === 'SETUP PENDING') return 'blue';

  return 'cool-gray';
}

/** Shared identity photo: 32px image or initial placeholder (patient-search pattern). */
function PeopleSearchPhoto({ result }: { result: Pick<PeopleSearchResult, 'name' | 'photoUrl'> }): ReactNode {
  if (!result.photoUrl) {
    return (
      <span
        aria-hidden="true"
        className="afyanova-header-search__photo"
        style={{
          inlineSize: '2rem',
          blockSize: '2rem',
          flexShrink: 0,
          display: 'grid',
          placeItems: 'center',
        }}
      >
        {result.name.charAt(0).toUpperCase()}
      </span>
    );
  }
  return (
    <img
      src={result.photoUrl}
      alt=""
      width={32}
      height={32}
      className="afyanova-header-search__photo"
      style={{ flexShrink: 0 }}
    />
  );
}

export function FacilityPeopleQuickSearch(): ReactNode {
  const can = useCan();
  const canSearch = can(permissions.hr.staffRead)
    || can(permissions.hr.staffManage)
    || can(permissions.identity.usersRead);
  const [term, setTerm] = useState('');
  const [results, setResults] = useState<PeopleSearchResult[]>([]);
  const [loading, setLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [open, setOpen] = useState(false);
  const [expanded, setExpanded] = useState(false);
  const [focusedIndex, setFocusedIndex] = useState(-1);
  const containerRef = useRef<HTMLDivElement>(null);
  const requestRef = useRef(0);
  const debouncedTerm = useDebouncedValue(term, 300);
  const trimmed = debouncedTerm.trim();
  const currentTerm = term.trim();
  const isPending = currentTerm.length >= MIN_SEARCH_CHARACTERS && currentTerm !== trimmed;

  useEffect(() => {
    if (trimmed.length < MIN_SEARCH_CHARACTERS) {
      requestRef.current += 1;
      setResults([]);
      setErrorMessage(null);
      setLoading(false);
      return;
    }

    const requestId = (requestRef.current += 1);
    let cancelled = false;
    setLoading(true);
    setErrorMessage(null);
    const params = new URLSearchParams({ q: trimmed });
    void getJson<PeopleSearchResponse>(`${routes.workspaces.hrPeopleSearch}?${params.toString()}`)
      .then((response) => {
        if (!cancelled && requestRef.current === requestId) setResults(response.results);
      })
      .catch(() => {
        if (cancelled || requestRef.current !== requestId) return;
        setResults([]);
        setErrorMessage('Could not reach the server. Check your connection and try again.');
      })
      .finally(() => {
        if (!cancelled && requestRef.current === requestId) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [trimmed]);

  useEffect(() => {
    setFocusedIndex(-1);
  }, [results]);

  useEffect(() => {
    if (!open) return;
    function onPointerDown(event: PointerEvent): void {
      if (containerRef.current && !containerRef.current.contains(event.target as Node)) setOpen(false);
    }
    document.addEventListener('pointerdown', onPointerDown);
    return () => document.removeEventListener('pointerdown', onPointerDown);
  }, [open]);

  if (!canSearch) return null;

  const showResults = open && (
    loading || isPending || errorMessage !== null || results.length > 0
    || currentTerm.length >= MIN_SEARCH_CHARACTERS
  );

  function openResult(result: PeopleSearchResult): void {
    requestRef.current += 1;
    setOpen(false);
    setExpanded(false);
    setTerm('');
    setResults([]);
    setFocusedIndex(-1);
    router.visit(result.href);
  }

  function collapse(): void {
    requestRef.current += 1;
    setTerm('');
    setResults([]);
    setErrorMessage(null);
    setOpen(false);
    setExpanded(false);
    setFocusedIndex(-1);
  }

  function onInputKeyDown(event: KeyboardEvent<HTMLInputElement>): void {
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      setOpen(true);
      setFocusedIndex((index) => Math.min(index + 1, results.length - 1));
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      setFocusedIndex((index) => (index <= 0 ? -1 : index - 1));
    } else if (event.key === 'Enter' && focusedIndex >= 0 && focusedIndex < results.length) {
      event.preventDefault();
      const result = results[focusedIndex];
      if (result) openResult(result);
    }
  }

  return (
    <div
      ref={containerRef}
      className="afyanova-header-search"
      style={{ position: 'relative' }}
      onKeyDownCapture={(event: KeyboardEvent<HTMLDivElement>) => {
        if (event.key === 'Escape') collapse();
      }}
    >
      <ExpandableSearch
        id="global-facility-people-search"
        labelText="Search users and employees"
        placeholder="Search users or employees"
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
          if (event.target !== event.currentTarget) return;
          const value = event.currentTarget.value;
          requestRef.current += 1;
          setTerm(value);
          setResults([]);
          setErrorMessage(null);
          setOpen(value !== '');
        }}
        onFocus={() => setOpen(true)}
        onClear={() => {
          requestRef.current += 1;
          setTerm('');
          setResults([]);
          setOpen(false);
        }}
        onKeyDown={onInputKeyDown}
        role="combobox"
        aria-expanded={showResults}
        aria-controls="global-facility-people-results"
        aria-activedescendant={focusedIndex >= 0 ? `facility-people-option-${focusedIndex}` : undefined}
      />
      {showResults ? (
        <div
          id="global-facility-people-results"
          role="listbox"
          aria-label="User and employee results"
          className="afyanova-header-search__results"
        >
          {(loading || isPending) && results.length === 0 ? (
            <div className="afyanova-header-search__message">
              <InlineLoading status="active" description="Searching people" />
            </div>
          ) : errorMessage !== null ? (
            <div className="afyanova-header-search__message">
              <ErrorState title="People search failed" message={errorMessage} />
            </div>
          ) : results.length > 0 ? (
            <ul className="afyanova-header-search__list">
              {results.map((result, index) => (
                <li
                  key={`${result.type}-${result.id}`}
                  id={`facility-people-option-${index}`}
                  role="option"
                  aria-selected={index === focusedIndex}
                >
                  <a
                    href={result.href}
                    onMouseDown={(event) => event.preventDefault()}
                    onClick={(event) => {
                      event.preventDefault();
                      openResult(result);
                    }}
                    onMouseEnter={() => setFocusedIndex(index)}
                    className="afyanova-header-search__link"
                  >
                    <PeopleSearchPhoto result={result} />
                    <span className="afyanova-header-search__identity">
                      <strong>{result.name}</strong>
                      <small>{result.type === 'employee' ? `Employee · ${result.detail}` : `User · ${result.detail}`}</small>
                      {result.accountEmail ? <small>Account · {result.accountEmail}</small> : null}
                    </span>
                    <Tag type={statusTag(result.status)} style={{ marginInlineStart: 'auto' }}>{staffStatusLabel(result.status)}</Tag>
                  </a>
                </li>
              ))}
            </ul>
          ) : currentTerm.length < MIN_SEARCH_CHARACTERS ? (
            <p className="afyanova-header-search__message">Type at least two characters to search.</p>
          ) : (
            <p className="afyanova-header-search__message">No users or employees found for “{currentTerm}”.</p>
          )}
        </div>
      ) : null}
    </div>
  );
}
