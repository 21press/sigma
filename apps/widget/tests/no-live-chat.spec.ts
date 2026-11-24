// 0.2.5 (owner): live chat is R2. Even if the server reported people online, the home card shows no
// "Someone is online now"; the human card keeps its email text.
import { expect, test } from '@playwright/test';

test('no "online now" on the human card, even with agents_online from the server @mobile', async ({ page }) => {
	await page.goto('/?s=online');
	await page.locator('sigma-chat .da-launcher').click();
	const human = page.locator('.da-option--human');
	await expect(human).toBeVisible();
	await expect(human).toContainText('We reply by email');
	await expect(page.locator('#da-panel')).not.toContainText('online');
	await expect(page.locator('.da-online')).toHaveCount(0);
});
