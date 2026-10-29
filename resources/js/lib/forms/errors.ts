/**
 * Named error-bag reader (Rule 06).
 *
 * Inertia shares the default validation bag flat (`errors.field`), but named
 * bags arrive nested (`errors.updatePassword.field` — Fortify password
 * update). `useForm().errors` never contains nested bags, so forms bound to
 * a named bag read through here.
 */
export type FormErrorsMap = Record<string, string | string[] | Record<string, string>>;

export function firstMessage(value: string | string[] | undefined): string | undefined {
  if (typeof value === 'string') return value;
  if (Array.isArray(value)) return value[0];
  return undefined;
}

export function bagErrors(
  errors: FormErrorsMap | undefined | null,
  bag: string,
): Record<string, string> {
  if (errors == null) return {};
  const nested = errors[bag];
  if (nested == null || typeof nested !== 'object' || Array.isArray(nested)) return {};
  const out: Record<string, string> = {};
  for (const [field, value] of Object.entries(nested)) {
    const message = firstMessage(value as string | string[]);
    if (message !== undefined) out[field] = message;
  }
  return out;
}
