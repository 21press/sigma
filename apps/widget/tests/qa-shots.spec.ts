// QA screenshots: a 3-turn chat with doc chips and the notice card, light and dark. DA_SHOTS + DA_TAG required.
import { expect, test } from '@playwright/test';
const shots = process.env.DA_SHOTS;
const tag = process.env.DA_TAG || 'x';
test.skip(!shots, 'DA_SHOTS not set');
test('3-turn chat shots @mobile', async ({ page }, info) => {
	await page.goto('/?s=sysfont&bg=none');
	await page.locator('sigma-chat .da-launcher').click();
	for (const [q, n] of [['How do I change the header?', 1], ['And the footer?', 2], ['What is the price?', 3]] as const) {
		if (!(await page.locator('#da-q').isVisible())) await page.locator('.da-option--bot').click();
		await page.locator('#da-q').fill(q);
		await page.locator('#da-q').press('Enter');
		await expect(page.locator('.da-log .da-msg--ai:not(.da-msg--intro)')).toHaveCount(n);
	}
	await page.mouse.move(0, 0);
	await page.locator('#da-q').blur();
	const shoot = async (scheme: string) => {
		await page.evaluate((s) => document.documentElement.setAttribute('data-theme', s), scheme);
		await page.evaluate(() => { const b = document.querySelector('sigma-chat')!.shadowRoot!.querySelector('.da-body')!; b.scrollTop = b.scrollHeight - b.clientHeight - 260; });
		await page.waitForTimeout(400);
		await page.screenshot({ path: `${shots}/spacing-${tag}-${info.project.name}-${scheme}.png` });
	};
	await shoot('light');
	await shoot('dark');
	// Home with the Continue card.
	await page.locator('#da-panel').getByRole('button', { name: 'Back', exact: true }).click();
	await expect(page.getByRole('button', { name: /^Continue with / })).toBeVisible();
	await page.mouse.move(0, 0);
	await page.locator('#da-q').blur().catch(() => {});
	for (const scheme of ['light', 'dark']) {
		await page.evaluate((s) => document.documentElement.setAttribute('data-theme', s), scheme);
		await page.waitForTimeout(400);
		await page.screenshot({ path: `${shots}/continue-card-${tag}-${info.project.name}-${scheme}.png` });
	}
});

test('team row shots @mobile', async ({ page }, info) => {
	await page.goto('/?s=teamfive&bg=none');
	await page.locator('sigma-chat .da-launcher').click();
	await expect(page.locator('.da-option--human .da-team')).toBeVisible();
	await page.mouse.move(0, 0);
	for (const scheme of ['light', 'dark']) {
		await page.evaluate((s) => document.documentElement.setAttribute('data-theme', s), scheme);
		await page.waitForTimeout(500);
		await page.screenshot({ path: `${shots}/team-row-${info.project.name}-${scheme}.png` });
	}
});
