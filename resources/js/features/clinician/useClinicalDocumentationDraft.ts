import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { routes } from '@/app/routes';
import { sendJson } from '@/lib/http/json';

export type ClinicalDraftStatus = 'idle' | 'unsaved' | 'saving' | 'saved' | 'error';
export type ClinicalDraftSection = 'NOTE' | 'DIAGNOSIS' | 'PLAN';

interface UseClinicalDocumentationDraftOptions {
  encounterId: string;
  patientId: string;
  section: ClinicalDraftSection;
  values: Record<string, unknown>;
  hasContent: boolean;
  enabled: boolean;
  initialSavedAt?: string | null;
}

interface ClinicalDocumentationDraft {
  status: ClinicalDraftStatus;
  savedAt: string | null;
  error: string | null;
  pause: () => void;
  resume: () => void;
  saveNow: () => void;
  discard: () => Promise<void>;
  clear: () => void;
}

function csrfToken(): string | undefined {
  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
}

/**
 * Persists one section of the clinician workspace to the author's private,
 * short-lived server draft. The returned status is intentionally small so the
 * workspace can show one quiet line instead of a panel per form.
 */
export function useClinicalDocumentationDraft({
  encounterId,
  patientId,
  section,
  values,
  hasContent,
  enabled,
  initialSavedAt = null,
}: UseClinicalDocumentationDraftOptions): ClinicalDocumentationDraft {
  const url = routes.workspaces.encounterDocumentationDraft(encounterId);
  const deleteUrl = routes.workspaces.encounterDocumentationDraftSection(encounterId, section);
  const serialized = JSON.stringify(values);
  const valuesRef = useRef(values);
  const hasContentRef = useRef(hasContent);
  const enabledRef = useRef(enabled);
  const pausedRef = useRef(false);
  const inFlightRef = useRef<Promise<void> | null>(null);
  const queuedRef = useRef(false);
  const lastSavedRef = useRef(serialized);

  valuesRef.current = values;
  hasContentRef.current = hasContent;
  enabledRef.current = enabled;

  const [status, setStatus] = useState<ClinicalDraftStatus>(initialSavedAt ? 'saved' : 'idle');
  const [savedAt, setSavedAt] = useState<string | null>(initialSavedAt);
  const [error, setError] = useState<string | null>(null);
  const [flushNonce, setFlushNonce] = useState(0);

  const persist = useCallback((
    serializedValues: string,
    payload: Record<string, unknown>,
  ): Promise<void> => {
    if (
      !enabledRef.current
      || !hasContentRef.current
      || pausedRef.current
      || serializedValues === lastSavedRef.current
    ) {
      return Promise.resolve();
    }

    if (inFlightRef.current) {
      queuedRef.current = true;
      return inFlightRef.current;
    }

    const request = (async (): Promise<void> => {
      setStatus('saving');

      try {
        const response = await sendJson<{ savedAt: string }>(url, 'POST', {
          section,
          patient_id: patientId,
          values: payload,
        });
        lastSavedRef.current = serializedValues;
        setSavedAt(response.savedAt);
        setError(null);
        setStatus('saved');
      } catch (caught) {
        setError(caught instanceof Error ? caught.message : 'The draft could not be saved.');
        setStatus('error');
      } finally {
        inFlightRef.current = null;
        if (queuedRef.current) {
          queuedRef.current = false;
          setFlushNonce((current) => current + 1);
        }
      }
    })();

    inFlightRef.current = request;
    return request;
  }, [patientId, section, url]);

  useEffect(() => {
    if (!enabled || !hasContent || pausedRef.current) {
      return;
    }

    if (serialized === lastSavedRef.current) {
      return;
    }

    setStatus('unsaved');
    const timer = window.setTimeout(() => {
      void persist(serialized, valuesRef.current);
    }, 650);

    return () => window.clearTimeout(timer);
  }, [enabled, flushNonce, hasContent, persist, serialized]);

  useEffect(() => {
    function retryWhenOnline(): void {
      if (
        !enabledRef.current
        || !hasContentRef.current
        || pausedRef.current
        || JSON.stringify(valuesRef.current) === lastSavedRef.current
      ) {
        return;
      }

      setFlushNonce((current) => current + 1);
    }

    window.addEventListener('online', retryWhenOnline);
    return () => window.removeEventListener('online', retryWhenOnline);
  }, []);

  useEffect(() => {
    function flush(): void {
      if (
        !enabledRef.current
        || !hasContentRef.current
        || pausedRef.current
        || JSON.stringify(valuesRef.current) === lastSavedRef.current
      ) {
        return;
      }

      const payload = new FormData();
      const token = csrfToken();
      if (token) {
        payload.append('_token', token);
      }
      payload.append('section', section);
      payload.append('patient_id', patientId);
      Object.entries(valuesRef.current).forEach(([key, value]) => {
        payload.append(`values[${key}]`, value === null || value === undefined ? '' : String(value));
      });

      navigator.sendBeacon?.(url, payload);
    }

    function onVisibilityChange(): void {
      if (document.visibilityState === 'hidden') {
        flush();
      }
    }

    window.addEventListener('pagehide', flush);
    document.addEventListener('visibilitychange', onVisibilityChange);
    return () => {
      window.removeEventListener('pagehide', flush);
      document.removeEventListener('visibilitychange', onVisibilityChange);
    };
  }, [patientId, section, url]);

  const pause = useCallback((): void => {
    pausedRef.current = true;
  }, []);

  const resume = useCallback((): void => {
    pausedRef.current = false;
    setFlushNonce((current) => current + 1);
  }, []);

  const saveNow = useCallback((): void => {
    const latest = JSON.stringify(valuesRef.current);
    void persist(latest, valuesRef.current);
  }, [persist]);

  const discard = useCallback(async (): Promise<void> => {
    pausedRef.current = true;
    queuedRef.current = false;

    if (inFlightRef.current) {
      await inFlightRef.current;
    }

    queuedRef.current = false;

    try {
      await sendJson<void>(deleteUrl, 'DELETE');
    } catch (caught) {
      pausedRef.current = false;
      setError(caught instanceof Error ? caught.message : 'The draft could not be discarded.');
      setStatus('error');
      setFlushNonce((current) => current + 1);
      throw caught;
    }

    lastSavedRef.current = '';
    setError(null);
    setSavedAt(null);
    setStatus('idle');
  }, [deleteUrl]);

  const clear = useCallback((): void => {
    lastSavedRef.current = '';
    queuedRef.current = false;
    setError(null);
    setSavedAt(null);
    setStatus('idle');
  }, []);

  return { status, savedAt, error, pause, resume, saveNow, discard, clear };
}

/**
 * Warns before losing an unsaved draft on a browser navigation or an Inertia
 * GET visit. Form POSTs (the actual save) are deliberately not intercepted.
 */
export function useClinicalDraftNavigationGuard(hasUnsavedChanges: boolean): void {
  useEffect(() => {
    if (typeof window === 'undefined') {
      return;
    }

    function onBeforeUnload(event: BeforeUnloadEvent): void {
      if (!hasUnsavedChanges) {
        return;
      }

      event.preventDefault();
      event.returnValue = '';
    }

    window.addEventListener('beforeunload', onBeforeUnload);
    const removeInertiaListener = router.on('before', (event) => {
      if (!hasUnsavedChanges || event.detail.visit.method.toLowerCase() !== 'get') {
        return;
      }

      if (!window.confirm('You have clinical changes that are not safely saved. Leave this page? Pending changes may be lost.')) {
        return false;
      }
    });

    return () => {
      window.removeEventListener('beforeunload', onBeforeUnload);
      removeInertiaListener();
    };
  }, [hasUnsavedChanges]);
}
