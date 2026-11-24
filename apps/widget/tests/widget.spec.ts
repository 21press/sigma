// Widget behaviour against the mock (dev/mock-server.mjs) and the design spec.
// Runs in Chromium, WebKit and Firefox; tests tagged @mobile also run on phone profiles.
import { expect, test, type Page } from '@playwright/test';

const panel = (p: Page) => p.locator('#da-panel');
const launcher = (p: Page) => p.locator('sigma-chat .da-launcher');
const input = (p: Page) => p.locator('#da-q');
const answer = (p: Page) => p.locator('.da-log .da-msg--ai:not(.da-msg--intro)');
const active = (p: Page) =>
	p.evaluate(() => {
		const el = document.querySelector('sigma-chat')!.shadowRoot!.activeElement as HTMLElement | null;
		return el ? el.id || el.className || el.tagName : null;
	});
const isMobile = (p: Page) => p.viewportSize()!.width < 640;

// Scenario names double as log keys; suffix the project so parallel browsers don't share one.
const sc = (name: string) => name + test.info().project.name.replace(/[^a-z]/g, '');
const apiLog = async (request: any, scenario: string) => (await (await request.get('/__log')).json()).filter((r: any) => r.scenario === scenario);

async function openPanel(p: Page, s = 'ok') {
	await p.goto(`/?s=${s}`);
	await expect(launcher(p)).toBeVisible();
	await p.waitForTimeout(150); // a panel left open on the previous page reopens by itself
	if (!(await panel(p).isVisible().catch(() => false))) await launcher(p).click();
	await expect(panel(p)).toBeVisible();
	await expect(p.locator('.da-header__status')).toBeVisible();
}

/** §8.6: home has no composer; "Talk to Sigma" opens a new AI chat. Ask from the chat (opening it if needed). */
/** v1.13: a new conversation is started from the AI chat header's New chat (the Sigma card continues the old one). */
const newChatBtn = (p: Page) => p.locator('#da-panel').getByRole('button', { name: 'Start a new chat with Sigma' });
async function askNew(p: Page, q: string) {
	if (!(await input(p).isVisible().catch(() => false))) {
		await p.locator('.da-option--bot').click();
		await expect(input(p)).toBeVisible();
	}
	await newChatBtn(p).click();
	await expect(p.locator('.da-log .da-msg--intro')).toBeVisible();
	await input(p).fill(q);
	await input(p).press('Enter');
}
async function ask(p: Page, q: string) {
	if (!(await input(p).isVisible().catch(() => false))) {
		await p.locator('.da-option--bot').click();
		await expect(input(p)).toBeVisible();
	}
	await input(p).fill(q);
	await input(p).press('Enter');
}

test('no REST call and no panel chunk until the bubble is opened', async ({ page, request }) => {
	const scripts: string[] = [];
	page.on('request', (r) => r.resourceType() === 'script' && scripts.push(r.url()));
	await page.goto(`/?s=${sc('norest')}`);
	await expect(launcher(page)).toBeVisible();
	await page.waitForTimeout(400);
	expect(await apiLog(request, sc('norest'))).toHaveLength(0);
	expect(scripts.some((u) => /panel-[0-9a-f]+\.js/.test(u))).toBe(false);

	await launcher(page).click();
	await expect(page.locator('.da-greeting__title')).toBeVisible();
	const after = await apiLog(request, sc('norest'));
	expect(after.map((r: any) => r.route)).toEqual(['config']); // the conversation starts with the first question
	expect(after[0].cookie).toBe('no');
});

test('launcher: fixed 56px, outside page flow, max z-index', async ({ page }) => {
	await page.goto('/?s=ok');
	await expect(launcher(page)).toBeVisible();
	const box = await launcher(page).boundingBox();
	expect([box!.width, box!.height]).toEqual([56, 56]);
	const host = await page.evaluate(() => {
		const h = document.querySelector('sigma-chat')!;
		const r = h.getBoundingClientRect();
		return { w: r.width, h: r.height, pos: getComputedStyle(h).position, z: getComputedStyle(h).zIndex, last: document.body.lastElementChild === h };
	});
	expect(host).toEqual({ w: 0, h: 0, pos: 'fixed', z: '2147483647', last: true });
	await expect(launcher(page)).toHaveCSS('position', 'fixed');
	await expect(launcher(page)).toHaveCSS('z-index', '2147483647');
});

test('home (§8.6): greeting, no composer, rows Talk to a human → Talk to Sigma, privacy line from config', async ({ page }) => {
	await openPanel(page);
	await expect(page.locator('#da-title')).toHaveText('Delta help');
	await expect(page.locator('.da-header__status')).toHaveText('Usually answers in seconds');
	await expect(page.locator('.da-greeting__title')).toHaveText('Hi. How can we help?');
	await expect(input(page)).toHaveCount(0); // no composer on home
	const titles = await page.locator('.da-option .da-option__title').allTextContents();
	expect(titles).toEqual(['Talk to a human', 'Talk to Sigma']);
	await expect(page.locator('.da-option--human')).toContainText('We reply by email');
	await expect(page.locator('.da-option--bot')).toContainText('Handles instant support');
	await expect(page.locator('.da-option--bot .da-remy--assistant svg')).toBeVisible(); // §8.8: Sigma's icon, the plugin mark by default
	await expect(page.locator('.da-privacy')).toContainText("Sigma's answers come from our docs and collective knowledge of Delta. Chats are stored for 90 days");
	await expect(page.locator('.da-privacy a')).toHaveAttribute('href', /\/privacy-policy\/$/);
	await expect.poll(() => active(page)).toContain('da-option--human'); // first row focused
});

test('ask → answer: ASSISTANT label, Markdown, doc chips in the same tab, token header', async ({ page, request }) => {
	await openPanel(page, sc('askflow'));
	await ask(page, 'How do I change the header?');
	await expect(page.locator('.da-log')).toHaveAttribute('aria-live', 'polite');
	await expect(page.locator('.da-msg--visitor .da-msg__body')).toHaveText('How do I change the header?');
	await expect(page.locator('.da-header__status')).toHaveText('Answers from our docs');
	const ai = answer(page);
	await expect(ai.locator('ol li')).toHaveCount(3);
	await expect(ai.locator('.da-msg__label').first()).toHaveText('SigmaAI'); // bot name + "AI" badge (§8.5)
	await expect(ai.locator('.da-msg__label .da-ai').first()).toHaveText('AI');
	await expect(page.locator('#da-title')).toHaveText('Sigma'); // AI chat header
	await expect(ai.locator('.da-msg__label').first()).toHaveCSS('text-transform', 'uppercase');
	await expect(ai.locator('pre code')).toContainText("add_filter( 'delta_header_layout'");
	await expect(ai.locator('.da-sources .da-msg__label')).toHaveText('From the docs');
	await expect(ai.locator('.da-chip')).toHaveCount(2);
	await expect(ai.locator('.da-chip').first()).toHaveAttribute('href', /\/docs\/header\/$/);
	expect(await page.locator('#da-panel a[target]').count()).toBe(0);
	await expect(input(page)).toHaveAttribute('placeholder', 'Ask a follow-up');
	// §8.7 v1.14: no always-visible "Talk to a human" button, and no offer under a normal answered reply.
	await expect(page.locator('.da-footer__assist')).toHaveCount(0);
	await expect(ai.locator('.da-notice')).toHaveCount(0);
	// A new answer is shown from its first line, not scrolled under the header.
	const gap = await page.evaluate(() => {
		const sr = document.querySelector('sigma-chat')!.shadowRoot!;
		return sr.querySelector('.da-log .da-msg--ai:not(.da-msg--intro)')!.getBoundingClientRect().top - sr.querySelector('.da-body')!.getBoundingClientRect().top;
	});
	expect(gap).toBeGreaterThanOrEqual(0);
	const log = await apiLog(request, sc('askflow'));
	expect(log.map((r: any) => r.route.replace(/[0-9a-f-]{36}/, 'U'))).toEqual(['config', 'conversations', 'conversations/U/ask']);
	expect(log[2].token).toBe('yes');
});

test('model output cannot inject HTML or script links', async ({ page }) => {
	await openPanel(page);
	await ask(page, 'inject please');
	const ai = answer(page);
	await expect(ai).toContainText('<img src=x onerror="window.__pwned=1">');
	await expect(ai).toContainText('<script>');
	expect(await page.locator('#da-panel .da-log img:not(.da-bot-av), #da-panel script').count()).toBe(0); // only Sigma's avatar
	const hrefs = await ai.locator('a').evaluateAll((as) => as.map((a) => (a as HTMLAnchorElement).href));
	expect(hrefs.every((h) => h.startsWith('https://') || h.startsWith('http://localhost'))).toBe(true);
	await expect(ai.locator('.da-chip')).toHaveText('<b>Safe</b> doc');
	await page.waitForTimeout(200);
	expect(await page.evaluate(() => (window as any).__pwned)).toBeUndefined();
});

test('not in the docs → "Talk to a human?" card, then the form prefilled with the last question', async ({ page }) => {
	await openPanel(page);
	await ask(page, 'What is the price?');
	const card = answer(page).locator('.da-notice');
	await expect(card.locator('.da-notice__title')).toHaveText('Talk to a human?');
	// answered:false WITH doc links (v1.6.1): don't claim it isn't in the docs.
	await expect(card.locator('.da-notice__text')).toHaveText("Need more than the docs? Send it to the team and we'll reply by email.");
	await expect(page.locator('.da-footer__assist')).toHaveCount(0);
	await expect(input(page)).toHaveAttribute('placeholder', 'Ask something else');
	await card.getByRole('button', { name: 'Talk to a human' }).click();
	await expect(page.locator('#da-title')).toHaveText('Talk to a human');
	await expect(page.locator('#da-text')).toHaveValue('What is the price?');
	await expect(page.locator('.da-hint')).toHaveText("We'll include your chat with the assistant, so you don't have to repeat it.");
	await expect(page.locator('.da-privacy')).toContainText('We use your name and email only to reply.');
	// Back returns to the chat and to the control that opened the form.
	await page.getByRole('button', { name: 'Back' }).click();
	await expect(answer(page)).toBeVisible();
	await expect.poll(() => active(page)).toContain('da-btn');
});

