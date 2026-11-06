import { afterEach, describe, expect, it, vi } from 'vitest';
import { sendJson } from '@/lib/http/json';

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('JSON requests', () => {
  it('accepts a successful no-content response', async () => {
    const json = vi.fn();
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
      ok: true,
      status: 204,
      json,
    }));

    const result = await sendJson<void>('/draft', 'DELETE');

    expect(result).toBeUndefined();
    expect(json).not.toHaveBeenCalled();
  });
});
