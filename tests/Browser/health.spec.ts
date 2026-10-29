import { expect, test } from '@playwright/test';

test('the system health screen renders dependency status through Inertia', async ({ page }) => {
    const response = await page.goto('/system/health');

    expect(response?.status()).toBe(200);
    await expect(page.getByRole('heading', { name: 'System health' })).toBeVisible();
    await expect(page.getByText(/PostgreSQL:/i)).toBeVisible();
    await expect(page.getByText(/Redis:/i)).toBeVisible();
});

test('the bare health endpoint answers', async ({ request }) => {
    const res = await request.get('/up');
    expect(res.status()).toBe(200);
});
