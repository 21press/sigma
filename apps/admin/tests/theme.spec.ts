// Light/dark switcher (owner, 0.2.5): moon in light ("Switch to dark look"), sun in dark ("Switch to light look"),
// swapped at once on click, kept after a reload; a short icon cross-fade and colour ease while switching, none with
// reduced motion. The PHP frame draws the same icons from data-theme (tested in Admin_Loading_Test).
import { expect, test, type Page } from '@playwright/test';

const toggle = ( p: Page ) => p.locator( '.plugin__theme-toggle' );
const shown = ( p: Page, icon: 'moon' | 'sun' ) => p.locator( `.theme-toggle__icon--${ icon }` ).evaluate( ( el ) => getComputedStyle( el ).opacity );

test( 'click switches the icon and the label both ways; a reload keeps the look and its icon @mobile', async ( { page } ) => {
	await page.goto( '/?screen=knowledge' );
	await expect( toggle( page ) ).toHaveAccessibleName( 'Switch to dark look' );
	await expect.poll( () => shown( page, 'moon' ) ).toBe( '1' );
	await expect.poll( () => shown( page, 'sun' ) ).toBe( '0' );

	await toggle( page ).click();
	await expect( page.locator( '.sigma-admin[data-theme="dark"]' ) ).toBeVisible();
	await expect( toggle( page ) ).toHaveAccessibleName( 'Switch to light look' );
	await expect.poll( () => shown( page, 'sun' ) ).toBe( '1' );
	await expect.poll( () => shown( page, 'moon' ) ).toBe( '0' );

	await page.reload();
	await expect( page.locator( '.sigma-admin[data-theme="dark"]' ) ).toBeVisible();
	await expect( toggle( page ) ).toHaveAccessibleName( 'Switch to light look' );
	expect( await shown( page, 'sun' ) ).toBe( '1' ); // right at once, no animation on load

	await toggle( page ).click();
	await expect( page.locator( '.sigma-admin[data-theme="light"]' ) ).toBeVisible();
	await expect( toggle( page ) ).toHaveAccessibleName( 'Switch to dark look' );
	await expect.poll( () => shown( page, 'moon' ) ).toBe( '1' );
} );

test( 'the switch eases: an icon cross-fade and a colour transition while switching, nothing on load', async ( { page } ) => {
	await page.goto( '/?screen=knowledge' );
	await expect( page.locator( '.list-table__primary' ).first() ).toBeVisible();
	const card = page.locator( 'section.card' ).first();
	const none = ( d: string ) => d.split( ',' ).every( ( x ) => parseFloat( x ) < 0.01 );
	expect( none( await card.evaluate( ( el ) => getComputedStyle( el ).transitionDuration ) ) ).toBe( true ); // no ease at load
	expect( await page.locator( '.theme-toggle__icon--sun' ).evaluate( ( el ) => getComputedStyle( el ).transitionDuration ) ).toContain( '0.18s' );
	await toggle( page ).click();
	expect( await card.evaluate( ( el ) => getComputedStyle( el ).transitionDuration ) ).toContain( '0.2s' );
	await expect.poll( async () => none( await card.evaluate( ( el ) => getComputedStyle( el ).transitionDuration ) ) ).toBe( true ); // gone after the switch
} );

test( 'reduced motion: no transition at all @mobile', async ( { page } ) => {
	await page.emulateMedia( { reducedMotion: 'reduce' } );
	await page.goto( '/?screen=knowledge' );
	await expect( page.locator( '.list-table__primary' ).first() ).toBeVisible();
	const durations = () => page.evaluate( () => [ '.theme-toggle__icon--sun', '.theme-toggle__icon--moon', 'section.card', '.sigma-admin' ].map( ( s ) => getComputedStyle( document.querySelector( s )! ).transitionDuration ) );
	// Alpha's own reduced-motion rule uses 0.01ms (reported as 1e-05s): anything under 10 ms counts as none.
	const still = ( ds: string[] ) => ds.every( ( d ) => d.split( ',' ).every( ( x ) => parseFloat( x ) < 0.01 ) );
	expect( still( await durations() ), JSON.stringify( await durations() ) ).toBe( true );
	await toggle( page ).click();
	await expect( page.locator( '.sigma-admin[data-theme="dark"]' ) ).toBeVisible();
	expect( still( await durations() ), JSON.stringify( await durations() ) ).toBe( true );
	// At once: Alpha's reduced-motion rule leaves a 0.01 ms transition, so allow a frame or two.
	await expect.poll( () => shown( page, 'sun' ), { timeout: 100, intervals: [ 16 ] } ).toBe( '1' );
} );

