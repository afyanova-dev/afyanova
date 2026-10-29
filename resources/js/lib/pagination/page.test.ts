import { describe, expect, it } from 'vitest';
import { emptyPagedMeta, hasNextPage } from './index';

describe('paged meta (backend LengthAwarePaginator shape)', () => {
  it('detects further pages', () => {
    expect(hasNextPage({ total: 30, currentPage: 1, lastPage: 2 })).toBe(true);
    expect(hasNextPage({ total: 30, currentPage: 2, lastPage: 2 })).toBe(false);
  });

  it('starts empty and closed', () => {
    const meta = emptyPagedMeta();
    expect(meta.total).toBe(0);
    expect(hasNextPage(meta)).toBe(false);
  });
});
