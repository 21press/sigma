// PLAN §8.5: teaser once per visit (tab session, v1.11); pulse on every page load, then every 12 s (v1.15). All 5 projects (@mobile).
//   DA_SHOTS=<dir> also saves the teaser on a second visit and a pulse frame.
import { expect, test, type Page } from '@playwright/test';

const shots = process.env.DA_SHOTS;
const launcher = (p: Page) => p.locator('sigma-chat .da-launcher');
const teaser = (p: Page) => p.locator('sigma-chat .da-teaser');
const visit = (p: Page) => p.evaluate(() => JSON.parse(sessionStorage.getItem('delta-assistant:visit') || '{}'));

/** Count pulse bursts (data-pulse being set) from now on. */
const countBursts = (p: Page) =>
	p.evaluate(() => {
		(window as any).__bursts = 0;
		const l = document.querySelector('sigma-chat')!.shadowRoot!.querySelector('.da-launcher')!;
		new MutationObserver(() => l.hasAttribute('data-pulse') && (window as any).__bursts++).observe(l, { attributes: true, attributeFilter: ['data-pulse'] });
	});
const bursts = (p: Page) => p.evaluate(() => (window as any).__bursts as number);
/** v1.15: record, from each page's own load event, when every burst starts (ms after load) and the breathe. Attached
 *  before the page's scripts, so no burst is missed while the test is still waiting for the page. */
async function pulseProbe(p: Page) {
	await p.addInitScript(() => {
		addEventListener('load', () => {
			const w = window as any;
			const t0 = Date.now();
			w.__pulseAt = [];
			w.__breathe = [];
			// The host may be appended after load: attach as soon as the launcher exists.
			const attach = () => {
				const l = document.querySelector('sigma-chat')?.shadowRoot?.querySelector('.da-launcher');
				if (!l) return false;
				new MutationObserver((ms) => {
					for (const m of ms) {
						if (m.attributeName === 'data-pulse' && l.hasAttribute('data-pulse')) w.__pulseAt.push(Date.now() - t0);
						if (m.attributeName === 'data-arrive' && l.hasAttribute('data-arrive')) w.__breathe.push(getComputedStyle(l).animationName + ' ' + getComputedStyle(l).animationDuration);
					}
				}).observe(l, { attributes: true, attributeFilter: ['data-pulse', 'data-arrive'] });
				return true;
			};
			if (!attach()) new MutationObserver((_, mo) => attach() && mo.disconnect()).observe(document.documentElement, { childList: true, subtree: true });
		});
	});
}
const pulseAt = (p: Page) => p.evaluate(() => ((window as any).__pulseAt || []) as number[]);
/** Advance the fake clock in 100 ms steps (max 4 s) until the first burst is recorded; returns its time after load.
 *  Robust when the page's load handlers run late on a busy machine: the time is measured from the page's own load. */
async function firstBurst(p: Page) {
	for (let i = 0; i < 40 && !(await pulseAt(p)).length; i++) await p.clock.runFor(100);
	return (await pulseAt(p))[0];
}
const breathe = (p: Page) => p.evaluate(() => ((window as any).__breathe || []) as string[]);
const setHidden = (p: Page, hidden: boolean) =>
	p.evaluate((h) => {
		Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => (h ? 'hidden' : 'visible') });
		document.dispatchEvent(new Event('visibilitychange'));
	}, hidden);

