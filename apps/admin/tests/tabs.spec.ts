// Settings tabs (owner, 0.2.5): the current tab's indicator has square ends and lies exactly on the divider under the
// tab row, so under the current tab there is only the accent colour, no grey line. Light, dark, and another wp-admin
// colour scheme (Sunrise, set as WordPress does through --wp-admin-theme-color). DA_SHOTS=<dir> saves the tab row.
import { expect, test, type Page } from '@playwright/test';

const shots = process.env.DA_SHOTS;

/** Pixels of a screenshot region, decoded in the page (no image library needed). */
async function pixels( page: Page, clip: { x: number; y: number; width: number; height: number } ) {
	const png = ( await page.screenshot( { clip, scale: 'css' } ) ).toString( 'base64' );
	return page.evaluate( async ( src ) => {
		const img = new Image();
		img.src = `data:image/png;base64,${ src }`;
		await img.decode();
		const c = document.createElement( 'canvas' );
		c.width = img.width;
		c.height = img.height;
		const ctx = c.getContext( '2d' )!;
		ctx.drawImage( img, 0, 0 );
		const d = ctx.getImageData( 0, 0, c.width, c.height ).data;
		const out: [ number, number, number ][] = [];
		for ( let i = 0; i < d.length; i += 4 ) out.push( [ d[ i ], d[ i + 1 ], d[ i + 2 ] ] );
		return out;
	}, png );
}
/** rgb(…) or color(srgb 0–1 …), as getComputedStyle gives them. */
const rgb = ( css: string ) => {
	const n = ( css.match( /\d*\.?\d+/g ) || [] ).slice( 0, 3 ).map( Number );
	return ( css.startsWith( 'color(' ) ? n.map( ( v ) => Math.round( v * 255 ) ) : n ) as [ number, number, number ];
};
const near = ( a: number[], b: number[], tol = 12 ) => a.every( ( v, i ) => Math.abs( v - b[ i ] ) <= tol );

async function check( page: Page, name: string ) {
	const tab = page.locator( '.tabs__tab--current' );
	await expect( tab ).toBeVisible();
	if ( shots ) await page.locator( 'nav.tabs' ).first().screenshot( { path: `${ shots }/tabs-${ name }-${ test.info().project.name }.png` } );
	const g = await tab.evaluate( ( t ) => {
		const nav = t.parentElement!;
		const cs = getComputedStyle( t );
		return {
			tab: t.getBoundingClientRect().toJSON(),
			navBottom: nav.getBoundingClientRect().bottom,
			radius: [ cs.borderTopLeftRadius, cs.borderTopRightRadius, cs.borderBottomLeftRadius, cs.borderBottomRightRadius ],
			accent: cs.borderBottomColor,
			width: parseFloat( cs.borderBottomWidth ),
			divider: getComputedStyle( nav ).boxShadow,
		};
	} );
	// 1. The indicator's bottom edge is the divider's bottom edge.
	expect( Math.abs( g.tab.bottom - g.navBottom ), `${ name }: indicator bottom vs divider bottom` ).toBeLessThanOrEqual( 0.5 );
	// 2. Square ends.
	expect( g.radius, `${ name }: border radius` ).toEqual( [ '0px', '0px', '0px', '0px' ] );
	expect( g.width, `${ name }: thickness kept` ).toBe( 3 );
	// 3. Under the current tab, the indicator's rows are accent only: no divider grey, no gap.
	// Whole pixel rows only: the row may sit on a fractional pixel, and a row cut by the edge is a blend.
	const top = Math.ceil( g.navBottom - g.width );
	const under = await pixels( page, { x: Math.ceil( g.tab.x ) + 1, y: top, width: Math.floor( g.tab.width ) - 2, height: Math.floor( g.navBottom ) - top } );
	const accent = rgb( g.accent );
	const off = under.filter( ( p ) => ! near( p, accent ) );
	expect( under.length, `${ name }: sampled pixels` ).toBeGreaterThan( 20 );
	expect( off.length, `${ name }: non-accent pixels under the current tab (accent ${ g.accent }; e.g. ${ JSON.stringify( off.slice( 0, 3 ) ) })` ).toBe( 0 );
	// The divider is still there under the other tabs.
	// A tab next to the current one that is in view: on a phone the row scrolls.
	const ob = ( await tab.evaluate( ( t ) => {
		const nav = t.parentElement!.getBoundingClientRect();
		const near = [ t.previousElementSibling, t.nextElementSibling ].filter( Boolean ).map( ( e ) => ( e as HTMLElement ).getBoundingClientRect() );
		const seen = near.find( ( r ) => r.left >= nav.left && r.left + 14 <= nav.right ) || near[ 0 ];
		return seen.toJSON();
	} ) ) as { x: number };
	// The divider is still there under the other tabs. On a fractional pixel WebKit spreads the 1px line over two
	// rows (as it did with the old border): the pixel that differs most from the tab's background must be at least
	// 40% of the way from the background to the divider colour.
	const rows = await pixels( page, { x: Math.ceil( ob.x ) + 2, y: Math.floor( g.navBottom ) - 6, width: 1, height: 7 } );
	const bg = rows[ 0 ];
	const dist = ( p: number[] ) => Math.abs( p[ 0 ] - bg[ 0 ] ) + Math.abs( p[ 1 ] - bg[ 1 ] ) + Math.abs( p[ 2 ] - bg[ 2 ] );
	const line = rows.reduce( ( a, b ) => ( dist( b ) > dist( a ) ? b : a ) );
	expect( dist( line ), `${ name }: the divider under another tab (${ g.divider }, got ${ line }, background ${ bg })` ).toBeGreaterThanOrEqual( 0.4 * dist( rgb( g.divider ) ) );
}

