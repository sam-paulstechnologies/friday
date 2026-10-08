import { expect, test } from '@playwright/test';
import { loginAndVisit, skipIfMissingCredentials } from './helpers/auth.js';

test('assistant page loads and returns a safe response', async ({ page }) => {
    skipIfMissingCredentials();

    await loginAndVisit(page, '/assistant', 'assistant-page');
    await page.getByPlaceholder('Ask Miriam...').fill('What should I focus on today?');
    const responsePromise = page.waitForResponse((response) => response.url().endsWith('/assistant/message') && response.request().method() === 'POST');
    await page.getByRole('button', { name: 'Send' }).click();
    const response = await responsePromise;
    expect(response.ok()).toBeTruthy();
    const result = await response.json();
    expect(result.provider).toBe('disabled');
    await expect(page.getByText(result.message, { exact: true })).toBeVisible();
});