test('teaser: a new visit shows it at 3 s, above the launcher, once; it stays open on the next page; no new greeting after × @mobile', async ({ page }) => {
	await page.goto('/?s=ok&teaser=1');
	await expect(launcher(page)).toBeVisible();
	await page.waitForTimeout(2000);
	await expect(teaser(page)).toBeHidden();
	await expect(teaser(page)).toBeVisible({ timeout: 3000 });
	await expect(page.locator('.da-teaser__open')).toHaveAccessibleName('Open chat: 👋 Hi, this is Sigma. How can I help you?');
	await expect(page.locator('.da-teaser__x')).toHaveCount(0); // §8.6: no ×
	await expect(page.locator('.da-teaser__open .da-remy--assistant svg')).toBeVisible(); // §8.8: Sigma's icon, the plugin mark by default
	expect(await page.locator('.da-teaser__av').getAttribute('aria-hidden')).toBe('true'); // decorative
	expect(await teaser(page).getAttribute('aria-live')).toBeNull();
	await page.waitForTimeout(300);
	const g = await page.evaluate(() => {
		const sr = document.querySelector('sigma-chat')!.shadowRoot!;
		const t = sr.querySelector('.da-teaser')!.getBoundingClientRect();
		const l = sr.querySelector('.da-launcher')!.getBoundingClientRect();
		return { gap: l.top - t.bottom, rightDiff: Math.abs(l.right - t.right), w: t.width };
	});
	expect(g.gap).toBeGreaterThanOrEqual(15); // §8.6: 16 px above the launcher
	expect(g.gap).toBeLessThanOrEqual(17);
	expect(g.rightDiff).toBeLessThanOrEqual(1);
	expect(g.w).toBeLessThanOrEqual(page.viewportSize()!.width < 640 ? 220 : 260);
	const order = await page.evaluate(() => [...document.querySelector('sigma-chat')!.shadowRoot!.querySelectorAll('button')].filter((b) => b.getClientRects().length).map((b) => b.className.split(' ')[0]));
	expect(order).toEqual(['da-teaser__open', 'da-launcher']);

	// Second page of the same visit: the same teaser is still there (no new 3 s greeting).
	await page.goto('/docs/header/?s=ok&teaser=1');
	await expect(teaser(page)).toBeVisible({ timeout: 1500 });
	// × ends it for the visit: a third page shows nothing.
	await page.locator('.da-teaser__open').focus();
	await page.keyboard.press('Escape'); // §8.6: Escape replaces the ×
	await expect(teaser(page)).toBeHidden();
	await page.goto('/?s=ok&teaser=1');
	await expect(launcher(page)).toBeVisible();
	await page.waitForTimeout(4000);
	await expect(teaser(page)).toHaveCount(0);
});

test('teaser: dismissed on page 1 → page 2 of the visit does not show it again @mobile', async ({ page }) => {
	await page.goto('/?s=ok&teaser=1');
	await expect(teaser(page)).toBeVisible({ timeout: 5000 });
	await page.locator('.da-teaser__open').focus();
	await page.keyboard.press('Escape'); // §8.6: Escape replaces the ×
	await page.goto('/docs/header/?s=ok&teaser=1');
	await page.waitForTimeout(4000);
	await expect(teaser(page)).toHaveCount(0);
	expect(await visit(page)).toMatchObject({ teaser: 'done' });
});

test('opening the chat ends the teaser for the visit and the pulse for this page only; the next page pulses again (v1.15) @mobile', async ({ page }) => {
	await page.clock.install();
	await page.goto('/?s=ok&teaser=1&pulse=1');
	await page.clock.runFor(3500);
	await expect(teaser(page)).toBeVisible();
	await page.locator('.da-teaser__open').click();
	await expect(page.locator('#da-panel')).toBeVisible();
	await expect(page.locator('.da-greeting__title')).toBeVisible(); // home
	await page.locator('#da-panel').getByRole('button', { name: 'Close help' }).click();
	await page.locator('#lorem').click();
	await countBursts(page);
	await page.clock.runFor(40000);
	await expect(teaser(page)).toBeHidden();
	expect(await bursts(page)).toBe(0); // stopped on this page view
	// Next page of the same visit: no teaser, but the arrival burst at 1 s again.
	await pulseProbe(page);
	await page.goto('/docs/header/?s=ok&teaser=1&pulse=1');
	await expect(launcher(page)).toBeVisible();
	const first = await firstBurst(page);
	expect(first).toBeGreaterThanOrEqual(1000);
	expect(first).toBeLessThanOrEqual(1600);
	await page.clock.runFor(5000);
	await expect(teaser(page)).toHaveCount(0);
});

