// PLAN v1.17 §10b (0.1.7), widget side: link safety, 429 busy, the handoff site cap, Turnstile, and the page origin
// sent with the first ask or handoff (lazy conversations). All 5 projects (@mobile).
import { expect, test, type Page } from '@playwright/test';

const panel = (p: Page) => p.locator('#da-panel');
const launcher = (p: Page) => p.locator('sigma-chat .da-launcher');
const input = (p: Page) => p.locator('#da-q');
const answer = (p: Page) => p.locator('.da-log .da-msg--ai:not(.da-msg--intro)');
const sc = (name: string) => name + test.info().project.name.replace(/[^a-z]/g, '');
const apiLog = async (request: any, scenario: string) => (await (await request.get('/__debug')).json()).filter((r: any) => r.scenario === scenario);

async function openPanel(p: Page, s = 'ok') {
	await p.goto(`/?s=${s}`);
	await expect(launcher(p)).toBeVisible();
	await p.waitForTimeout(150);
	if (!(await panel(p).isVisible().catch(() => false))) await launcher(p).click();
	await expect(p.locator('.da-header__status')).toBeVisible();
}
async function ask(p: Page, q: string) {
	if (!(await input(p).isVisible().catch(() => false))) await p.locator('.da-option--bot').click();
	await input(p).fill(q);
	await input(p).press('Enter');
}
async function openForm(p: Page) {
	await p.locator('.da-option--human').click();
	await expect(p.locator('#da-name')).toBeVisible();
}
async function fillForm(p: Page, name = 'Sam') {
	await p.locator('#da-name').fill(name);
	await p.locator('#da-email').fill('sam@example.com');
	await p.locator('#da-text').fill('Need an invoice.');
}

/** A stand-in for Cloudflare's script: renders a button that yields `tok-ok` (or window.__tsToken). No network. */
const FAKE_TURNSTILE = `window.turnstile = {
	n: 0,
	render(el, o) {
		const id = 'ts' + (++this.n);
		const b = document.createElement('button');
		b.type = 'button'; b.className = 'fake-ts'; b.textContent = 'I am human';
		b.dataset.theme = o.theme; b.dataset.sitekey = o.sitekey;
		b.onclick = () => o.callback(window.__tsToken || 'tok-ok');
		el.appendChild(b);
		(window.__tsRenders = window.__tsRenders || []).push(o.theme);
		return id;
	},
	reset() {}, remove() {},
};`;
async function fakeTurnstile(p: Page) {
	const hits: string[] = [];
	await p.route('https://challenges.cloudflare.com/**', (r) => {
		hits.push(r.request().url());
		return r.fulfill({ contentType: 'text/javascript', body: FAKE_TURNSTILE });
	});
	return hits;
}
const tsButton = (p: Page) => p.locator('#da-panel .fake-ts');

// ----- links -----

test('links: clickable only when listed or on the site host; backslash, userinfo, quotes and control characters stay text @mobile', async ({ page }) => {
	await openPanel(page);
	await ask(page, 'sneaky links');
	const ai = answer(page);
	await expect(ai).toBeVisible();
	const hrefs = await ai.locator('.da-msg__body a').evaluateAll((as) => as.map((a) => ({ text: a.textContent, href: (a as HTMLAnchorElement).getAttribute('href') })));
	const origin = new URL(page.url()).origin;
	// Same-site and listed links work; the quote cut keeps the listed URL clean.
	expect(hrefs).toContainEqual({ text: 'header', href: origin + '/docs/header/' });
	expect(hrefs).toContainEqual({ text: 'listed', href: 'https://deltatheme.com/docs/listed/' });
	expect(hrefs.filter((h) => h.text === 'https://deltatheme.com/docs/listed/')).toHaveLength(1); // the bare URL before the quote
	// Nothing else is a link: off-site, backslash, userinfo, control characters.
	expect(hrefs).toHaveLength(3);
	for (const h of hrefs) {
		expect(h.href).not.toMatch(/evil|@|\\|\u0007/);
	}
	for (const t of ['evil', 'bs', 'ui', 'cc']) await expect(ai.locator('.da-msg__body')).toContainText(t);
	await expect(ai.locator('.da-msg__body')).toContainText('https://evil.com\\@deltatheme.com/docs/listed/');
	await expect(ai.locator('.da-msg__body')).toContainText('https://user@deltatheme.com/docs/listed/');
	expect(await ai.locator('[onmouseover]').count()).toBe(0);
	// Source chips: the listed links that are safe; the backslash and userinfo ones are dropped.
	await expect(ai.locator('.da-chip')).toHaveCount(1);
	await expect(ai.locator('.da-chip')).toHaveAttribute('href', 'https://deltatheme.com/docs/listed/');
	expect(await page.evaluate(() => (window as any).__pwned)).toBeUndefined();
});