test('handoff: validation copy, server field error, sending state, sent state', async ({ page, request }) => {
	await openPanel(page, sc('handoff'));
	await page.locator('.da-option--human').click();
	await expect.poll(() => active(page)).toBe('da-name');
	await page.getByRole('button', { name: 'Send message' }).click();
	await expect(page.locator('#da-name-err')).toHaveText('Enter your name.');
	await expect(page.locator('#da-email-err')).toHaveText('Enter an email like name@example.com.');
	await expect(page.locator('#da-text-err')).toHaveText('Write a message.');
	await expect(page.locator('#da-name')).toHaveAttribute('aria-invalid', 'true');
	await expect(page.locator('#da-name')).toHaveAttribute('aria-describedby', 'da-name-err');
	await expect.poll(() => active(page)).toBe('da-name');
	// Live validation once shown.
	await page.locator('#da-name').fill('Sam');
	await expect(page.locator('#da-name-err')).toHaveCount(0);

	await page.locator('#da-email').fill('taken@example.com');
	await page.locator('#da-text').fill('Need an invoice with my VAT number.');
	await page.getByRole('button', { name: 'Send message' }).click();
	await expect(page.locator('#da-email-err')).toHaveText('Enter an email like name@example.com.'); // server invalid_email, data.field

	await page.locator('#da-email').fill('sam@example.com');
	await page.getByRole('button', { name: 'Send message' }).click();
	await expect(page.getByRole('button', { name: 'Sending…' })).toHaveAttribute('aria-disabled', 'true');
	await expect(page.locator('.da-end__title')).toHaveText('Message sent');
	await expect(page.locator('.da-end__text')).toHaveText("We'll reply to sam@example.com by email."); // no visitor email in R1 (v1.6)
	await expect(page.locator('.da-end')).toHaveAttribute('role', 'status');
	const body = (await apiLog(request, sc('handoff'))).find((r: any) => r.route === 'handoff-body');
	expect(body.website).toBe('');
	await page.getByRole('button', { name: 'Back to start' }).click();
	await expect(page.locator('.da-greeting__title')).toBeVisible();
});

test('handoff: 429 and 5xx notices, honeypot', async ({ page, request }) => {
	await openPanel(page, sc('hp'));
	await page.locator('.da-option--human').click();
	await page.locator('#da-name').fill('flood');
	await page.locator('#da-email').fill('a@example.com');
	await page.locator('#da-text').fill('hi');
	await page.getByRole('button', { name: 'Send message' }).click();
	await expect(page.locator('.da-notice--warning')).toHaveText('Too many messages from this connection. Try again in an hour.');
	await page.locator('#da-name').fill('crash');
	await page.getByRole('button', { name: 'Send message' }).click();
	await expect(page.locator('.da-notice--danger')).toHaveText("Couldn't send your message. Check your connection and try again.");
	await expect(page.getByRole('button', { name: 'Send message' })).toBeVisible();
	// A bot that fills the hidden field.
	await page.locator('#da-website').evaluate((el: HTMLInputElement) => {
		el.value = 'http://spam.example';
		el.dispatchEvent(new Event('input', { bubbles: true }));
	});
	await page.locator('#da-name').fill('Bot');
	await page.getByRole('button', { name: 'Send message' }).click();
	await expect(page.locator('.da-end__title')).toHaveText('Message sent');
	expect((await apiLog(request, sc('hp'))).some((r: any) => r.route === 'honeypot')).toBe(true);
});

test('429 hour: warning card with minutes, composer locked with placeholder', async ({ page }) => {
	await openPanel(page);
	await ask(page, 'over the limit');
	const card = page.locator('.da-notice--warning');
	await expect(card).toHaveAttribute('role', 'alert');
	await expect(card.locator('.da-notice__title')).toHaveText("You've reached the question limit");
	await expect(card.locator('.da-notice__text')).toHaveText('You can ask again in about 20 minutes. A person can help now.');
	await expect(input(page)).toBeDisabled();
	await expect(input(page)).toHaveAttribute('placeholder', 'You can ask again in 20 min');
	await expect(card.getByRole('button', { name: 'Talk to a human' })).toBeVisible();
});

test('429 day: "tomorrow" copy', async ({ page }) => {
	await openPanel(page);
	await ask(page, 'daily cap');
	await expect(page.locator('.da-notice--warning .da-notice__text')).toHaveText('You can ask again tomorrow. A person can help now.');
	await expect(input(page)).toHaveAttribute('placeholder', 'You can ask again tomorrow');
});

test('503 ai_unavailable: danger card, composer hidden, human path keeps the question', async ({ page }) => {
	await openPanel(page);
	await ask(page, 'is it down?');
	const card = page.locator('.da-notice--danger');
	await expect(card.locator('.da-notice__title')).toHaveText('The assistant is unavailable right now');
	await expect(card).toContainText("You can still reach a person. We'll reply by email.");
	await expect(page.locator('.da-header__status')).toHaveText('Assistant unavailable');
	await expect(input(page)).toHaveCount(0);
	await card.getByRole('button', { name: 'Talk to a human' }).click();
	await expect(page.locator('#da-text')).toHaveValue('is it down?');
});

test('network/5xx on ask: "Not sent. Try again" under the bubble, retry resends without a duplicate', async ({ page }) => {
	await openPanel(page);
	await ask(page, 'boom');
	const meta = page.locator('.da-msg--visitor .da-msg__meta--error');
	await expect(meta).toContainText('Not sent.');
	await expect(input(page)).toBeEnabled();
	await meta.getByRole('button', { name: 'Try again' }).click();
	await expect(page.locator('.da-msg--visitor')).toHaveCount(1);
	await expect(meta).toContainText('Not sent.'); // mock fails again; still one bubble
});

test('slow answer: typing dots, then "Still reading the docs…" after 8 s', async ({ page }) => {
	test.setTimeout(30_000);
	await openPanel(page);
	await ask(page, 'answer slowly');
	await expect(page.locator('.da-typing')).toBeVisible();
	await expect(page.locator('[role=status]').filter({ hasText: 'The assistant is writing an answer' })).toHaveCount(1);
	await expect(page.locator('.da-typing__slow')).toHaveText('Still reading the docs…', { timeout: 10_000 });
	await expect(answer(page)).toBeVisible({ timeout: 5_000 });
	await expect(page.locator('.da-typing')).toHaveCount(0);
});

test('desktop keyboard: non-modal, Escape closes and returns focus to the launcher', async ({ page }) => {
	test.skip(isMobile(page));
	await page.goto('/?s=ok');
	await launcher(page).focus();
	await page.keyboard.press('Enter');
	await expect(panel(page)).toBeVisible();
	await expect.poll(() => active(page)).toContain('da-option--human');
	await expect(panel(page)).toHaveAttribute('aria-modal', 'false');
	await expect(launcher(page)).toHaveAttribute('aria-expanded', 'true');
	await expect(launcher(page)).toHaveAttribute('aria-label', 'Close help');
	await page.keyboard.press('Escape');
	await expect(panel(page)).toBeHidden();
	await expect.poll(() => active(page)).toBe('da-launcher');
	await expect(launcher(page)).toHaveAttribute('aria-label', 'Open Delta help');
});

test('phone: full-screen modal with focus trap, scroll lock, 16px inputs @mobile', async ({ page, browserName }) => {
	test.skip(!isMobile(page));
	await openPanel(page);
	const vp = page.viewportSize()!;
	// Poll: the 200ms open animation scales from .98.
	await expect.poll(async () => Math.round((await panel(page).boundingBox())!.width)).toBe(vp.width);
	expect(Math.round((await panel(page).boundingBox())!.x)).toBe(0);
	await expect(panel(page)).toHaveAttribute('aria-modal', 'true');
	await expect(launcher(page)).toBeHidden();
	expect(await page.evaluate(() => document.documentElement.style.overflow)).toBe('hidden');
	await page.locator('.da-option--bot').click(); // §8.6: the composer lives in the chat
	expect(await input(page).evaluate((el) => getComputedStyle(el).fontSize)).toBe('16px');
	// Tab never leaves the panel.
	const key = browserName === 'webkit' ? 'Alt+Tab' : 'Tab';
	for (let i = 0; i < 8; i++) {
		await page.keyboard.press(key);
		const inside = await page.evaluate(() => {
			const sr = document.querySelector('sigma-chat')!.shadowRoot!;
			return !!sr.activeElement && sr.getElementById('da-panel')!.contains(sr.activeElement);
		});
		expect(inside).toBe(true);
	}
	await page.locator('#da-panel').getByRole('button', { name: 'Close help' }).click();
	await expect(launcher(page)).toBeVisible();
	expect(await page.evaluate(() => document.documentElement.style.overflow)).toBe('');
});

