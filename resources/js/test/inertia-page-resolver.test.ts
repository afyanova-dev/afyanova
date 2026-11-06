import { describe, expect, it } from 'vitest';
import { resolveInertiaPage } from '@/app/resolveInertiaPage';

describe('Inertia page resolver', () => {
  it('resolves the legacy patient registration component alias', async () => {
    const [legacyPage, currentPage] = await Promise.all([
      resolveInertiaPage('Patient/Register'),
      resolveInertiaPage('Patients/Register'),
    ]);

    expect(legacyPage).toBe(currentPage);
  });

  it('loads a registered page for a direct Inertia visit', async () => {
    const page = await resolveInertiaPage('System/Health');

    expect(page).toBeTypeOf('function');
  });

  it('throws an explicit error when the backend requests a missing page', async () => {
    await expect(resolveInertiaPage('Missing/Page')).rejects.toThrow(
      'Inertia page not found: ./pages/Missing/Page.tsx. Backend requested "Missing/Page".',
    );
  });
});
