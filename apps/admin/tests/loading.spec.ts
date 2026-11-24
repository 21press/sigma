// Loading (0.2.5, owner feedback): the screens draw real content from data preloaded into the page, with no
// /admin/* request on load; a slow answer shows the skeleton first; a failure shows its reason and "Try again";
// the live preview's chat box shows a small "Loading preview…" card while widget.js loads.
// The harness preloads like Admin_Page::enqueue (rest_preload_api_request + apiFetch.createPreloadingMiddleware).
import { expect, test, type Page } from '@playwright/test';

const adminCalls = ( p: Page ) => p.evaluate( () => ( ( window as any ).__kbCalls as { method: string; path: string }[] ).filter( ( c ) => c.path.includes( '/admin/' ) ).map( ( c ) => `${ c.method } ${ c.path }` ) );

test( 'Settings, Knowledge and Conversations render real content with no /admin/* request on load @mobile', async ( { page } ) => {
	await page.goto( '/?screen=settings#agent' );
	await expect( page.locator( '#da-instructions' ) ).toBeVisible();
	await page.waitForLoadState( 'networkidle' );
	expect( await adminCalls( page ) ).toEqual( [] );

	await page.goto( '/?screen=knowledge' );
	await expect( page.locator( '.list-table__primary' ).first() ).toBeVisible();
	await page.waitForLoadState( 'networkidle' );
	expect( await adminCalls( page ) ).toEqual( [] );

	await page.goto( '/?screen=conversations' );
	await expect( page.locator( '.list-table__primary' ).first() ).toBeVisible();
	await page.waitForLoadState( 'networkidle' );
	expect( await adminCalls( page ) ).toEqual( [] );
} );

test( 'without the preload, a slow /admin/settings shows the skeleton frame first, then the content @mobile', async ( { page } ) => {
	await page.goto( '/?screen=settings&preload=0&slow=/admin/settings#agent' );
	const skeleton = page.getByRole( 'status', { name: 'Loading settings' } );
	await expect( skeleton ).toBeVisible();
	await expect( page.getByRole( 'heading', { name: 'Settings' } ) ).toBeVisible(); // the frame, not a bare spinner
	await expect( page.locator( '.tabs__tab' ) ).toHaveCount( 3 );
	await expect( page.locator( '#da-instructions' ) ).toBeVisible( { timeout: 10000 } );
	await expect( skeleton ).toHaveCount( 0 );
} );

test( 'a 500 shows the reason and "Try again", which loads the screen @mobile', async ( { page } ) => {
	await page.goto( '/?screen=settings&preload=0&fail=/admin/settings#agent' );
	const alert = page.getByRole( 'alert' );
	await expect( alert ).toContainText( 'There has been a critical error on this website.' );
	await alert.getByRole( 'button', { name: 'Try again' } ).click();
	await expect( page.locator( '#da-instructions' ) ).toBeVisible();
	await expect( page.getByRole( 'alert' ) ).toHaveCount( 0 );
} );

test( 'Knowledge and Conversations: a failed list load offers "Try again" too', async ( { page } ) => {
	await page.goto( '/?screen=knowledge&preload=0&fail=/admin/knowledge' );
	await page.getByRole( 'alert' ).getByRole( 'button', { name: 'Try again' } ).click();
	await expect( page.locator( '.list-table__primary' ).first() ).toBeVisible();

	await page.goto( '/?screen=conversations&preload=0&fail=/admin/conversations' );
	await page.getByRole( 'alert' ).getByRole( 'button', { name: 'Try again' } ).click();
	await expect( page.locator( '.list-table__primary' ).first() ).toBeVisible();
} );

test( 'the live preview shows "Loading preview…" while widget.js loads; the settings work meanwhile', async ( { page } ) => {
	let release: () => void = () => {};
	const held = new Promise< void >( ( r ) => ( release = r ) );
	await page.route( '**/build/widget/widget.js*', async ( route ) => {
		await held;
		await route.continue();
	} );
	await page.goto( '/?screen=settings#agent' );
	const card = page.locator( '.preview-loading' );
	await expect( card ).toHaveText( 'Loading preview…' );
	await expect( card ).toHaveAttribute( 'role', 'status' );
	await page.locator( '#da-bot-name' ).fill( 'Nova' ); // the screen is usable meanwhile
	release();
	await expect( page.locator( 'sigma-chat .da-launcher' ) ).toBeVisible();
	await expect( card ).toHaveCount( 0 );
} );

test( 'the live preview says so when widget.js can\'t load', async ( { page } ) => {
	await page.route( '**/build/widget/widget.js*', ( route ) => route.fulfill( { status: 404, body: '' } ) );
	await page.goto( '/?screen=settings#agent' );
	await expect( page.locator( '.preview-loading' ) ).toContainText( 'couldn\'t load' );
	await expect( page.locator( '#da-instructions' ) ).toBeVisible();
} );