test('theme CSS does not leak in, widget CSS does not leak out (hostile page)', async ({ page }) => {
	await openPanel(page);
	await page.locator('.da-option--bot').click(); // the composer lives in the chat (§8.6)
	await expect(input(page)).toBeVisible();
	const s = await page.evaluate(() => {
		const sr = document.querySelector('sigma-chat')!.shadowRoot!;
		const cs = (sel: string) => getComputedStyle(sr.querySelector(sel)!);
		return {
			panelSize: cs('.da-panel').fontSize,
			panelTransform: cs('.da-panel').textTransform,
			panelSpacing: cs('.da-panel').letterSpacing,
			panelLine: cs('.da-panel').lineHeight,
			title: cs('#da-title').fontSize,
			launcherPad: cs('.da-launcher').paddingTop,
			iconW: cs('.da-launcher svg').width,
			inputBox: cs('#da-q').boxSizing,
			inputBorder: cs('.da-composer').borderTopColor,
			btnBg: cs('.da-header__btn').backgroundColor,
		};
	});
	expect(s).toMatchObject({ panelSize: '14px', panelTransform: 'none', panelSpacing: 'normal', panelLine: '21px', title: '16px', launcherPad: '0px', iconW: '56px', inputBox: 'border-box' });
	expect(s.inputBorder).not.toBe('rgb(0, 128, 0)');
	expect(s.btnBg).not.toBe('rgb(255, 105, 180)');
	expect(await page.locator('main button').first().evaluate((b) => getComputedStyle(b).backgroundColor)).toBe('rgb(255, 105, 180)');
});

test('scheme: page data-theme wins, flips live', async ({ page }) => {
	await openPanel(page);
	const bg = () => panel(page).evaluate((el) => getComputedStyle(el).backgroundColor);
	expect(await bg()).toBe('rgb(255, 255, 255)'); // OS light (Playwright default) → light (neutral default, #41)
	await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'));
	await expect.poll(bg).toBe('rgb(28, 28, 30)');
	await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'light'));
	await expect.poll(bg).toBe('rgb(255, 255, 255)');
});

test('scheme: a dark page without data-theme gets the dark widget even on a light OS (deltatheme.com)', async ({ page }) => {
	await page.goto('/?s=ok&bg=dark');
	await expect(launcher(page)).toBeVisible();
	await expect(page.locator('sigma-chat')).toHaveAttribute('data-scheme', 'dark');
	await expect(launcher(page)).toHaveCSS('background-color', 'rgb(245, 245, 247)'); // light brand on the dark page (neutral default, #41)
});

test('scheme: OS dark when the page has no switch and no background; brand.scheme=light forces light', async ({ browser }) => {
	const ctx = await browser.newContext({ colorScheme: 'dark' });
	const page = await ctx.newPage();
	await openPanel(page, 'ok&bg=none');
	expect(await panel(page).evaluate((el) => getComputedStyle(el).backgroundColor)).toBe('rgb(28, 28, 30)');
	await openPanel(page, 'forcelight&bg=none');
	await expect.poll(() => panel(page).evaluate((el) => getComputedStyle(el).backgroundColor)).toBe('rgb(255, 255, 255)');
	await ctx.close();
});

test('reduced motion: no panel animation, static typing dots', async ({ browser }) => {
	const ctx = await browser.newContext({ reducedMotion: 'reduce' });
	const page = await ctx.newPage();
	await openPanel(page);
	expect(await panel(page).evaluate((el) => getComputedStyle(el).animationName)).toBe('none');
	await ctx.close();
});

test('config failure retry; disabled; AI off shows the human home', async ({ page }) => {
	await page.goto('/?s=configfail');
	await launcher(page).click();
	await expect(page.getByRole('alert')).toContainText("Help couldn't load");
	await expect(page.getByRole('button', { name: 'Try again' })).toBeFocused();

	await page.evaluate(() => sessionStorage.clear());
	await page.goto('/?s=disabled');
	await launcher(page).click();
	await expect(panel(page)).toContainText("Help isn't available right now");

	await page.evaluate(() => sessionStorage.clear());
	await openPanel(page, 'aioff');
	await expect(page.locator('.da-greeting__intro')).toHaveText('Leave a message and the team will reply by email.');
	await expect(page.locator('.da-header__status')).toHaveText('We reply by email');
	await expect(input(page)).toHaveCount(0);
	await expect(page.locator('.da-privacy')).toContainText('We use your name and email only to reply.');
});

test('same-tab doc link: next page reopens the panel with the chat, focus stays on the page', async ({ page, request }) => {
	test.skip(isMobile(page));
	await openPanel(page, sc('persist'));
	await ask(page, 'How do I change the header?');
	await answer(page).locator('.da-chip').first().click();
	await expect(page).toHaveURL(/\/docs\/header\/$/);
	await expect(panel(page)).toBeVisible();
	await expect(page.locator('.da-msg--visitor .da-msg__body')).toHaveText('How do I change the header?');
	await expect(answer(page)).toHaveCount(1);
	expect(await page.evaluate(() => document.activeElement?.tagName)).toBe('BODY');
	await ask(page, 'And the footer?');
	await expect(answer(page)).toHaveCount(2);
	expect((await apiLog(request, sc('persist'))).filter((r: any) => r.route === 'conversations')).toHaveLength(1);
	// Closed panel stays closed on the next page.
	await page.keyboard.press('Escape');
	await expect(panel(page)).toBeHidden();
	await expect.poll(() => page.evaluate(() => sessionStorage.getItem('delta-assistant:open'))).toBeNull();
	await page.reload();
	await expect(launcher(page)).toBeVisible();
	await page.waitForTimeout(300);
	await expect(panel(page)).toHaveCount(0);
});

test('phone: a doc link lets the next page start closed so the doc is visible @mobile', async ({ page }) => {
	test.skip(!isMobile(page));
	await openPanel(page);
	await ask(page, 'How do I change the header?');
	await answer(page).locator('.da-chip').first().click();
	await expect(page).toHaveURL(/\/docs\/header\/$/);
	await expect(launcher(page)).toBeVisible();
	await page.waitForTimeout(300);
	expect(await panel(page).count()).toBe(0);
	await launcher(page).click();
	await expect(page.locator('.da-msg--visitor .da-msg__body')).toHaveText('How do I change the header?');
});

test('brand: colour per scheme with computed text colour, left position @mobile', async ({ page }) => {
	await openPanel(page, 'brand');
	await page.keyboard.press('Escape');
	await page.mouse.move(0, 0);
	await expect(launcher(page)).toHaveCSS('background-color', 'rgb(180, 72, 42)');
	await expect(launcher(page)).toHaveCSS('color', 'rgb(255, 255, 255)');
	await page.evaluate(() => sessionStorage.clear());
	await openPanel(page, 'left');
	await page.keyboard.press('Escape');
	await expect(launcher(page)).toBeVisible();
	await expect.poll(async () => (await launcher(page).boundingBox())?.x ?? 999).toBeLessThan(100);
});

test('conversation create 429 (1 per 10 s per IP) is retried quietly, no limit card', async ({ page }) => {
	await openPanel(page, sc('busyip'));
	await ask(page, 'How do I change the header?');
	await expect(answer(page)).toBeVisible({ timeout: 8_000 });
	await expect(page.locator('.da-notice--warning')).toHaveCount(0);
});

test('AI-down lasts for the page view only; the next page asks /config again', async ({ page }) => {
	test.skip(isMobile(page));
	await openPanel(page);
	await ask(page, 'is it down?');
	await expect(page.locator('.da-notice--danger')).toBeVisible();
	await page.reload();
	await expect(panel(page)).toBeVisible();
	await expect(input(page)).toBeVisible(); // the chat is restored with the AI on (the composer lives in the chat)
});

test('503 from a provider hiccup (reason status_*) offers "Try again" and keeps the AI on', async ({ page }) => {
	await openPanel(page);
	await ask(page, 'overloaded?');
	await expect(page.locator('.da-msg--visitor .da-msg__meta--error')).toContainText('Not sent.');
	await expect(page.locator('.da-notice--danger')).toHaveCount(0);
	await expect(input(page)).toBeEnabled();
});

test('not in the docs WITHOUT doc links: the spec copy "Your question isn\'t in the docs"', async ({ page }) => {
	await openPanel(page);
	await ask(page, 'What is the capital of Peru?');
	const card = answer(page).locator('.da-notice');
	await expect(card.locator('.da-notice__title')).toHaveText('Talk to a human?');
	await expect(card.locator('.da-notice__text')).toHaveText("Your question isn't in the docs. Send it to the team and we'll reply by email.");
	await expect(answer(page).locator('.da-chip')).toHaveCount(0);
	await expect(card.getByRole('button', { name: 'Talk to a human' })).toBeVisible();
});

test('handoff waits for a slow mail server (25 s) instead of failing at 20 s', async ({ page }) => {
	test.setTimeout(60_000);
	await openPanel(page);
	await page.locator('.da-option--human').click();
	await page.locator('#da-name').fill('slowmail');
	await page.locator('#da-email').fill('slow@example.com');
	await page.locator('#da-text').fill('Hello');
	await page.getByRole('button', { name: 'Send message' }).click();
	await expect(page.getByRole('button', { name: 'Sending…' })).toBeVisible();
	await expect(page.locator('.da-end__title')).toHaveText('Message sent', { timeout: 35_000 });
});

// ----- Idempotent handoff (PLAN v1.7.1) -----

async function fillHandoff(page: Page, text = 'Need an invoice.') {
	await page.locator('.da-option--human').click();
	await page.locator('#da-name').fill('Sam');
	await page.locator('#da-email').fill('sam@example.com');
	await page.locator('#da-text').fill(text);
}

