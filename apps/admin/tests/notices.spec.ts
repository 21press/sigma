// The plugin's own notices (0.2.5, owner): rendered inside the app, first in the page body under the page header,
// aligned with the cards (left edges equal ±1px), in light and dark, on every Sigma screen; none in or above the
// header. Dismiss hides at once and tells the server. The harness sends one of each type (?notices=all).
import { expect, test, type Page } from '@playwright/test';

const IDS = [ 'bot-renamed', 'build-stale', 'turnstile-outage', 'legacy-constants', 'handoffs-failed', 'mail-failing' ];

async function placed( page: Page, where: string ) {
	const notices = page.locator( '.app-notice' );
	await expect( notices ).toHaveCount( IDS.length );
	const g = await page.evaluate( () => {
		const header = document.querySelector( '.plugin__topbar' )!.getBoundingClientRect();
		const card = document.querySelector( '.plugin__body .card' )!.getBoundingClientRect();
		const ns = [ ...document.querySelectorAll( '.app-notice' ) ].map( ( n ) => {
			const box = n.closest( '.notice' )!;
			return { id: ( n as HTMLElement ).dataset.notice, left: box.getBoundingClientRect().left, right: box.getBoundingClientRect().right, top: box.getBoundingClientRect().top, inBody: !! box.closest( '.plugin__body' ), inHeader: !! box.closest( '.plugin__topbar' ) };
		} );
		return { headerBottom: header.bottom, cardLeft: card.left, cardRight: card.right, ns };
	} );
	expect( g.ns.map( ( n ) => n.id ) ).toEqual( IDS );
	for ( const n of g.ns ) {
		expect( n.inBody && ! n.inHeader, `${ where }: ${ n.id } is in the page body` ).toBe( true );
		expect( Math.abs( n.left - g.cardLeft ), `${ where }: ${ n.id } left edge vs the cards` ).toBeLessThanOrEqual( 1 );
		expect( n.top, `${ where }: ${ n.id } is below the page header` ).toBeGreaterThanOrEqual( g.headerBottom );
	}
}

for ( const [ screen, url ] of [ [ 'Settings', '/?screen=settings&notices=all#chat-box' ], [ 'Knowledge', '/?screen=knowledge&notices=all' ], [ 'Conversations', '/?screen=conversations&notices=all' ] ] ) {
	test( `${ screen }: every notice type sits in the page body, aligned with the cards, light and dark @mobile`, async ( { page } ) => {
		await page.goto( url );
		await placed( page, `${ screen } light` );
		await page.locator( '.plugin__theme-toggle' ).click();
		await expect( page.locator( '.sigma-admin[data-theme="dark"]' ) ).toBeVisible();
		await placed( page, `${ screen } dark` );
		if ( process.env.DA_SHOTS ) await page.screenshot( { path: `${ process.env.DA_SHOTS }/notices-${ screen.toLowerCase() }-dark-${ test.info().project.name }.png`, fullPage: true } );
	} );
}

test( 'text: `code` marks render as code, the rest as plain text; an action is a link', async ( { page } ) => {
	await page.goto( '/?screen=knowledge&notices=all' );
	const stale = page.locator( '[data-notice="build-stale"]' );
	await expect( stale.locator( 'code' ) ).toHaveText( 'npm run build' );
	await expect( stale.locator( 'strong' ) ).toContainText( 'out of date' );
	await expect( page.locator( '[data-notice="handoffs-failed"]' ).getByRole( 'link', { name: 'Conversations' } ) ).toHaveAttribute( 'href', /page=sigma-conversations/ );
} );

test( 'dismiss hides the notice at once and tells the server @mobile', async ( { page } ) => {
	await page.goto( '/?screen=knowledge&notices=all' );
	await page.locator( '[data-notice="bot-renamed"]' ).getByRole( 'button', { name: 'Dismiss this notice' } ).click();
	await expect( page.locator( '[data-notice="bot-renamed"]' ) ).toHaveCount( 0 );
	await expect( page.locator( '.app-notice' ) ).toHaveCount( IDS.length - 1 );
	const calls = await page.evaluate( () => ( window as any ).__kbCalls.filter( ( c: any ) => /\/admin\/notices\//.test( c.path ) ).map( ( c: any ) => `${ c.method } ${ c.path }` ) );
	expect( calls ).toEqual( [ 'POST /sigma/v1/admin/notices/bot-renamed/dismiss' ] );
} );

test( 'no notices: nothing is drawn', async ( { page } ) => {
	await page.goto( '/?screen=knowledge' );
	await expect( page.locator( '.list-table__primary' ).first() ).toBeVisible();
	await expect( page.locator( '.app-notices' ) ).toHaveCount( 0 );
} );
