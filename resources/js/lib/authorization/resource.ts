/**
 * Per-resource capability reader (Rule 02).
 *
 * Some backend presenters embed a `can` map on an individual resource,
 * e.g. `{ id: '…', can: { update: true, delete: false } }`, where every value
 * was decided server-side by the owning policy. This reader consumes that map
 * and nothing else: it never inspects resource fields, roles, or facilities.
 *
 * Fail-closed: a missing resource, missing map, or missing action is `false`.
 */
export interface CapableResource {
  can?: Record<string, boolean> | null;
}

export function resourceCan(
  resource: CapableResource | null | undefined,
  action: string,
): boolean {
  if (resource?.can == null) return false;
  return resource.can[action] === true;
}