test( 'a short screen: the open live preview stops below Sigma\'s header, so the switcher stays clickable', async ( { page } ) => {
	await page.setViewportSize( { width: 1280, height: 700 } );
	await page.goto( '/?screen=settings#chat-box' );
	const panel = page.locator( 'sigma-chat #da-panel' );
	await expect( panel ).toBeVisible();
	const headerBottom = await page.locator( '.plugin__topbar' ).evaluate( ( el ) => el.getBoundingClientRect().bottom );
	const panelTop = ( await panel.boundingBox() )!.y;
	expect( panelTop ).toBeGreaterThanOrEqual( headerBottom );
	await toggle( page ).click(); // would time out if the panel covered it
	await expect( page.locator( '.sigma-admin[data-theme="dark"]' ) ).toBeVisible();
} );

// ARCH (live report): read what the user sees. Three real clicks from each start state; after each, wait 1 s of real
// rendering, then the computed opacity of the moon and sun matches the look: moon in light, sun in dark.
for ( const start of [ 'light', 'dark' ] as const ) {
	test( `three clicks from ${ start }: after each, the icon on screen matches the look (computed opacity) @mobile`, async ( { page } ) => {
		await page.addInitScript( ( s ) => {
			if ( ! sessionStorage.getItem( 'seeded' ) ) {
				localStorage.setItem( 'sigma-admin-theme', s === 'dark' ? 'dark' : '' );
				sessionStorage.setItem( 'seeded', '1' );
			}
		}, start );
		await page.goto( '/?screen=knowledge' );
		await expect( page.locator( '.list-table__primary' ).first() ).toBeVisible();
		let look: 'light' | 'dark' = start;
		for ( let click = 0; click <= 3; click++ ) {
			if ( click ) {
				await toggle( page ).click();
				look = look === 'dark' ? 'light' : 'dark';
			}
			await page.waitForTimeout( 1000 );
			const s = await page.evaluate( () => ( {
				theme: document.querySelector( '#sigma-admin > .sigma-admin' )!.getAttribute( 'data-theme' ),
				label: document.querySelector( '.plugin__theme-toggle' )!.getAttribute( 'aria-label' ),
				moon: getComputedStyle( document.querySelector( '.theme-toggle__icon--moon' )! ).opacity,
				sun: getComputedStyle( document.querySelector( '.theme-toggle__icon--sun' )! ).opacity,
			} ) );
			const want = look === 'dark' ? { theme: 'dark', label: 'Switch to light look', moon: '0', sun: '1' } : { theme: 'light', label: 'Switch to dark look', moon: '1', sun: '0' };
			expect( s, `after ${ click } click(s) from ${ start }` ).toEqual( want );
		}
	} );
}

test( 'dark mode covers the whole page: under a short screen and below the last card it is dark, not wp-admin\'s light page', async ( { page } ) => {
	await page.setViewportSize( { width: 1280, height: 1400 } );
	await page.goto( '/?screen=conversations' );
	await expect( toggle( page ) ).toHaveAccessibleName( 'Switch to dark look' );
	const bodyBg = () => page.evaluate( () => getComputedStyle( document.body ).backgroundColor );
	const appBg = () => page.locator( '.sigma-admin' ).first().evaluate( ( el ) => getComputedStyle( el ).backgroundColor );
	const light = await bodyBg();

	await toggle( page ).click();
	await expect( page.locator( '.sigma-admin[data-theme="dark"]' ) ).toBeVisible();
	await expect.poll( appBg ).toBe( 'rgb(29, 35, 39)' );
	await expect.poll( bodyBg ).toBe( 'rgb(29, 35, 39)' ); // the page itself, the same as the app's canvas
	// The bottom of the window, below the app's box on this tall viewport: what paints there is the body, dark.
	const below = await page.evaluate( () => {
		const app = document.querySelector( '.sigma-admin' ) as HTMLElement;
		const y = window.innerHeight - 2;
		const hit = document.elementFromPoint( 20, y ) as HTMLElement;
		return { appBottom: app.getBoundingClientRect().bottom, y, hit: hit === document.body || hit === document.documentElement ? 'page' : hit.className };
	} );
	expect( below.appBottom ).toBeLessThan( below.y );
	expect( below.hit ).toBe( 'page' );

	await page.reload();
	await expect.poll( bodyBg ).toBe( 'rgb(29, 35, 39)' ); // also right after a reload

	await toggle( page ).click();
	await expect( page.locator( '.sigma-admin[data-theme="light"]' ) ).toBeVisible();
	await expect.poll( bodyBg ).toBe( light ); // back to wp-admin's own page in light
} );
