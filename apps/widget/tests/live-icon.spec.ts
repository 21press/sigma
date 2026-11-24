// Opt-in, read-only (DA_LIVE=1 DA_LIVE_URL=<site>): Remy's one icon (§8.8) on the live page, in the test browser only. Nothing
// is installed: the page is fetched, and the local widget build is injected with inline data built from the site's
// own /config. For `image` the test browser also rewrites /config to use one of the site's existing team avatar
// copies as bot_avatar_url. The site may have the chat box switched off; the served copy switches it on so the UI
// renders. No question is sent (the shots stop at the local intro). Shots: <DA_SHOTS>/icon-live-<kind>-<project>-<step>-<scheme>.png
import { expect, test, type Page } from '@playwright/test';

const SITE = process.env.DA_LIVE_URL || ''; // a live site running the plugin, e.g. https://example.com/
const shots = process.env.DA_SHOTS;
test.skip(!process.env.DA_LIVE || !SITE, 'DA_LIVE and DA_LIVE_URL not set');

async function inject(page: Page, baseURL: string, kind: 'assistant' | 'image') {
	const cfgRes = await page.request.get(SITE + 'wp-json/sigma/v1/config');
	const cfg = await cfgRes.json();
	const avatar = kind === 'image' ? String(cfg.team?.[0]?.avatar_url || '') : '';
	const brand = { ...cfg.brand, launcher_icon: kind };
	// Serve the site's own /config (fetched once above) with the icon set; no second network round trip.
	await page.route('**/wp-json/sigma/v1/config', (r) =>
		r.fulfill({ status: 200, contentType: 'application/json', headers: { 'Cache-Control': 'no-store' }, json: { ...cfg, enabled: true, ai_enabled: true, human_enabled: true, brand: { ...cfg.brand, launcher_icon: kind }, bot_avatar_url: avatar } }),
	);
	await page.route('**/__da-local/*.js', async (route) => {
		const file = new URL(route.request().url()).pathname.split('/').pop()!;
		const res = await route.fetch({ url: `${baseURL}/build/widget/${file}` });
		await route.fulfill({ response: res });
	});
	await page.route(SITE, async (route) => {
		const res = await route.fetch();
		let html = await res.text();
		html = html.replace(/<script[^>]*sigma[^>]*widget\.js[^>]*><\/script>/g, ''); // in case it is printed
		const boot = { root: SITE + 'wp-json/sigma/v1/', bot_name: cfg.bot_name || 'Remy', bot_avatar_url: avatar, teaser: { enabled: true, text: "👋 Hi, this is Remy. How can I help you?" }, pulse: false, brand };
		html = html.replace('</body>', `<script>window.SigmaWidget=${JSON.stringify(boot)};</script><script defer src="/__da-local/widget.js"></script></body>`);
		await route.fulfill({ response: res, body: html, headers: { ...res.headers(), 'content-type': 'text/html; charset=utf-8' } });
	});
	return avatar;
}

for (const kind of ['assistant', 'image'] as const) {
	test(`live §8.8 one icon: ${kind} @mobile`, async ({ page, baseURL }, info) => {
		test.setTimeout(90_000);
		const avatar = await inject(page, baseURL!, kind);
		if (kind === 'image') expect(avatar.startsWith(new URL(SITE).origin + '/')).toBe(true);
		await page.goto(SITE, { waitUntil: 'load' });
		const consent = page.locator('.consent');
		if (await consent.isVisible().catch(() => false)) await consent.getByRole('button', { name: 'Decline' }).click();
		const host = page.locator('sigma-chat');
		await expect(host.locator('.da-launcher')).toBeVisible({ timeout: 10000 });
		await expect(host.locator('.da-teaser')).toBeVisible({ timeout: 8000 });
		await page.mouse.move(0, 0);
		const snap = async (step: string) => {
			for (const scheme of ['dark', 'light']) {
				await page.evaluate((s) => document.documentElement.setAttribute('data-theme', s), scheme);
				await page.waitForTimeout(400);
				if (shots) await page.screenshot({ path: `${shots}/icon-live-${kind}-${info.project.name}-${step}-${scheme}.png` });
			}
		};
		// The teaser avatar sits on the page: the launcher's disc, and its edge stands out from the page (≥ 3:1).
		for (const scheme of ['dark', 'light']) {
			await page.evaluate((s) => document.documentElement.setAttribute('data-theme', s), scheme);
			await page.waitForTimeout(300);
			const m = await page.evaluate(() => {
				const parse = (c: string) => (c.match(/[\d.]+/g) || []).map(Number).slice(0, 3);
				const lum = ([r, g, b]: number[]) => {
					const f = (v: number) => ((v /= 255) <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4);
					return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
				};
				const sr = document.querySelector('sigma-chat')!.shadowRoot!;
				const av = getComputedStyle(sr.querySelector('.da-teaser__av')!).backgroundColor;
				const lb = getComputedStyle(sr.querySelector('.da-launcher')!).backgroundColor;
				let pageBg = '';
				for (const e of [document.body, document.documentElement]) {
					const c = getComputedStyle(e).backgroundColor;
					if (!/rgba\(0, 0, 0, 0\)|transparent/.test(c)) {
						pageBg = c;
						break;
					}
				}
				const [x, y] = [lum(parse(av)), lum(parse(pageBg || 'rgb(255,255,255)'))].sort((a, b) => b - a);
				return { av, lb, pageBg, ratio: Math.round(((x + 0.05) / (y + 0.05)) * 100) / 100 };
			});
			console.log(JSON.stringify({ kind, project: info.project.name, scheme, ...m }));
			if (kind !== 'image') {
				expect(m.av, `teaser disc = launcher (${scheme})`).toBe(m.lb);
				expect(m.ratio, `disc edge vs page (${scheme})`).toBeGreaterThanOrEqual(3);
			}
		}
		await snap('teaser');
		const kindClass = 'da-remy--' + kind;
		await expect(host.locator(`.da-launcher .i-chat.${kindClass}`)).toBeAttached();
		await expect(host.locator(`.da-teaser__av.${kindClass}`)).toBeVisible();
		await host.locator('.da-launcher').click();
		await expect(page.locator(`.da-option--bot .${kindClass}`)).toBeVisible();
		await page.mouse.move(0, 0);
		await snap('home');
		await page.locator('.da-option--bot').click();
		await expect(page.locator(`.da-header__id .${kindClass}`)).toBeVisible();
		await expect(page.locator(`.da-log .da-msg__head .${kindClass}`)).toBeVisible();
		await snap('chat');
		if (kind === 'image') expect(await page.locator('.da-header__id img').getAttribute('src')).toBe(avatar);
	});
}
