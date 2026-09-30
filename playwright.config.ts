import { defineConfig, devices } from '@playwright/test';

/*
 * End-to-end tests for the chat. They need a migrated database prepared with
 * `php artisan migrate:fresh --seed && php tests/e2e/seed.php` and built
 * assets (`npm run build`); see docs/development.md.
 *
 * Ada runs with the password-less development login against the mock
 * provider in tests/e2e/mock-provider.mjs. The PHP server gets several
 * workers so a "stop" request can arrive while an answer is streaming.
 */
const appPort = Number(process.env.E2E_APP_PORT ?? 8123);
const mockPort = Number(process.env.MOCK_PROVIDER_PORT ?? 8765);
const baseURL = `http://127.0.0.1:${appPort}`;

export default defineConfig({
    testDir: 'tests/e2e',
    fullyParallel: false,
    workers: 1,
    forbidOnly: !!process.env.CI,
    retries: 0,
    reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
    timeout: 60_000,
    use: {
        baseURL,
        // The app's existing convention (e.g. data-test="logout-button").
        testIdAttribute: 'data-test',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        launchOptions: process.env.PLAYWRIGHT_CHROMIUM_PATH
            ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_PATH }
            : {},
    },
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
    webServer: [
        {
            command: 'node tests/e2e/mock-provider.mjs',
            url: `http://127.0.0.1:${mockPort}/v1/models`,
            env: { MOCK_PROVIDER_PORT: String(mockPort) },
            reuseExistingServer: !process.env.CI,
        },
        {
            command: `php artisan serve --no-reload --host=127.0.0.1 --port=${appPort}`,
            url: `${baseURL}/login`,
            env: {
                APP_ENV: 'local',
                APP_URL: baseURL,
                ADA_DEV_LOGIN: 'true',
                PHP_CLI_SERVER_WORKERS: '4',
            },
            reuseExistingServer: !process.env.CI,
            timeout: 60_000,
        },
    ],
});
