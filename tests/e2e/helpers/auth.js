import { expect, test } from '@playwright/test';

export function credentialsAvailable() {
    return Boolean(process.env.PLAYWRIGHT_USER_EMAIL && process.env.PLAYWRIGHT_USER_PASSWORD);
}

export function skipIfMissingCredentials() {
    if (!credentialsAvailable()) {
        throw new Error('Synthetic credentials are required; authenticated contracts cannot silently skip.');
    }
}

export async function login(page) {
    const email = process.env.PLAYWRIGHT_USER_EMAIL;
    const password = process.env.PLAYWRIGHT_USER_PASSWORD;

    if (!email || !password) {
        throw new Error('Missing Playwright credentials. Set PLAYWRIGHT_USER_EMAIL and PLAYWRIGHT_USER_PASSWORD.');
    }

    await page.goto('/login');
    await page.getByLabel(/Email/i).fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: /Log ?in|Login/i }).click();
    await expect(page).toHaveURL(/\/today$/);
    await expect(page.getByRole('heading', { name: 'Priority work', exact: true })).toBeVisible();
}

export async function loginAndVisit(page, url, testId) {
    await login(page);
    await page.goto(url);
    await expect(page.getByTestId(testId)).toBeVisible();
}