test('Escape on the focused teaser hides it for the visit and stops the pulse for this page; the next page pulses (v1.15) @mobile', async ({ page }) => {
	await page.clock.install();
	await page.goto('/?s=ok&teaser=1&pulse=1');
	await page.clock.runFor(3500);
	await expect(teaser(page)).toBeVisible();
	await page.locator('.da-teaser__open').focus();
	await page.keyboard.press('Escape'); // §8.6: Escape replaces the ×
	await expect(teaser(page)).toBeHidden();
	await expect.poll(() => page.evaluate(() => (document.querySelector('sigma-chat')!.shadowRoot!.activeElement as HTMLElement)?.className)).toContain('da-launcher');
	await page.locator('#lorem').click(); // focus away from the launcher
	await countBursts(page);
	await page.clock.runFor(40000);
	expect(await bursts(page)).toBe(0);
	await pulseProbe(page);
	await page.goto('/docs/header/?s=ok&teaser=1&pulse=1');
	await expect(launcher(page)).toBeVisible();
	expect(await firstBurst(page)).toBeLessThanOrEqual(1600);
	await page.clock.runFor(4000);
	await expect(teaser(page)).toHaveCount(0); // the teaser stays done for the visit
});

test('a new visit greets again, even after the previous visit opened the chat @mobile', async ({ browser }, info) => {
	const opts = info.project.use;
	const ctx1 = await browser.newContext(opts);
	const p1 = await ctx1.newPage();
	await p1.goto('/?s=ok&teaser=1');
	await p1.locator('sigma-chat .da-launcher').click();
	await expect(p1.locator('#da-panel')).toBeVisible();
	const state = await ctx1.storageState(); // localStorage carries over (same browser); sessionStorage doesn't
	await ctx1.close();
	const ctx2 = await browser.newContext({ ...opts, storageState: state });
	const p2 = await ctx2.newPage();
	await p2.goto('/?s=ok&teaser=1');
	await expect(p2.locator('sigma-chat .da-teaser')).toBeVisible({ timeout: 5000 });
	if (shots) {
		await p2.waitForTimeout(300);
		for (const scheme of ['light', 'dark']) {
			await p2.evaluate((s) => document.documentElement.setAttribute('data-theme', s), scheme);
			await p2.waitForTimeout(250);
			await p2.screenshot({ path: `${shots}/teaser-second-visit-${info.project.name}-${scheme}.png` });
		}
	}
	await ctx2.close();
});

test('pulse: repeats every 12 s (≥ 4 bursts in 50 s) and pauses while the tab is hidden @mobile', async ({ page }) => {
	await page.clock.install();
	await page.goto('/?s=ok&pulse=1');
	await expect(launcher(page)).toBeVisible();
	await countBursts(page);
	await page.clock.runFor(50000);
	expect(await bursts(page)).toBeGreaterThanOrEqual(4); // 1, 13, 25, 37, 49 s
	const anim = await page.evaluate(() => {
		const l = document.querySelector('sigma-chat')!.shadowRoot!.querySelector('.da-launcher')!;
		l.setAttribute('data-pulse', '');
		const cs = getComputedStyle(l.querySelector('.da-ring')!); // live object: read before removing the attribute
		const out = { name: cs.animationName, count: cs.animationIterationCount, dur: cs.animationDuration };
		l.removeAttribute('data-pulse');
		return out;
	});
	expect(anim).toEqual({ name: 'da-ring', count: '2', dur: '1.2s' });

	await setHidden(page, true);
	await countBursts(page);
	await page.clock.runFor(60000);
	expect(await bursts(page)).toBe(0);
	await setHidden(page, false);
	await page.clock.runFor(17000);
	expect(await bursts(page)).toBeGreaterThanOrEqual(1);
});

