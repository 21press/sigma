// Opt-in: the real deltatheme.com cookie card (read-only; nothing is installed or deployed: the local build is
// injected inside the test browser only, and the card is dismissed with "Decline").
//   DA_LIVE=1 DA_SHOTS=<dir> npx playwright test tests/live-consent.spec.ts
import { expect, test } from '@playwright/test';

const ORIGIN = 'https://deltatheme.com';
const shots = process.env.DA_SHOTS;
test.skip(!process.env.DA_LIVE, 'DA_LIVE not set');

test('deltatheme.com: launcher sits 12px above the cookie card, card buttons clickable, returns after Decline @mobile', async ({ page, baseURL }, info) => {
	await page.route(`${ORIGIN}/__da/**`, async (route) => {
		const path = new URL(route.request().url()).pathname.replace(/^\/__da/, '');
		await route.fulfill({ response: await route.fetch({ url: baseURL + path }) });
	});
	await page.goto(ORIGIN + '/', { waitUntil: 'load' });
	const card = page.locator('.consent');
	await expect(card).toBeVisible({ timeout: 10_000 });
	await page.evaluate((o) => {
		(window as any).SigmaWidget = { root: o + '/__da/s/iso/wp-json/sigma/v1/' };
		const s = document.createElement('script');
		s.src = o + '/__da/build/widget/widget.js';
		document.body.appendChild(s);
	}, ORIGIN);
	const launcher = page.locator('sigma-chat .da-launcher');
	await expect(launcher).toBeVisible();
	await expect(page.locator('sigma-chat')).toHaveAttribute('data-scheme', 'dark'); // dark site, no data-theme
	const g = () =>
		page.evaluate(() => {
			const r = document.querySelector('sigma-chat')!.shadowRoot!.querySelector('.da-launcher')!.getBoundingClientRect();
			const c = document.querySelector('.consent')?.getBoundingClientRect();
			// Test each control at the centre of each of its line boxes (an inline link can wrap).
			const hits = [...document.querySelectorAll('.consent button, .consent a')].map((b) =>
				[...b.getClientRects()].some((br) => {
					const el = document.elementFromPoint(br.left + br.width / 2, br.top + br.height / 2);
					return !!el && (el === b || b.contains(el));
				}),
			);
			return { bottom: r.bottom, cardTop: c ? c.top : null, gap: innerHeight - r.bottom, hits };
		});
	await expect.poll(async () => {
		const x = await g();
		return x.cardTop !== null && Math.abs(x.bottom - (x.cardTop - 12)) <= 1;
	}).toBe(true);
	const hits = (await g()).hits;
	expect(hits.every(Boolean)).toBe(true);
	await page.waitForTimeout(300);
	if (shots) await page.screenshot({ path: `${shots}/deltatheme-live-${info.project.name}-with-cookie-card.png` });

	await card.getByRole('button', { name: 'Decline' }).click();
	await expect(card).toBeHidden();
	const offset = page.viewportSize()!.width < 640 ? 16 : 24;
	await expect.poll(async () => Math.round((await g()).gap), { timeout: 4000 }).toBe(offset);
	await page.waitForTimeout(300);
	if (shots) await page.screenshot({ path: `${shots}/deltatheme-live-${info.project.name}-without-cookie-card.png` });
});
