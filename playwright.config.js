import { defineConfig, devices } from '@playwright/test';

const baseURL = 'http://127.0.0.1:8765';
if (process.env.PLAYWRIGHT_BASE_URL && process.env.PLAYWRIGHT_BASE_URL !== baseURL) {
    throw new Error('E2E is restricted to the disposable loopback server.');
}

export default defineConfig({
    testDir: './tests/e2e',
    outputDir: './test-results/playwright',
    timeout: 30_000,
    expect: {
        timeout: 10_000,
    },
    fullyParallel: false,
    workers: 1,
    forbidOnly: !!process.env.CI,
    retries: 0,
    reporter: [['list'], ['html', { open: 'never' }], ['junit', { outputFile: 'test-results/e2e-junit.xml' }]],
    use: {
        baseURL,
        screenshot: 'only-on-failure',
        trace: 'retain-on-failure',
        video: 'retain-on-failure',
    },
    webServer: {
            command: 'php -S 127.0.0.1:8765 -t public tools/testing/e2e-router.php',
            url: baseURL,
            reuseExistingServer: false,
            timeout: 60_000,
        },
    projects: [
        {
            name: 'desktop-chromium',
            use: { ...devices['Desktop Chrome'] },
        },
        {
            name: 'mobile-chrome',
            use: { ...devices['Pixel 5'] },
        },
    ],
});