test('pulse: none at all with reduced motion (no rings, no breathe) @mobile', async ({ browser }, info) => {
	const ctx = await browser.newContext({ ...info.project.use, reducedMotion: 'reduce' });
	const p = await ctx.newPage();
	await p.goto('/?s=ok&pulse=1');
	await countBursts(p);
	await p.waitForTimeout(5000);
	expect(await bursts(p)).toBe(0);
	expect(await p.locator('sigma-chat .da-ring').evaluate((r) => getComputedStyle(r).display)).toBe('none');
	expect(await p.evaluate(() => {
		const l = document.querySelector('sigma-chat')!.shadowRoot!.querySelector('.da-launcher')!;
		l.setAttribute('data-arrive', '');
		const name = getComputedStyle(l).animationName;
		l.removeAttribute('data-arrive');
		return name;
	})).toBe('none');
	await ctx.close();
});

test('pulse (v1.15): arrival burst between 1 and 1.6 s with the breathe, then every 12 s, also while the teaser shows @mobile', async ({ page }) => {
	await page.clock.install();
	await pulseProbe(page);
	await page.goto('/?s=ok&teaser=1&pulse=1');
	await expect(launcher(page)).toBeVisible();
	const first = await firstBurst(page);
	expect(first).toBeGreaterThanOrEqual(1000);
	expect(first).toBeLessThanOrEqual(1600);
	const at = await pulseAt(page);
	expect(at.length).toBe(1);
	expect(await breathe(page)).toEqual(['da-breathe 0.6s']);
	await page.clock.runFor(2000); // 3.6 s: the teaser is up
	await expect(teaser(page)).toBeVisible();
	await page.clock.runFor(10000); // 13.6 s: the second burst, while the teaser shows
	await expect(teaser(page)).toBeVisible();
	const at2 = await pulseAt(page);
	expect(at2.length).toBe(2);
	expect(Math.abs(at2[1] - at2[0] - 12000)).toBeLessThanOrEqual(5); // 12 s apart (a 1 ms rounding under load is fine)
	expect((await breathe(page)).length).toBe(1); // the breathe only on arrival
	await page.clock.runFor(24000); // 37.6 s: 25 and 37 s
	expect((await pulseAt(page)).length).toBe(4);
});

test('teaser auto-hides at 18 s if untouched; the pulse keeps its 12 s rhythm @mobile', async ({ page }) => {
	await page.clock.install();
	await pulseProbe(page);
	await page.goto('/?s=ok&teaser=1&pulse=1');
	await page.clock.runFor(3500);
	await expect(teaser(page)).toBeVisible();
	await page.clock.runFor(14000); // 17.5 s
	await expect(teaser(page)).toBeVisible();
	await page.clock.runFor(1000); // 18.5 s
	await expect(teaser(page)).toBeHidden();
	expect(await visit(page)).toEqual({ teaser: 'done' }); // shown, not dismissed, not opened
	expect((await pulseAt(page)).length).toBe(2); // 1 s and 13 s, independent of the teaser
	await page.clock.runFor(19000); // 37.5 s
	expect((await pulseAt(page)).length).toBe(4);
});

test('pulse (v1.15): arrival burst on every load, including page 2 and after the chat was opened on an earlier page @mobile', async ({ page }) => {
	await page.clock.install();
	await pulseProbe(page);
	const arrival = async (url: string) => {
		await page.goto(url);
		await expect(launcher(page)).toBeVisible();
		const first = await firstBurst(page);
		expect(first, url).toBeGreaterThanOrEqual(1000);
		expect(first, url).toBeLessThanOrEqual(1600);
		expect((await pulseAt(page)).length, url).toBe(1);
	};
	await arrival('/?s=ok&pulse=1');
	await arrival('/docs/header/?s=ok&pulse=1');
	// Open the chat on this page, close it, go to the next page: it pulses again.
	await launcher(page).click();
	await expect(page.locator('#da-panel')).toBeVisible();
	await page.locator('#da-panel').getByRole('button', { name: 'Close help' }).click();
	// The closed state is saved in an effect on the next frame: let it land before navigating (review on #37),
	// or the next page restores the open panel and the pulse is (correctly) stopped there.
	await page.clock.runFor(100);
	await expect.poll(() => page.evaluate(() => sessionStorage.getItem('delta-assistant:open'))).toBeNull();
	expect(await visit(page)).toMatchObject({ opened: 1 });
	await arrival('/?s=ok&pulse=1');
});

