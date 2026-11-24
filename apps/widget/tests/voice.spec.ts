// §19.4 voice dictation in the AI chat composer, with a fake SpeechRecognition the test drives (automation can't
// speak; a person checks real Chrome and Safari). The dev page is http://localhost, a secure context.
import { expect, test, type Page } from '@playwright/test';

/** A controllable SpeechRecognition: window.__sr.say(parts), .fail(code), .end(); counts start/stop/abort. */
async function fakeSpeech(page: Page) {
	await page.addInitScript(() => {
		const sr: any = { starts: 0, stops: 0, aborts: 0, last: null, all: [] };
		class Fake {
			lang = '';
			continuous = false;
			interimResults = false;
			onresult: any = null;
			onerror: any = null;
			onend: any = null;
			constructor() { sr.last = this; sr.all.push(this); }
			start() { sr.starts++; }
			stop() { sr.stops++; setTimeout(() => this.onend?.(), 0); }
			abort() { sr.aborts++; }
		}
		sr.say = (parts: { t: string; final?: boolean }[]) => sr.last.onresult({ results: parts.map((p) => Object.assign([{ transcript: p.t }], { isFinal: !!p.final })) });
		sr.fail = (error: string) => { sr.last.onerror({ error }); sr.last.onend?.(); };
		(window as any).__sr = sr;
		(window as any).SpeechRecognition = Fake;
		(window as any).webkitSpeechRecognition = Fake;
	});
}
const sr = (p: Page) => p.evaluate(() => { const s = (window as any).__sr; return { starts: s.starts, stops: s.stops, aborts: s.aborts, lang: s.last?.lang, continuous: s.last?.continuous, interim: s.last?.interimResults }; });

async function openChat(page: Page, query = '?s=ok') {
	await page.goto('/' + query);
	await page.locator('sigma-chat .da-launcher').click();
	await page.locator('.da-option--bot').click();
	await expect(page.locator('#da-q')).toBeVisible();
}
const mic = (p: Page) => p.locator('.da-composer__mic');
const asks = (p: Page) => p.evaluate(() => performance.getEntriesByType('resource').filter((e) => /\/ask/.test(e.name)).length);

test.describe('the mic button shows only where dictation can work', () => {
	test('shown: the browser has the API, the page is secure, the setting is on @mobile', async ({ page }) => {
		await fakeSpeech(page);
		await openChat(page);
		await expect(mic(page)).toBeVisible();
		await expect(mic(page)).toHaveAttribute('aria-pressed', 'false');
		await expect(mic(page)).toHaveAccessibleName('Dictate your question');
	});
	test('hidden: no speech recognition in the browser (Firefox) @mobile', async ({ page }) => {
		await page.addInitScript(() => { (window as any).SpeechRecognition = undefined; (window as any).webkitSpeechRecognition = undefined; });
		await openChat(page);
		await expect(page.locator('.da-composer__send')).toBeVisible();
		await expect(mic(page)).toHaveCount(0);
	});
	test('hidden: the page is not https @mobile', async ({ page }) => {
		await fakeSpeech(page);
		await page.addInitScript(() => Object.defineProperty(window, 'isSecureContext', { get: () => false }));
		await openChat(page);
		await expect(page.locator('.da-composer__send')).toBeVisible();
		await expect(mic(page)).toHaveCount(0);
	});
	test('hidden: the site switched voice input off @mobile', async ({ page }) => {
		await fakeSpeech(page);
		await openChat(page, '?s=novoice');
		await expect(page.locator('.da-composer__send')).toBeVisible();
		await expect(mic(page)).toHaveCount(0);
	});
});

