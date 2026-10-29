import { describe, expect, it, vi } from 'vitest';
import { router } from '@inertiajs/react';
import { applyListQuery } from './index';

vi.mock('@inertiajs/react', () => ({
  router: { get: vi.fn() },
}));
describe('applyListQuery (URL is the list state)', () => {
  it('strips empty values and preserves state and scroll', () => {
    applyListQuery({ q: 'doe', date: '', page: undefined, per_page: null, status: 'active' });

    expect(router.get).toHaveBeenCalledOnce();
    expect(router.get).toHaveBeenCalledWith(
      window.location.pathname,
      { q: 'doe', status: 'active' },
      { preserveState: true, preserveScroll: true, replace: true },
    );
  });

  it('keeps page numbers numeric', () => {
    applyListQuery({ page: 3 });
    expect(router.get).toHaveBeenCalledWith(
      window.location.pathname,
      { page: 3 },
      expect.objectContaining({ preserveState: true }),
    );
  });

  it('forwards regional loading and failure events', () => {
    const events = {
      onStart: vi.fn(),
      onFinish: vi.fn(),
      onNetworkError: vi.fn(),
      onHttpException: vi.fn(),
    };
    applyListQuery({ q: 'doe' }, { events });

    const calls = (router.get as ReturnType<typeof vi.fn>).mock.calls;
    const options = calls[calls.length - 1]?.[2] as {
      onStart?: () => void;
      onFinish?: () => void;
      onNetworkError?: () => void;
      onHttpException?: (response: { status: number }) => void;
    };
    options.onStart?.();
    options.onFinish?.();
    options.onNetworkError?.();
    options.onHttpException?.({ status: 500 });

    expect(events.onStart).toHaveBeenCalledOnce();
    expect(events.onFinish).toHaveBeenCalledOnce();
    expect(events.onNetworkError).toHaveBeenCalledOnce();
    expect(events.onHttpException).toHaveBeenCalledWith(500);
  });
});
