import { expect, test } from '@playwright/test';

/**
 * PHASE 2 auth smoke. The full login → dashboard → logout journey is exercised
 * end-to-end at the HTTP layer by tests/Feature/Security/*; here we only prove
 * the Inertia auth screens render and the protected-route guard fires.
 */

test('the login screen renders through the Inertia lifecycle', async ({ page }) => {
    const response = await page.goto('/login');

    expect(response?.status()).toBe(200);
    await expect(page.getByLabel('Email')).toBeVisible();
    await expect(page.getByRole('textbox', { name: 'Password' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Log in' })).toBeVisible();
});

test('invalid credentials show the generic backend error', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email').fill('unknown@example.com');
    await page.getByRole('textbox', { name: 'Password' }).fill('wrong-password');
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page.getByText('These credentials do not match our records.')).toBeVisible();
});

test('an unauthenticated visit to a protected route is redirected to login', async ({ page }) => {
    await page.goto('/dashboard');
    await expect(page).toHaveURL(/\/login$/);
});
