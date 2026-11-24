// Translations (§19.3 item 3). The harness loads a test translation the way WordPress does for
// wp_set_script_translations(): wp.i18n.setLocaleData( <Jed JSON>, 'sigma' ) before admin.js (?lang=de, dev/test-locale.ts),
// and the chat box gets its strings as SigmaWidget.i18n (SigmaAdmin.widgetI18n in the live preview).
// ?lang=pseudo brackets every string that goes through __(), so text that bypasses it stands out.
import { expect, test, type Page } from '@playwright/test';

const go = async ( p: Page, query: string ) => {
	await p.goto( `/?${ query }` );
	await p.waitForLoadState( 'networkidle' );
};

test( 'with a translation loaded, the Settings strings change (tabs, the aside card, Instructions, the counter) @mobile', async ( { page } ) => {
	await go( page, 'screen=settings#agent' );
	await expect( page.getByRole( 'link', { name: 'AI agent' } ) ).toBeVisible();
	await expect( page.getByText( 'How the bot answers' ) ).toBeVisible();

	await go( page, 'screen=settings&lang=de#agent' );
	const tabs = page.getByRole( 'navigation', { name: 'Settings sections' } );
	await expect( tabs.getByRole( 'link', { name: 'Chatfenster' } ) ).toBeVisible();
	await expect( tabs.getByRole( 'link', { name: 'KI-Agent' } ) ).toBeVisible();
	await expect( tabs.getByRole( 'link', { name: 'Menschlicher Support' } ) ).toBeVisible();
	await expect( page.getByRole( 'heading', { name: 'Anweisungen' } ) ).toBeVisible();
	await expect( page.getByText( 'So antwortet der Bot' ) ).toBeVisible();
	await expect( page.getByRole( 'button', { name: 'Auf Standard zurücksetzen' } ) ).toBeVisible();
	await expect( page.locator( '#da-instructions-count' ) ).toHaveText( /^[\d,]+ \/ 4,000 Zeichen$/ );
	await expect( page.getByRole( 'heading', { name: 'Ein Produkt von 21press' } ) ).toBeVisible();
	// Untranslated strings stay English.
	await expect( page.getByRole( 'heading', { name: 'Identity' } ) ).toBeVisible();
} );

test( 'plural forms follow the translation: one and many', async ( { page } ) => {
	await go( page, 'screen=conversations&lang=de' );
	await expect( page.locator( '.list-table__count' ).first() ).toHaveText( '1 Element' );
	await go( page, 'screen=knowledge&lang=de' );
	await expect( page.locator( '.list-table__count' ).first() ).toHaveText( /^\d+ Einträge$/ );
} );

test( 'the live preview chat box gets its strings in the site language', async ( { page } ) => {
	await go( page, 'screen=settings&lang=de#agent' );
	const host = page.locator( 'sigma-chat' );
	await expect( host.locator( '#da-panel' ) ).toBeVisible();
	await host.locator( '.da-option--bot' ).click();
	await expect( host.locator( '#da-q' ) ).toHaveAttribute( 'placeholder', 'Stellen Sie eine Frage' );
} );

test( 'every visible Settings string goes through __() (pseudo-translation)', async ( { page } ) => {
	// Site data and values the plugin doesn't own are not translated: the fixture's post types, team, default
	// texts shown as placeholders, colours, file names and the like. Inline markup splits a translated sentence
	// into pieces, so only text nodes with no bracket anywhere in their element's sentence count.
	const DATA = /^(#[0-9a-f]{3,8}|21press\.com|Posts|Pages|Docs|Integrations|Raj|Maya|admin|Editor|Author|Administrator|Up to date: .*|support@example\.com|https:\/\/.*)$/;
	for ( const tab of [ 'chat-box', 'agent', 'human', 'setup' ] ) {
		await go( page, `screen=settings&lang=pseudo#${ tab }` );
		const left = await page.evaluate( ( dataSource ) => {
			const data = new RegExp( dataSource );
			const root = document.querySelector( '.sigma-admin' )!;
			const out: string[] = [];
			const walker = document.createTreeWalker( root, NodeFilter.SHOW_TEXT );
			let n: Node | null;
			while ( ( n = walker.nextNode() ) ) {
				const text = ( n.textContent || '' ).trim();
				const el = n.parentElement!;
				if ( ! /[A-Za-z]{3,}/.test( text ) || /[⟦⟧]/.test( text ) || data.test( text ) || ! el.getClientRects().length ) continue;
				if ( el.closest( 'textarea, code, pre, sigma-chat, option' ) ) continue;
				// A piece of a translated sentence with inline markup (createInterpolateElement).
				// The sentence is the inline element's parent, or the element holding the inline markup.
				const sentence = el.matches( 'a, strong, em, code' ) ? el.parentElement! : el.querySelector( ':scope > a, :scope > strong, :scope > em, :scope > code' ) ? el : null;
				if ( sentence && /[⟦⟧]/.test( sentence.textContent || '' ) ) continue;
				out.push( text );
			}
			return out;
		}, DATA.source );
		expect( left, `untranslated text on #${ tab }` ).toEqual( [] );
	}
} );
