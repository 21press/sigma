// PLAN v1.6 §8.1 "Position and stacking": always on top, and the consent guard. Runs on every project
// (desktop engines and the phone profiles), so no @mobile tag filter is needed: the phone projects pick up
// tests tagged @mobile only, hence the tag on each test.
//   DA_SHOTS=<dir> also saves screenshots with and without the cookie card.
import { expect, test, type Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';

const shots = process.env.DA_SHOTS;
if (shots) mkdirSync(shots, { recursive: true });

const launcher = (p: Page) => p.locator('sigma-chat .da-launcher');

/** What a click at the launcher's centre would hit: true when it is our widget. */
const launcherOnTop = (p: Page) =>
	p.evaluate(() => {
		const host = document.querySelector('sigma-chat')!;
		const r = host.shadowRoot!.querySelector('.da-launcher')!.getBoundingClientRect();
		return document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2) === host;
	});

const geometry = (p: Page) =>
	p.evaluate(() => {
		const r = document.querySelector('sigma-chat')!.shadowRoot!.querySelector('.da-launcher')!.getBoundingClientRect();
		const card = document.querySelector('.test-cookie');
		const btn = document.querySelector('.test-accept');
		const c = card ? card.getBoundingClientRect() : null;
		const b = btn ? btn.getBoundingClientRect() : null;
		return {
			launcherBottom: r.bottom,
			launcherTop: r.top,
			viewport: innerHeight,
			cardTop: c ? c.top : null,
			buttonHit: b ? document.elementFromPoint(b.left + b.width / 2, b.top + b.height / 2) === btn : null,
		};
	});

test('on top of a max-z element added before the widget (ties won by DOM order) @mobile', async ({ page }) => {
	await page.goto('/?s=ok&blocker=before');
	await expect(launcher(page)).toBeVisible();
	expect(await page.evaluate(() => document.querySelector('.test-blocker')!.compareDocumentPosition(document.querySelector('sigma-chat')!) & 4)).toBe(4);
	expect(await launcherOnTop(page)).toBe(true);
	await launcher(page).click();
	await expect(page.locator('#da-panel')).toBeVisible();
});

test('on top of a max-z element added after the widget: re-appended once @mobile', async ({ page }) => {
	await page.goto('/?s=ok');
	await expect(launcher(page)).toBeVisible();
	await page.evaluate(() => (window as any).daBlocker());
	await expect.poll(() => page.evaluate(() => document.body.lastElementChild?.tagName)).toBe('SIGMA-CHAT');
	expect(await launcherOnTop(page)).toBe(true);
	await launcher(page).click();
	await expect(page.locator('#da-panel')).toBeVisible();
	// Once per page: a second late rival is not chased (no loop).
	await page.evaluate(() => (window as any).daBlocker());
	await page.waitForTimeout(200);
	expect(await page.evaluate(() => document.body.lastElementChild?.className)).toBe('test-blocker');
});

test('cookie card bottom-right: launcher lifts 12px above it, its button stays clickable, returns after Accept @mobile', async ({ page }, info) => {
	await page.goto('/?s=ok&cookie=1');
	await expect(launcher(page)).toBeVisible();
	await expect.poll(async () => (await geometry(page)).launcherBottom).toBeLessThanOrEqual((await geometry(page)).cardTop! - 12 + 0.5);
	const g = await geometry(page);
	expect(g.launcherBottom).toBeGreaterThanOrEqual(g.cardTop! - 12 - 1.5); // sits right above, not far away
	expect(g.buttonHit).toBe(true);
	expect(await launcherOnTop(page)).toBe(true);
	if (shots) {
		await page.waitForTimeout(300);
		await page.screenshot({ path: join(shots, `cookie-${info.project.name}-with-card.png`) });
	}

	await page.locator('.test-accept').click();
	await expect(page.locator('.test-cookie')).toHaveCount(0);
	const offset = page.viewportSize()!.width < 640 ? 16 : 24;
	await expect.poll(async () => Math.round((await geometry(page)).viewport - (await geometry(page)).launcherBottom), { timeout: 3000 }).toBe(offset);
	if (shots) {
		await page.waitForTimeout(300);
		await page.screenshot({ path: join(shots, `cookie-${info.project.name}-without-card.png`) });
	}
});

test('a cookie card that appears after load (within 10 s) is still avoided @mobile', async ({ page }) => {
	await page.goto('/?s=ok&cookie=late');
	await expect(page.locator('.test-cookie')).toBeVisible({ timeout: 4000 });
	await expect.poll(async () => {
		const g = await geometry(page);
		return g.launcherBottom <= g.cardTop! - 12 + 0.5 && g.buttonHit === true;
	}).toBe(true);
});

test('open panel covers everything; closing it puts the launcher back above the card @mobile', async ({ page }) => {
	await page.goto('/?s=ok&cookie=1');
	await expect(launcher(page)).toBeVisible();
	await expect.poll(async () => (await geometry(page)).buttonHit).toBe(true);
	await launcher(page).click();
	await expect(page.locator('#da-panel')).toBeVisible();
	await page.locator('#da-panel').getByRole('button', { name: 'Close help' }).click();
	await expect(page.locator('#da-panel')).toBeHidden();
	await expect.poll(async () => {
		const g = await geometry(page);
		return g.launcherBottom <= g.cardTop! - 12 + 0.5 && g.buttonHit === true;
	}).toBe(true);
});