test('handoff retry after a lost response reuses the idempotency key: one email', async ({ page, request }) => {
	await openPanel(page, sc('idemretry'));
	const keys: string[] = [];
	let first = true;
	await page.route('**/handoff', async (route) => {
		keys.push(JSON.parse(route.request().postData() || '{}').idempotency_key);
		if (first) {
			first = false;
			await route.fetch(); // the server gets it and sends the email…
			return route.abort('failed'); // …but the visitor never sees the answer (like the 45 s timeout)
		}
		return route.continue();
	});
	await fillHandoff(page);
	await page.getByRole('button', { name: 'Send message' }).click();
	await expect(page.locator('.da-notice--danger')).toContainText("Couldn't send your message");
	await page.getByRole('button', { name: 'Send message' }).click();
	await expect(page.locator('.da-end__title')).toHaveText('Message sent');
	expect(keys).toHaveLength(2);
	expect(keys[0]).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
	expect(keys[1]).toBe(keys[0]);
	const log = await apiLog(request, sc('idemretry'));
	expect(log.filter((r: any) => r.route === 'handoff-body')).toHaveLength(1); // one support email
});

test('a submit after an edit gets a new key', async ({ page }) => {
	await openPanel(page);
	const keys: string[] = [];
	let first = true;
	await page.route('**/handoff', async (route) => {
		keys.push(JSON.parse(route.request().postData() || '{}').idempotency_key);
		if (first) {
			first = false;
			return route.abort('failed'); // never reached the server
		}
		return route.continue();
	});
	await fillHandoff(page);
	await page.getByRole('button', { name: 'Send message' }).click();
	await expect(page.locator('.da-notice--danger')).toBeVisible();
	await page.locator('#da-text').fill('Need an invoice, VAT GB123.');
	await page.getByRole('button', { name: 'Send message' }).click();
	await expect(page.locator('.da-end__title')).toHaveText('Message sent');
	expect(keys).toHaveLength(2);
	expect(keys[1]).not.toBe(keys[0]);
});

test('double click on Send sends one request', async ({ page }) => {
	await openPanel(page);
	let calls = 0;
	await page.route('**/handoff', (route) => {
		calls++;
		return route.continue();
	});
	await fillHandoff(page);
	await page.getByRole('button', { name: 'Send message' }).dblclick();
	await expect(page.locator('.da-end__title')).toHaveText('Message sent');
	expect(calls).toBe(1);
});

test('409 already_handed_off (new submit after a handoff) shows the sent state, no second email', async ({ page, request }) => {
	await openPanel(page, sc('idemconflict'));
	await fillHandoff(page);
	await page.getByRole('button', { name: 'Send message' }).click();
	await expect(page.locator('.da-end__title')).toHaveText('Message sent');
	await page.getByRole('button', { name: 'Back to start' }).click();
	// v1.13: the human card now continues the handed-off conversation (sent state). A second submit on the same
	// conversation comes from a page that lost the local sent state (another tab, an older page): simulate that.
	await expect(page.locator('.da-option--human')).toHaveAccessibleName(/^Continue with the team: /);
	// Edit storage on the next load, before the widget starts: the widget saves its own state as the page
	// unloads, which would overwrite an edit made before reload().
	await page.addInitScript(() => {
		if (sessionStorage.getItem('da-test-lost-sent')) return;
		sessionStorage.setItem('da-test-lost-sent', '1');
		const c = JSON.parse(sessionStorage.getItem('delta-assistant:chat') || '{}');
		sessionStorage.setItem('delta-assistant:chat', JSON.stringify({ ...c, sent: null, view: 'home' }));
		sessionStorage.removeItem('delta-assistant:open');
	});
	await page.reload();
	await launcher(page).click();
	await expect(page.locator('.da-option--human .da-option__title')).toHaveText('Talk to a human');
	await fillHandoff(page, 'One more thing.');
	await page.getByRole('button', { name: 'Send message' }).click();
	await expect(page.locator('.da-end__title')).toHaveText('Message sent');
	await expect(page.locator('.da-notice--danger')).toHaveCount(0);
	const log = await apiLog(request, sc('idemconflict'));
	const reqs = log.filter((r: any) => r.route === 'handoff-request');
	expect(reqs).toHaveLength(2);
	expect(reqs[1].key).not.toBe(reqs[0].key);
	expect(log.filter((r: any) => r.route === 'handoff-body')).toHaveLength(1);
});

// ----- 0.1.1: "Continue conversation" card and Back history (owner feedback) -----

const card = (p: Page) => p.locator('.da-option--continue');
const back = (p: Page) => p.locator('#da-panel').getByRole('button', { name: 'Back', exact: true });
async function askAndWait(p: Page, q: string, n: number) {
	await ask(p, q);
	await expect(answer(p)).toHaveCount(n);
}
async function reopen(p: Page) {
	await p.waitForTimeout(300); // let the sessionStorage write after the last view change land (a person can't reload faster)
	await p.reload();
	await expect(p.locator('sigma-chat')).toBeAttached();
	await p.waitForTimeout(400); // an open panel reopens by itself (and hides the launcher on phones)
	if (!(await panel(p).isVisible())) await launcher(p).click();
	await expect(p.locator('.da-header__status')).toBeVisible();
}

test('no Continue card on a fresh tab @mobile', async ({ page }) => {
	await openPanel(page);
	await expect(page.locator('.da-greeting__title')).toBeVisible();
	await expect(card(page)).toHaveCount(0);
});

test('Continue card after a question: below Talk to a human, restores the transcript at the bottom, focus in the composer @mobile', async ({ page }) => {
	await openPanel(page);
	await askAndWait(page, 'How do I change the header?', 1);
	await askAndWait(page, 'And how do I change the footer layout on archive pages without touching the templates?', 2);
	await back(page).click();
	await expect(page.locator('.da-greeting__title')).toBeVisible();
	// v1.12.2: the full question, in the name and the text (CSS cuts it to one line).
	await expect(card(page)).toHaveAccessibleName('Continue with Sigma: And how do I change the footer layout on archive pages without touching the templates?');
	await expect(card(page).locator('.da-option__title')).toHaveText('Continue with Sigma');
	await expect(card(page).locator('.da-option__desc')).toHaveText('And how do I change the footer layout on archive pages without touching the templates?');
	// Order: Talk to a human, Talk to Sigma, then the Continue card.
	const titles = await page.locator('.da-option .da-option__title').allTextContents();
	expect(titles).toEqual(['Talk to a human', 'Continue with Sigma']); // v1.13: the Sigma card continues; no third row
	await expect.poll(() => active(page)).toContain('da-option--bot'); // this chat was opened from Talk to Sigma (SPEC §7.1)

	await card(page).click();
	await expect(page.locator('.da-msg--visitor')).toHaveCount(2);
	await expect(answer(page)).toHaveCount(2);
	await expect.poll(() => active(page)).toBe('da-q');
	const atBottom = await page.evaluate(() => {
		const b = document.querySelector('sigma-chat')!.shadowRoot!.querySelector('.da-body')!;
		return b.scrollHeight - b.scrollTop - b.clientHeight < 4;
	});
	expect(atBottom).toBe(true);
});

test('Back → card → Back keeps the card, and it survives a reload @mobile', async ({ page }) => {
	await openPanel(page);
	await askAndWait(page, 'How do I change the header?', 1);
	await back(page).click();
	await card(page).click();
	await expect(answer(page)).toHaveCount(1);
	await back(page).click();
	await expect(card(page)).toHaveAccessibleName('Continue with Sigma: How do I change the header?');
	await expect.poll(() => active(page)).toContain('da-option--continue');
	await reopen(page);
	await expect(card(page)).toHaveAccessibleName('Continue with Sigma: How do I change the header?');
});

test('New chat while a conversation exists: intro, Back without sending restores the old one, sending replaces it @mobile', async ({ page, request }) => {
	await openPanel(page, sc('newconv'));
	await askAndWait(page, 'How do I change the header?', 1);
	await back(page).click();
	// Continue, then New chat in the header: a fresh chat with the intro; type, go Back without sending.
	await card(page).click();
	await expect(newChatBtn(page)).toBeVisible();
	await newChatBtn(page).click();
	await expect(page.locator('.da-log .da-msg--intro')).toBeVisible();
	await expect(page.locator('.da-msg--visitor')).toHaveCount(0);
	await expect(input(page)).toHaveAttribute('placeholder', 'Ask a question');
	await input(page).fill('Something else');
	await back(page).click();
	await expect(page.locator('#da-panel .da-option')).toHaveCount(2); // never three cards
	await expect(card(page)).toHaveAccessibleName('Continue with Sigma: How do I change the header?');
	await expect.poll(() => active(page)).toContain('da-option--bot');
	await card(page).click();
	await expect(page.locator('.da-msg--visitor .da-msg__body')).toHaveText('How do I change the header?');
	await back(page).click();
	// Sending replaces it: a new conversation with only the new question; the card shows the new text.
	await askNew(page, 'How do I add a logo?');
	await expect(answer(page)).toHaveCount(1);
	await expect(page.locator('.da-msg--visitor')).toHaveCount(1);
	await expect(page.locator('.da-msg--visitor .da-msg__body')).toHaveText('How do I add a logo?');
	await back(page).click();
	await expect(card(page)).toHaveAccessibleName('Continue with Sigma: How do I add a logo?');
	const log = await apiLog(request, sc('newconv'));
	expect(log.filter((r: any) => r.route === 'conversations')).toHaveLength(2);
});

