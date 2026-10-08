import { expect, test } from '@playwright/test';
import { loginAndVisit, skipIfMissingCredentials } from './helpers/auth.js';

test('my day page loads without crashing', async ({ page }) => {
    skipIfMissingCredentials();

    await loginAndVisit(page, '/tasks', 'tasks-page');
    await page.goto('/today');
    await expect(page.getByRole('heading', { name: 'Priority work', exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Follow-up', exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Reminders', exact: true })).toBeVisible();
});
