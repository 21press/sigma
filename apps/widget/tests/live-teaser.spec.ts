// Opt-in, read-only: the teaser on the live page (DA_LIVE=1 DA_LIVE_URL=<site>). Nothing is installed. With DA_TAG=after the
// test browser serves the local build in place of the site's widget files (request interception), as with the
// consent check. Shots: <DA_SHOTS>/live-teaser-<tag>-<project>-<step>.png
import { expect, test, type Page } from '@playwright/test';

const SITE = process.env.DA_LIVE_URL || ''; // a live site running the plugin, e.g. https://example.com/
const shots = process.env.DA_SHOTS;
const tag = process.env.DA_TAG || 'before';
test.skip(!process.env.DA_LIVE || !SITE, 'DA_LIVE and DA_LIVE_URL not set');

async function useLocalBuild(page: Page, baseURL: string) {
	await page.route('**/plugins/sigma/build/widget/*.js*', async (route) => {
		const file = new URL(route.request().url()).pathname.split('/').pop()!;
		const res = await route.fetch({ url: `${baseURL}/build/widget/${file}` });
		await route.fulfill({ response: res });
	});
}

const metrics = (page: Page) =>
	page.evaluate(() => {
		const sr = document.querySelector('sigma-chat')?.shadowRoot;
		const t = sr?.querySelector('.da-teaser') as HTMLElement | null;
		const l = sr?.querySelector('.da-launcher') as HTMLElement | null;
		if (!t || !l) return null;
		const tr = t.getBoundingClientRect();
		const lr = l.getBoundingClientRect();
		return { visible: !t.hidden && tr.width > 0, teaserBg: getComputedStyle(t).backgroundColor, launcherBg: getComputedStyle(l).backgroundColor, teaser: tr.toJSON(), launcher: lr.toJSON(), vh: innerHeight };
	});

test('live teaser @mobile', async ({ page, baseURL }, info) => {
	test.setTimeout(60_000);
	if (tag === 'after') await useLocalBuild(page, baseURL!);
	await page.goto(SITE, { waitUntil: 'load' });
	await expect(page.locator('sigma-chat .da-launcher')).toBeVisible();
	await page.waitForTimeout(4200); // past the 3 s greeting
	const withCard = await metrics(page);
	console.log(tag, info.project.name, 'with cookie card:', JSON.stringify(withCard));
	if (shots) await page.screenshot({ path: `${shots}/live-teaser-${tag}-${info.project.name}-dark-card.png` });
	const card = page.locator('.consent');
	if (await card.isVisible()) await card.getByRole('button', { name: 'Decline' }).click();
	await page.waitForTimeout(4200);
	console.log(tag, info.project.name, 'card gone:', JSON.stringify(await metrics(page)));
	if (shots) await page.screenshot({ path: `${shots}/live-teaser-${tag}-${info.project.name}-dark.png` });
	await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'light'));
	await page.waitForTimeout(600);
	console.log(tag, info.project.name, 'light:', JSON.stringify(await metrics(page)));
	if (shots) await page.screenshot({ path: `${shots}/live-teaser-${tag}-${info.project.name}-light.png` });
});

test('live §8.6: teaser chat line, home rows, Remy chat with intro @mobile', async ({ page, baseURL }, info) => {
	test.skip(tag === 'before', 'after-only');
	test.setTimeout(60_000);
	await useLocalBuild(page, baseURL!);
	await page.goto(SITE, { waitUntil: 'load' });
	const card = page.locator('.consent');
	if (await card.isVisible().catch(() => false)) await card.getByRole('button', { name: 'Decline' }).click();
	await expect(page.locator('sigma-chat .da-teaser')).toBeVisible({ timeout: 6000 });
	for (const scheme of ['dark', 'light']) {
		await page.evaluate((s) => document.documentElement.setAttribute('data-theme', s), scheme);
		await page.waitForTimeout(500);
		if (shots) await page.screenshot({ path: `${shots}/remy-${tag}-${info.project.name}-teaser-${scheme}.png` });
	}
	await page.locator('.da-teaser__open').click();
	await expect(page.locator('.da-option--bot')).toBeVisible();
	await page.mouse.move(0, 0);
	for (const scheme of ['dark', 'light']) {
		await page.evaluate((s) => document.documentElement.setAttribute('data-theme', s), scheme);
		await page.waitForTimeout(400);
		if (shots) await page.screenshot({ path: `${shots}/remy-${tag}-${info.project.name}-home-${scheme}.png` });
	}
	await page.locator('.da-option--bot').click();
	await expect(page.locator('.da-log .da-msg--intro')).toBeVisible();
	for (const scheme of ['dark', 'light']) {
		await page.evaluate((s) => document.documentElement.setAttribute('data-theme', s), scheme);
		await page.waitForTimeout(400);
		if (shots) await page.screenshot({ path: `${shots}/remy-${tag}-${info.project.name}-chat-${scheme}.png` });
	}
});

