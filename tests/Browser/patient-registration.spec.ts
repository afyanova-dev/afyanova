import { expect, test } from '@playwright/test';

/**
 * Registration page smoke: guard fires and the two-panel workspace renders.
 * Duplicate/override journeys are covered by
 * tests/Feature/Domains/Patient/*.
 */

test('unauthenticated registration visits are redirected to login', async ({ page }) => {
    await page.goto('/workspaces/patient/register');
    await expect(page).toHaveURL(/\/login$/);
});
