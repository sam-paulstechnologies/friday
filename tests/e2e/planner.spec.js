import { expect, test } from '@playwright/test';
import { loginAndVisit, skipIfMissingCredentials } from './helpers/auth.js';

test('planner tabs can be opened', async ({ page }) => {
    skipIfMissingCredentials();

    await loginAndVisit(page, '/planner', 'planner-page');

    for (const tab of ['Calendar', 'Week', 'Timeline', 'Workload']) {
        await page.getByRole('button', { name: tab }).click();
        await expect(page.getByRole('button', { name: tab })).toBeVisible();
    }
});
