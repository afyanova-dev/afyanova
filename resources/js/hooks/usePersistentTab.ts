import { useCallback, useEffect, useState } from 'react';

function readTabIndex(parameter: string, tabs: readonly string[]): number {
  const selectedTab = new URLSearchParams(window.location.search).get(parameter);
  const selectedIndex = tabs.indexOf(selectedTab ?? '');

  return selectedIndex >= 0 ? selectedIndex : 0;
}

export function usePersistentTab(
  parameter: string,
  tabs: readonly string[],
): [number, (index: number) => void] {
  const [selectedIndex, setSelectedIndex] = useState(() => readTabIndex(parameter, tabs));

  useEffect(() => {
    const syncFromUrl = (): void => setSelectedIndex(readTabIndex(parameter, tabs));
    window.addEventListener('popstate', syncFromUrl);

    return () => window.removeEventListener('popstate', syncFromUrl);
  }, [parameter, tabs]);

  const selectTab = useCallback((index: number): void => {
    const selectedTab = tabs[index];
    if (!selectedTab) return;

    const url = new URL(window.location.href);
    url.searchParams.set(parameter, selectedTab);
    window.history.replaceState(window.history.state, '', url);
    setSelectedIndex(index);
  }, [parameter, tabs]);

  return [selectedIndex, selectTab];
}
