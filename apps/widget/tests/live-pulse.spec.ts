// Opt-in, read-only (DA_LIVE=1 DA_LIVE_URL=<site>): the v1.15 arrival pulse on the live page, with the local build swapped in
// by request interception (nothing is installed). Measures the ring's starting edge against the page (≥ 3:1) in the
// page's dark and light schemes and saves 3 frames of the arrival burst: <DA_SHOTS>/pulse-arrival-<project>-<scheme>-<n>.png
import { expect, test, type Page } from '@playwright/test';

const SITE = process.env.DA_LIVE_URL || ''; // a live site running the plugin, e.g. https://example.com/
const shots = process.env.DA_SHOTS;
test.skip(!process.env.DA_LIVE || !SITE, 'DA_LIVE and DA_LIVE_URL not set');

async function useLocalBuild(page: Page, baseURL: string) {
	await page.route('**/plugins/sigma/build/widget/*.js*', async (route) => {
		const file = new URL(route.request().url()).pathname.split('/').pop()!;
		const res = await route.fetch({ url: `${baseURL}/build/widget/${file}` });
		await route.fulfill({ response: res });
	});
}

for (const scheme of ['dark', 'light'] as const) {
	test(`live v1.15 arrival pulse: ${scheme} @mobile`, async ({ page, baseURL }, info) => {
		test.setTimeout(60_000);
		await useLocalBuild(page, baseURL!);
		await page.emulateMedia({ colorScheme: scheme });
		// The site keeps its own theme choice: set data-theme as the page loads (its toggle sets the same attribute).
		await page.addInitScript((sc) => {
			const set = () => document.documentElement.setAttribute('data-theme', sc);
			document.addEventListener('DOMContentLoaded', set);
			addEventListener('load', set);
		}, scheme);
		// Record the burst start from the page's own load event.
		await page.addInitScript(() => {
			addEventListener('load', () => {
				const w = window as any;
				const t0 = performance.now();
				w.__pulseAt = [];
				const l = document.querySelector('sigma-chat')?.shadowRoot?.querySelector('.da-launcher');
				if (l) new MutationObserver(() => l.hasAttribute('data-pulse') && w.__pulseAt.push(Math.round(performance.now() - t0))).observe(l, { attributes: true, attributeFilter: ['data-pulse'] });
			});
		});
		await page.goto(SITE, { waitUntil: 'load' });
		const consent = page.locator('.consent');
		if (await consent.isVisible().catch(() => false)) await consent.getByRole('button', { name: 'Decline' }).click();
		await page.mouse.move(0, 0);
		await page.waitForFunction(() => (window as any).__pulseAt?.length > 0, null, { timeout: 5000 });
		const box = (await page.locator('sigma-chat .da-launcher').boundingBox())!;
		const clip = { x: Math.max(0, box.x - 60), y: Math.max(0, box.y - 60), width: box.width + 120, height: box.height + 120 };
		for (const [n, ms] of [[1, 120], [2, 450], [3, 900]] as const) {
			await page.waitForTimeout(n === 1 ? ms : ms - [0, 120, 450][n - 1]);
			if (shots) await page.screenshot({ path: `${shots}/pulse-arrival-${info.project.name}-${scheme}-${n}.png`, clip });
		}
		const m = await page.evaluate(() => {
			const parse = (c: string) => (c.match(/[\d.]+/g) || []).map(Number);
			const lum = ([r, g, b]: number[]) => {
				const f = (v: number) => ((v /= 255) <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4);
				return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
			};
			const ratio = (a: number[], b: number[]) => {
				const [x, y] = [lum(a), lum(b)].sort((p, q) => q - p);
				return (x + 0.05) / (y + 0.05);
			};
			const sr = document.querySelector('sigma-chat')!.shadowRoot!;
			const ring = parse(getComputedStyle(sr.querySelector('.da-ring')!).borderTopColor);
			// The page right behind the launcher: the first opaque background up the tree from the body.
			let el: Element | null = document.body;
			let bg = [255, 255, 255];
			for (const e of [document.body, document.documentElement]) {
				const c = parse(getComputedStyle(e).backgroundColor);
				if (c.length >= 3 && (c.length < 4 || c[3] > 0)) {
					bg = c.slice(0, 3);
					el = e;
					break;
				}
			}
			const a = 0.55; // the ring's starting opacity (@keyframes da-ring 0%)
			const edge = ring.slice(0, 3).map((v, i) => v * a + bg[i] * (1 - a));
			const kf = [...sr.styleSheets].flatMap((s) => [...s.cssRules]).find((r) => (r as CSSKeyframesRule).name === 'da-ring') as CSSKeyframesRule;
			return { ring: ring.slice(0, 3), page: bg, from: el?.tagName, startOpacity: (kf.cssRules[0] as CSSKeyframeRule).style.opacity, ratio: Math.round(ratio(edge, bg) * 100) / 100, at: (window as any).__pulseAt };
		});
		console.log(JSON.stringify({ project: info.project.name, scheme, ...m }));
		expect(m.startOpacity).toBe('0.55');
		expect(m.at[0]).toBeGreaterThanOrEqual(1000);
		expect(m.at[0]).toBeLessThanOrEqual(1600);
		expect(m.ratio).toBeGreaterThanOrEqual(3);
		expect(m.page.join(), 'the page really is in the requested scheme').toBe(scheme === 'dark' ? '21,23,27' : m.page.join());
	});
}
