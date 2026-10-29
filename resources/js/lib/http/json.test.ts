import { afterEach, describe, expect, it, vi } from 'vitest';
import { JsonRequestError, getJson, sendJson } from './index';

function jsonResponse<T>(body: T, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}

describe('getJson (the only fetch path for domain data)', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('returns typed JSON on success', async () => {
    const fetchMock = vi.fn(async () => jsonResponse({ patients: [], total: 0 }));
    vi.stubGlobal('fetch', fetchMock);

    const data = await getJson<{ patients: unknown[]; total: number }>(
      '/workspaces/patient/api/search?q=doe',
    );

    expect(data).toEqual({ patients: [], total: 0 });
    expect(fetchMock).toHaveBeenCalledOnce();
    const calls = fetchMock.mock.calls as unknown as Array<[string, RequestInit]>;
    const [, init] = calls[0] as [string, RequestInit];
    expect(init.credentials).toBe('same-origin');
    expect((init.headers as Record<string, string>)['X-Requested-With']).toBe('XMLHttpRequest');
  });

  it('throws a typed error on HTTP failure, never silent data', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ message: 'Forbidden.' }, 403)));

    const error = await getJson('/workspaces/patient/api/search').catch((e: unknown) => e);
    expect(error).toBeInstanceOf(JsonRequestError);
    expect((error as JsonRequestError).status).toBe(403);
  });

  it('sends JSON mutations with the session CSRF token', async () => {
    document.head.innerHTML = '<meta name="csrf-token" content="test-token">';
    const fetchMock = vi.fn(async () => jsonResponse({ saved: true }));
    vi.stubGlobal('fetch', fetchMock);

    await sendJson('/patient/patient-id/sticky-note', 'POST', { body: 'Call family' });

    const calls = fetchMock.mock.calls as unknown as Array<[string, RequestInit]>;
    const [, init] = calls[0] as [string, RequestInit];
    expect(init.method).toBe('POST');
    expect(JSON.parse(String(init.body))).toEqual({ body: 'Call family' });
    expect((init.headers as Record<string, string>)['X-CSRF-TOKEN']).toBe('test-token');
  });

  it('preserves useful JSON error messages for failed mutations', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ message: 'Sticky note is already present.' }, 409)));

    const error = await sendJson('/patient/patient-id/sticky-note', 'POST', { body: 'Call family' })
      .catch((caught: unknown) => caught);

    expect(error).toBeInstanceOf(JsonRequestError);
    expect((error as Error).message).toBe('Sticky note is already present.');
  });
});