test('Continue card for a handed-off conversation: "Continue with the team", team stack, opens the sent state @mobile', async ({ page }) => {
	await openPanel(page, 'teamthree');
	await askAndWait(page, 'What is the price?', 1);
	await answer(page).locator('.da-notice').getByRole('button', { name: 'Talk to a human' }).click();
	await page.locator('#da-name').fill('Sam');
	await page.locator('#da-email').fill('sam@example.com');
	await page.getByRole('button', { name: 'Send message' }).click();
	await expect(page.locator('.da-end__title')).toHaveText('Message sent');
	await page.getByRole('button', { name: 'Back to start' }).click();
	// v1.12.2: a handed-off conversation continues with the team: its title and the team stack, no Sigma avatar.
	await expect(card(page)).toHaveAccessibleName('Continue with the team: What is the price?');
	await expect(card(page).locator('.da-option__title')).toHaveText('Continue with the team');
	await expect(card(page).locator('.da-team')).toBeVisible();
	await expect(card(page).locator('.da-bot-av')).toHaveCount(0);
	await card(page).click();
	await expect(page.locator('.da-end__title')).toHaveText('Message sent');
	await expect.poll(() => active(page)).toBe('da-end__title');
});

test('Back: home → form → Back → home, focus on Talk to a human (with a conversation in progress) @mobile', async ({ page }) => {
	await openPanel(page);
	await askAndWait(page, 'How do I change the header?', 1);
	await back(page).click();
	await page.locator('.da-option--human').click();
	await expect(page.locator('#da-title')).toHaveText('Talk to a human');
	await back(page).click();
	await expect(page.locator('.da-greeting__title')).toBeVisible(); // home, not the chat (owner's bug)
	await expect.poll(() => page.evaluate(() => (document.querySelector('sigma-chat')!.shadowRoot!.activeElement as HTMLElement)?.dataset.fk)).toBe('home-human');
});

test('Back: chat → request card or not-in-docs card → form → Back → chat, focus on that control @mobile', async ({ page }) => {
	await openPanel(page);
	await askAndWait(page, 'person please', 1);
	await answer(page).locator('.da-notice').getByRole('button', { name: 'Talk to a human' }).click();
	await back(page).click();
	await expect(answer(page)).toHaveCount(1);
	await expect.poll(() => page.evaluate(() => (document.querySelector('sigma-chat')!.shadowRoot!.activeElement as HTMLElement)?.textContent)).toBe('Talk to a human');

	await askAndWait(page, 'What is the price?', 2);
	await answer(page).last().locator('.da-notice').getByRole('button', { name: 'Talk to a human' }).click();
	await back(page).click();
	await expect(answer(page)).toHaveCount(2);
	await expect.poll(() => page.evaluate(() => (document.querySelector('sigma-chat')!.shadowRoot!.activeElement as HTMLElement)?.textContent)).toBe('Talk to a human');
});

test('Back: home → Continue → chat → Back → home; sent → Back → where the form was opened from @mobile', async ({ page }) => {
	await openPanel(page);
	await askAndWait(page, 'How do I change the header?', 1);
	await back(page).click();
	await card(page).click();
	await back(page).click();
	await expect(page.locator('.da-greeting__title')).toBeVisible();
	await expect.poll(() => page.evaluate(() => (document.querySelector('sigma-chat')!.shadowRoot!.activeElement as HTMLElement)?.dataset.fk)).toBe('home-remy');

	// Form opened from the chat (a reply that offers a human, §8.7) → sent → Back → chat.
	await card(page).click();
	await askAndWait(page, 'person please', 2);
	await answer(page).last().locator('.da-notice').getByRole('button', { name: 'Talk to a human' }).click();
	await page.locator('#da-name').fill('Sam');
	await page.locator('#da-email').fill('sam@example.com');
	await page.locator('#da-text').fill('Hello');
	await page.getByRole('button', { name: 'Send message' }).click();
	await expect(page.locator('.da-end__title')).toHaveText('Message sent');
	await back(page).click();
	await expect(answer(page)).toHaveCount(2);
});

test('Back: sent → Back → home when the form was opened from home @mobile', async ({ page }) => {
	await openPanel(page);
	await page.locator('.da-option--human').click();
	await page.locator('#da-name').fill('Sam');
	await page.locator('#da-email').fill('sam@example.com');
	await page.locator('#da-text').fill('Hello');
	await page.getByRole('button', { name: 'Send message' }).click();
	await expect(page.locator('.da-end__title')).toHaveText('Message sent');
	await back(page).click();
	await expect(page.locator('.da-greeting__title')).toBeVisible();
});

test('Back history survives a reload mid-path @mobile', async ({ page }) => {
	await openPanel(page);
	await askAndWait(page, 'person please', 1);
	await back(page).click();
	await card(page).click(); // home → chat
	await answer(page).locator('.da-notice').getByRole('button', { name: 'Talk to a human' }).click(); // chat → form
	await expect(page.locator('#da-title')).toHaveText('Talk to a human');
	await expect.poll(() => page.evaluate(() => JSON.parse(sessionStorage.getItem('delta-assistant:chat') || '{}').view)).toBe('handoff');
	await reopen(page);
	await expect(page.locator('#da-title')).toHaveText('Talk to a human');
	await back(page).click();
	await expect(answer(page)).toHaveCount(1); // chat
	await back(page).click();
	await expect(page.locator('.da-greeting__title')).toBeVisible(); // home
	await expect(back(page)).toHaveCount(0);
});

// ----- 0.1.2: streaming answers (PLAN §8.3) -----

test('stream: sends Accept text/event-stream; dots until the first delta; plain text while streaming; final re-render with chips @mobile', async ({ page, request }) => {
	await openPanel(page, sc('streamprog'));
	await ask(page, 'progressive: how do I change the header?');
	await expect(page.locator('.da-typing')).toBeVisible(); // before the first delta (start is sent after 300 ms)
	const live = page.locator('.da-stream');
	await expect(live).toBeVisible();
	await expect(page.locator('.da-typing')).toHaveCount(0);
	await expect(live).toHaveAttribute('aria-busy', 'true');
	await expect.poll(async () => (await live.locator('.da-stream__text').textContent())!.length).toBeGreaterThan(20);
	// Mid-stream: raw Markdown as plain text, no links, no chips, nothing in the live log yet.
	expect(await live.locator('a, strong, ol, ul, code').count()).toBe(0);
	await expect(live.locator('.da-stream__text')).toContainText('**Delta**');
	expect(await page.locator('.da-log .da-msg--ai:not(.da-msg--intro)').count()).toBe(0);
	// Done: one finished message in the log, rendered with the safe Markdown renderer and doc chips.
	await expect(answer(page)).toHaveCount(1, { timeout: 30_000 });
	await expect(page.locator('.da-stream')).toHaveCount(0);
	await expect(answer(page).locator('strong').first()).toHaveText('Delta');
	await expect(answer(page).locator('.da-chip')).toHaveCount(2);
	const log = await apiLog(request, sc('streamprog'));
	expect(log.find((r: any) => /ask$/.test(r.route)).accept).toContain('text/event-stream');
});

test('stream: the live region gets the finished answer once (screen readers announce it once) @mobile', async ({ page }) => {
	await openPanel(page);
	await page.evaluate(() => {
		const sr = document.querySelector('sigma-chat')!.shadowRoot!;
		(window as any).__added = 0;
		const watch = () => {
			const log = sr.querySelector('.da-log');
			if (!log) return requestAnimationFrame(watch);
			new MutationObserver((rs) => rs.forEach((r) => r.addedNodes.forEach((n) => n instanceof Element && n.classList.contains('da-msg--ai') && (window as any).__added++))).observe(log, { childList: true });
		};
		watch();
	});
	await ask(page, 'progressive: tell me about the header');
	await expect(page.locator('.da-stream')).toBeVisible();
	expect(await page.locator('.da-log .da-stream').count()).toBe(0); // the streaming text is outside the log
	await expect(answer(page)).toHaveCount(1, { timeout: 30_000 });
	await page.waitForTimeout(300);
	expect(await page.evaluate(() => (window as any).__added)).toBe(1);
});

test('stream: a buffered stream (all events at once) behaves like today @mobile', async ({ page }) => {
	await openPanel(page);
	await ask(page, 'buffered: how do I change the header?');
	await expect(answer(page)).toHaveCount(1);
	await expect(answer(page).locator('.da-chip')).toHaveCount(2);
	await expect(page.locator('.da-stream')).toHaveCount(0);
	await expect(page.locator('.da-msg--partial')).toHaveCount(0);
});

test('stream: a host that answers JSON (no stream) still works @mobile', async ({ page }) => {
	await openPanel(page, sc('nostream'));
	await ask(page, 'How do I change the header?');
	await expect(answer(page)).toHaveCount(1);
	await expect(answer(page).locator('.da-chip')).toHaveCount(2);
});

test('stream: mid-stream error keeps the partial text greyed, "Not finished. Try again" resends the same question @mobile', async ({ page, request }) => {
	await openPanel(page, sc('streammid'));
	await ask(page, 'midfail: how do I change the header?');
	const partial = page.locator('.da-msg--partial');
	await expect(partial).toBeVisible();
	await expect(partial.locator('.da-msg__body')).toContainText('To change');
	expect(await partial.locator('a, .da-chip').count()).toBe(0);
	await expect(partial.locator('.da-msg__meta--error')).toContainText('Not finished.');
	const grey = await partial.locator('.da-msg__body').evaluate((el) => getComputedStyle(el).color);
	const ink = await page.locator('.da-msg--visitor .da-msg__body').evaluate(() => getComputedStyle(document.querySelector('sigma-chat')!.shadowRoot!.querySelector('.da-panel')!).color);
	expect(grey).not.toBe(ink);
	await expect(page.locator('.da-msg--visitor .da-msg__meta--error')).toHaveCount(0); // the question did send
	await partial.getByRole('button', { name: 'Try again' }).click();
	await expect(answer(page)).toHaveCount(1, { timeout: 30_000 });
	await expect(page.locator('.da-msg--partial')).toHaveCount(0);
	await expect(page.locator('.da-msg--visitor')).toHaveCount(1); // no duplicate question
	const asks = (await apiLog(request, sc('streammid'))).filter((r: any) => /ask$/.test(r.route));
	expect(asks).toHaveLength(2);
});

