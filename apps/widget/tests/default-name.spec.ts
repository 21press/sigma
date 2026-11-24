// 0.2.5 (owner): the default bot name is "Sigma". With no name and no texts from the site (?s=nobot), the widget's own
// fallbacks say Sigma: the teaser, the home card, the chat header and the intro.
import { expect, test } from '@playwright/test';

test('with no name set, the teaser, home card, chat header and intro say Sigma @mobile', async ({ page }) => {
	await page.goto('/?s=nobot&teaser=1');
	await expect(page.locator('sigma-chat .da-teaser')).toBeVisible({ timeout: 6000 });
	await expect(page.locator('sigma-chat .da-teaser')).toContainText('👋 Hi, this is Sigma. How can I help you?');
	await page.locator('sigma-chat .da-launcher').click();
	await expect(page.locator('.da-option--bot')).toContainText('Talk to Sigma');
	await page.locator('.da-option--bot').click();
	await expect(page.locator('#da-panel')).toContainText('Sigma');
	await expect(page.locator('.da-msg--intro')).toContainText("Hi, I'm Sigma. Ask me anything and I'll answer from our docs.");
	await expect(page.locator('#da-panel')).not.toContainText('Remy');
});
