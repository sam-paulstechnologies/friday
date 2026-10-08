import { expect, test } from '@playwright/test';
import { login, skipIfMissingCredentials } from './helpers/auth.js';

test('workspace settings access check', async ({ page }) => {
    skipIfMissingCredentials();

    await login(page);
    await page.goto('/settings/workspace');

    await expect(page.getByTestId('workspace-settings-page')).toBeVisible();
});
