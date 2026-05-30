import { expect, test } from '@playwright/test';
import { login, skipIfMissingCredentials } from './helpers/auth.js';

test.use({ viewport: { width: 390, height: 844 } });

test('mobile dashboard and my day navigation smoke test', async ({ page }) => {
    skipIfMissingCredentials();

    await login(page);
    await expect(page.getByTestId('dashboard-page')).toBeVisible();
    await page.getByTestId('mobile-nav-toggle').click();
    await expect(page.getByTestId('sidebar-nav')).toBeVisible();
    await page.getByRole('link', { name: 'My Day' }).click();
    await expect(page.getByTestId('today-page')).toBeVisible();
});
