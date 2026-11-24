// §19.4 voice input: the setting is on by default (owner), and the live preview follows the unsaved toggle:
// on shows the mic in the preview's AI chat, off hides it. A fake SpeechRecognition stands in for the browser's.
import { expect, test } from '@playwright/test';

test( 'Voice input is on by default; switching it off hides the mic in the live preview', async ( { page } ) => {
	await page.addInitScript( () => {
		class Fake {
			start() {}
			stop() {}
			abort() {}
		}
		( window as unknown as Record< string, unknown > ).SpeechRecognition = Fake;
		( window as unknown as Record< string, unknown > ).webkitSpeechRecognition = Fake;
	} );
	await page.goto( '/?screen=settings#agent' );
	const toggle = page.getByRole( 'checkbox', { name: /Let visitors dictate their question/ } );
	await expect( toggle ).toBeChecked();
	await expect( page.getByRole( 'heading', { name: 'Voice input', exact: true } ) ).toBeVisible();

	const host = page.locator( 'sigma-chat' );
	await expect( host.locator( '#da-panel' ) ).toBeVisible();
	await host.locator( '.da-option--bot' ).click();
	await expect( host.locator( '.da-composer__mic' ) ).toBeVisible();

	const label = page.locator( 'label.toggle', { has: toggle } ).locator( '.toggle__track' );
	await label.click();
	await expect( toggle ).not.toBeChecked();
	await expect( host.locator( '.da-composer__send' ) ).toBeVisible();
	await expect( host.locator( '.da-composer__mic' ) ).toHaveCount( 0 );

	await label.click();
	await expect( toggle ).toBeChecked();
	await expect( host.locator( '.da-composer__mic' ) ).toBeVisible();
} );
