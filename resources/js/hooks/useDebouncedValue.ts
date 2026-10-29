import { useEffect, useState } from 'react';

/**
 * Debounces a fast-changing control value (Rule 05: JSON typeahead).
 * Page-level search goes through applyListQuery on apply — only the
 * widget-style JSON endpoints need per-keystroke debounce.
 */
export function useDebouncedValue<T>(value: T, delayMs = 300): T {
  const [debounced, setDebounced] = useState(value);

  useEffect(() => {
    const timer = window.setTimeout(() => setDebounced(value), delayMs);
    return () => window.clearTimeout(timer);
  }, [value, delayMs]);

  return debounced;
}