test('links: when site_host differs from the page host (www vs apex), page-host and site_host links both stay clickable @mobile', async ({ page }) => {
	await openPanel(page, 'otherhost');
	await ask(page, 'sneaky links');
	const ai = answer(page);
	await expect(ai).toBeVisible();
	const origin = new URL(page.url()).origin;
	await expect(ai.locator('.da-msg__body a', { hasText: 'header' })).toHaveAttribute('href', origin + '/docs/header/');
	await expect(ai.locator('.da-msg__body a', { hasText: /^listed$/ })).toHaveAttribute('href', 'https://deltatheme.com/docs/listed/');
	await expect(ai.locator('.da-msg__body a', { hasText: 'evil' })).toHaveCount(0);
});

// ----- 429 busy -----

test('429 busy: retried once automatically after retry_after, then the answer shows; no limit card @mobile', async ({ page, request }) => {
	await openPanel(page, sc('busy'));
	await ask(page, 'busyonce: how do I change the header?');
	await expect(answer(page)).toHaveCount(1, { timeout: 8000 });
	await expect(page.locator('.da-msg__meta--error')).toHaveCount(0);
	await expect(page.locator('.da-limit, .da-notice--warning')).toHaveCount(0);
	const log = await apiLog(request, sc('busy'));
	expect(log.filter((r: any) => r.route === 'busy')).toHaveLength(1);
	expect(log.filter((r: any) => r.route === 'ask-body').length).toBe(2); // the try and one retry
	await expect(input(page)).toBeEnabled(); // not locked like a rate limit
});

test('429 busy twice: "Sigma is busy, try again in a few seconds" with Try again; no lock @mobile', async ({ page, request }) => {
	await openPanel(page, sc('busytwice'));
	await ask(page, 'busyalways please');
	const meta = page.locator('.da-msg--visitor .da-msg__meta--error');
	await expect(meta).toContainText('Sigma is busy, try again in a few seconds.', { timeout: 8000 });
	await expect(meta.getByRole('button', { name: 'Try again' })).toBeVisible();
	await expect(input(page)).toBeEnabled();
	const log = await apiLog(request, sc('busytwice'));
	expect(log.filter((r: any) => r.route === 'busy')).toHaveLength(2); // one automatic retry, not more
});

// ----- handoff site cap -----

test('handoff site cap (503 handoff_busy): "Our inbox is busy, try again later." @mobile', async ({ page }) => {
	await openPanel(page);
	await openForm(page);
	await fillForm(page, 'inboxbusy');
	await page.getByRole('button', { name: 'Send message' }).click();
	await expect(page.locator('.da-form .da-notice')).toHaveText('Our inbox is busy, try again later.');
	await expect(page.locator('.da-end__title')).toHaveCount(0);
});

// ----- lazy conversations: the origin travels with the first ask and the handoff -----

test('the first ask and the handoff carry page_url and locale (lazy conversations) @mobile', async ({ page, request }) => {
	await openPanel(page, sc('origin'));
	await ask(page, 'How do I change the header?');
	await expect(answer(page)).toHaveCount(1);
	await page.locator('#da-panel').getByRole('button', { name: 'Back', exact: true }).click();
	await openForm(page);
	await fillForm(page);
	await page.getByRole('button', { name: 'Send message' }).click();
	await expect(page.locator('.da-end__title')).toHaveText('Message sent');
	const log = await apiLog(request, sc('origin'));
	const askBody = log.find((r: any) => r.route === 'ask-body');
	expect(askBody.keys).toBe('locale,page_url,text');
	expect(askBody.page_url).toBe(new URL(page.url()).origin + '/'); // no query or hash
	expect(askBody.locale).toBeTruthy();
	const ho = log.find((r: any) => r.route === 'handoff-fields');
	expect(ho.keys).toBe('email,idempotency_key,locale,name,page_url,text,website'); // no turnstile_token when it's off
});

// ----- Turnstile -----

test('Turnstile off: no Cloudflare script, no challenge on the form @mobile', async ({ page }) => {
	const hits = await fakeTurnstile(page);
	await openPanel(page);
	await openForm(page);
	await page.waitForTimeout(300);
	expect(hits).toEqual([]);
	await expect(tsButton(page)).toHaveCount(0);
});

