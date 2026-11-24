// Isolation on the real Delta preview themes. Nothing is deployed: the test browser intercepts
// https://preview.deltatheme.com/__da/* and serves the local build + §8 mock from it (same origin, like the plugin).
//   DA_SHOTS=/path npx playwright test tests/isolation.spec.ts   → also saves screenshots
import { expect, test, type Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';

const SITES = ['kali', 'kiln', 'nordra', 'quadrant'];
const ORIGIN = 'https://preview.deltatheme.com';
const shots = process.env.DA_SHOTS;
if (shots) mkdirSync(shots, { recursive: true });

async function inject(page: Page, baseURL: string, site: string) {
	await page.route(`${ORIGIN}/__da/**`, async (route) => {
		const path = new URL(route.request().url()).pathname.replace(/^\/__da/, '');
		const res = await route.fetch({ url: baseURL + path, headers: route.request().headers() });
		await route.fulfill({ response: res });
	});
	await page.goto(`${ORIGIN}/${site}/`, { waitUntil: 'load' });
	const before = await pageFingerprint(page);
	await page.evaluate((origin) => {
		(window as any).SigmaWidget = { root: origin + '/__da/s/iso/wp-json/sigma/v1/' };
		const s = document.createElement('script');
		s.src = origin + '/__da/build/widget/widget.js';
		document.body.appendChild(s);
	}, ORIGIN);
	await expect(page.locator('sigma-chat .da-launcher')).toBeVisible();
	return before;
}

/** Things the widget must not change on the page. */
function pageFingerprint(page: Page) {
	return page.evaluate(() => {
		const b = getComputedStyle(document.body);
		const a = document.querySelector('a, button');
		return {
			scrollHeight: document.documentElement.scrollHeight,
			scrollWidth: document.documentElement.scrollWidth,
			bodyFont: b.fontFamily,
			bodyColor: b.color,
			bodyBg: b.backgroundColor,
			firstLink: a ? getComputedStyle(a).color : '',
		};
	});
}

function widgetStyles(page: Page) {
	return page.evaluate(() => {
		const sr = document.querySelector('sigma-chat')!.shadowRoot!;
		const cs = (sel: string) => getComputedStyle(sr.querySelector(sel)!);
		return {
			panelSize: cs('.da-panel').fontSize,
			panelLine: cs('.da-panel').lineHeight,
			nameSize: cs('#da-title').fontSize,
			nameWeight: cs('#da-title').fontWeight,
			nameMargin: cs('#da-title').marginTop,
			labelFont: cs('.da-msg__label').fontSize,
			labelCase: cs('.da-msg__label').textTransform,
			inputSize: cs('#da-q').fontSize,
			inputBox: cs('#da-q').boxSizing,
			launcherW: cs('.da-launcher').width,
			chipH: cs('.da-chip').minHeight,
			listPad: cs('.da-msg--ai ol').paddingLeft,
		};
	});
}

const EXPECTED = {
	panelSize: '14px',
	panelLine: '21px',
	nameSize: '16px',
	nameWeight: '600',
	nameMargin: '0px',
	labelFont: '12px',
	labelCase: 'uppercase',
	inputSize: '16px',
	inputBox: 'border-box',
	launcherW: '56px',
	chipH: '36px',
	listPad: '17.5px', // 1.25em of 14px
};

for (const site of SITES) {
	test.describe(`preview: ${site}`, () => {
		test(`widget looks the same and the page is untouched — ${site}`, async ({ page, baseURL }, info) => {
			test.skip(page.viewportSize()!.width < 640);
			const before = await inject(page, baseURL!, site);
			await page.locator('sigma-chat .da-launcher').click();
			await expect(page.locator('#da-panel')).toBeVisible();
			if (shots) {
				await expect(page.locator('.da-greeting__title')).toBeVisible();
				await page.waitForTimeout(400); // let the open animation finish
				await page.screenshot({ path: join(shots, `${site}-${info.project.name}-home.png`) });
			}
			await page.locator('.da-option--bot').click(); // §8.6: the composer is in the chat
			await page.locator('#da-q').fill('How do I change the header?');
			await page.locator('#da-q').press('Enter');
			await expect(page.locator('.da-log .da-msg--ai:not(.da-msg--intro)')).toBeVisible();

			expect(await widgetStyles(page)).toMatchObject(EXPECTED);
			// Page untouched: same size, same body styles, no horizontal overflow added.
			expect(await pageFingerprint(page)).toEqual(before);

			if (shots) {
				const tag = `${site}-${info.project.name}`;
				await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'light'));
				await page.screenshot({ path: join(shots, `${tag}-light.png`) });
				await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'));
				await expect.poll(() => page.locator('#da-panel').evaluate((el) => getComputedStyle(el).backgroundColor)).toBe('rgb(28, 31, 36)');
				await page.screenshot({ path: join(shots, `${tag}-dark.png`) });
			}
		});

		test(`phone layout — ${site} @mobile`, async ({ page, baseURL }, info) => {
			await inject(page, baseURL!, site);
			await page.locator('sigma-chat .da-launcher').click();
			await page.locator('.da-option--bot').click();
			await page.locator('#da-q').fill('What is the price?');
			await page.locator('#da-q').press('Enter');
			await expect(page.locator('.da-log .da-msg--ai .da-notice')).toBeVisible();
			const vp = page.viewportSize()!;
			if (vp.width < 640) await expect.poll(async () => Math.round((await page.locator('#da-panel').boundingBox())!.width)).toBe(vp.width);
			if (shots) {
				await page.waitForTimeout(400);
				await page.screenshot({ path: join(shots, `${site}-${info.project.name}-phone.png`) });
			}
		});
	});
}
