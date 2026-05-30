import { expect, test } from '@playwright/test';
import { loginAndVisit, skipIfMissingCredentials } from './helpers/auth.js';

test('dashboard loads with core sections', async ({ page }) => {
    skipIfMissingCredentials();

    await loginAndVisit(page, '/dashboard', 'dashboard-page');
    await expect(page.getByText("Today's Focus")).toBeVisible();
    await expect(page.getByText('Planning')).toBeVisible();
    await expect(page.getByText('Open Planner')).toBeVisible();
    await expect(page.getByText('Ask Miriam')).toBeVisible();
});
