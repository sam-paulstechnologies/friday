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

    if (page.url().includes('/login')) {
        test.skip(true, 'Authenticated user was redirected away from task creation.');
    }

    await expect(page.getByTestId('task-create-page')).toBeVisible();
    await page.getByTestId('task-title-input').fill(title);
    await page.getByTestId('task-submit-button').click();
    await expect(page.getByTestId('task-show-page')).toBeVisible();
    await expect(page.getByDisplayValue(title)).toBeVisible();

    const completeButton = page.getByTestId('task-complete-button');
    if (await completeButton.count() === 0) {
        test.skip(true, 'User can create tasks but cannot complete this task.');
    }

    await completeButton.click();
    await expect(page.getByText('Completed')).toBeVisible();
});
