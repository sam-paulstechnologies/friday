import { expect, test } from '@playwright/test';
import { login, skipIfMissingCredentials } from './helpers/auth.js';

test.use({ viewport: { width: 390, height: 844 } });

test('mobile dashboard and my day navigation smoke test', async ({ page }) => {
    skipIfMissingCredentials();

    await login(page);
    await page.getByRole('button', { name: 'Open navigation', exact: true }).click();
    const navigation = page.getByRole('dialog');
    const todayLink = navigation.getByRole('link', { name: 'Today', exact: true });
    await expect(todayLink).toBeVisible();
    await todayLink.click();
    await expect(todayLink).not.toBeVisible();
    await expect(page.getByRole('heading', { name: 'Priority work', exact: true })).toBeVisible();
});