test('hover (or focus on phones) holds the teaser past 18 s; leaving restarts the countdown @mobile', async ({ page, isMobile }) => {
	await page.clock.install();
	await page.goto('/?s=ok&teaser=1&pulse=1');
	await page.clock.runFor(3500);
	await expect(teaser(page)).toBeVisible();
	if (isMobile) await page.locator('.da-teaser__open').focus();
	else await page.locator('.da-teaser__open').hover();
	await page.clock.runFor(25000); // well past 18 s
	await expect(teaser(page)).toBeVisible();
	if (isMobile) await page.locator('#lorem').click();
	else await page.mouse.move(5, 5);
	await page.clock.runFor(16000);
	await expect(teaser(page)).toBeHidden();
});

test('after an auto-hide, a later page of the visit shows no teaser and keeps pulsing @mobile', async ({ page }) => {
	await page.clock.install();
	await page.goto('/?s=ok&teaser=1&pulse=1');
	await page.clock.runFor(19000);
	await expect(teaser(page)).toBeHidden();
	await page.goto('/docs/header/?s=ok&teaser=1&pulse=1');
	await expect(launcher(page)).toBeVisible();
	await countBursts(page);
	await page.clock.runFor(17000); // 1 s and 13 s bursts
	await expect(teaser(page)).toHaveCount(0);
	expect(await bursts(page)).toBeGreaterThanOrEqual(2);
});

test('pulse: pauses on hover or focus @mobile', async ({ page, isMobile }) => {
	await page.clock.install();
	await page.goto('/?s=ok&pulse=1');
	await expect(launcher(page)).toBeVisible();
	if (isMobile) await launcher(page).focus();
	else await launcher(page).hover();
	await countBursts(page);
	await page.clock.runFor(14000); // past the 1 s arrival and the 13 s burst
	expect(await bursts(page)).toBe(0);
});

test('pulse: the idle main thread stays near 0 (compositor-only animation)', async ({ page, browserName }) => {
	test.skip(browserName !== 'chromium', 'CDP metrics');
	test.setTimeout(60_000);
	await page.goto('/?s=ok&pulse=1');
	await expect(launcher(page)).toBeVisible();
	const cdp = await page.context().newCDPSession(page);
	await cdp.send('Performance.enable');
	const metric = async () => Object.fromEntries((await cdp.send('Performance.getMetrics')).metrics.map((m: any) => [m.name, m.value]));
	await page.waitForTimeout(1000);
	const a = await metric();
	await countBursts(page);
	await page.waitForTimeout(26000); // spans 2+ bursts
	const b = await metric();
	const task = b.TaskDuration - a.TaskDuration;
	const script = b.ScriptDuration - a.ScriptDuration;
	const layout = b.LayoutCount - a.LayoutCount;
	console.log(JSON.stringify({ bursts: await bursts(page), seconds: 26, taskMs: Math.round(task * 1000), scriptMs: Math.round(script * 1000), layouts: layout, recalcStyle: b.RecalcStyleCount - a.RecalcStyleCount }));
	expect(await bursts(page)).toBeGreaterThanOrEqual(2);
	expect(task).toBeLessThan(0.26); // < 1% of a 26 s window on the main thread
	expect(layout).toBeLessThanOrEqual(4);
});

