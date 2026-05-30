import { expect, test } from '@playwright/test';
import { loginAndVisit, skipIfMissingCredentials } from './helpers/auth.js';

test('inbox notifications page loads', async ({ page }) => {
    skipIfMissingCredentials();

    await loginAndVisit(page, '/notifications', 'inbox-page');
    await expect(page.getByText('Unread')).toBeVisible();
    await expect(page.getByText('Read')).toBeVisible();
});
