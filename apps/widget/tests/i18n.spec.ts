// §19.3: the chat box has no i18n runtime. The plugin prints the translated strings as SigmaWidget.i18n
// ({ English: translation }, only those that differ); the dev page does the same with ?lang=de.
import { expect, test, type Page } from '@playwright/test';

const launcher = (p: Page) => p.locator('sigma-chat .da-launcher');

test('English by default: no dictionary, the code’s own strings @mobile', async ({ page }) => {
	await page.goto('/?s=ok');
	await expect(launcher(page)).toHaveAttribute('aria-label', 'Open help');
	await launcher(page).click();
	await page.locator('.da-option--bot').click();
	await expect(page.locator('#da-q')).toHaveAttribute('placeholder', 'Ask a question');
});

test('with SigmaWidget.i18n, the bubble and the panel use the translations @mobile', async ({ page }) => {
	await page.goto('/?s=ok&lang=de');
	await expect(launcher(page)).toHaveAttribute('aria-label', 'Hilfe öffnen'); // widget.js (the bubble)
	await launcher(page).click();
	await expect(page.locator('#da-panel')).toBeVisible();
	await page.locator('.da-option--bot').click();
	await expect(page.locator('#da-q')).toHaveAttribute('placeholder', 'Stellen Sie eine Frage'); // the lazy panel
	await expect(page.getByRole('button', { name: 'Zurück' })).toBeVisible();
	// A string the dictionary doesn't have stays English.
	await expect(page.locator('#da-panel')).toContainText('AI');
});
