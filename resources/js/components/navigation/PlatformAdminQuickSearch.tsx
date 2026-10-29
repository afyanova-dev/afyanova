import { router } from '@inertiajs/react';
import { ExpandableSearch, InlineLoading, Tag } from '@carbon/react';
import { useEffect, useRef, useState, type KeyboardEvent, type ReactNode } from 'react';
import { routes } from '@/app/routes';
import { ErrorState } from '@/components/ui';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import { getJson } from '@/lib/http';

const MIN_SEARCH_CHARACTERS = 2;
interface PlatformSearchResult {
  type: 'tenant' | 'facility';
  id: string;
  tenantId: string;
  name: string;
  tenantName: string;
  status: string;
}

interface PlatformSearchResponse {
  results: PlatformSearchResult[];
}

function resultHref(result: PlatformSearchResult): string {
  return result.type === 'tenant'
    ? routes.platformAdmin.tenant(result.tenantId)
    : routes.platformAdmin.facility(result.tenantId, result.id);
}

/** Cross-tenant registry search for platform operators. */
export function PlatformAdminQuickSearch(): ReactNode {
  const [term, setTerm] = useState('');
  const [results, setResults] = useState<PlatformSearchResult[]>([]);
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
    void getJson<PlatformSearchResponse>(`${routes.platformAdmin.search}?${params.toString()}`)
      .then((response) => {
        if (cancelled || requestRef.current !== requestId) return;
        setResults(response.results);
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
      if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
        setOpen(false);
      }
    }
    document.addEventListener('pointerdown', onPointerDown);
    return () => document.removeEventListener('pointerdown', onPointerDown);
  }, [open]);

  const showResults = open && (
    loading
    || errorMessage !== null
    || results.length > 0
    || currentTerm.length >= MIN_SEARCH_CHARACTERS
  );

  function openResult(result: PlatformSearchResult): void {
    requestRef.current += 1;
    setOpen(false);
    setTerm('');
    setResults([]);
    setExpanded(false);
    setFocusedIndex(-1);
    router.visit(resultHref(result));
  }

  function collapse(): void {
    requestRef.current += 1;
    setTerm('');
    setResults([]);
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
        id="global-platform-search"
        labelText="Search tenants and facilities"
        placeholder="Search tenants or facilities"
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
          setLoading(false);
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
        aria-controls="global-platform-search-results"
        aria-activedescendant={focusedIndex >= 0 ? `platform-search-option-${focusedIndex}` : undefined}
      />
      {showResults ? (
        <div
          id="global-platform-search-results"
          role="listbox"
          aria-label="Tenant and facility results"
          className="afyanova-header-search__results"
        >
          {(loading || isPending) && results.length === 0 ? (
            <div className="afyanova-header-search__message">
              <InlineLoading status="active" description="Searching tenants and facilities" />
            </div>
          ) : errorMessage !== null ? (
            <div className="afyanova-header-search__message">
              <ErrorState title="Registry search failed" message={errorMessage} />
            </div>
          ) : results.length > 0 ? (
            <ul className="afyanova-header-search__list">
              {results.map((result, index) => (
                <li
                  key={`${result.type}-${result.id}`}
                  id={`platform-search-option-${index}`}
                  role="option"
                  aria-selected={index === focusedIndex}
                >
                  <a
                    href={resultHref(result)}
                    onMouseDown={(event) => event.preventDefault()}
                    onClick={(event) => {
                      event.preventDefault();
                      openResult(result);
                    }}
                    onMouseEnter={() => setFocusedIndex(index)}
                    className="afyanova-header-search__link"
                  >
                    <span className="afyanova-header-search__identity">
                      <strong>{result.name}</strong>
                      <small>
                        {result.type === 'facility' ? `${result.tenantName} · Facility` : 'Tenant'}
                      </small>
                    </span>
                    <Tag type={result.status === 'ACTIVE' ? 'green' : 'cool-gray'}>{result.status}</Tag>
                  </a>
                </li>
              ))}
            </ul>
          ) : currentTerm.length < MIN_SEARCH_CHARACTERS ? (
            <p className="afyanova-header-search__message">Type at least two characters to search.</p>
          ) : (
            <p className="afyanova-header-search__message">
              No tenants or facilities found for “{currentTerm}”.
            </p>
          )}
        </div>
      ) : null}
    </div>
  );
}
