import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.BASE_URL || 'http://trunk.localhost:8088';
const outputDir = process.env.PLAYWRIGHT_OUTPUT_DIR || 'var/playwright-results';
const isCi = Boolean(process.env.CI);
const workerSetting = process.env.PLAYWRIGHT_WORKERS;
const workers = workerSetting && /^\d+%$/.test(workerSetting)
    ? workerSetting
    : Number(workerSetting || 1);

export default defineConfig({
    testDir: './tests/e2e',
    outputDir,
    timeout: 120_000,
    expect: {
        timeout: 15_000,
    },
    forbidOnly: isCi,
    retries: isCi ? 1 : 0,
    workers: typeof workers === 'string' || (Number.isFinite(workers) && workers > 0) ? workers : 1,
    reporter: isCi
        ? [
            ['list'],
            ['html', { outputFolder: 'var/playwright-report', open: 'never' }],
            ['junit', { outputFile: 'var/playwright-results/junit.xml' }],
        ]
        : [['list'], ['html', { outputFolder: 'var/playwright-report', open: 'never' }]],
    use: {
        baseURL,
        trace: isCi ? 'retain-on-failure' : 'on-first-retry',
        screenshot: 'only-on-failure',
        video: isCi ? 'retain-on-failure' : 'off',
        viewport: { width: 1440, height: 1200 },
        actionTimeout: 30_000,
        navigationTimeout: 120_000,
        ignoreHTTPSErrors: true,
    },
    projects: [
        {
            name: 'admin-chromium',
            testMatch: /admin\/.*\.spec\.js/,
            use: { ...devices['Desktop Chrome'] },
        },
        {
            name: 'storefront-chromium',
            testMatch: /storefront\/.*\.spec\.js/,
            use: { ...devices['Desktop Chrome'] },
        },
        {
            name: 'ucp-api',
            testMatch: /ucp\/.*\.spec\.js/,
        },
    ],
});
