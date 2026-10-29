import { usePage } from '@inertiajs/react';
import { useCallback } from 'react';
import type { SharedProps } from '@/types';

/** A single backend permission key, e.g. `queue.ticket.view`. */
export type Permission = string;

/** The effective-permission map from Inertia shared props (`auth.can`). */
export type PermissionMap = Record<string, boolean> | undefined | null;

/**
 * Central authorization check. Fail-closed: a missing map or a missing key
 * is `false` — protected UI is never shown without positive permission data.
 */
export function checkCan(map: PermissionMap, permission: Permission): boolean {
  if (map == null) return false;
  return map[permission] === true;
}

export function checkAny(map: PermissionMap, required: readonly Permission[]): boolean {
  return required.some((permission) => checkCan(map, permission));
}

export function checkAll(map: PermissionMap, required: readonly Permission[]): boolean {
  return required.every((permission) => checkCan(map, permission));
}

/**
 * Component authorization API. Reads the effective permissions the backend
 * already shares on every Inertia response — no fetching, no role logic.
 *
 * @example
 * const can = useCan();
 * {can(permissions.billing.access) && <Button>Billing</Button>}
 */
export function useCan(): (permission: Permission) => boolean {
  const { props } = usePage<SharedProps>();
  const map = props.auth?.can;
  return useCallback((permission: Permission) => checkCan(map, permission), [map]);
}
