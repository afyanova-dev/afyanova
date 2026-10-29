import { act, renderHook } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { usePersistentTab } from './usePersistentTab';

const TABS = ['overview', 'details', 'history'] as const;

afterEach(() => {
  window.history.replaceState({}, '', '/');
});

describe('usePersistentTab', () => {
  it('restores the selected tab from the URL after remount', () => {
    const [, selectTab] = renderHook(() => usePersistentTab('tab', TABS)).result.current;
    act(() => selectTab(2));

    expect(new URL(window.location.href).searchParams.get('tab')).toBe('history');

    const { result } = renderHook(() => usePersistentTab('tab', TABS));
    expect(result.current[0]).toBe(2);
  });

  it('defaults to the first tab for missing or unknown URL values', () => {
    window.history.replaceState({}, '', '/?tab=unknown');
    const { result } = renderHook(() => usePersistentTab('tab', TABS));

    expect(result.current[0]).toBe(0);
  });

  it('preserves unrelated query parameters', () => {
    window.history.replaceState({}, '', '/?status=PENDING');
    const { result } = renderHook(() => usePersistentTab('tab', TABS));

    act(() => result.current[1](1));

    const url = new URL(window.location.href);
    expect(url.searchParams.get('status')).toBe('PENDING');
    expect(url.searchParams.get('tab')).toBe('details');
  });
});
