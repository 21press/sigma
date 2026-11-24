// PLAN v1.18 §8.8 (0.1.8): one Remy icon, set by brand.launcher_icon, drawn the same in every place Remy appears:
// the launcher, the teaser, the Talk to Remy / Continue card, the AI chat header and each "Remy · AI" label.
// All 5 projects (@mobile). DA_SHOTS=<dir> also saves the launcher, teaser, home and chat for `assistant` and `image`.
import { expect, test, type Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';

const shots = process.env.DA_SHOTS;
if (shots) mkdirSync(shots, { recursive: true });

const KINDS = ['assistant', 'remy', 'chat', 'sparkles', 'help', 'image'] as const;
const launcher = (p: Page) => p.locator('sigma-chat .da-launcher');
const panel = (p: Page) => p.locator('#da-panel');

/** Open a page with this icon inline (boot) and in /config; teaser on. */
async function open(p: Page, kind: string, extra = '') {
	await p.goto(`/?s=ic${kind}${extra ? extra : ''}&teaser=1&icon=${kind}${extra === 'bad' ? '&av=bad' : ''}`);
	await expect(launcher(p)).toBeVisible();
}

/** A fingerprint of the icon in each place: the SVG path data (and circle/rect for the Remy face), or the img src. */
const fingerprints = (p: Page) =>
	p.evaluate(() => {
		const sr = document.querySelector('sigma-chat')!.shadowRoot!;
		const fp = (el: Element | null) => {
			if (!el) return 'missing';
			const img = el.querySelector('img');
			if (img) return 'img:' + new URL(img.src).pathname;
			const svg = el.querySelector('svg');
			if (!svg) return 'empty';
			return [...svg.querySelectorAll('path, circle, rect')].map((n) => n.tagName + ':' + (n.getAttribute('d') || n.getAttribute('r') || n.getAttribute('width'))).join('|');
		};
		const kind = (el: Element | null) => [...(el?.classList || [])].find((c) => c.startsWith('da-remy--')) || 'none';
		const places = {
			launcher: sr.querySelector('.da-launcher .i-chat'),
			teaser: sr.querySelector('.da-teaser__av'),
			card: sr.querySelector('.da-option--bot .da-bot-av'),
			header: sr.querySelector('.da-header__id .da-bot-av'),
			label: sr.querySelector('.da-log .da-msg__head .da-bot-av'),
		};
		return Object.fromEntries(Object.entries(places).map(([k, el]) => [k, { kind: kind(el), fp: fp(el) }]));
	});

for (const kind of KINDS) {
	test(`one icon (${kind}): the launcher, teaser, Remy card, AI chat header and "Remy · AI" label all match @mobile`, async ({ page }, info) => {
		await open(page, kind);
		await expect(page.locator('sigma-chat .da-teaser')).toBeVisible({ timeout: 5000 });
		const before = await fingerprints(page); // launcher and teaser, before the panel loads
		await launcher(page).click();
		await expect(page.locator('.da-option--bot')).toBeVisible();
		await page.locator('.da-option--bot').click();
		await expect(page.locator('.da-log .da-msg--intro')).toBeVisible();
		const after = await fingerprints(page);
		const all = { launcher: before.launcher, teaser: before.teaser, header: after.header, label: after.label };
		// The card: back on home.
		await page.locator('#da-panel').getByRole('button', { name: 'Back', exact: true }).click();
		await expect(page.locator('.da-option--bot')).toBeVisible();
		const card = (await fingerprints(page)).card;
		const places = { ...all, card };
		for (const [where, v] of Object.entries(places)) {
			expect(v.kind, `${where} is ${kind}`).toBe('da-remy--' + kind);
		}
		const ref = places.launcher.fp;
		expect(ref).not.toMatch(/^(missing|empty)$/);
		if (kind === 'image') expect(ref).toBe('img:/avatars/leo.svg');
		for (const [where, v] of Object.entries(places)) expect(v.fp, `${where} matches the launcher`).toBe(ref);
		if (shots && (kind === 'assistant' || kind === 'image')) {
			for (const scheme of ['dark', 'light']) {
				await page.evaluate((s) => document.documentElement.setAttribute('data-theme', s), scheme);
				await page.waitForTimeout(300);
				await page.screenshot({ path: `${shots}/icon-${kind}-${info.project.name}-home-${scheme}.png` });
			}
		}
	});
}

test('one icon: an image that fails to load becomes `assistant` everywhere, never a broken image @mobile', async ({ page }) => {
	await open(page, 'imagebad', 'bad');
	await expect(page.locator('sigma-chat .da-teaser')).toBeVisible({ timeout: 5000 });
	await expect(page.locator('sigma-chat .da-launcher .i-chat.da-remy--assistant svg')).toBeAttached();
	await expect(page.locator('sigma-chat .da-teaser__av.da-remy--assistant svg')).toBeVisible();
	await launcher(page).click();
	await expect(page.locator('.da-option--bot .da-remy--assistant svg')).toBeVisible();
	await page.locator('.da-option--bot').click();
	await expect(page.locator('.da-header__id .da-remy--assistant svg')).toBeVisible();
	await expect(page.locator('.da-log .da-msg__head .da-remy--assistant svg')).toBeVisible();
	expect(await page.locator('sigma-chat img').count()).toBe(0);
});

test('one icon: `image` with no uploaded copy is `assistant`; an unknown value is `assistant` @mobile', async ({ page }) => {
	await page.goto('/?s=ok&teaser=1&icon=image'); // boot says image, but /config (scenario ok) has no upload
	await page.evaluate(() => {});
	await expect(launcher(page)).toBeVisible();
	await launcher(page).click();
	await expect(page.locator('.da-option--bot .da-remy--assistant svg')).toBeVisible();
	await page.evaluate(() => sessionStorage.clear());
	await page.goto('/?s=ok&teaser=1&icon=rocket');
	await expect(page.locator('sigma-chat .da-launcher .i-chat.da-remy--assistant')).toBeAttached();
	await page.evaluate(() => sessionStorage.clear());
	await page.goto('/?s=ok&teaser=1&icon=constructor'); // a prototype key is not an icon
	await expect(page.locator('sigma-chat .da-launcher .i-chat.da-remy--assistant')).toBeAttached();
});

test('the home header keeps the site logo, not Remy @mobile', async ({ page }) => {
	await open(page, 'chat');
	await launcher(page).click();
	await expect(page.locator('.da-greeting__title')).toBeVisible();
	await expect(page.locator('.da-header__id .da-remy')).toHaveCount(0);
	await expect(page.locator('#da-title')).toHaveText('Delta help'); // no logo_url: the name only (§8.8)
	await page.route('**/wp-json/sigma/v1/config', async (r) => {
		const res = await r.fetch();
		const j = await res.json();
		await r.fulfill({ response: res, json: { ...j, brand: { ...j.brand, logo_url: '/avatars/maya.svg' } } });
	});
	await page.evaluate(() => sessionStorage.clear());
	await page.reload();
	await launcher(page).click();
	await expect(page.locator('.da-header__id img.da-header__logo')).toHaveAttribute('src', /\/avatars\/maya\.svg$/); // the site's logo
	await expect(page.locator('.da-header__id .da-remy')).toHaveCount(0);
});

test('one icon: no image request unless `image` is chosen @mobile', async ({ page }) => {
	const imgs: string[] = [];
	page.on('request', (r) => r.resourceType() === 'image' && imgs.push(new URL(r.url()).pathname));
	await open(page, 'remy');
	await launcher(page).click();
	await page.locator('.da-option--bot').click();
	await expect(page.locator('.da-log .da-msg--intro')).toBeVisible();
	expect(imgs.filter((u) => u === '/' || u.startsWith('/?') || u.startsWith('/avatars/'))).toEqual([]);
});

/** WCAG contrast of the mark against its disc in one place. */
const markContrast = (p: Page, sel: string) =>
	p.evaluate((sel) => {
		const el = document.querySelector('sigma-chat')!.shadowRoot!.querySelector(sel)! as HTMLElement;
		const parse = (c: string) => (c.match(/[\d.]+/g) || []).map(Number).slice(0, 3);
		const lum = ([r, g, b]: number[]) => {
			const f = (v: number) => ((v /= 255) <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4);
			return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
		};
		const cs = getComputedStyle(el);
		const disc = parse(cs.backgroundColor);
		const svg = el.querySelector('svg')!;
		const mark = parse(getComputedStyle(svg).color);
		const [a, b] = [lum(disc), lum(mark)].sort((x, y) => y - x);
		return Math.round(((a + 0.05) / (b + 0.05)) * 100) / 100;
	}, sel);

for (const kind of ['assistant', 'remy', 'chat'] as const) {
	test(`one icon (${kind}): the teaser avatar sits on the page with the launcher's colours, not swapped @mobile`, async ({ page }) => {
		await open(page, kind);
		await expect(page.locator('sigma-chat .da-teaser')).toBeVisible({ timeout: 5000 });
		for (const scheme of ['dark', 'light']) {
			await page.evaluate((s) => document.documentElement.setAttribute('data-theme', s), scheme);
			await page.waitForTimeout(200);
			const c = await page.evaluate(() => {
				const sr = document.querySelector('sigma-chat')!.shadowRoot!;
				const av = sr.querySelector('.da-teaser__av')!;
				const l = sr.querySelector('.da-launcher')!;
				const svg = av.querySelector('svg')!;
				const lsvg = l.querySelector('.i-chat svg')!;
				return { avBg: getComputedStyle(av).backgroundColor, lBg: getComputedStyle(l).backgroundColor, avInk: getComputedStyle(svg).color, lInk: getComputedStyle(lsvg).color };
			});
			expect(c.avBg, `disc = launcher background (${scheme})`).toBe(c.lBg);
			expect(c.avInk, `mark = launcher mark (${scheme})`).toBe(c.lInk);
		}
	});
}

for (const kind of ['assistant', 'chat'] as const) {
	test(`one icon (${kind}): mark contrast ≥ 3:1 on its disc in dark and light (card, label, teaser) @mobile`, async ({ page }) => {
		await open(page, kind);
		await expect(page.locator('sigma-chat .da-teaser')).toBeVisible({ timeout: 5000 });
		for (const scheme of ['dark', 'light']) {
			await page.evaluate((s) => document.documentElement.setAttribute('data-theme', s), scheme);
			await page.waitForTimeout(200);
			expect(await markContrast(page, '.da-teaser__av'), `teaser ${scheme}`).toBeGreaterThanOrEqual(3);
		}
		await launcher(page).click();
		await page.locator('.da-option--bot').click();
		await expect(page.locator('.da-log .da-msg--intro')).toBeVisible();
		for (const scheme of ['dark', 'light']) {
			await page.evaluate((s) => document.documentElement.setAttribute('data-theme', s), scheme);
			await page.waitForTimeout(200);
			expect(await markContrast(page, '.da-log .da-msg__head .da-bot-av'), `label ${scheme}`).toBeGreaterThanOrEqual(3);
			expect(await markContrast(page, '.da-header__id .da-bot-av'), `header ${scheme}`).toBeGreaterThanOrEqual(3);
		}
	});
}
