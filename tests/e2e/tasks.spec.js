import { expect, test } from '@playwright/test';
import { login, skipIfMissingCredentials } from './helpers/auth.js';

test('tasks page loads', async ({ page }) => {
    skipIfMissingCredentials();

    await login(page);
    await page.goto('/tasks');
    await expect(page.getByTestId('tasks-page')).toBeVisible();
});

test('user can create and complete a safe E2E task when permitted', async ({ page }) => {
    skipIfMissingCredentials();

    const title = `[E2E] Smoke task ${Date.now()}`;

    await login(page);
    await page.goto('/tasks/create');

    await expect(page.getByTestId('task-create-page')).toBeVisible();
    await page.getByTestId('task-title-input').fill(title);
    await page.getByTestId('task-submit-button').click();
    await expect(page.getByTestId('task-show-page')).toBeVisible();
    await expect(page.getByRole('heading', { name: title, exact: true })).toBeVisible();

    const completeButton = page.getByTestId('task-complete-button');
    await completeButton.click();
    await expect(page.getByText('Completed')).toBeVisible();
});
