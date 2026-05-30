import { expect, test } from '@playwright/test';
import { loginAndVisit, skipIfMissingCredentials } from './helpers/auth.js';

test('assistant page loads and returns a safe response', async ({ page }) => {
    skipIfMissingCredentials();

    await loginAndVisit(page, '/assistant', 'assistant-page');
    await page.getByPlaceholder('Ask Miriam...').fill('What should I focus on today?');
    await page.getByRole('button', { name: 'Send' }).click();

    await expect(page.getByText(/disabled|focus|today|workspace/i)).toBeVisible();
});
