/**
 * Backend pagination shape (Rule 03).
 *
 * Controllers hand-map LengthAwarePaginator to `{ total, currentPage,
 * lastPage }` beside a per-endpoint items array — never Laravel's default
 * `meta`/`links` envelope. Consume this shape; do not invent another.
 */
export interface PagedMeta {
  total: number;
  currentPage: number;
  lastPage: number;
}

export function hasNextPage(meta: PagedMeta): boolean {
  return meta.currentPage < meta.lastPage;
}

export function emptyPagedMeta(): PagedMeta {
  return { total: 0, currentPage: 1, lastPage: 1 };
}