test('no fixed element under it: the launcher stays at its offset @mobile', async ({ page }) => {
	await page.goto('/?s=ok');
	await expect(launcher(page)).toBeVisible();
	await page.waitForTimeout(1200);
	const g = await geometry(page);
	expect(Math.round(g.viewport - g.launcherBottom)).toBe(page.viewportSize()!.width < 640 ? 16 : 24);
});

test('full-screen consent wall: launcher hidden and out of the tab order, wall button clickable, back after closing @mobile', async ({ page }) => {
	await page.goto('/?s=ok&wall=1');
	await expect(page.locator('.test-wall')).toBeVisible();
	await expect(page.locator('sigma-chat')).toHaveAttribute('data-covered', '');
	await expect(launcher(page)).toHaveCSS('visibility', 'hidden');
	await expect(launcher(page)).toBeHidden();
	// Out of the tab order: focus() on a visibility:hidden button does nothing.
	expect(await page.evaluate(() => {
		const l = document.querySelector('sigma-chat')!.shadowRoot!.querySelector('.da-launcher') as HTMLElement;
		l.focus();
		return document.querySelector('sigma-chat')!.shadowRoot!.activeElement === l;
	})).toBe(false);
	// The wall's button is what a click there hits, and the launcher's corner is not ours either.
	expect(await page.evaluate(() => {
		const b = document.querySelector('.test-wall-accept')!;
		const r = b.getBoundingClientRect();
		return document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2) === b;
	})).toBe(true);
	expect(await launcherOnTop(page)).toBe(false);

	await page.locator('.test-wall-accept').click();
	await expect(page.locator('.test-wall')).toHaveCount(0);
	await expect(launcher(page)).toBeVisible({ timeout: 3000 });
	await expect(page.locator('sigma-chat')).not.toHaveAttribute('data-covered', '');
	expect(await launcherOnTop(page)).toBe(true);
	const offset = page.viewportSize()!.width < 640 ? 16 : 24;
	const g = await geometry(page);
	expect(Math.round(g.viewport - g.launcherBottom)).toBe(offset);
});

test('a tall narrow fixed sidebar under the launcher does not hide it @mobile', async ({ page }) => {
	await page.goto('/?s=ok');
	await expect(launcher(page)).toBeVisible();
	await page.evaluate(() => {
		const d = document.createElement('div');
		d.setAttribute('style', 'position:fixed!important;top:0!important;right:0!important;bottom:0!important;width:120px!important;z-index:10!important;background:#eee!important;margin:0!important;border:0!important');
		document.body.appendChild(d);
	});
	await page.waitForTimeout(1200);
	await expect(launcher(page)).toBeVisible();
	await expect(page.locator('sigma-chat')).not.toHaveAttribute('data-covered', '');
});

/** Count guard checks: each check reads the probe's box exactly once. */
const countChecks = (p: Page) =>
	p.evaluate(() => {
		const probe = document.querySelector('sigma-chat')!.shadowRoot!.querySelector('.da-probe')! as any;
		(window as any).__daChecks = 0;
		const orig = probe.getBoundingClientRect.bind(probe);
		probe.getBoundingClientRect = () => {
			(window as any).__daChecks++;
			return orig();
		};
	});
const checks = (p: Page) => p.evaluate(() => (window as any).__daChecks as number);

test('a full-screen site menu opened 20 s after load hides the launcher; closing it brings it back @mobile', async ({ page }) => {
	test.setTimeout(60_000);
	await page.goto('/?s=ok&menu=1');
	await expect(launcher(page)).toBeVisible();
	await page.waitForTimeout(20_000); // well past the 10 s load window
	await page.locator('.test-menu-open').click();
	await expect(page.locator('sigma-chat')).toHaveAttribute('data-covered', '', { timeout: 2000 });
	await expect(launcher(page)).toBeHidden();
	expect(await page.evaluate(() => {
		const b = document.querySelector('.test-menu-close')!;
		const r = b.getBoundingClientRect();
		return document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2) === b;
	})).toBe(true);
	await page.locator('.test-menu-close').click();
	await expect(launcher(page)).toBeVisible({ timeout: 2000 });
	await expect(page.locator('sigma-chat')).not.toHaveAttribute('data-covered', '');

	// Keyboard: Escape closes the menu, keyup triggers the check too.
	await page.locator('.test-menu-open').click();
	await expect(launcher(page)).toBeHidden({ timeout: 2000 });
	await page.keyboard.press('Escape');
	await expect(launcher(page)).toBeVisible({ timeout: 2000 });
});

test('10 rapid clicks cause at most 2 guard checks; nothing runs between interactions @mobile', async ({ page }) => {
	test.setTimeout(40_000);
	await page.goto('/?s=ok');
	await expect(launcher(page)).toBeVisible();
	await page.waitForTimeout(10_600); // the load window's 500 ms checks have stopped
	await countChecks(page);
	await page.waitForTimeout(1500);
	expect(await checks(page)).toBe(0); // idle: no polling
	for (let i = 0; i < 10; i++) await page.mouse.click(200, 300, { delay: 0 });
	await page.waitForTimeout(1500);
	const n = await checks(page);
	expect(n).toBeGreaterThanOrEqual(1);
	expect(n).toBeLessThanOrEqual(2);
});
