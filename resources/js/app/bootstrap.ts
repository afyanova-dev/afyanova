import { router } from '@inertiajs/react';

/**
 * Global Inertia defaults for the foundation.
 * No progress-bar dependency — loading is expressed per-page / per-form
 * with Carbon inline loading states.
 */
export function initInertiaDefaults(): void {
  router.on('error', (event) => {
    // Backend renders `back()->with('error')` for domain failures.
    // Flash display is handled in AppLayout; log correlation here.
    const detail = event.detail as { errors?: unknown } | undefined;
    if (import.meta.env.DEV) {
      console.warn('[inertia:error]', detail?.errors);
    }
  });
}