test('pulse frame shots @mobile', async ({ page }, info) => {
	test.skip(!shots);
	for (const scheme of ['light', 'dark']) {
		await page.goto(`/?s=sysfont&pulse=1${scheme === 'dark' ? '&bg=dark' : ''}`);
		await expect(launcher(page)).toBeVisible();
		await page.mouse.move(0, 0);
		await page.evaluate(() => {
			const l = document.querySelector('sigma-chat')!.shadowRoot!.querySelector('.da-launcher')!;
			l.removeAttribute('data-pulse');
			void (l as HTMLElement).offsetWidth;
			l.setAttribute('data-pulse', '');
		});
		await page.waitForTimeout(380);
		const r = await page.evaluate(() => document.querySelector('sigma-chat')!.shadowRoot!.querySelector('.da-launcher')!.getBoundingClientRect().toJSON());
		await page.screenshot({ path: `${shots}/pulse-v111-${info.project.name}-${scheme}.png`, clip: { x: r.left - 40, y: r.top - 40, width: r.width + 80, height: r.height + 80 } });
	}
});

// ----- v1.11.2 (0.1.4): the teaser stands out (launcher colours) and doesn't wait for a small consent card -----

const geo = (p: Page) =>
	p.evaluate(() => {
		const sr = document.querySelector('sigma-chat')!.shadowRoot!;
		const t = sr.querySelector('.da-teaser') as HTMLElement;
		const l = sr.querySelector('.da-launcher') as HTMLElement;
		const card = document.querySelector('.test-cookie');
		const btn = document.querySelector('.test-accept');
		const b = btn?.getBoundingClientRect();
		return {
			shown: !!t && !t.hidden && t.getBoundingClientRect().width > 0,
			t: t?.getBoundingClientRect().toJSON(),
			l: l.getBoundingClientRect().toJSON(),
			card: card?.getBoundingClientRect().toJSON() ?? null,
			btnHit: b ? document.elementFromPoint(b.left + b.width / 2, b.top + b.height / 2) === btn : null,
			side: document.querySelector('sigma-chat')!.getAttribute('data-teaser-at'),
			vh: innerHeight,
			vw: innerWidth,
		};
	});

const contrast = (p: Page) =>
	p.evaluate(() => {
		const sr = document.querySelector('sigma-chat')!.shadowRoot!;
		const t = sr.querySelector('.da-teaser') as HTMLElement;
		const rgb = (v: string) => (v.match(/[\d.]+/g) || []).slice(0, 3).map(Number);
		const lum = (c: number[]) => {
			const [r, g, b] = c.map((v) => {
				const x = v / 255;
				return x <= 0.03928 ? x / 12.92 : ((x + 0.055) / 1.055) ** 2.4;
			});
			return 0.2126 * r + 0.7152 * g + 0.0722 * b;
		};
		const ratio = (a: number[], b: number[]) => {
			const [x, y] = [lum(a), lum(b)].sort((m, n) => n - m);
			return (x + 0.05) / (y + 0.05);
		};
		const m = sr.querySelector('.da-teaser__msg')!; // the message bubble carries the colours (§8.6)
		const bg = rgb(getComputedStyle(m).backgroundColor);
		const fg = rgb(getComputedStyle(m).color);
		const page = rgb(getComputedStyle(document.body).backgroundColor);
		return { text: ratio(fg, bg), edge: ratio(bg, page) };
	});

test('teaser bubble uses the launcher colours (background and text) @mobile', async ({ page }) => {
	for (const bg of ['', '&bg=dark']) {
		await page.goto(`/?s=ok&teaser=1${bg}`);
		await expect(teaser(page)).toBeVisible({ timeout: 5000 });
		const c = await page.evaluate(() => {
			const sr = document.querySelector('sigma-chat')!.shadowRoot!;
			const cs = (sel: string) => getComputedStyle(sr.querySelector(sel)!);
			return { tBg: cs('.da-teaser__msg').backgroundColor, lBg: cs('.da-launcher').backgroundColor, tFg: cs('.da-teaser__msg').color, lFg: cs('.da-launcher').color, x: cs('.da-teaser__msg').color };
		});
		expect(c.tBg).toBe(c.lBg);
		expect(c.tFg).toBe(c.lFg);
		expect(c.x).toBe(c.lFg);
		await page.evaluate(() => sessionStorage.clear());
	}
});