test( 'the current tab\'s indicator is square and covers the divider: light @mobile', async ( { page } ) => {
	await page.goto( '/?screen=settings#chat-box' );
	await check( page, 'light' );
	const human = page.locator( '.tabs__tab', { hasText: 'Human support' } );
	await human.click();
	await expect( human ).toHaveClass( /tabs__tab--current/ );
	// Let any colour transition finish before reading pixels.
	await expect.poll( () => human.evaluate( ( t ) => getComputedStyle( t ).borderBottomColor ) ).toBe( 'rgb(34, 113, 177)' );
	await check( page, 'light-human' );
} );

test( 'the same in dark mode @mobile', async ( { page } ) => {
	await page.addInitScript( () => localStorage.setItem( 'sigma-admin-theme', 'dark' ) );
	await page.goto( '/?screen=settings#agent' );
	await expect( page.locator( '.sigma-admin[data-theme="dark"]' ) ).toBeVisible();
	await check( page, 'dark' );
} );

test( 'the same with another admin colour scheme (Sunrise)', async ( { page } ) => {
	await page.addInitScript( () => document.addEventListener( 'DOMContentLoaded', () => document.documentElement.style.setProperty( '--wp-admin-theme-color', '#dd823b' ) ) );
	await page.goto( '/?screen=settings#chat-box' );
	await expect.poll( () => page.locator( '.tabs__tab--current' ).evaluate( ( t ) => getComputedStyle( t ).borderBottomColor ) ).toBe( 'rgb(221, 130, 59)' );
	await check( page, 'sunrise' );
} );

// Every state, not only the first render (owner, after switching tabs on a local site).
const tabNamed = ( page: Page, name: string ) => page.locator( '.tabs__tab', { hasText: name } );
async function switchTo( page: Page, name: string ) {
	const t = tabNamed( page, name );
	await t.click();
	await expect( t ).toHaveClass( /tabs__tab--current/ );
	await expect.poll( () => t.evaluate( ( el ) => getComputedStyle( el ).borderBottomColor ) ).not.toBe( 'rgba(0, 0, 0, 0)' );
}

test( 'after every switch, through the hash and React state, the indicator stays square and flush @mobile', async ( { page } ) => {
	await page.goto( '/?screen=settings#chat-box' );
	await check( page, 'first-render' );
	for ( const name of [ 'AI agent', 'Human support', 'Chat box', 'Human support', 'AI agent' ] ) {
		await switchTo( page, name );
		await check( page, `after-${ name.replace( ' ', '-' ).toLowerCase() }` );
	}
} );

