// End to end against a real WordPress site running the plugin (not the mock). Skipped unless DA_E2E_URL is set.
//   DA_E2E_URL=https://your-local-site.test DA_MAILPIT=https://your-mail-catcher.test \
//     npx playwright test tests/e2e-site.spec.ts --workers=1
// The plugin rate-limits new conversations (1 per 10 s per IP) and handoffs (3 per hour per IP), so tests run
// serially with a pause, and only Chromium and WebKit send a handoff. Since plan v1.6 a handoff sends one email
// (to support, Reply-To the visitor) and none to the visitor; since v1.7.1 it carries an idempotency key.
import { expect, test, type Page } from '@playwright/test';

const SITE = process.env.DA_E2E_URL;
const MAIL = process.env.DA_MAILPIT;

test.describe.configure({ mode: 'serial' });
test.skip(!SITE, 'DA_E2E_URL not set');
test.use({ ignoreHTTPSErrors: true });

const launcher = (p: Page) => p.locator('sigma-chat .da-launcher');
const answer = (p: Page) => p.locator('.da-log .da-msg--ai:not(.da-msg--intro)');

async function open(page: Page, apiCalls: string[]) {
	page.on('request', (r) => r.url().includes('/wp-json/sigma/') && apiCalls.push(`${r.method()} ${new URL(r.url()).pathname}`));
	await page.goto(SITE + '/');
	await expect(launcher(page)).toBeVisible();
	await page.waitForTimeout(500);
	expect(apiCalls).toEqual([]); // nothing before the visitor opens it
	await launcher(page).click();
	await expect(page.locator('.da-greeting__title')).toBeVisible();
}

async function ask(page: Page, q: string) {
	// §8.6: home has no composer; open a chat with "Talk to Remy" when needed (0.1.3 servers: the composer is there).
	if (!(await page.locator('#da-q').isVisible().catch(() => false))) await page.locator('.da-option--bot').click();
	await page.locator('#da-q').fill(q);
	await page.locator('#da-q').press('Enter');
}

test.beforeEach(async () => {
	await new Promise((r) => setTimeout(r, 11_000)); // stay under 1 new conversation per 10 s
});

test('real site: ask → answered with doc chip → same-tab doc → panel reopens', async ({ page }) => {
	test.setTimeout(90_000);
	const calls: string[] = [];
	await open(page, calls);
	await ask(page, 'How do I switch on Snippets?');
	await expect(answer(page)).toBeVisible({ timeout: 30_000 });
	const chip = answer(page).locator('.da-chip').first();
	await expect(chip).toBeVisible();
	const href = await chip.getAttribute('href');
	expect(new URL(href!).origin).toBe(new URL(SITE!).origin);
	await expect(answer(page).locator('.da-notice')).toHaveCount(0);
	await chip.click();
	await expect(page).toHaveURL(href!);
	await expect(page.locator('#da-panel')).toBeVisible();
	await expect(page.locator('.da-msg--visitor .da-msg__body')).toHaveText('How do I switch on Snippets?');
	expect(calls.filter((c) => c.startsWith('POST') && c.endsWith('/conversations'))).toHaveLength(1);
});

test('real site: not in the docs → card → handoff → sent → emails in Mailpit', async ({ page, browserName }) => {
	test.setTimeout(90_000);
	test.skip(browserName === 'firefox', 'handoff limit: 3 per hour per IP; Chromium + WebKit send one each');
	const calls: string[] = [];
	await open(page, calls);
	await ask(page, 'What is the capital of Peru?');
	const card = answer(page).locator('.da-notice');
	await expect(card).toContainText('Talk to a human?', { timeout: 30_000 });
	await card.getByRole('button', { name: 'Talk to a human' }).click();
	await expect(page.locator('#da-text')).toHaveValue('What is the capital of Peru?');
	const email = `e2e+${browserName}-${Date.now()}@example.com`;
	await page.locator('#da-name').fill('E2E Tester');
	await page.locator('#da-email').fill(email);
	await page.getByRole('button', { name: 'Send message' }).click();
	await expect(page.locator('.da-end__title')).toHaveText('Message sent', { timeout: 30_000 });
	await expect(page.locator('.da-end__text')).toContainText(email);

	if (!MAIL) return;
	const found = async () => {
		const r = await page.request.get(`${MAIL}/api/v1/search?query=${encodeURIComponent(email)}`, { ignoreHTTPSErrors: true });
		return ((await r.json()).messages || []) as any[];
	};
	// Plan v1.6: one email, to support, Reply-To the visitor. No confirmation email to the visitor.
	await expect.poll(async () => (await found()).length, { timeout: 20_000 }).toBeGreaterThanOrEqual(1);
	await page.waitForTimeout(2000); // a duplicate would arrive by now
	const msgs = await found();
	const toSupport = msgs.filter((m) => (m.ReplyTo || []).some((t: any) => t.Address === email));
	const toVisitor = msgs.filter((m) => m.To.some((t: any) => t.Address === email));
	expect(toSupport, 'exactly one support email with Reply-To = visitor').toHaveLength(1);
	expect(toVisitor, 'no email to the visitor').toHaveLength(0);
	test.info().annotations.push({ type: 'mail', description: toSupport[0].Subject });
});

test('real site: response lost, visitor retries with the same key → one support email (v1.7.1)', async ({ page, browserName }) => {
	test.setTimeout(90_000);
	test.skip(browserName !== 'chromium', 'handoff limit: 3 per hour per IP');
	const calls: string[] = [];
	await open(page, calls);
	const keys: string[] = [];
	let first = true;
	await page.route('**/handoff', async (route) => {
		keys.push(JSON.parse(route.request().postData() || '{}').idempotency_key);
		if (first) {
			first = false;
			await route.fetch(); // the server stores it and sends the email…
			return route.abort('failed'); // …the answer never reaches the visitor
		}
		return route.continue();
	});
	await page.locator('.da-option--human').click();
	const email = `e2e+retry-${Date.now()}@example.com`;
	await page.locator('#da-name').fill('E2E Retry');
	await page.locator('#da-email').fill(email);
	await page.locator('#da-text').fill('Retry test');
	await page.getByRole('button', { name: 'Send message' }).click();
	await expect(page.locator('.da-notice--danger')).toBeVisible({ timeout: 30_000 });
	await page.getByRole('button', { name: 'Send message' }).click();
	await expect(page.locator('.da-end__title')).toHaveText('Message sent', { timeout: 30_000 });
	expect(keys).toHaveLength(2);
	expect(keys[1]).toBe(keys[0]);
	if (!MAIL) return;
	await page.waitForTimeout(3000);
	const r = await page.request.get(`${MAIL}/api/v1/search?query=${encodeURIComponent(email)}`, { ignoreHTTPSErrors: true });
	const msgs = ((await r.json()).messages || []) as any[];
	expect(msgs.filter((m) => (m.ReplyTo || []).some((t: any) => t.Address === email))).toHaveLength(1);
});