test('Turnstile on: the script loads only when the form opens; the token is required and sent @mobile', async ({ page, request }) => {
	const hits = await fakeTurnstile(page);
	await openPanel(page, sc('ts'));
	await page.waitForTimeout(300);
	expect(hits).toEqual([]); // not on page load, not when the panel opens
	await openForm(page);
	await expect(tsButton(page)).toBeVisible();
	expect(hits).toHaveLength(1);
	expect(hits[0]).toMatch(/^https:\/\/challenges\.cloudflare\.com\/turnstile\/v0\/api\.js\?render=explicit$/);
	await expect(tsButton(page)).toHaveAttribute('data-sitekey', '1x00000000000000000000AA');
	// Submitting without the check asks for it, and sends nothing.
	await fillForm(page);
	await page.getByRole('button', { name: 'Send message' }).click();
	await expect(page.locator('.da-form .da-notice')).toHaveText('Please complete the security check above.');
	expect((await apiLog(request, sc('ts'))).filter((r: any) => r.route === 'handoff-fields')).toHaveLength(0);
	// With the check done, the token goes in the body and the message is sent.
	await tsButton(page).click();
	await page.getByRole('button', { name: 'Send message' }).click();
	await expect(page.locator('.da-end__title')).toHaveText('Message sent');
	const ho = (await apiLog(request, sc('ts'))).filter((r: any) => r.route === 'handoff-fields');
	expect(ho).toHaveLength(1);
	expect(ho[0].turnstile).toBe('tok-ok');
	// The script was loaded once.
	expect(hits).toHaveLength(1);
});

test('Turnstile failed verify: a clear message and a fresh challenge (tokens are single-use) @mobile', async ({ page }) => {
	await fakeTurnstile(page);
	await openPanel(page, sc('tsfail'));
	await openForm(page);
	await page.evaluate(() => ((window as any).__tsToken = 'tok-bad'));
	await tsButton(page).click();
	await fillForm(page);
	await page.getByRole('button', { name: 'Send message' }).click();
	await expect(page.locator('.da-form .da-notice')).toHaveText("The security check didn't go through. Please try it again.");
	await expect.poll(() => page.evaluate(() => ((window as any).__tsRenders || []).length)).toBe(2); // re-rendered
	await page.evaluate(() => ((window as any).__tsToken = 'tok-ok'));
	await tsButton(page).click();
	await page.getByRole('button', { name: 'Send message' }).click();
	await expect(page.locator('.da-end__title')).toHaveText('Message sent');
});

test('Turnstile theme: auto follows the widget scheme; a forced light theme is passed through @mobile', async ({ page }) => {
	await fakeTurnstile(page);
	await openPanel(page, sc('ts'));
	await openForm(page);
	const scheme = await page.locator('sigma-chat').getAttribute('data-scheme');
	await expect(tsButton(page)).toHaveAttribute('data-theme', scheme === 'light' ? 'light' : 'dark');
	await page.evaluate(() => sessionStorage.clear());
	await openPanel(page, sc('tslight'));
	await openForm(page);
	await expect(tsButton(page)).toHaveAttribute('data-theme', 'light');
});

test('Turnstile gate on a new conversation (403 turnstile_required): check, then the question goes through @mobile', async ({ page, request }) => {
	const hits = await fakeTurnstile(page);
	await openPanel(page, sc('tsgate'));
	await ask(page, 'How do I change the header?');
	const gate = page.locator('#da-panel .da-gate');
	await expect(gate).toBeVisible();
	await expect(gate).toContainText("Confirm you're a person to start the chat.");
	await expect.poll(() => hits.length).toBe(1);
	await gate.locator('.fake-ts').click();
	await expect(gate).toHaveCount(0);
	await expect(answer(page)).toHaveCount(1);
	const log = (await apiLog(request, sc('tsgate'))).filter((r: any) => r.route === 'create-turnstile');
	expect(log.map((r: any) => r.token)).toEqual(['', 'tok-ok']);
});

test('Turnstile gate: Back cancels it; the question stays "Not sent" with Try again @mobile', async ({ page }) => {
	await fakeTurnstile(page);
	await openPanel(page, sc('tsgateback'));
	await ask(page, 'How do I change the header?');
	await expect(page.locator('#da-panel .da-gate')).toBeVisible();
	await page.locator('#da-panel').getByRole('button', { name: 'Back', exact: true }).click();
	await expect(page.locator('#da-panel .da-gate')).toHaveCount(0);
	await expect(page.locator('.da-greeting__title')).toBeVisible();
});