test('teaser contrast: text ≥ 4.5:1 on the teaser, teaser edge ≥ 3:1 against dark and light pages @mobile', async ({ page }) => {
	for (const bg of ['&bg=dark', '']) {
		await page.goto(`/?s=ok&teaser=1${bg}`);
		await expect(teaser(page)).toBeVisible({ timeout: 5000 });
		const r = await contrast(page);
		expect(r.text, `text ${bg || 'light'}`).toBeGreaterThanOrEqual(4.5);
		expect(r.edge, `edge ${bg || 'light'}`).toBeGreaterThanOrEqual(3);
		await page.evaluate(() => sessionStorage.clear());
	}
});

test('a 200px consent card: teaser at 3 s above the lifted launcher, card button clickable, follows when the card goes @mobile', async ({ page }) => {
	await page.goto('/?s=ok&teaser=1&cookie=1');
	await expect(page.locator('.test-cookie')).toBeVisible();
	await expect(teaser(page)).toBeVisible({ timeout: 4500 }); // no longer waits for the card
	await page.waitForTimeout(400);
	let g = await geo(page);
	expect(g.l.bottom).toBeLessThanOrEqual(g.card!.top - 11); // launcher lifted over the card
	expect(g.t.top).toBeGreaterThanOrEqual(0);
	if (!g.side) expect(Math.round(g.l.top - g.t.bottom)).toBe(16); // above the lifted launcher
	expect(g.t.bottom).toBeLessThanOrEqual(g.card!.top); // never over the card
	expect(g.btnHit).toBe(true);
	await page.locator('.test-accept').click();
	await expect(page.locator('.test-cookie')).toHaveCount(0);
	await expect.poll(async () => {
		const n = await geo(page);
		return n.shown && Math.round(n.l.top - n.t.bottom) === 16 && n.l.bottom > g.l.bottom + 100;
	}, { timeout: 4000 }).toBe(true); // moved down with the launcher
});

test('a full-screen wall: no teaser until the wall closes @mobile', async ({ page }) => {
	await page.goto('/?s=ok&teaser=1&wall=1');
	await expect(page.locator('.test-wall')).toBeVisible();
	await page.waitForTimeout(4500);
	await expect(teaser(page)).toBeHidden();
	await page.locator('.test-wall-accept').click();
	await expect(teaser(page)).toBeVisible({ timeout: 3000 });
});

test('short viewports with a card: the teaser stays in view (beside the launcher when there is no room above) @mobile', async ({ page }) => {
	for (const height of [500, 380]) {
		await page.setViewportSize({ width: page.viewportSize()!.width, height });
		await page.goto('/?s=ok&teaser=1&cookie=1');
		await expect(teaser(page)).toBeVisible({ timeout: 4500 });
		await page.waitForTimeout(400);
		const g = await geo(page);
		expect(g.t.top, `top at ${height}`).toBeGreaterThanOrEqual(0);
		expect(g.t.bottom).toBeLessThanOrEqual(g.vh);
		expect(g.t.left).toBeGreaterThanOrEqual(0);
		expect(g.t.right).toBeLessThanOrEqual(g.vw);
		expect(g.btnHit).toBe(true);
		if (g.side) expect(g.t.right).toBeLessThanOrEqual(g.l.left - 11); // beside, not over, the launcher
		await page.evaluate(() => sessionStorage.clear());
	}
});

// The teaser avatar is covered with every other place in tests/icon.spec.ts (plan §8.8).