test('dictating: interim words fill the composer live, final words replace them, nothing is sent @mobile', async ({ page }) => {
	await fakeSpeech(page);
	await openChat(page);
	await page.evaluate(() => document.documentElement.setAttribute('lang', 'de-DE')); // the page's language, read at start
	await page.locator('#da-q').fill('Hello');
	await mic(page).click();
	await expect(mic(page)).toHaveAttribute('aria-pressed', 'true');
	await expect(mic(page)).toHaveAccessibleName('Stop dictation');
	await expect(page.locator('.da-sr[aria-live="polite"]').filter({ hasText: 'Listening' })).toHaveText('Listening… speak now');
	expect(await sr(page)).toMatchObject({ starts: 1, lang: 'de-DE', continuous: true, interim: true });

	await page.evaluate(() => (window as any).__sr.say([{ t: 'how much' }]));
	await expect(page.locator('#da-q')).toHaveValue('Hello how much');
	await page.evaluate(() => (window as any).__sr.say([{ t: 'how much is the agency licence', final: true }]));
	await expect(page.locator('#da-q')).toHaveValue('Hello how much is the agency licence');
	await page.waitForTimeout(500);
	expect(await asks(page)).toBe(0); // never sent automatically
	await expect(page.locator('.da-msg--visitor')).toHaveCount(0);
});

test('the 2,000-character cap applies to dictated text', async ({ page }) => {
	await fakeSpeech(page);
	await openChat(page);
	await mic(page).click();
	await page.evaluate(() => (window as any).__sr.say([{ t: 'word '.repeat(500), final: true }]));
	await expect.poll(async () => (await page.locator('#da-q').inputValue()).length).toBe(2000);
});

test('stops on a second tap, then says "Stopped" @mobile', async ({ page }) => {
	await fakeSpeech(page);
	await openChat(page);
	await mic(page).click();
	await mic(page).click();
	await expect(mic(page)).toHaveAttribute('aria-pressed', 'false');
	await expect(page.locator('.da-sr[aria-live="polite"]').filter({ hasText: 'Stopped' })).toHaveText('Stopped');
	expect((await sr(page)).stops).toBe(1);
});

test('Escape stops dictation and keeps the panel open; the next Escape closes it', async ({ page }) => {
	await fakeSpeech(page);
	await openChat(page);
	await mic(page).click();
	await page.locator('#da-q').press('Escape');
	await expect(mic(page)).toHaveAttribute('aria-pressed', 'false');
	await expect(page.locator('#da-panel')).toBeVisible();
	await page.locator('#da-q').press('Escape');
	await expect(page.locator('#da-panel')).toBeHidden();
});

test('8 seconds of silence stops it; words heard restart the 8 seconds', async ({ page }) => {
	await fakeSpeech(page);
	await page.clock.install();
	await openChat(page);
	await mic(page).click();
	await page.clock.runFor(6000);
	await page.evaluate(() => (window as any).__sr.say([{ t: 'hello' }]));
	await page.clock.runFor(6000);
	expect((await sr(page)).stops).toBe(0); // reset by the words
	await page.clock.runFor(2500);
	expect((await sr(page)).stops).toBe(1);
	await page.clock.runFor(50);
	await expect(mic(page)).toHaveAttribute('aria-pressed', 'false');
});

test('Send ends dictation and sends the text as it is', async ({ page }) => {
	await fakeSpeech(page);
	await openChat(page);
	await mic(page).click();
	await page.evaluate(() => (window as any).__sr.say([{ t: 'how do I install it', final: true }]));
	await page.locator('.da-composer__send').click();
	expect((await sr(page)).aborts).toBe(1);
	await expect(mic(page)).toHaveAttribute('aria-pressed', 'false');
	await expect(page.locator('.da-msg--visitor')).toContainText('how do I install it');
});

