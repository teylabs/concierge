import { defineConfig, devices } from '@playwright/test';

/**
 * Browser tests run against the Vite harness on the lane's dedicated port
 * 8152. reuseExistingServer is off so a foreign process on the port fails
 * the run instead of being tested by accident.
 */
export default defineConfig({
    testDir: 'tests/Browser',
    outputDir: 'build/playwright',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    reporter: [['list']],
    use: {
        baseURL: 'http://127.0.0.1:8152',
        trace: 'retain-on-failure',
        timezoneId: 'UTC',
        locale: 'en-US',
    },
    projects: [
        { name: 'desktop', use: { ...devices['Desktop Chrome'] } },
        { name: 'phone', use: { ...devices['Pixel 7'] } },
    ],
    webServer: {
        command: 'npm run harness',
        url: 'http://127.0.0.1:8152',
        reuseExistingServer: false,
        timeout: 60_000,
    },
});
