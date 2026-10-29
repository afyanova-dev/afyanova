import { router } from '@inertiajs/react';

export type ListQueryValue = string | number | undefined | null;

export interface ListQueryEvents {
  onStart?: () => void;
  onFinish?: () => void;
  onNetworkError?: () => void;
  onHttpException?: (status: number) => void;
}

/**
 * Applies list state (search / filter / sort / page) to the URL (Rule 05).
 *
 * The backend owns every key: only send params the controller reads, and the
 * echoed props coming back are the applied truth. Empty values are stripped
 * so the URL stays shareable; state and scroll are preserved so typing and
 * paging do not reset the page. Events drive regional loading/error states.
 */
export function applyListQuery(
  query: Record<string, ListQueryValue>,
  options?: { replace?: boolean; events?: ListQueryEvents },
): void {
  const cleaned: Record<string, string | number> = {};
  for (const [key, value] of Object.entries(query)) {
    if (value === undefined || value === null || value === '') continue;
    cleaned[key] = value;
  }

  const events = options?.events;
  router.get(window.location.pathname, cleaned, {
    preserveState: true,
    preserveScroll: true,
    replace: options?.replace ?? true,
    onStart: events?.onStart,
    onFinish: events?.onFinish,
    onNetworkError: events?.onNetworkError,
    onHttpException: events?.onHttpException
      ? (response) => events.onHttpException?.(response.status)
      : undefined,
  });
}
