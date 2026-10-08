import { expect, test } from '@playwright/test';
import { loginAndVisit, skipIfMissingCredentials } from './helpers/auth.js';

test('inbox notifications page loads', async ({ page }) => {
    skipIfMissingCredentials();

    await loginAndVisit(page, '/notifications', 'inbox-page');
    const unreadHeading = page.getByRole('heading', { name: /^\d+ unread$/ });
    await expect(unreadHeading).toBeVisible();
    // Earlier synthetic task tests may have generated notifications for this owner.
    if (Number((await unreadHeading.textContent()).split(' ')[0]) > 0) {
        await page.getByRole('button', { name: 'Mark All Read', exact: true }).click();
    }
    await expect(page.getByRole('heading', { name: '0 unread', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Mark All Read', exact: true })).toBeDisabled();
});
