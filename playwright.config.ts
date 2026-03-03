import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './Tests/E2E',
  fullyParallel: false, // Tests within a file run sequentially (safer for state)
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: 1, // CKEditor tests need sequential execution
  reporter: [
    ['html', { outputFolder: '.Build/playwright-report' }],
    ['list'],
  ],
  outputDir: '.Build/playwright-results',
  use: {
    baseURL: process.env.TYPO3_BASE_URL || 'https://v14.t3-cowriter.ddev.site',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'on-first-retry',
    ignoreHTTPSErrors: true,
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
  expect: {
    timeout: 10000,
  },
  timeout: 60000,
});
