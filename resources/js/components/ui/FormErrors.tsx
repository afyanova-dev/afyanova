import { InlineNotification } from '@carbon/react';
import type { ReactNode } from 'react';

/**
 * Form-level error area (Rule 06). Renders every error whose key is NOT a
 * known field — backend pseudo-field failures (`catalog`, `cash_session`)
 * arrive as errors and must be visible, not silently dropped.
 */
export function FormErrors({
  errors,
  fields,
}: {
  errors: Record<string, string | string[] | undefined>;
  fields: readonly string[];
}): ReactNode {
  const known = new Set(fields);
  const rest = Object.entries(errors).filter(
    ([key, value]) => !known.has(key) && value !== undefined,
  );
  if (rest.length === 0) return null;

  const messages = rest.map(([, value]) => (Array.isArray(value) ? value[0] : value));
  const [first] = messages;
  if (first === undefined) return null;

  return (
    <div role="alert" className="afy-form-errors">
      <InlineNotification
        kind="error"
        title={first}
        subtitle={messages.length > 1 ? `${messages.length - 1} more error(s).` : undefined}
        lowContrast
      />
    </div>
  );
}
