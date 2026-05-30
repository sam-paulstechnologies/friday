import { expect, test } from '@playwright/test';
import { loginAndVisit, skipIfMissingCredentials } from './helpers/auth.js';

test('reports page loads with overview metrics', async ({ page }) => {
    skipIfMissingCredentials();

    await loginAndVisit(page, '/reports', 'reports-page');
    await expect(page.getByText('Miriam metrics cockpit')).toBeVisible();
    await expect(page.getByText('Portfolio readiness and progress')).toBeVisible();
    await expect(page.getByText('Project progress report')).toBeVisible();
});
