import { expect, test } from '@playwright/test';

/**
 * Reception V2 critical journey (smoke at the HTTP/render layer).
 * Authenticated check-in/register flows are covered by
 * tests/Feature/Domains/Encounter/Presentation/*; here we prove the V2 page
 * boots inside the new shell and the protected-route guard still fires.
 *
 * NOTE: lives in tests/Browser (the single E2E location per
 * IMPLEMENTATION-CONTRACTS.md §17.1) rather than the docs/plans path.
 */

test('unauthenticated reception visits are redirected to login', async ({ page }) => {
    await page.goto('/workspaces/reception');
    await expect(page).toHaveURL(/\/login$/);
});

test('reception redirects unauthenticated users to the current login page', async ({ page }) => {
    const response = await page.goto('/workspaces/reception');

    expect(response?.status()).toBe(200);
    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByRole('heading', { name: 'Log in' })).toBeVisible();
    await expect(page.getByLabel('Email')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Log in' })).toBeVisible();
});
