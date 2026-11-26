import { defineConfig, devices } from '@playwright/test'
import { randomBytes } from 'node:crypto'

const baseURL = 'http://127.0.0.1:8001'
const localChromium = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE

export default defineConfig({
    testDir: './e2e',
    outputDir: 'test-results/playwright',
    forbidOnly: Boolean(process.env.CI),
    timeout: 30_000,
    workers: 2,
    reporter: [['list'], ['html', { open: 'never' }]],
    use: {
        baseURL,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [
        {
            name: 'chromium',
            use: {
                ...devices['Desktop Chrome'],
                launchOptions: localChromium
                    ? { executablePath: localChromium }
                    : {},
            },
        },
        { name: 'firefox', use: { ...devices['Desktop Firefox'] } },
        { name: 'webkit', use: { ...devices['Desktop Safari'] } },
    ],
    webServer: {
        command: 'bash scripts/testing/serve.sh',
        url: `${baseURL}/up`,
        reuseExistingServer: false,
        timeout: 30_000,
        env: { APP_KEY: `base64:${randomBytes(32).toString('base64')}` },
        gracefulShutdown: { signal: 'SIGTERM', timeout: 5_000 },
    },
})
