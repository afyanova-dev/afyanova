import { expect, test } from '@playwright/test';

/**
 * Visual journey for the patient chart banner: log in, ensure a facility,
 * register a patient, open the chart, screenshot the banner.
 * Run: npx playwright test tests/Browser/patient-banner-visual.spec.ts
 */

const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'reception@afyanova.local';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'password';

async function login(page, email: string, password: string): Promise<void> {
  await page.goto('/login');
  await page.getByLabel('Email').fill(email);
  await page.getByRole('textbox', { name: 'Password' }).fill(password);
  await page.getByRole('button', { name: 'Log in' }).click();
  await expect(page).not.toHaveURL(/\/login$/);
}

test('chart banner renders identity, tags and actions', async ({ page }) => {
  test.setTimeout(120_000);
  await login(page, ADMIN_EMAIL, ADMIN_PASSWORD);

  // Facility context is required for registration.
  const switcher = page.getByRole('button', { name: 'Switch facility' });
  if (await switcher.isVisible()) {
    await switcher.click();
    await page.getByRole('radio', { name: 'Demo General Hospital' }).check();
    await page.getByRole('button', { name: 'Switch facility' }).last().click();
  }

  const family = `Check${Date.now().toString().slice(-6)}`;
  await page.goto('/workspaces/patient/register');
  await page.getByLabel('First Name').fill('Banner');
  await page.getByLabel('Family Name').fill(family);
  await page.locator('label[for="sex-male"]').click();
  await page.getByLabel('Date of birth precision').selectOption('KNOWN');
  await page.locator('#date_of_birth').waitFor();
  await page.locator('#date_of_birth').fill('1990-02-03');
  await page.getByRole('button', { name: 'Register patient' }).click();

  // Land on the chart however registration redirects, then open it.
  await page.waitForURL((url) => !url.pathname.includes('/register'), { timeout: 30_000 });
  if (!page.url().includes('/chart')) {
    await page.goto(`/workspaces/patient?q=${family}`);
    await page.getByRole('link', { name: new RegExp(`Banner ${family}`) }).first().click();
  }
  await expect(page.locator('header[aria-label="Patient header"]')).toBeVisible();

  const banner = page.locator('header[aria-label="Patient header"]');
  await expect(banner.getByRole('heading', { name: `Banner ${family}` })).toBeVisible();
  await banner.screenshot({ path: 'test-results/patient-banner.png' });
  await page.screenshot({ path: 'test-results/patient-chart.png' });
});
