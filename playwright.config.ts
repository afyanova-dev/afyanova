import { defineConfig, devices } from '@playwright/test';

const e2eBaseUrl = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8011';

/**
 * End-to-end journeys only (IMPLEMENTATION-CONTRACTS.md §17.1 — tests/Browser is
 * the single E2E location). `playwright test` is part of `composer conform`
 * (§17.4). Component-level assertions belong in Vitest, not here.
 */
export default defineConfig({
    testDir: './tests/Browser',
    globalSetup: './tests/Browser/global-setup.ts',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    reporter: process.env.CI ? 'github' : 'list',
    use: {
        baseURL: e2eBaseUrl,
        trace: 'on-first-retry',
    },
    projects: [
        { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    ],
    webServer: {
        command: 'SESSION_DRIVER=file CACHE_STORE=array php artisan serve --env=testing --host=127.0.0.1 --port=8011',
        url: `${e2eBaseUrl}/up`,
        reuseExistingServer: false,
        timeout: 120_000,
    },
});