test('Turnstile outage (script fails to load): the form says so and sends without a token; the server accepts it @mobile', async ({ page, request }) => {
	await page.route('https://challenges.cloudflare.com/**', (r) => r.abort('failed'));
	await openPanel(page, sc('tsout'));
	await openForm(page);
	await expect(page.locator('.da-challenge')).toContainText("The security check couldn't load. You can still send your message.");
	await fillForm(page);
	await page.getByRole('button', { name: 'Send message' }).click();
	await expect(page.locator('.da-end__title')).toHaveText('Message sent');
	const ho = (await apiLog(request, sc('tsout'))).filter((r: any) => r.route === 'handoff-fields');
	expect(ho).toHaveLength(1);
	expect(ho[0].turnstile).toBe(''); // no token sent
	expect(ho[0].keys).not.toContain('turnstile_token');
});

test('Turnstile: script fails here but Cloudflare is reachable (403 turnstile_required) → the challenge comes back @mobile', async ({ page, request }) => {
	let fail = true;
	await page.route('https://challenges.cloudflare.com/**', (r) => (fail ? ((fail = false), r.abort('failed')) : r.fulfill({ contentType: 'text/javascript', body: FAKE_TURNSTILE })));
	await openPanel(page, sc('tsback'));
	await openForm(page);
	await expect(page.locator('.da-challenge')).toContainText("couldn't load");
	await fillForm(page);
	await page.getByRole('button', { name: 'Send message' }).click();
	// The server is reachable to Cloudflare: tokenless refused, the widget shows a fresh challenge.
	await expect(page.locator('.da-form .da-notice')).toHaveText('Please complete the security check above.');
	await expect(tsButton(page)).toBeVisible();
	await expect(page.locator('.da-challenge')).not.toContainText("couldn't load");
	await tsButton(page).click();
	await page.getByRole('button', { name: 'Send message' }).click();
	await expect(page.locator('.da-end__title')).toHaveText('Message sent');
	const ho = (await apiLog(request, sc('tsback'))).filter((r: any) => r.route === 'handoff-fields');
	expect(ho.map((r: any) => r.turnstile)).toEqual(['', 'tok-ok']);
});

test("Turnstile error-callback (Cloudflare's widget can't run) counts as unavailable: the form sends without a token @mobile", async ({ page, request }) => {
	await page.route('https://challenges.cloudflare.com/**', (r) =>
		r.fulfill({ contentType: 'text/javascript', body: `window.turnstile = { render(el, o) { setTimeout(() => o['error-callback'](), 50); return 'x'; }, reset() {}, remove() {} };` }),
	);
	await openPanel(page, sc('tsouterr'));
	await openForm(page);
	await expect(page.locator('.da-challenge')).toContainText("couldn't load");
	await fillForm(page);
	await page.getByRole('button', { name: 'Send message' }).click();
	await expect(page.locator('.da-end__title')).toHaveText('Message sent');
	expect((await apiLog(request, sc('tsouterr'))).filter((r: any) => r.route === 'handoff-fields')[0].turnstile).toBe('');
});

test('Turnstile breaker during an outage: the new conversation starts without a token or a gate (server fail-open) @mobile', async ({ page, request }) => {
	await page.route('https://challenges.cloudflare.com/**', (r) => r.abort('failed'));
	await openPanel(page, sc('tsgateout'));
	await ask(page, 'How do I change the header?');
	await expect(answer(page)).toHaveCount(1);
	await expect(page.locator('#da-panel .da-gate')).toHaveCount(0);
	const log = (await apiLog(request, sc('tsgateout'))).filter((r: any) => r.route === 'create-turnstile');
	expect(log.map((r: any) => r.token)).toEqual(['']); // the server's own probe found Cloudflare down: accepted tokenless
});

test('Turnstile gate whose script cannot load: it closes and retries without a token; a reachable server still refuses @mobile', async ({ page, request }) => {
	await page.route('https://challenges.cloudflare.com/**', (r) => r.abort('failed'));
	await openPanel(page, sc('tsgatefail'));
	await ask(page, 'How do I change the header?');
	// The gate opens, its script fails, so the create is retried without a token; this server is reachable to
	// Cloudflare, so it refuses again and the question shows as not sent (Try again brings the gate back).
	await expect(page.locator('.da-msg--visitor .da-msg__meta--error')).toContainText('Not sent.', { timeout: 8000 });
	await expect(page.locator('#da-panel .da-gate')).toHaveCount(0);
	const log = (await apiLog(request, sc('tsgatefail'))).filter((r: any) => r.route === 'create-turnstile');
	expect(log.map((r: any) => r.token)).toEqual(['', '']);
});
