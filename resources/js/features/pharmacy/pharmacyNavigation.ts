import { router } from '@inertiajs/react';

export function withQuery(currentUrl: string, updates: Record<string, string | null>): string {
  const url = new URL(currentUrl, 'http://afyanova.local');
  for (const [key, value] of Object.entries(updates)) {
    if (value === null || value === '') url.searchParams.delete(key);
    else url.searchParams.set(key, value);
  }
  const query = url.searchParams.toString();
  return query === '' ? url.pathname : `${url.pathname}?${query}`;
}

/**
 * In-place navigation inside the pharmacy workspace: background prop
 * refresh with the URL kept in sync. Tab switches, filters, and detail
 * open/close must never remount the page (no full-visit flash).
 */
export function refreshInPlace(currentUrl: string, updates: Record<string, string | null>): void {
  router.get(withQuery(currentUrl, updates), {}, { preserveScroll: true, preserveState: true, replace: true });
}
