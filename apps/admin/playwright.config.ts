// Admin screens in a browser (Knowledge, plan §7.6): the built admin.js against the in-memory mock of the REST
// routes (dev/mock-api.ts). Build first: npm run build:admin. Port: DA_ADMIN_PORT (default 5320).
import { defineConfig, devices } from '@playwright/test';

const port = Number( process.env.DA_ADMIN_PORT || 5320 );

export default defineConfig( {
	testDir: './tests',
	fullyParallel: true,
	retries: 0,
	reporter: 'list',
	use: { baseURL: `http://localhost:${ port }`, trace: 'retain-on-failure' },
	webServer: { command: `node dev/serve.mjs ${ port }`, url: `http://localhost:${ port }/`, reuseExistingServer: false, timeout: 60_000 },
	projects: [
		{ name: 'chromium', use: { ...devices[ 'Desktop Chrome' ] } },
		{ name: 'webkit', use: { ...devices[ 'Desktop Safari' ] } },
		{ name: 'firefox', use: { ...devices[ 'Desktop Firefox' ] } },
		{ name: 'mobile-chrome', use: { ...devices[ 'Pixel 7' ] }, grep: /@mobile/ },
		{ name: 'mobile-webkit', use: { ...devices[ 'iPhone 15' ] }, grep: /@mobile/ },
	],
} );