test( 'hover: the soft fill and accent text as before, down to the divider, which stays visible; the current bar is unchanged @mobile', async ( { page, isMobile, browserName } ) => {
	test.skip( isMobile, 'no hover on touch screens' );
	await page.goto( '/?screen=settings#chat-box' );
	const hovered = tabNamed( page, 'Human support' );
	await hovered.hover();
	await expect.poll( () => hovered.evaluate( ( el ) => getComputedStyle( el ).backgroundColor ) ).not.toBe( 'rgba(0, 0, 0, 0)' );
	const h = await hovered.evaluate( ( el ) => {
		const cs = getComputedStyle( el );
		const probe = document.createElement( 'span' );
		probe.style.color = 'var(--color-action-primary)';
		probe.style.background = 'var(--color-action-soft)';
		el.parentElement!.appendChild( probe );
		const want = { text: getComputedStyle( probe ).color, fill: getComputedStyle( probe ).backgroundColor };
		probe.remove();
		return { radius: cs.borderRadius, color: cs.color, fill: cs.backgroundColor, want, bottom: el.getBoundingClientRect().bottom, nav: el.parentElement!.getBoundingClientRect().bottom, box: el.getBoundingClientRect().toJSON() };
	} );
	// The pre-#81 hover: Alpha's soft accent fill and accent text, square.
	expect( h.fill ).toBe( h.want.fill );
	expect( h.color ).toBe( h.want.text );
	expect( h.radius ).toBe( '0px' );
	// It stops right above the divider (1px), so the divider shows under the hovered tab.
	expect( Math.abs( h.nav - 1 - h.bottom ) ).toBeLessThanOrEqual( 0.5 );
	const col = await pixels( page, { x: Math.round( h.box.x + h.box.width / 2 ), y: Math.floor( h.nav ) - 8, width: 1, height: 9 } );
	const fill = col[ 0 ];
	expect( col.slice( 0, 5 ).every( ( p ) => near( p, fill, 6 ) ), `the fill reaches down to the divider: ${ JSON.stringify( col ) }` ).toBe( true );
	// WebKit draws a 1px line on a fractional pixel as a faint two-row blend (as it did before #81); the geometry above
	// already shows the fill stops above the divider, so the pixel check runs where lines are crisp.
	if ( browserName !== 'webkit' ) expect( col.slice( 5 ).some( ( p ) => ! near( p, fill, 8 ) ), `the divider shows under the hovered tab: ${ JSON.stringify( col ) }` ).toBe( true );
	await check( page, 'hover-other' ); // the current tab is unchanged
	await tabNamed( page, 'Chat box' ).hover();
	await check( page, 'hover-current' );
} );

test( 'keyboard: focus moves with Tab, Enter switches; the focus ring is square and the bar stays flush @mobile', async ( { page, browserName } ) => {
	await page.goto( '/?screen=settings#chat-box' );
	const agent = tabNamed( page, 'AI agent' );
	await tabNamed( page, 'Chat box' ).focus();
	// WebKit, like Safari by default, doesn't move Tab focus to links: focus the next tab directly there.
	if ( browserName === 'webkit' ) await agent.focus();
	else await page.keyboard.press( 'Tab' );
	await expect( agent ).toBeFocused();
	expect( await agent.evaluate( ( el ) => getComputedStyle( el ).borderRadius ) ).toBe( '0px' );
	if ( browserName !== 'webkit' ) expect( await agent.evaluate( ( el ) => getComputedStyle( el ).outlineStyle ) ).toBe( 'solid' ); // :focus-visible ring
	await check( page, 'focus-other' );
	await page.keyboard.press( 'Enter' );
	await expect( agent ).toHaveClass( /tabs__tab--current/ );
	await expect.poll( () => agent.evaluate( ( el ) => getComputedStyle( el ).borderBottomColor ) ).not.toBe( 'rgba(0, 0, 0, 0)' );
	await check( page, 'focus-current' );
} );