// v1.12.2: the Continue card with a long question. The transcript is seeded in this tab's sessionStorage, so no
// question is sent to the site's AI and nothing is stored there.
test('live v1.12.2: Continue card, long question on one line @mobile', async ({ page, baseURL }, info) => {
	test.skip(tag === 'before', 'after-only');
	test.setTimeout(60_000);
	await useLocalBuild(page, baseURL!);
	const q = 'How do I change the footer layout on archive pages without touching the templates, keep the sidebar on the right, and still let editors pick a different footer per category from the block editor?';
	await page.addInitScript((q) => {
		if (sessionStorage.getItem('delta-assistant:chat')) return;
		sessionStorage.setItem('delta-assistant:visit', JSON.stringify({ teaser: 'done' }));
		sessionStorage.setItem('delta-assistant:chat', JSON.stringify({ messages: [{ id: 'l1', sender: 'visitor', body: q }, { id: 'l2', sender: 'ai', body: 'Use a footer template part per category.' }], view: 'home' }));
	}, q);
	await page.goto(SITE, { waitUntil: 'load' });
	const consent = page.locator('.consent');
	if (await consent.isVisible().catch(() => false)) await consent.getByRole('button', { name: 'Decline' }).click();
	await page.locator('sigma-chat .da-launcher').click();
	const card = page.locator('.da-option--continue');
	await expect(card).toBeVisible();
	await expect(card).toHaveAccessibleName(`Continue with Remy: ${q}`);
	const m = await card.locator('.da-option__desc').evaluate((el) => ({ scroll: el.scrollWidth, client: el.clientWidth }));
	expect(m.scroll).toBeGreaterThan(m.client);
	await page.mouse.move(0, 0);
	for (const scheme of ['dark', 'light']) {
		await page.evaluate((s) => document.documentElement.setAttribute('data-theme', s), scheme);
		await page.waitForTimeout(400);
		if (shots) await page.screenshot({ path: `${shots}/continue-after-${info.project.name}-home-${scheme}.png` });
	}
});

// v1.13: the three home states (fresh, AI conversation, handed off). Each transcript is seeded in this tab's
// sessionStorage: nothing is sent to the site's AI and nothing is stored there.
for (const state of ['fresh', 'ai', 'handedoff'] as const) {
	test(`live v1.13 home: ${state} @mobile`, async ({ page, baseURL }, info) => {
		test.skip(tag === 'before', 'after-only');
		test.setTimeout(60_000);
		await useLocalBuild(page, baseURL!);
		const q = 'How do I change the footer layout on archive pages without touching the templates, and keep the sidebar?';
		await page.addInitScript(([q, state]) => {
			if (sessionStorage.getItem('delta-assistant:visit')) return;
			sessionStorage.setItem('delta-assistant:visit', JSON.stringify({ teaser: 'done' }));
			if (state === 'fresh') return;
			const messages = [{ id: 'l1', sender: 'visitor', body: q }, { id: 'l2', sender: 'ai', body: 'Use a footer template part per category.' }];
			sessionStorage.setItem('delta-assistant:chat', JSON.stringify({ messages, view: 'home', ...(state === 'handedoff' ? { sent: { email: 'sam@example.com' } } : {}) }));
		}, [q, state]);
		await page.goto(SITE, { waitUntil: 'load' });
		const consent = page.locator('.consent');
		if (await consent.isVisible().catch(() => false)) await consent.getByRole('button', { name: 'Decline' }).click();
		await page.locator('sigma-chat .da-launcher').click();
		const cards = page.locator('#da-panel .da-body--home .da-option');
		await expect(cards).toHaveCount(2);
		const titles = await page.locator('#da-panel .da-option__title').allTextContents();
		expect(titles).toEqual(state === 'fresh' ? ['Talk to a human', 'Talk to Remy'] : state === 'ai' ? ['Talk to a human', 'Continue with Remy'] : ['Continue with the team', 'Talk to Remy']);
		await page.mouse.move(0, 0);
		for (const scheme of ['dark', 'light']) {
			await page.evaluate((s) => document.documentElement.setAttribute('data-theme', s), scheme);
			await page.waitForTimeout(400);
			if (shots) await page.screenshot({ path: `${shots}/home-v113-${state}-${info.project.name}-${scheme}.png` });
		}
	});
}