test('stream: an error before any text shows "Not sent. Try again" on the question @mobile', async ({ page }) => {
	await openPanel(page);
	await ask(page, 'earlyfail please');
	await expect(page.locator('.da-msg--visitor .da-msg__meta--error')).toContainText('Not sent.');
	await expect(page.locator('.da-msg--partial')).toHaveCount(0);
	await expect(page.locator('.da-notice--danger')).toHaveCount(0); // a hiccup, not "assistant unavailable"
	await expect(input(page)).toBeEnabled();
});

test('stream: model text with HTML stays text while streaming and after @mobile', async ({ page }) => {
	await openPanel(page);
	await ask(page, 'inject please');
	await expect(answer(page)).toHaveCount(1);
	expect(await page.locator('#da-panel .da-log img:not(.da-bot-av), #da-panel script').count()).toBe(0); // only Sigma's avatar
	expect(await page.evaluate(() => (window as any).__pwned)).toBeUndefined();
});

// ----- 0.1.2: "Talk to a human" row with the team avatar stack (plan v1.9 §8.4) -----

const humanRow = (p: Page) => p.locator('.da-option--human');
/** Wait for the panel's open animation (scale .98 → 1) so measurements are final. */
const settle = (p: Page) =>
	expect.poll(() => p.evaluate(() => document.querySelector('sigma-chat')!.shadowRoot!.getAnimations().length)).toBe(0);
const teamInfo = async (p: Page) => {
	await settle(p);
	return p.evaluate(() => {
		const sr = document.querySelector('sigma-chat')!.shadowRoot!;
		const row = sr.querySelector('.da-option--human')!;
		const items = [...row.querySelectorAll('.da-team__a')] as HTMLElement[];
		return {
			label: row.getAttribute('aria-label'),
			hasIconBox: !!row.querySelector('.da-option__icon'),
			items: items.map((el) => {
				const r = el.getBoundingClientRect();
				return { tag: el.tagName, text: el.textContent, left: r.left, right: r.right, w: r.width, h: r.height, z: getComputedStyle(el).zIndex, alt: el.getAttribute('alt'), loading: el.getAttribute('loading'), decoding: el.getAttribute('decoding'), wa: el.getAttribute('width'), ha: el.getAttribute('height') };
			}),
			rowH: row.getBoundingClientRect().height,
		};
	});
};

test('team: none → text-only row, no icon box, visible text is the name @mobile', async ({ page }) => {
	await openPanel(page, 'teamnone');
	const t = await teamInfo(page);
	expect(t.hasIconBox).toBe(false);
	expect(t.items).toHaveLength(0);
	expect(t.label).toBeNull();
	await expect(humanRow(page)).toHaveAccessibleName(/^Talk to a human/);
	await expect(humanRow(page).locator('.da-option__title')).toHaveText('Talk to a human');
});

test('team: one member → one avatar, name "Talk to a human. Team: Maya" @mobile', async ({ page }) => {
	await openPanel(page, 'teamone');
	await expect(humanRow(page)).toHaveAccessibleName('Talk to a human. Team: Maya');
	const t = await teamInfo(page);
	expect(t.items.map((i) => i.tag)).toEqual(['IMG']);
	expect(t.items[0]).toMatchObject({ alt: '', loading: 'lazy', decoding: 'async', wa: '28', ha: '28', w: 28, h: 28 });
});

test('team: three members → 2 avatars + initials, overlapping 8px, first on top, no +N @mobile', async ({ page }) => {
	await openPanel(page, 'teamthree');
	await expect(humanRow(page)).toHaveAccessibleName('Talk to a human. Team: Maya, Raj and Ana');
	const t = await teamInfo(page);
	expect(t.items.map((i) => i.tag)).toEqual(['IMG', 'IMG', 'SPAN']);
	expect(t.items[2].text).toBe('A'); // initials fallback for an empty avatar_url
	expect(Math.round(t.items[1].left - t.items[0].left)).toBe(20); // 28px circles overlapping by 8px
	expect(Number(t.items[0].z)).toBeGreaterThan(Number(t.items[1].z));
	// First-picked on top: the overlap point hits the first avatar.
	const hit = await page.evaluate(() => {
		const sr = document.querySelector('sigma-chat')!.shadowRoot!;
		const [a, b] = sr.querySelectorAll('.da-team__a');
		const ra = a.getBoundingClientRect();
		return sr.elementFromPoint(ra.right - 3, ra.top + ra.height / 2) === a && b !== null;
	});
	expect(hit).toBe(true);
	expect(t.items.some((i) => (i.text || '').startsWith('+'))).toBe(false);
});

test('team: five members → 3 shown + "+2", name lists 2 and "3 others" @mobile', async ({ page }) => {
	await openPanel(page, 'teamfive');
	await expect(humanRow(page)).toHaveAccessibleName('Talk to a human. Team: Maya, Raj and 3 others');
	const t = await teamInfo(page);
	expect(t.items).toHaveLength(4);
	expect(t.items[3].text).toBe('+2');
});

test('team: no layout shift when the avatars load; initials readable in light and dark @mobile', async ({ page }) => {
	let release: () => void = () => {};
	const gate = new Promise<void>((r) => (release = r));
	await page.route('**/avatars/**', async (route) => {
		await gate; // hold the images until we have measured the row without them
		return route.continue();
	});
	await openPanel(page, 'teamfive');
	const before = await teamInfo(page);
	release();
	await expect.poll(() => page.evaluate(() => [...document.querySelector('sigma-chat')!.shadowRoot!.querySelectorAll('img.da-team__a')].every((i) => (i as HTMLImageElement).complete))).toBe(true);
	const after = await teamInfo(page);
	expect(after.rowH).toBe(before.rowH);
	expect(after.items.map((i) => [i.left, i.w, i.h])).toEqual(before.items.map((i) => [i.left, i.w, i.h]));

	for (const scheme of ['light', 'dark']) {
		await page.evaluate((s) => document.documentElement.setAttribute('data-theme', s), scheme);
		const ratio = await page.evaluate(() => {
			const host = document.querySelector('sigma-chat')!;
			const el = host.shadowRoot!.querySelector('.da-team__i') as HTMLElement;
			const hs = getComputedStyle(host);
			const parse = (v: string) => {
				v = v.trim();
				if (v.startsWith('#')) {
					const h = v.length === 4 ? v.slice(1).split('').map((c) => c + c).join('') : v.slice(1, 7);
					return [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16));
				}
				return (v.match(/[\d.]+/g) || []).slice(0, 3).map(Number);
			};
			const brand = parse(hs.getPropertyValue('--da-brand'));
			const surface = parse(hs.getPropertyValue('--da-surface'));
			const bg = brand.map((c, i) => c * 0.22 + surface[i] * 0.78); // color-mix(in srgb, brand 22%, surface)
			const fg = parse(getComputedStyle(el).color);
			const lum = (rgb: number[]) => {
				const [r, g, b] = rgb.map((v) => {
					const c = v / 255;
					return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
				});
				return 0.2126 * r + 0.7152 * g + 0.0722 * b;
			};
			const [l1, l2] = [lum(fg), lum(bg)].sort((a, b) => b - a);
			return (l1 + 0.05) / (l2 + 0.05);
		});
		expect(ratio, `initials contrast in ${scheme}`).toBeGreaterThanOrEqual(4.5);
	}
});

test('new conversation from home: a failed first send + Back restores the old conversation and its card @mobile', async ({ page }) => {
	await openPanel(page);
	await askAndWait(page, 'How do I change the header?', 1);
	await back(page).click();
	await askNew(page, 'boom');
	await expect(page.locator('.da-msg--visitor .da-msg__meta--error')).toContainText('Not sent.');
	// Storage still holds the old conversation while the new one has no answer.
	expect(await page.evaluate(() => JSON.parse(sessionStorage.getItem('delta-assistant:chat') || '{}').messages?.find((m: any) => m.sender === 'visitor')?.body)).toBe('How do I change the header?');
	await back(page).click();
	await expect(card(page)).toHaveAccessibleName('Continue with Sigma: How do I change the header?');
	await card(page).click();
	await expect(page.locator('.da-msg--visitor .da-msg__body')).toHaveText('How do I change the header?');
	await expect(answer(page)).toHaveCount(1);
	// And a reload in the failed state also comes back to the old one.
	await back(page).click();
	await askNew(page, 'boom');
	await expect(page.locator('.da-msg--visitor .da-msg__meta--error')).toBeVisible();
	await reopen(page);
	if (await back(page).count()) await back(page).click();
	await expect(card(page)).toHaveAccessibleName('Continue with Sigma: How do I change the header?');
});

test('503 ai_unavailable while the docs index builds (reason corpus_building) offers "Try again", AI stays on @mobile', async ({ page }) => {
	await openPanel(page);
	await ask(page, 'warming up?');
	await expect(page.locator('.da-msg--visitor .da-msg__meta--error')).toContainText('Not sent.');
	await expect(page.locator('.da-notice--danger')).toHaveCount(0);
	await expect(input(page)).toBeEnabled();
});

// ----- 0.1.2: the old conversation is replaced only when the new one is created AND its first ask accepted -----

const storedFirst = (p: Page) => p.evaluate(() => JSON.parse(sessionStorage.getItem('delta-assistant:chat') || '{}').messages?.find((m: any) => m.sender === 'visitor')?.body);
const storedToken = (p: Page) => p.evaluate(() => JSON.parse(localStorage.getItem('delta-assistant:v1') || '{}').token);

