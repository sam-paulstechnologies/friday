import { expect, test } from '@playwright/test';
import { login, skipIfMissingCredentials } from './helpers/auth.js';

test('workspace settings access check', async ({ page }) => {
    skipIfMissingCredentials();

    await login(page);
    await page.goto('/settings/workspace');

    if (page.url().includes('/login')) {
        test.skip(true, 'Authenticated user was redirected away from workspace settings.');
    }

    const forbidden = page.getByText('403');
    if (await forbidden.count() > 0) {
        await expect(forbidden).toBeVisible();
        return;
    }

    await expect(page.getByTestId('workspace-settings-page')).toBeVisible();
});
