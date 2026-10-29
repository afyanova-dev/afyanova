/**
 * Typed fetch for the session-authenticated JSON endpoints (Rule 03).
 *
 * Inertia is the transport for page data. The only legitimate `fetch()` case
 * is the backend's widget-style JSON API (patient search, appointment
 * calendar/options), where an Inertia visit would be wrong. Everything else
 * goes through Inertia.
 */
export class JsonRequestError extends Error {
  readonly status: number;

  constructor(status: number, url: string, message = `Request failed (${status}) for ${url}`) {
    super(message);
    this.name = 'JsonRequestError';
    this.status = status;
  }
}

export async function sendJson<T>(
  url: string,
  method: 'GET' | 'POST' | 'PATCH' | 'DELETE' = 'GET',
  body?: Record<string, unknown>,
): Promise<T> {
  const csrfToken = method === 'GET'
    ? undefined
    : document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
  const response = await fetch(url, {
    method,
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      ...(body ? { 'Content-Type': 'application/json' } : {}),
      ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
    },
    ...(body ? { body: JSON.stringify(body) } : {}),
  });

  if (!response.ok) {
    let message = `Request failed (${response.status}) for ${url}`;
    if (response.headers.get('content-type')?.includes('application/json')) {
      const payload: unknown = await response.json();
      if (typeof payload === 'object' && payload !== null && 'message' in payload
        && typeof payload.message === 'string') {
        message = payload.message;
      }
    }
    throw new JsonRequestError(response.status, url, message);
  }

  return (await response.json()) as T;
}

export async function getJson<T>(url: string): Promise<T> {
  return sendJson<T>(url);
}
