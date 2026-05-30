import { expect, test } from '@playwright/test';
import { loginAndVisit, skipIfMissingCredentials } from './helpers/auth.js';

test('my day page loads without crashing', async ({ page }) => {
    skipIfMissingCredentials();

    await loginAndVisit(page, '/today', 'today-page');
    await expect(page.getByText('Top 3 Focus')).toBeVisible();
    await expect(page.getByText('Active Today')).toBeVisible();
    await expect(page.getByText('Completed Today')).toBeVisible();
});
