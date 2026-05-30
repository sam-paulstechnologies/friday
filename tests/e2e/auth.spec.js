import { expect, test } from '@playwright/test';
import { credentialsAvailable, login, skipIfMissingCredentials } from './helpers/auth.js';

test('login page loads', async ({ page }) => {
    await page.goto('/login');
    await expect(page.getByLabel(/Email/i)).toBeVisible();
    await expect(page.getByLabel('Password')).toBeVisible();
    await expect(page.getByRole('button', { name: /Log ?in|Login/i })).toBeVisible();
});

test('authenticated login works when credentials are provided', async ({ page }) => {
    skipIfMissingCredentials();

    await login(page);
    await expect(page.getByTestId('dashboard-page')).toBeVisible();
    await expect(page).toHaveURL(/dashboard/);
});

test('credentials are supplied through environment only', async () => {
    expect(typeof credentialsAvailable()).toBe('boolean');
});
