import { defineConfig, devices } from '@playwright/test';

const PORT = Number(process.env.DA_MOCK_PORT || 5178);

export default defineConfig({
	testDir: 'tests',
	timeout: 30_000,
	fullyParallel: true,
	reporter: [['list']],
	use: { baseURL: `http://localhost:${PORT}`, trace: 'retain-on-failure' },
	webServer: {
		command: `npm run build && node dev/mock-server.mjs ${PORT}`,
		url: `http://localhost:${PORT}/__log`,
		reuseExistingServer: !process.env.CI,
	},
	projects: [
		{ name: 'chromium', use: { ...devices['Desktop Chrome'] } },
		{ name: 'webkit', use: { ...devices['Desktop Safari'] } },
		{ name: 'firefox', use: { ...devices['Desktop Firefox'] } },
		{ name: 'mobile-webkit', use: { ...devices['iPhone 15'] }, grep: /@mobile/ },
		{ name: 'mobile-chrome', use: { ...devices['Pixel 7'] }, grep: /@mobile/ },
	],
});
