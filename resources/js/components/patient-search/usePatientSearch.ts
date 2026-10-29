import { useEffect, useRef, useState } from 'react';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import {
  MIN_SEARCH_CHARACTERS,
  searchPatients,
  type PatientSearchRow,
} from './patientSearch';

/**
 * Shared debounced patient search over the single JSON contract. Fragments
 * below the backend minimum never match (exact MRN/phone/ID classification
 * still applies at/above it). Stale responses are dropped by request id —
 * no client cache, no tracking store.
 */
export function usePatientSearch(
  term: string,
  options: { enabled: boolean; perPage?: number; debounceMs?: number; fetchWhenEmpty?: boolean },
): { rows: PatientSearchRow[]; loading: boolean; isPending: boolean; errorMessage: string | null } {
  const { enabled, perPage = 5, debounceMs = 300, fetchWhenEmpty = true } = options;
  const [rows, setRows] = useState<PatientSearchRow[]>([]);
  const [loading, setLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [settledTerm, setSettledTerm] = useState<string | null>(null);
  const requestRef = useRef(0);

  const debouncedTerm = useDebouncedValue(term, debounceMs);
  const trimmed = debouncedTerm.trim();
  const requestable = trimmed.length >= MIN_SEARCH_CHARACTERS || (trimmed === '' && fetchWhenEmpty);
  const isPending = enabled && (
    term.trim() !== trimmed || (requestable && settledTerm !== trimmed)
  );

  useEffect(() => {
    if (!enabled) return;
    if (trimmed === '' && !fetchWhenEmpty) {
      setRows([]);
      setErrorMessage(null);
      setLoading(false);
      setSettledTerm(trimmed);
      return;
    }
    if (trimmed !== '' && trimmed.length < MIN_SEARCH_CHARACTERS) {
      setRows([]);
      setErrorMessage(null);
      setLoading(false);
      setSettledTerm(trimmed);
      return;
    }
    const requestId = (requestRef.current += 1);
    setLoading(true);
    setErrorMessage(null);
    void searchPatients(trimmed, perPage)
      .then((response) => {
        if (requestRef.current !== requestId) return;
        setRows(response.patients);
        setErrorMessage(response.error ?? null);
        setSettledTerm(trimmed);
      })
      .catch(() => {
        if (requestRef.current !== requestId) return;
        setRows([]);
        setErrorMessage('Could not reach the server. Check your connection and try again.');
        setSettledTerm(trimmed);
      })
      .finally(() => {
        if (requestRef.current === requestId) setLoading(false);
      });
  }, [debouncedTerm, enabled, perPage, trimmed, fetchWhenEmpty]);

  return { rows, loading, isPending, errorMessage };
}