async function oldConversation(p: Page) {
	await openPanel(p);
	await askAndWait(p, 'How do I change the header?', 1);
	await back(p).click();
	return storedToken(p);
}

test('replace: the create fails → old conversation and card stay @mobile', async ({ page }) => {
	const oldToken = await oldConversation(page);
	await page.route('**/wp-json/sigma/v1/conversations', (r) => r.fulfill({ status: 500, contentType: 'application/json', body: '{"code":"internal","message":"x","data":{"status":500}}' }));
	await askNew(page, 'A new topic');
	await expect(page.locator('.da-msg--visitor .da-msg__meta--error')).toContainText('Not sent.');
	expect(await storedFirst(page)).toBe('How do I change the header?');
	expect(await storedToken(page)).toBe(oldToken);
	await back(page).click();
	await expect(card(page)).toHaveAccessibleName('Continue with Sigma: How do I change the header?');
});

test('replace: the first ask fails (503 or network) → old card stays; a retry succeeds and replaces it @mobile', async ({ page }) => {
	const oldToken = await oldConversation(page);
	// 503 provider hiccup on the new conversation's first ask.
	await askNew(page, 'overloaded topic');
	await expect(page.locator('.da-msg--visitor .da-msg__meta--error')).toContainText('Not sent.');
	expect(await storedFirst(page)).toBe('How do I change the header?');
	expect(await storedToken(page)).toBe(oldToken);
	await back(page).click();
	await expect(card(page)).toHaveAccessibleName('Continue with Sigma: How do I change the header?');

	// Network error, then "Try again" succeeds and replaces the old conversation.
	let failNext = true;
	await page.route('**/ask', (r) => (failNext ? ((failNext = false), r.abort('failed')) : r.continue()));
	await askNew(page, 'A new topic');
	await expect(page.locator('.da-msg--visitor .da-msg__meta--error')).toContainText('Not sent.');
	expect(await storedFirst(page)).toBe('How do I change the header?');
	expect(await storedToken(page)).toBe(oldToken);
	await page.locator('.da-msg--visitor .da-msg__meta--error').getByRole('button', { name: 'Try again' }).click();
	await expect(answer(page)).toHaveCount(1);
	await expect.poll(() => storedFirst(page)).toBe('A new topic');
	expect(await storedToken(page)).not.toBe(oldToken);
	await back(page).click();
	await expect(card(page)).toHaveAccessibleName('Continue with Sigma: A new topic');
});

test('replace: success replaces at the first start event (stream), before the answer finishes @mobile', async ({ page }) => {
	const oldToken = await oldConversation(page);
	await askNew(page, 'progressive: a new topic');
	await expect(page.locator('.da-stream')).toBeVisible(); // streaming, not done yet
	await expect(answer(page)).toHaveCount(0);
	await expect.poll(() => storedFirst(page)).toBe('progressive: a new topic');
	expect(await storedToken(page)).not.toBe(oldToken);
	await expect(answer(page)).toHaveCount(1, { timeout: 30_000 });
});

test('replace: success replaces at the 200 on the JSON path @mobile', async ({ page }) => {
	await openPanel(page, sc('nostream'));
	await askAndWait(page, 'How do I change the header?', 1);
	await back(page).click();
	const oldToken = await storedToken(page);
	await askNew(page, 'A new topic');
	await expect(answer(page)).toHaveCount(1);
	await expect.poll(() => storedFirst(page)).toBe('A new topic');
	expect(await storedToken(page)).not.toBe(oldToken);
});

test('team: an avatar that fails to load falls back to the initial, not a broken image @mobile', async ({ page }) => {
	await openPanel(page, 'teambroken');
	await expect(humanRow(page)).toHaveAccessibleName('Talk to a human. Team: Maya and Raj');
	await expect.poll(async () => (await teamInfo(page)).items.map((i) => i.tag + ':' + (i.tag === 'SPAN' ? i.text : ''))).toEqual(['IMG:', 'SPAN:R']);
	const t = await teamInfo(page);
	expect([t.items[1].w, t.items[1].h]).toEqual([28, 28]); // same box: no layout shift
});

// ----- 0.1.4 §8.6: Sigma's identity and the home screen -----

test('home rows in order after a question: Talk to a human, then the Sigma card as Continue with Sigma @mobile', async ({ page }) => {
	await openPanel(page);
	await askAndWait(page, 'How do I change the header?', 1);
	await back(page).click();
	const titles = await page.locator('.da-option .da-option__title').allTextContents();
	expect(titles).toEqual(['Talk to a human', 'Continue with Sigma']);
});

test('Talk to Sigma opens a new AI chat with the intro first (Sigma avatar + "Sigma AI"), intro never sent @mobile', async ({ page }) => {
	const bodies: any[] = [];
	page.on('request', (r) => /\/ask$/.test(new URL(r.url()).pathname) && bodies.push(JSON.parse(r.postData() || '{}')));
	await openPanel(page);
	await page.locator('.da-option--bot').click();
	await expect(input(page)).toBeVisible();
	await expect.poll(() => active(page)).toBe('da-q');
	const intro = page.locator('.da-log .da-msg--intro');
	await expect(intro).toContainText("Hi, I'm Sigma. Ask me anything about Delta and I'll answer from our docs.");
	await expect(intro.locator('.da-msg__label').first()).toHaveText('SigmaAI');
	await expect(intro.locator('.da-remy--assistant svg')).toBeVisible(); // §8.8: Sigma's icon
	expect(await intro.locator('.da-bot-av').evaluate((el) => [el.getBoundingClientRect().width, el.getBoundingClientRect().height])).toEqual([24, 24]);
	await expect(page.locator('.da-header__id .da-remy--assistant svg')).toBeVisible(); // Sigma's icon in the AI chat header
	await expect(page.locator('#da-title')).toHaveText('Sigma');
	await expect(page.locator('.da-msg--visitor')).toHaveCount(0);
	await expect(input(page)).toHaveAttribute('placeholder', 'Ask a question'); // intro only: no question yet
	await ask(page, 'How do I change the header?');
	await expect(input(page)).toHaveAttribute('placeholder', 'Ask a follow-up'); // after the first ask
	await expect(answer(page)).toHaveCount(1); // the answer (the intro is separate)
	expect(bodies).toHaveLength(1);
	expect(Object.keys(bodies[0]).sort()).toEqual(['locale', 'page_url', 'text']); // §10b: the origin, never the intro
	expect(JSON.stringify(bodies[0])).not.toContain("Hi, I'm Sigma");
	expect(bodies[0].text).toBe('How do I change the header?'); // the intro is never sent
	// The Continue card shows the visitor's question, not the intro.
	await back(page).click();
	await expect(card(page)).toHaveAccessibleName('Continue with Sigma: How do I change the header?');
});

// §8.6 avatar tests moved to tests/icon.spec.ts (plan §8.8: one Sigma icon everywhere).

// ----- 0.1.4 §8.6 v1.12.2: the Continue card -----

const LONG = ('How do I change the footer layout on archive pages without touching the templates, keep the sidebar on the right, and still let editors pick a different footer per category from the block editor? ' +
	'Also, does this work with the classic widgets screen, and will it survive a theme update next month? And what about the mobile menu?').slice(0, 300);

test('Continue card: a 300-character question stays on one line with an ellipsis at 320 and 1280 px; full question in the name; Sigma avatar, no leading icon @mobile', async ({ page }) => {
	test.setTimeout(60_000);
	expect(LONG.length).toBe(300);
	await openPanel(page);
	await askAndWait(page, LONG, 1);
	await back(page).click();
	await expect(card(page)).toHaveAccessibleName(`Continue with Sigma: ${LONG}`);
	await expect(card(page).locator('.da-option__icon')).toHaveCount(0); // the leading chat icon is gone
	await expect(card(page).locator('.da-bot-av')).toBeVisible(); // Sigma on the right, 28 px
	await expect.poll(() => card(page).locator('.da-bot-av').evaluate((el) => el.getBoundingClientRect().width)).toBe(28);
	expect(await card(page).evaluate((el) => {
		const kids = [...el.children].map((k) => k.className);
		return kids.findIndex((k) => String(k).includes('da-bot-av')) > kids.findIndex((k) => String(k).includes('da-option__text'));
	})).toBe(true);
	for (const width of [320, 1280]) {
		await page.setViewportSize({ width, height: 800 });
		const measure = () => page.evaluate(() => {
			const sr = document.querySelector('sigma-chat')!.shadowRoot!;
			const cardEl = sr.querySelector('.da-option--continue') as HTMLElement;
			const desc = cardEl.querySelector('.da-option__desc') as HTMLElement;
			const bot = sr.querySelector('.da-option--human') as HTMLElement; // a one-line card for reference
			const cs = getComputedStyle(desc);
			const r = cardEl.getBoundingClientRect();
			const d = desc.getBoundingClientRect();
			return { scroll: desc.scrollWidth, client: desc.clientWidth, ws: cs.whiteSpace, ov: cs.overflow, to: cs.textOverflow, h: r.height, oneLine: bot.getBoundingClientRect().height, descH: d.height, lh: parseFloat(cs.lineHeight) || 0, inside: d.right <= r.right + 0.5 && d.left >= r.left - 0.5, text: desc.textContent };
		});
		// Poll until layout has settled at this width (no fixed wait for the open animation).
		await expect.poll(async () => { const m = await measure(); return Math.abs(m.h - m.oneLine) <= 0.5 && m.inside; }, { timeout: 10_000 }).toBe(true);
		const m = await measure();
		expect(m.text, `${width}px: full text kept`).toBe(LONG);
		expect(m.scroll, `${width}px: overflowing`).toBeGreaterThan(m.client);
		expect([m.ws, m.ov, m.to]).toEqual(['nowrap', 'hidden', 'ellipsis']);
		expect(m.inside, `${width}px: inside the card`).toBe(true);
		expect(Math.abs(m.h - m.oneLine), `${width}px: same height as a one-line card (${m.h} vs ${m.oneLine})`).toBeLessThanOrEqual(0.5);
	}
});