test('a late event from the session Send aborted does not touch the next one (Send, then a quick mic tap) @mobile', async ({ page }) => {
	await fakeSpeech(page);
	await openChat(page);
	await mic(page).click();
	await page.evaluate(() => (window as any).__sr.say([{ t: 'how do I install it', final: true }]));
	await page.locator('.da-composer__send').click();
	await expect(page.locator('.da-msg--visitor')).toContainText('how do I install it');
	await expect(mic(page)).toBeEnabled();
	await mic(page).click(); // a new session
	await expect(mic(page)).toHaveAttribute('aria-pressed', 'true');
	await page.evaluate(() => {
		const old = (window as any).__sr.all[0];
		old.onresult({ results: [Object.assign([{ transcript: 'stale words' }], { isFinal: true })] });
		old.onerror({ error: 'network' });
		old.onend();
	});
	await expect(mic(page)).toHaveAttribute('aria-pressed', 'true'); // still listening
	await expect(page.locator('#da-q')).toHaveValue('');
	await expect(page.locator('.da-voice-msg')).toHaveCount(0); // the stale network error showed nothing and left the mic on
	await page.evaluate(() => (window as any).__sr.say([{ t: 'and the licence' }]));
	await expect(page.locator('#da-q')).toHaveValue('and the licence');
	await mic(page).click();
	await expect(mic(page)).toHaveAttribute('aria-pressed', 'false');
});

test.describe('errors: typing always still works', () => {
	test('permission denied @mobile', async ({ page }) => {
		await fakeSpeech(page);
		await openChat(page);
		await mic(page).click();
		await page.evaluate(() => (window as any).__sr.fail('not-allowed'));
		await expect(page.locator('.da-voice-msg')).toHaveText('Microphone access is blocked. Allow it in your browser to dictate.');
		await expect(mic(page)).toBeVisible(); // can try again once allowed
		await page.locator('#da-q').fill('typed instead');
		await expect(page.locator('.da-voice-msg')).toHaveCount(0); // typing clears it
	});
	test('no speech', async ({ page }) => {
		await fakeSpeech(page);
		await openChat(page);
		await mic(page).click();
		await page.evaluate(() => (window as any).__sr.fail('no-speech'));
		await expect(page.locator('.da-voice-msg')).toHaveText("Didn't catch that. Try again.");
	});
	test('a network or service error turns it off for the visit @mobile', async ({ page }) => {
		await fakeSpeech(page);
		await openChat(page);
		await mic(page).click();
		await page.evaluate(() => (window as any).__sr.fail('network'));
		await expect(page.locator('.da-voice-msg')).toHaveText("Voice input isn't available right now. You can still type.");
		await expect(mic(page)).toHaveCount(0);
		await page.locator('#da-q').fill('still typing');
		await expect(page.locator('.da-composer__send')).toBeEnabled();
		await page.goto('/?s=ok'); // next page of the same visit: the chat comes back as it was
		if (!(await page.locator('#da-panel').isVisible())) await page.locator('sigma-chat .da-launcher').click();
		await expect(page.locator('.da-composer__send')).toBeVisible();
		await expect(mic(page)).toHaveCount(0);
	});
});

test('listening pulses; with reduced motion it is a still ring', async ({ page }) => {
	await fakeSpeech(page);
	await openChat(page);
	await mic(page).click();
	expect(await mic(page).evaluate((el) => getComputedStyle(el).animationName)).toBe('da-mic');
	await page.emulateMedia({ reducedMotion: 'reduce' });
	expect(await mic(page).evaluate((el) => getComputedStyle(el).animationName)).toBe('none');
	expect(await mic(page).evaluate((el) => getComputedStyle(el).boxShadow)).not.toBe('none');
});

test('a hidden tab stops dictation at once and keeps the words (the silence timer may not run there) @mobile', async ({ page }) => {
	await fakeSpeech(page);
	await openChat(page);
	await mic(page).click();
	await page.evaluate(() => (window as any).__sr.say([{ t: 'how much is it', final: true }]));
	await page.evaluate(() => {
		Object.defineProperty(document, 'hidden', { configurable: true, get: () => true });
		document.dispatchEvent(new Event('visibilitychange'));
	});
	await expect(mic(page)).toHaveAttribute('aria-pressed', 'false');
	expect((await sr(page)).aborts).toBe(1);
	await expect(page.locator('#da-q')).toHaveValue('how much is it');
	await expect(page.locator('.da-sr[aria-live="polite"]').filter({ hasText: 'Stopped' })).toHaveText('Stopped');
	expect(await asks(page)).toBe(0); // nothing sent
});
