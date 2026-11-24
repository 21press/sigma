// 0.2.1 copy rule (owner): the bot's name is editable, so admin copy never hard-codes "Remy". It says
// "the bot", or shows the configured name. With bot_name "Nova", no admin screen may show "Remy" anywhere a person
// reads it (text, labels, alt, title, placeholders), the live preview's widget included, except the name field's
// placeholder (the default value).
import { expect, test, type Page } from '@playwright/test';

/** Every string a person can read on the page: text and readable attributes, open shadow roots included. */
const readable = ( p: Page ) =>
	p.evaluate( () => {
		const out: string[] = [];
		const walk = ( root: Node ) => {
			for ( const n of Array.from( root.childNodes ) ) {
				if ( n.nodeType === Node.TEXT_NODE ) {
					const t = n.textContent?.trim();
					if ( t ) out.push( t );
					continue;
				}
				if ( ! ( n instanceof Element ) || [ 'SCRIPT', 'STYLE', 'TEMPLATE' ].includes( n.tagName ) ) continue;
				for ( const a of [ 'aria-label', 'title', 'alt', 'placeholder', 'aria-description' ] ) {
					const v = n.getAttribute( a );
					if ( v && ! ( n.id === 'da-bot-name' && a === 'placeholder' ) ) out.push( `[${ a }] ${ v }` );
				}
				if ( ( n instanceof HTMLInputElement && n.type !== 'password' ) || n instanceof HTMLTextAreaElement ) out.push( `[value] ${ n.value }` );
				if ( n.shadowRoot ) walk( n.shadowRoot );
				walk( n );
			}
		};
		walk( document.body );
		return out;
	} );

const noRemy = async ( p: Page, where: string ) => {
	const all = await readable( p );
	expect( all.length, `readable strings on ${ where }` ).toBeGreaterThan( 10 ); // the walk saw the page
	const hits = all.filter( ( t ) => /Remy/.test( t ) );
	expect( hits, `"Remy" on ${ where }` ).toEqual( [] );
};

test.describe( 'with bot_name "Nova"', () => {
	test( 'Settings (every tab, the system rules and the live preview) shows no "Remy" @mobile', async ( { page } ) => {
		for ( const tab of [ 'chat-box', 'agent', 'human' ] ) {
			await page.goto( `/?screen=settings&bot=Nova#${ tab }` );
			await expect( page.locator( `.tabs__tab--current[href="#${ tab }"]` ) ).toBeVisible();
			await page.waitForLoadState( 'networkidle' );
			if ( tab === 'agent' ) {
				await expect( page.locator( '#da-bot-name' ) ).toHaveValue( 'Nova' );
				await expect( page.locator( '#da-bot-name' ) ).toHaveAttribute( 'placeholder', 'Sigma' ); // the default name (0.2.5)
				await expect( page.getByText( 'How the bot answers' ) ).toBeVisible();
				await expect( page.getByRole( 'radiogroup', { name: 'Bot icon' } ) ).toBeVisible();
				await expect( page.getByText( 'Bubble face' ) ).toBeVisible();
				await page.locator( 'details.instructions__rules summary' ).click();
				expect( await readable( page ) ).toContain( '[value] Nova' ); // inputs are read too
			}
			await noRemy( page, `Settings #${ tab }` );
		}
	} );

	test( 'the live preview widget greets as Nova, with no "Remy"', async ( { page } ) => {
		await page.goto( '/?screen=settings&bot=Nova#agent' );
		const host = page.locator( 'sigma-chat' );
		await expect( host.locator( '#da-panel' ) ).toBeVisible();
		await expect( host.locator( '#da-panel' ) ).toContainText( 'Nova' );
		await host.locator( '.da-option--bot' ).click();
		await expect( host.locator( '.da-log' ) ).toContainText( 'Nova' );
		await noRemy( page, 'the live preview chat' );
	} );

	test( 'Setup shows no "Remy" @mobile', async ( { page } ) => {
		await page.goto( '/?screen=settings&bot=Nova#setup' );
		await expect( page.locator( '.sigma-admin' ) ).toContainText( /Setup|setup/ );
		await page.waitForLoadState( 'networkidle' );
		await noRemy( page, 'Setup' );
	} );

	test( 'Knowledge shows no "Remy" @mobile', async ( { page } ) => {
		await page.goto( '/?screen=knowledge&bot=Nova' );
		await expect( page.getByRole( 'heading', { name: 'Knowledge' } ).first() ).toBeVisible();
		await expect( page.locator( '.list-table__primary' ).first() ).toBeVisible();
		await noRemy( page, 'Knowledge' );
	} );

	test( 'Conversations: the list, and a transcript labels the AI with the configured name @mobile', async ( { page } ) => {
		await page.goto( '/?screen=conversations&bot=Nova' );
		await expect( page.locator( '.list-table__primary' ).first() ).toBeVisible();
		await noRemy( page, 'Conversations' );
		await page.locator( '.list-table__primary' ).first().click();
		const ai = page.locator( '.chat__message:not(.chat__message--user) .chat__meta' ).first();
		await expect( ai ).toContainText( /^Nova\s+AI/ );
		await noRemy( page, 'a transcript' );
	} );
} );