// ----- v1.13: exactly two home cards, one per channel, each switching to "Continue" -----

const homeCards = (p: Page) => p.locator('#da-panel .da-body--home .da-option');
const humanTitleOf = (p: Page) => p.locator('.da-option--human .da-option__title');
const remyTitleOf = (p: Page) => p.locator('.da-option--bot .da-option__title');

test('v1.13 home: fresh tab → Talk to a human + Talk to Sigma; after a question → Sigma card continues, human card unchanged @mobile', async ({ page }) => {
	await openPanel(page, 'teamthree');
	await expect(homeCards(page)).toHaveCount(2);
	await expect(humanTitleOf(page)).toHaveText('Talk to a human');
	await expect(remyTitleOf(page)).toHaveText('Talk to Sigma');
	const humanName = await page.locator('.da-option--human').getAttribute('aria-label');
	await askAndWait(page, 'How do I change the header?', 1);
	await back(page).click();
	await expect(homeCards(page)).toHaveCount(2);
	await expect(page.locator('.da-option--bot')).toHaveAccessibleName('Continue with Sigma: How do I change the header?');
	await expect(page.locator('.da-option--bot .da-option__desc')).toHaveText('How do I change the header?');
	await expect(page.locator('.da-option--bot .da-bot-av')).toBeVisible(); // Sigma avatar in both states
	await expect(humanTitleOf(page)).toHaveText('Talk to a human');
	await expect(page.locator('.da-option--human')).toHaveAttribute('aria-label', humanName!);
	await expect(page.locator('.da-option--human .da-option__desc')).toHaveText('We reply by email');
	await expect(page.locator('.da-option--human')).not.toHaveClass(/da-option--continue/);
	// The Sigma card reopens that chat (not a new one).
	await page.locator('.da-option--bot').click();
	await expect(page.locator('.da-msg--visitor .da-msg__body')).toHaveText('How do I change the header?');
	await expect(newChatBtn(page)).toBeVisible();
});

test('v1.13 home after a handoff: human card "Continue with the team", Sigma card "Talk to Sigma"; a new AI chat keeps the handed-off one until its first ask @mobile', async ({ page }) => {
	await openPanel(page, 'teamthree');
	await askAndWait(page, 'What is the price?', 1);
	await answer(page).locator('.da-notice').getByRole('button', { name: 'Talk to a human' }).click();
	await page.locator('#da-name').fill('Sam');
	await page.locator('#da-email').fill('sam@example.com');
	await page.getByRole('button', { name: 'Send message' }).click();
	await expect(page.locator('.da-end__title')).toHaveText('Message sent');
	await page.getByRole('button', { name: 'Back to start' }).click();
	await expect(homeCards(page)).toHaveCount(2);
	await expect(page.locator('.da-option--human')).toHaveAccessibleName('Continue with the team: What is the price?');
	await expect(page.locator('.da-option--human .da-team')).toBeVisible();
	await expect(remyTitleOf(page)).toHaveText('Talk to Sigma');
	await expect(page.locator('.da-option--bot .da-option__desc')).toHaveText('Handles instant support');
	await expect(page.locator('.da-option--bot .da-bot-av')).toBeVisible();
	// Talk to Sigma starts a new AI chat with the intro; Back without sending keeps the handed-off one on the human card.
	await page.locator('.da-option--bot').click();
	await expect(page.locator('.da-log .da-msg--intro')).toBeVisible();
	await expect(page.locator('.da-msg--visitor')).toHaveCount(0);
	await back(page).click();
	await expect(page.locator('.da-option--human')).toHaveAccessibleName('Continue with the team: What is the price?');
	await expect(remyTitleOf(page)).toHaveText('Talk to Sigma');
	// Its first accepted ask replaces it: the human card is back to "Talk to a human", Sigma continues the new chat.
	await ask(page, 'How do I change the header?');
	await expect(answer(page)).toHaveCount(1);
	await back(page).click();
	await expect(homeCards(page)).toHaveCount(2);
	await expect(humanTitleOf(page)).toHaveText('Talk to a human');
	await expect(page.locator('.da-option--bot')).toHaveAccessibleName('Continue with Sigma: How do I change the header?');
});

test('v1.13 New chat button: named "Start a new chat with Sigma", only in the AI chat, disabled while answering @mobile', async ({ page }) => {
	await openPanel(page);
	await expect(newChatBtn(page)).toHaveCount(0); // not on home
	await page.locator('.da-option--bot').click();
	await expect(newChatBtn(page)).toBeVisible();
	await input(page).fill('progressive: a slow one');
	await input(page).press('Enter');
	await expect(page.locator('.da-stream')).toBeVisible();
	await expect(newChatBtn(page)).toBeDisabled();
	await expect(answer(page)).toHaveCount(1, { timeout: 30_000 });
	await expect(newChatBtn(page)).toBeEnabled();
});

// ----- §8.7 v1.14: the chat offers a human only on request or when the docs don't cover it -----

const notice = (p: Page) => answer(p).last().locator('.da-notice');

for (const mode of ['stream', 'json'] as const) {
	test(`v1.14 (${mode}): an answered reply shows no card and no assist button; the home human card stays @mobile`, async ({ page }) => {
		await openPanel(page, mode === 'json' ? sc('nostream') : 'teamthree');
		await askAndWait(page, 'How do I change the header?', 1);
		await expect(notice(page)).toHaveCount(0);
		await expect(page.locator('#da-panel .da-footer').getByRole('button', { name: 'Talk to a human' })).toHaveCount(0);
		await expect(page.locator('#da-panel').getByRole('button', { name: 'Talk to a human' })).toHaveCount(0);
		await back(page).click();
		await expect(page.locator('.da-option--human .da-option__title')).toHaveText('Talk to a human');
	});

	test(`v1.14 (${mode}): handoff_requested shows the request copy; with answered:false too, the request copy wins @mobile`, async ({ page }) => {
		await openPanel(page, mode === 'json' ? sc('nostream') : 'ok');
		// A request on an answered reply.
		await askAndWait(page, 'person please', 1);
		await expect(notice(page).locator('.da-notice__title')).toHaveText('Talk to a human');
		await expect(notice(page).locator('.da-notice__text')).toHaveText("Send your question to the team and we'll reply by email.");
		await expect(notice(page).getByRole('button', { name: 'Talk to a human' })).toBeVisible();
		await expect(input(page)).toHaveAttribute('placeholder', 'Ask a follow-up');
		// A request with no doc content: answered:false AND handoff_requested:true.
		await askAndWait(page, 'Can I talk to a human?', 2);
		await expect(notice(page).locator('.da-notice__title')).toHaveText('Talk to a human');
		await expect(notice(page).locator('.da-notice__text')).toHaveText("Send your question to the team and we'll reply by email.");
		// The button opens the form, prefilled with the question.
		await notice(page).getByRole('button', { name: 'Talk to a human' }).click();
		await expect(page.locator('#da-title')).toHaveText('Talk to a human');
		await expect(page.locator('#da-text')).toHaveValue(/Can I talk to a human\?/);
	});
}

test('v1.14: answered:false keeps the not-in-docs copy (with and without links) @mobile', async ({ page }) => {
	await openPanel(page);
	await askAndWait(page, 'What is the price?', 1);
	await expect(notice(page).locator('.da-notice__title')).toHaveText('Talk to a human?');
	await expect(notice(page).locator('.da-notice__text')).toHaveText("Need more than the docs? Send it to the team and we'll reply by email.");
	await askAndWait(page, 'Tell me about Peru', 2);
	await expect(notice(page).locator('.da-notice__title')).toHaveText('Talk to a human?');
	await expect(notice(page).locator('.da-notice__text')).toHaveText("Your question isn't in the docs. Send it to the team and we'll reply by email.");
	// The earlier answered=false card stays under its own reply; still no assist button.
	await expect(page.locator('.da-footer__assist')).toHaveCount(0);
});

test('v1.14: with the human channel off, a request shows no card @mobile', async ({ page }) => {
	await page.route('**/wp-json/sigma/v1/config', async (r) => {
		const res = await r.fetch();
		const j = await res.json();
		await r.fulfill({ response: res, json: { ...j, human_enabled: false } });
	});
	await openPanel(page);
	await askAndWait(page, 'person please', 1);
	await expect(notice(page)).toHaveCount(0);
});

test('v1.13 follow-up: a handoff sent straight from home shows "Continue with the team" with what was sent @mobile', async ({ page }) => {
	await openPanel(page, 'teamthree');
	await page.locator('.da-option--human').click();
	await page.locator('#da-name').fill('Sam');
	await page.locator('#da-email').fill('sam@example.com');
	await page.locator('#da-text').fill('Please call me back about   the Pro licence.');
	await page.getByRole('button', { name: 'Send message' }).click();
	await expect(page.locator('.da-end__title')).toHaveText('Message sent');
	await page.getByRole('button', { name: 'Back to start' }).click();
	await expect(page.locator('#da-panel .da-body--home .da-option')).toHaveCount(2);
	await expect(page.locator('.da-option--human')).toHaveAccessibleName('Continue with the team: Please call me back about the Pro licence.');
	await expect(page.locator('.da-option--bot .da-option__title')).toHaveText('Talk to Sigma');
	await page.locator('.da-option--human').click();
	await expect(page.locator('.da-end__title')).toHaveText('Message sent');
});
