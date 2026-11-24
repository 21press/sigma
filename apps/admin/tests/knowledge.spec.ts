// Sigma → Knowledge (plan §7.6), built to the design mockups: list, views, search, bulk actions, Edit / Add /
// Import drawers, Delete, the empty state, file parsing, and "Used:" in Conversations. The built admin.js runs
// against the in-memory REST mock (dev/mock-api.ts). Desktop engines run everything; phones run @mobile.
//   DA_SHOTS=<dir> also saves screenshots.
import { expect, test, type Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';

const shots = process.env.DA_SHOTS;
if ( shots ) mkdirSync( shots, { recursive: true } );
const shot = async ( p: Page, name: string ) => {
	if ( shots ) await p.screenshot( { path: `${ shots }/knowledge-${ name }-${ test.info().project.name }.png`, fullPage: false } );
};

const open = async ( p: Page, query = '' ) => {
	await p.goto( `/?screen=knowledge${ query }` );
	await expect( p.getByRole( 'heading', { name: 'Knowledge', level: 2 } ) ).toBeVisible();
};
const table = ( p: Page ) => p.locator( '.list-table__table' );
const row = ( p: Page, title: string ) => table( p ).locator( 'tr', { has: p.getByRole( 'link', { name: title, exact: true } ) } );
const drawer = ( p: Page ) => p.getByRole( 'dialog' );
const calls = ( p: Page ) => p.evaluate( () => ( window as any ).__kbCalls as { method: string; path: string; data?: any }[] );
const toast = ( p: Page ) => p.locator( '.toast' );
const titles = ( p: Page ) => table( p ).locator( '.list-table__primary' ).allTextContents();
const md = ( name: string, text: string ) => ( { name, mimeType: 'text/markdown', buffer: Buffer.from( text, 'utf8' ) } );

test( 'list as drawn: views with counts, columns, a row, the footer, the provider notice @mobile', async ( { page } ) => {
	await open( page );
	await expect( page.locator( '.views' ) ).toContainText( 'All (6)' );
	await expect( page.locator( '.views' ) ).toContainText( 'Active (3)' );
	await expect( page.locator( '.views' ) ).toContainText( 'Draft (3)' );
	await expect( page.locator( '.list-table__head-cell' ) ).toHaveText( [ 'Title', 'Source', 'Length', 'Status', 'Updated (UTC)' ] );
	const r = row( page, 'Pricing and licences' );
	await expect( r.locator( '.list-table__cell' ).nth( 1 ) ).toHaveText( 'pricing.md' );
	await expect( r.locator( '.list-table__cell' ).nth( 2 ) ).toHaveText( /^\d+ words$/ );
	await expect( r.locator( '.badge' ) ).toHaveText( 'Active' );
	await expect( r.locator( '.list-table__cell' ).nth( 4 ) ).toHaveText( '2026-11-20 10:14' );
	await expect( row( page, 'Refund policy' ).locator( '.list-table__cell' ).nth( 1 ) ).toHaveText( 'Written here' );
	await expect( row( page, 'WooCommerce support' ).locator( '.badge' ) ).toHaveText( 'Draft' );
	await expect( r.locator( '.list-table__actions' ) ).toContainText( 'Edit|Move to draft|Delete' );
	await expect( row( page, 'Shipping times' ).locator( '.list-table__actions' ) ).toContainText( 'Make active' );
	await expect( page.locator( '.list-table__toolbar' ).first() ).toContainText( '6 entries' );
	await expect( page.locator( '.knowledge__footer' ) ).toHaveText( /^The agent uses the 3 active entries · \d+ words$/ );
	await expect( page.locator( '.notice' ).first() ).toHaveText( "Active entries are sent to your AI provider with every question, and the bot can share anything in them with any visitor. Don't put passwords, internal contacts or anything private here." );
	expect( await titles( page ) ).toEqual( [ 'Pricing and licences', 'Refund policy', 'Setting up the header', 'WooCommerce support', 'Shipping times', 'Unsafe sample' ] ); // updated, newest first
	await shot( page, 'list' );
} );

test( 'empty state as drawn, and both actions open their drawers @mobile', async ( { page } ) => {
	await open( page, '&kb=empty' );
	const empty = page.locator( '.empty-state' );
	await expect( empty.getByRole( 'heading', { name: 'No knowledge yet' } ) ).toBeVisible();
	await expect( empty ).toContainText( 'Add what the agent should know that your site pages do not say' );
	await expect( page.locator( '.card__header-actions' ) ).toHaveCount( 0 );
	await expect( table( page ) ).toHaveCount( 0 );
	await shot( page, 'empty' );
	await empty.getByRole( 'button', { name: 'Add entry' } ).click();
	await expect( drawer( page ) ).toHaveAccessibleName( 'Add entry' );
	await page.keyboard.press( 'Escape' );
	await empty.getByRole( 'button', { name: 'Import files' } ).click();
	await expect( drawer( page ) ).toHaveAccessibleName( 'Import files' );
} );

test( 'views and search filter the list; no match says so', async ( { page } ) => {
	await open( page );
	await page.locator( '.views' ).getByRole( 'link', { name: /^Draft/ } ).click();
	await expect.poll( () => titles( page ) ).toEqual( [ 'WooCommerce support', 'Shipping times', 'Unsafe sample' ] );
	await page.locator( '.views' ).getByRole( 'link', { name: /^All/ } ).click();
	await page.getByRole( 'searchbox', { name: 'Search titles and text' } ).fill( 'refunds within' ); // matches content, not the title
	await page.getByRole( 'button', { name: 'Search', exact: true } ).click();
	await expect.poll( () => titles( page ) ).toEqual( [ 'Refund policy' ] );
	await expect( page.locator( '.list-table__toolbar' ).first() ).toContainText( '1 entry' );
	await page.getByRole( 'searchbox' ).fill( 'nothing like this' );
	await page.getByRole( 'button', { name: 'Search', exact: true } ).click();
	await expect( table( page ) ).toContainText( 'No entries match “nothing like this”.' );
} );

test( 'Add entry: checks the fields, then adds an active entry; off keeps it a draft @mobile', async ( { page } ) => {
	await open( page );
	await page.getByRole( 'button', { name: 'Add entry' } ).click();
	const d = drawer( page );
	await expect( d ).toHaveAccessibleName( 'Add entry' );
	await expect( d.getByLabel( 'Title' ) ).toBeFocused();
	await expect( d.getByLabel( 'Content' ) ).toHaveClass( /field__textarea--code/ );
	await expect( page.locator( '.drawer--wide' ) ).toBeVisible();
	await shot( page, 'add' );
	await d.getByRole( 'button', { name: 'Add entry' } ).click();
	await expect( d.locator( '.field--error' ) ).toHaveCount( 2 );
	await expect( d.getByLabel( 'Title' ) ).toBeFocused();
	await d.getByLabel( 'Title' ).fill( 'Opening hours' );
	await d.getByLabel( 'Content' ).fill( 'Mon–Fri 9:00–17:00 CET.' );
	await d.getByRole( 'button', { name: 'Add entry' } ).click();
	await expect( d ).toHaveCount( 0 );
	await expect( toast( page ) ).toContainText( '“Opening hours” added.' );
	await expect( row( page, 'Opening hours' ).locator( '.badge' ) ).toHaveText( 'Active' );
	const post = ( await calls( page ) ).find( ( c ) => c.method === 'POST' && c.path.endsWith( '/admin/knowledge' ) );
	expect( post?.data ).toEqual( { title: 'Opening hours', content: 'Mon–Fri 9:00–17:00 CET.', status: 'active' } );
	// Toggle off → a draft.
	await page.getByRole( 'button', { name: 'Add entry' } ).click();
	await drawer( page ).getByLabel( 'Title' ).fill( 'Holiday closures' );
	await drawer( page ).getByLabel( 'Content' ).fill( 'Closed 24–26 December.' );
	await drawer( page ).getByText( 'The agent uses this entry' ).click();
	await drawer( page ).getByRole( 'button', { name: 'Add entry' } ).click();
	await expect( toast( page ) ).toContainText( '“Holiday closures” added as a draft.' );
	await expect( row( page, 'Holiday closures' ).locator( '.badge' ) ).toHaveText( 'Draft' );
} );

test( 'Add entry from a file: title from the first # heading, content, source; a wrong file is refused', async ( { page } ) => {
	await open( page );
	await page.getByRole( 'button', { name: 'Add entry' } ).click();
	const d = drawer( page );
	const pick = d.locator( 'input[type=file]' );
	await pick.setInputFiles( { name: 'notes.pdf', mimeType: 'application/pdf', buffer: Buffer.from( '%PDF' ) } );
	await expect( d.getByRole( 'alert' ) ).toHaveText( 'notes.pdf: only .md and .txt files.' );
	await pick.setInputFiles( md( 'opening-hours.md', 'Intro line\n\n# Opening hours\n\nMon–Fri 9–17.\n' ) );
	await expect( d.getByLabel( 'Title' ) ).toHaveValue( 'Opening hours' );
	await expect( d.getByLabel( 'Content' ) ).toHaveValue( 'Intro line\n\n# Opening hours\n\nMon–Fri 9–17.\n' );
	await expect( d ).toContainText( 'From opening-hours.md.' );
	await d.getByRole( 'button', { name: 'Add entry' } ).click();
	await expect( row( page, 'Opening hours' ).locator( '.list-table__cell' ).nth( 1 ) ).toHaveText( 'opening-hours.md' );
	// No heading: the file name becomes the title.
	await page.getByRole( 'button', { name: 'Add entry' } ).click();
	await drawer( page ).locator( 'input[type=file]' ).setInputFiles( { name: 'return_policy.txt', mimeType: 'text/plain', buffer: Buffer.from( 'Thirty days.' ) } );
	await expect( drawer( page ).getByLabel( 'Title' ) ).toHaveValue( 'return policy' );
} );

test( 'Edit entry: loads the text, saves title, content and status; Escape closes and returns focus @mobile', async ( { page } ) => {
	await open( page );
	await page.getByRole( 'link', { name: 'Pricing and licences', exact: true } ).click();
	const d = drawer( page );
	await expect( d ).toHaveAccessibleName( 'Edit entry' );
	await expect( d.getByLabel( 'Content' ) ).toHaveValue( /Founder's License/ );
	await expect( d ).toContainText( 'Imported from pricing.md on 2026-11-20. Edits here do not change the file.' );
	await expect( d.getByLabel( 'Title' ) ).toBeFocused();
	await shot( page, 'edit' );
	await d.getByLabel( 'Title' ).fill( 'Pricing' );
	await d.getByLabel( 'Content' ).fill( 'Unlimited: 199 EUR.' );
	await d.getByText( 'The agent uses this entry' ).click();
	await d.getByRole( 'button', { name: 'Save' } ).click();
	await expect( d ).toHaveCount( 0 );
	await expect( toast( page ) ).toContainText( '“Pricing” saved.' );
	await expect( row( page, 'Pricing' ).locator( '.badge' ) ).toHaveText( 'Draft' );
	const putCall = ( await calls( page ) ).find( ( c ) => c.method === 'PUT' );
	expect( putCall?.data ).toEqual( { title: 'Pricing', content: 'Unlimited: 199 EUR.', status: 'draft' } );
	// Escape closes and focus returns to the link that opened it.
	await page.getByRole( 'link', { name: 'Refund policy', exact: true } ).click();
	await expect( drawer( page ).getByLabel( 'Title' ) ).toBeFocused();
	await page.keyboard.press( 'Escape' );
	await expect( drawer( page ) ).toHaveCount( 0 );
	await expect( page.getByRole( 'link', { name: 'Refund policy', exact: true } ) ).toBeFocused();
} );

test( 'Edit: Replace from a file fills the content and records the file as the source', async ( { page } ) => {
	await open( page );
	await page.getByRole( 'link', { name: 'Refund policy', exact: true } ).click();
	const d = drawer( page );
	await expect( d ).toContainText( 'Written here.' );
	await d.getByLabel( /Replace from a file/ ).setInputFiles( md( 'refunds-2027.md', '# Refunds\n\n14 days from 2027.' ) );
	await expect( d.getByLabel( 'Content' ) ).toHaveValue( '# Refunds\n\n14 days from 2027.' );
	await expect( d.getByLabel( 'Title' ) ).toHaveValue( 'Refund policy' ); // the title stays
	await expect( d ).toContainText( 'From refunds-2027.md. Save to keep it.' );
	await d.getByRole( 'button', { name: 'Save' } ).click();
	await expect( row( page, 'Refund policy' ).locator( '.list-table__cell' ).nth( 1 ) ).toHaveText( 'refunds-2027.md' );
	expect( ( await calls( page ) ).find( ( c ) => c.method === 'PUT' )?.data.source ).toBe( 'refunds-2027.md' );
} );

test( 'Edit: a server field error shows under its field; a deep link ?edit= opens the drawer', async ( { page } ) => {
	await open( page );
	await page.getByRole( 'link', { name: 'Refund policy', exact: true } ).click();
	await drawer( page ).getByLabel( 'Title' ).fill( 'server-refuses this' );
	await drawer( page ).getByRole( 'button', { name: 'Save' } ).click();
	await expect( drawer( page ).locator( '.field--error .field__message' ) ).toHaveText( 'The server refused this title.' );
	await expect( drawer( page ) ).toBeVisible();
	const id = await page.evaluate( () => ( window as any ).__kbRows.find( ( r: any ) => r.title === 'Setting up the header' ).id );
	await open( page, `&edit=${ id }` );
	await expect( drawer( page ).getByLabel( 'Title' ) ).toHaveValue( 'Setting up the header' );
	await page.keyboard.press( 'Escape' );
	expect( new URL( page.url() ).searchParams.has( 'edit' ) ).toBe( false );
} );

test( 'safety: an entry with a shortcode, <script>, a block comment and an <img onerror> shows as text, never runs', async ( { page } ) => {
	await open( page );
	await page.getByRole( 'link', { name: 'Unsafe sample', exact: true } ).click();
	const d = drawer( page );
	await expect( d.getByLabel( 'Content' ) ).toHaveValue( /\[gallery ids="1"\] <script>window\.__pwned = 1<\/script> <!-- wp:paragraph -->/ );
	expect( await d.locator( 'script, img' ).count() ).toBe( 0 );
	expect( await page.evaluate( () => ( window as any ).__pwned ) ).toBeUndefined();
} );

test( 'row actions: Move to draft and Make active update the row and the counts', async ( { page } ) => {
	await open( page );
	await row( page, 'Refund policy' ).hover(); // row actions show on hover or focus, as in wp-admin
	await row( page, 'Refund policy' ).getByRole( 'link', { name: 'Move to draft: Refund policy' } ).click();
	await expect( toast( page ) ).toContainText( '“Refund policy” moved to draft.' );
	await expect( row( page, 'Refund policy' ).locator( '.badge' ) ).toHaveText( 'Draft' );
	await expect( page.locator( '.views' ) ).toContainText( 'Active (2)' );
	await row( page, 'Shipping times' ).hover();
	await row( page, 'Shipping times' ).getByRole( 'link', { name: 'Make active: Shipping times' } ).click();
	await expect( row( page, 'Shipping times' ).locator( '.badge' ) ).toHaveText( 'Active' );
	await expect( page.locator( '.views' ) ).toContainText( 'Active (3)' );
} );

test( 'Delete: confirm modal as drawn; Cancel keeps it, Delete removes it @mobile', async ( { page } ) => {
	await open( page );
	await row( page, 'Shipping times' ).hover();
	await row( page, 'Shipping times' ).getByRole( 'link', { name: 'Delete Shipping times' } ).click();
	const m = page.getByRole( 'dialog', { name: 'Delete this entry?' } );
	await expect( m ).toContainText( 'The agent stops using it at once. You cannot undo this. To keep it without using it, move it to draft.' );
	await shot( page, 'delete' );
	await m.getByRole( 'button', { name: 'Cancel' } ).click();
	await expect( row( page, 'Shipping times' ) ).toHaveCount( 1 );
	await row( page, 'Shipping times' ).hover();
	await row( page, 'Shipping times' ).getByRole( 'link', { name: 'Delete Shipping times' } ).click();
	await page.getByRole( 'dialog' ).getByRole( 'button', { name: 'Delete' } ).click();
	await expect( toast( page ) ).toContainText( '“Shipping times” deleted.' );
	await expect( row( page, 'Shipping times' ) ).toHaveCount( 0 );
	await expect( page.locator( '.views' ) ).toContainText( 'All (5)' );
} );

test( 'bulk actions: Make active, Move to draft, Delete (with confirm); Apply needs a choice and a selection', async ( { page } ) => {
	await open( page );
	const apply = page.getByRole( 'button', { name: 'Apply' } );
	await expect( apply ).toBeDisabled();
	await page.getByRole( 'checkbox', { name: 'Select WooCommerce support' } ).check();
	await page.getByRole( 'checkbox', { name: 'Select Shipping times' } ).check();
	await page.getByRole( 'combobox', { name: 'Bulk actions' } ).selectOption( 'activate' );
	await apply.click();
	await expect( toast( page ) ).toContainText( '2 entries made active.' );
	await expect( page.locator( '.views' ) ).toContainText( 'Active (5)' );
	expect( ( await calls( page ) ).find( ( c ) => c.path.endsWith( '/bulk' ) )?.data.action ).toBe( 'activate' );
	// Select all → Move to draft.
	await page.getByRole( 'checkbox', { name: 'Select all' } ).check();
	await page.getByRole( 'combobox', { name: 'Bulk actions' } ).selectOption( 'draft' );
	await apply.click();
	await expect( toast( page ) ).toContainText( '6 entries moved to draft.' );
	await expect( page.locator( '.views' ) ).toContainText( 'Active (0)' );
	await expect( page.locator( '.knowledge__footer' ) ).toHaveText( 'The agent uses no entries yet. Make one active to use it.' );
	// Delete two, confirmed.
	await page.getByRole( 'checkbox', { name: 'Select Refund policy' } ).check();
	await page.getByRole( 'checkbox', { name: 'Select Unsafe sample' } ).check();
	await page.getByRole( 'combobox', { name: 'Bulk actions' } ).selectOption( 'delete' );
	await apply.click();
	const m = page.getByRole( 'dialog', { name: 'Delete 2 entries?' } );
	await expect( m ).toContainText( 'The agent stops using them at once.' );
	await m.getByRole( 'button', { name: 'Delete' } ).click();
	await expect( toast( page ) ).toContainText( '2 entries deleted.' );
	await expect( page.locator( '.views' ) ).toContainText( 'All (4)' );
} );

test( 'Import: titles from # or the name, replaces warned, wrong type / too big / not UTF-8 refused, drafts by default @mobile', async ( { page } ) => {
	await open( page );
	await page.getByRole( 'button', { name: 'Import files' } ).click();
	const d = drawer( page );
	await expect( d.getByText( 'Make new entries active' ) ).toBeVisible();
	await expect( d.getByRole( 'checkbox', { name: /Make new entries active/ } ) ).not.toBeChecked();
	await d.locator( 'input[type=file]' ).setInputFiles( [
		md( 'opening-hours.md', '# Opening hours\n\nMon–Fri 9–17.' ),
		md( 'pricing.md', '# Pricing and licences\n\nNew prices from 2027.' ),
		md( 'theme_updates.md', 'Updates ship monthly.' ),
		{ name: 'notes.pdf', mimeType: 'application/pdf', buffer: Buffer.from( '%PDF-1.7' ) },
		{ name: 'big.md', mimeType: 'text/markdown', buffer: Buffer.alloc( 1048577, 'a' ) },
		{ name: 'latin1.txt', mimeType: 'text/plain', buffer: Buffer.from( [ 0x43, 0x61, 0x66, 0xe9 ] ) },
	] );
	await expect( d.getByRole( 'heading', { name: 'Ready to import (3)' } ) ).toBeVisible();
	await expect( d ).toContainText( 'opening-hours.md · 5 words · new' );
	await expect( d ).toContainText( 'pricing.md · 8 words · replaces the current text' );
	await expect( d ).toContainText( 'theme updatestheme_updates.md' ); // no heading: the name, without .md, _ → space
	await expect( d ).toContainText( "notes.pdf · can't import: only .md and .txt files" );
	await expect( d ).toContainText( "big.md · can't import: larger than 1 MB" );
	await expect( d ).toContainText( "latin1.txt · can't import: not UTF-8 text" );
	await expect( d.locator( '.notice--warning' ) ).toHaveText( '1 file has the same title as an entry. Importing replaces that entry’s text.' );
	await shot( page, 'import' );
	await d.getByRole( 'button', { name: 'Import 3 files' } ).click();
	await expect( d ).toHaveCount( 0 );
	await expect( toast( page ) ).toContainText( 'Imported 3 files: 2 new, 1 replaced.' );
	await expect( row( page, 'Opening hours' ).locator( '.badge' ) ).toHaveText( 'Draft' );
	await expect( row( page, 'Pricing and licences' ).locator( '.badge' ) ).toHaveText( 'Active' ); // a replaced entry keeps its status
	const imp = ( await calls( page ) ).find( ( c ) => c.path.endsWith( '/import' ) )!;
	expect( imp.data.active ).toBe( false );
	expect( imp.data.files.map( ( f: any ) => f.name ) ).toEqual( [ 'opening-hours.md', 'pricing.md', 'theme_updates.md' ] );
	expect( Object.keys( imp.data.files[ 0 ] ).sort() ).toEqual( [ 'content', 'name', 'title' ] ); // only the text is sent
} );

test( 'Import: unchecking a file leaves it out; two files with one title keep the first; "active" on is sent', async ( { page } ) => {
	await open( page );
	await page.getByRole( 'button', { name: 'Import files' } ).click();
	const d = drawer( page );
	await d.locator( 'input[type=file]' ).setInputFiles( [ md( 'a.md', '# FAQ\n\nOne.' ), md( 'b.md', '# faq\n\nTwo.' ), md( 'c.md', '# Contact\n\nEmail us.' ) ] );
	await expect( d ).toContainText( 'b.md · 3 words · same title as a file above, left out' );
	await d.getByRole( 'checkbox', { name: /^Contact/ } ).uncheck();
	await d.getByText( 'Make new entries active' ).click();
	await d.getByRole( 'button', { name: 'Import 1 file' } ).click();
	await expect( toast( page ) ).toContainText( 'Imported 1 file: 1 new.' );
	await expect( row( page, 'FAQ' ).locator( '.badge' ) ).toHaveText( 'Active' );
	await expect( row( page, 'Contact' ) ).toHaveCount( 0 );
} );

test( 'Import: a file the server refuses keeps the drawer open with the reason; the rest is imported', async ( { page } ) => {
	await open( page );
	await page.getByRole( 'button', { name: 'Import files' } ).click();
	const d = drawer( page );
	await d.locator( 'input[type=file]' ).setInputFiles( [ md( 'good.md', '# Good\n\nFine.' ), md( 'server-refuses.md', '# Odd\n\nHmm.' ) ] );
	await d.getByRole( 'button', { name: 'Import 2 files' } ).click();
	await expect( d.locator( '.notice--danger' ) ).toHaveText( 'Not imported: server-refuses.md (not UTF-8 text).' );
	await expect( toast( page ) ).toContainText( 'Imported 1 file: 1 new.' );
	await expect( row( page, 'Good' ) ).toHaveCount( 1 );
} );

test( 'drawers keep focus inside (Tab cycles) and close on the overlay', async ( { page } ) => {
	await open( page );
	await page.getByRole( 'button', { name: 'Import files' } ).click();
	const d = drawer( page );
	for ( let i = 0; i < 12; i++ ) await page.keyboard.press( 'Tab' );
	expect( await page.evaluate( () => !! document.activeElement?.closest( '[role=dialog]' ) ) ).toBe( true );
	await page.locator( '.drawer-overlay' ).click( { position: { x: 5, y: 5 } } );
	await expect( d ).toHaveCount( 0 );
} );

test( 'pagination: 20 per page, Previous and Next', async ( { page } ) => {
	await open( page, '&kb=many' );
	await expect( page.locator( '.list-table__toolbar' ).first() ).toContainText( '46 entries' );
	await expect( page.locator( '.list-table__pagination' ) ).toContainText( '1 of 3' );
	expect( await titles( page ) ).toHaveLength( 20 );
	await page.getByRole( 'button', { name: 'Next' } ).click();
	await expect( page.locator( '.list-table__pagination' ) ).toContainText( '2 of 3' );
} );

test( 'phone width: the table scrolls inside its card, the drawer takes the screen, nothing overflows the page @mobile', async ( { page, isMobile } ) => {
	test.skip( ! isMobile, 'phones' );
	await open( page );
	const overflow = await page.evaluate( () => document.documentElement.scrollWidth - document.documentElement.clientWidth );
	expect( overflow ).toBeLessThanOrEqual( 1 );
	await page.getByRole( 'link', { name: 'Pricing and licences', exact: true } ).click();
	const box = await page.locator( '.drawer' ).boundingBox();
	const vw = page.viewportSize()!.width;
	expect( Math.round( box!.width ) ).toBe( vw );
	await expect( drawer( page ).getByRole( 'button', { name: 'Save' } ) ).toBeInViewport();
	await shot( page, 'edit-phone' );
	await page.keyboard.press( 'Escape' );
	// Touch: no hover, so the row actions are always shown.
	await expect( row( page, 'Refund policy' ).getByRole( 'link', { name: 'Delete Refund policy' } ) ).toBeVisible();
} );

test( 'Conversations: "Used:" lists the entries an answer used, each linking to its edit drawer @mobile', async ( { page } ) => {
	await page.goto( '/?screen=conversations#/11111111-2222-4333-8444-555555555555' );
	const used = page.locator( '.chat__used' );
	await expect( used ).toHaveText( 'Used: Pricing and licences, Refund policy' );
	const href = await used.getByRole( 'link', { name: 'Pricing and licences' } ).getAttribute( 'href' );
	// adminUrl is admin_url( 'admin.php' ): the query follows it directly (found on a live site).
	expect( href ).toMatch( /^http:\/\/localhost:\d+\/wp-admin\/admin\.php\?page=sigma-knowledge&edit=\d+$/ );
	expect( href ).not.toContain( 'admin.phpadmin.php' );
	await shot( page, 'conversation-used' );
} );

test( 'drawers and the delete modal start below the wp-admin toolbar (32 px; 46 px on phones) @mobile', async ( { page } ) => {
	await open( page );
	const bar = await page.locator( '#wpadminbar' ).boundingBox();
	await page.getByRole( 'button', { name: 'Add entry' } ).first().click();
	const title = await drawer( page ).locator( '.drawer__title' ).boundingBox();
	expect( title!.y ).toBeGreaterThanOrEqual( bar!.y + bar!.height );
	const close = await drawer( page ).getByRole( 'button', { name: 'Close' } ).boundingBox();
	expect( close!.y ).toBeGreaterThanOrEqual( bar!.y + bar!.height );
	await page.keyboard.press( 'Escape' );
	await row( page, 'Refund policy' ).hover();
	await row( page, 'Refund policy' ).getByRole( 'link', { name: 'Delete Refund policy' } ).click();
	const modal = await page.getByRole( 'dialog' ).boundingBox();
	expect( modal!.y ).toBeGreaterThanOrEqual( bar!.y + bar!.height );
} );

test( "the provider notice stays in the Knowledge card when wp-admin's common.js moves notices @mobile", async ( { page } ) => {
	await open( page );
	await page.waitForTimeout( 600 ); // the harness mimics common.js at 400 ms
	const notice = page.locator( '.notice', { hasText: 'Active entries are sent to your AI provider' } );
	await expect( notice ).toHaveClass( /\binline\b/ );
	expect( await notice.evaluate( ( n ) => !! n.closest( '.card' ) ) ).toBe( true );
	expect( await notice.evaluate( ( n ) => !! n.closest( '.plugin__topbar' ) ) ).toBe( false );
} );
