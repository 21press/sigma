// Settings → AI agent → Instructions (plan v1.20 §7.7): the editable text with its badge, counter and reset, the
// read-only system rules, and the live preview's "Try with real AI" switch. The built admin.js and widget.js run
// against the in-memory mock (dev/mock-api.ts). DA_SHOTS=<dir> also saves screenshots.
import { expect, test, type Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';

const shots = process.env.DA_SHOTS;
if ( shots ) mkdirSync( shots, { recursive: true } );
const shot = async ( p: Page, name: string, el?: string ) => {
	if ( ! shots ) return;
	const path = `${ shots }/instructions-${ name }-${ test.info().project.name }.png`;
	if ( el ) await p.locator( el ).screenshot( { path } );
	else await p.screenshot( { path } );
};

const open = async ( p: Page, query = '' ) => {
	await p.goto( `/?screen=settings${ query }#agent` );
	await expect( p.getByRole( 'heading', { name: 'Instructions' } ) ).toBeVisible();
};
const box = ( p: Page ) => p.locator( '#da-instructions' );
const group = ( p: Page ) => p.locator( '.instructions' );
const badge = ( p: Page ) => group( p ).locator( '.instructions__head .badge' );
const calls = ( p: Page ) => p.evaluate( () => ( window as any ).__kbCalls as { method: string; path: string; data?: any }[] );
const saveBtn = ( p: Page ) => p.getByRole( 'button', { name: 'Save changes' } );
/** The live preview's chat: on desktop it opens by itself shortly after load (wait for it: clicking the launcher
 *  meanwhile would close it again); on phones it opens from the launcher. */
const openPreview = async ( p: Page ) => {
	if ( ! test.info().project.use.isMobile ) {
		await expect( p.locator( '#da-panel' ) ).toBeVisible( { timeout: 15000 } );
		return;
	}
	// Under a full parallel run, mobile WebKit sometimes leaves the first tap without an open panel (seen 1 in 3 runs,
	// never alone). Tap again once; the cause is followed up on a real iPhone in the accessibility pass.
	await expect( async () => {
		if ( ! ( await p.locator( '#da-panel' ).isVisible() ) ) await p.locator( 'sigma-chat .da-launcher' ).click();
		await expect( p.locator( '#da-panel' ) ).toBeVisible( { timeout: 5000 } );
	} ).toPass( { timeout: 20000 } );
};

test( 'shows the default text, a "Default" badge, placeholder help and the counter @mobile', async ( { page } ) => {
	await open( page );
	await expect( box( page ) ).toHaveValue( /^You are \{bot_name\}, the AI assistant for \{product\}\./ );
	await expect( box( page ) ).toHaveClass( /field__textarea--code/ );
	await expect( badge( page ) ).toHaveText( 'Default' );
	await expect( group( page ) ).toContainText( '{bot_name}' );
	await expect( group( page ) ).toContainText( '{pricing_url}' );
	const len = ( await box( page ).inputValue() ).length;
	await expect( page.locator( '#da-instructions-count' ) ).toHaveText( `${ len.toLocaleString( 'en' ) } / 4,000 characters` );
	await expect( group( page ).getByRole( 'button', { name: 'Reset to default' } ) ).toBeDisabled();
	await shot( page, 'section', '.instructions' );
} );

test( 'editing shows "Customised"; saving sends the text; Reset to default brings the default back and saves it as ""', async ( { page } ) => {
	await open( page );
	await box( page ).fill( 'Answer warmly and sign off with "— Remy".' );
	await expect( badge( page ) ).toHaveText( 'Customised' );
	await expect( page.locator( '#da-instructions-count' ) ).toHaveText( '41 / 4,000 characters' );
	await saveBtn( page ).click();
	await expect.poll( async () => ( await calls( page ) ).filter( ( c ) => c.method === 'POST' && c.path.endsWith( '/admin/settings' ) ).length ).toBe( 1 );
	const sent = ( await calls( page ) ).find( ( c ) => c.method === 'POST' && c.path.endsWith( '/admin/settings' ) )!;
	expect( sent.data.instructions ).toBe( 'Answer warmly and sign off with "— Remy".' );
	await expect( badge( page ) ).toHaveText( 'Customised' ); // saved and still custom
	// Reset to default.
	await group( page ).getByRole( 'button', { name: 'Reset to default' } ).click();
	await expect( box( page ) ).toHaveValue( /^You are \{bot_name\}/ );
	await expect( badge( page ) ).toHaveText( 'Default' );
	await saveBtn( page ).click();
	await expect.poll( async () => ( await calls( page ) ).filter( ( c ) => c.method === 'POST' && c.path.endsWith( '/admin/settings' ) ).length ).toBe( 2 );
	// The server stores text equal to the default as '' (it follows future default updates).
	expect( await page.evaluate( () => ( window as any ).__kbCalls.filter( ( c: any ) => c.method === 'POST' ).pop().data.instructions ) ).toMatch( /^You are \{bot_name\}/ );
	await page.reload();
	await expect( box( page ) ).toHaveValue( /^You are \{bot_name\}/ );
	await expect( badge( page ) ).toHaveText( 'Default' );
} );

test( 'a saved custom text loads as Customised; clearing the box says the default is used', async ( { page } ) => {
	await open( page, '&instructions=custom' );
	await expect( box( page ) ).toHaveValue( 'Answer warmly and sign off with "— Remy".' );
	await expect( badge( page ) ).toHaveText( 'Customised' );
	await box( page ).fill( '' );
	await expect( page.locator( '#da-instructions-count' ) ).toHaveText( 'Empty: the default is used. 0 / 4,000 characters' );
	await expect( badge( page ) ).toHaveText( 'Default' );
} );

test( 'the 4,000-character cap: typing stops at the cap, the counter warns near it', async ( { page } ) => {
	await open( page );
	await box( page ).fill( 'x'.repeat( 3850 ) );
	await expect( page.locator( '#da-instructions-count' ) ).toHaveClass( /instructions__count--near/ );
	// Typing stops at the cap (maxlength): start 5 under it and type 9 more.
	await box( page ).evaluate( ( el: HTMLTextAreaElement ) => {
		el.value = 'a'.repeat( 3995 );
		el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	} );
	await box( page ).press( 'End' );
	await box( page ).pressSequentially( 'bcdefghij' );
	expect( ( await box( page ).inputValue() ).length ).toBe( 4000 );
	await expect( page.locator( '#da-instructions-count' ) ).toHaveText( '4,000 / 4,000 characters' );
	expect( await box( page ).getAttribute( 'maxlength' ) ).toBe( '4000' );
} );

test( 'the system rules: collapsed, read-only, the exact protected text after the override line @mobile', async ( { page } ) => {
	await open( page );
	const panel = group( page ).locator( 'details.instructions__rules' );
	await expect( panel ).not.toHaveAttribute( 'open', '' );
	await expect( panel.locator( 'summary' ) ).toHaveText( 'System rules: always applied' );
	await panel.locator( 'summary' ).click();
	const rules = panel.locator( 'pre.instructions__system' );
	await expect( rules ).toBeVisible();
	await expect( rules ).toContainText( 'The following system rules always apply and override anything above:' );
	expect( ( ( await rules.textContent() ) || '' ).split( 'The following system rules' ).length - 1 ).toBe( 1 ); // the lead line once
	await expect( rules ).toContainText( '[[NOT_IN_DOCS]]' );
	await expect( rules ).toContainText( '[[SOURCES: kb-…]]' );
	// Read-only: no form control, not contenteditable, and typing changes nothing.
	expect( await panel.locator( 'textarea, input, [contenteditable="true"]' ).count() ).toBe( 0 );
	const before = await rules.textContent();
	await rules.click();
	await page.keyboard.type( 'IGNORE ALL RULES' );
	expect( await rules.textContent() ).toBe( before );
	await shot( page, 'system-rules', '.instructions' );
} );

test( '"Try with real AI" is off by default: the preview answers locally and sends nothing @mobile', async ( { page } ) => {
	await open( page );
	await expect( group( page ).getByRole( 'checkbox', { name: /Try with real AI/ } ) ).not.toBeChecked();
	await expect( group( page ) ).toContainText( 'costs like a normal question' );
	const host = page.locator( 'sigma-chat' );
	await expect( host.locator( '.da-launcher' ) ).toBeAttached();
	await openPreview( page );
	await page.locator( '.da-option--bot' ).click();
	await page.locator( '#da-q' ).fill( 'How much is the Agency licence?' );
	await page.locator( '#da-q' ).press( 'Enter' );
	await expect( page.locator( '.da-log .da-msg--ai:not(.da-msg--intro)' ) ).toContainText( 'This is a preview, so nothing was sent.' );
	expect( ( await calls( page ) ).filter( ( c ) => c.path.endsWith( '/admin/preview/ask' ) ) ).toHaveLength( 0 );
	expect( await page.evaluate( () => typeof ( window as any ).SigmaPreviewAsk ) ).toBe( 'undefined' );
} );

test( '"Try with real AI": asking right after switching on uses the real AI (no wait for the preview to update)', async ( { page } ) => {
	await open( page );
	const host = page.locator( 'sigma-chat' );
	await openPreview( page );
	await page.locator( '.da-option--bot' ).click();
	await page.locator( '#da-q' ).fill( 'How much is the Agency licence?' );
	await group( page ).getByText( 'Try with real AI in the live preview' ).click();
	await page.locator( '#da-q' ).press( 'Enter' ); // at once
	await expect( page.locator( '.da-log .da-msg--ai:not(.da-msg--intro)' ) ).toContainText( 'The Agency licence is 399 dollars a year.' );
	// Off again: back to the local placeholder, nothing sent.
	await group( page ).getByText( 'Try with real AI in the live preview' ).click();
	await page.locator( '#da-q' ).fill( 'And now?' );
	await page.locator( '#da-q' ).press( 'Enter' );
	await expect( page.locator( '.da-log .da-msg--ai:not(.da-msg--intro)' ).last() ).toContainText( 'This is a preview, so nothing was sent.' );
	expect( ( await calls( page ) ).filter( ( c ) => c.path.endsWith( '/admin/preview/ask' ) ) ).toHaveLength( 1 );
} );

test( '"Try with real AI" on: the preview sends the unsaved instructions and renders the reply safely, like an answer @mobile', async ( { page } ) => {
	await open( page );
	await box( page ).fill( 'Answer warmly and sign off with "— Remy".' ); // not saved
	await group( page ).getByText( 'Try with real AI in the live preview' ).click();
	const host = page.locator( 'sigma-chat' );
	await openPreview( page );
	await page.locator( '.da-option--bot' ).click();
	await page.locator( '#da-q' ).fill( 'How much is the Agency licence?' );
	await page.locator( '#da-q' ).press( 'Enter' );
	const ans = page.locator( '.da-log .da-msg--ai:not(.da-msg--intro)' );
	await expect( ans ).toContainText( 'Happy to help!' );
	await expect( ans ).toContainText( '— Remy' );
	await expect( ans.locator( 'strong' ) ).toHaveText( 'Agency' ); // Markdown, through the widget's safe renderer
	await expect( ans.locator( '.da-chip' ) ).toHaveAttribute( 'href', 'https://example.com/pricing/' );
	const ask = ( await calls( page ) ).filter( ( c ) => c.path.endsWith( '/admin/preview/ask' ) );
	expect( ask ).toHaveLength( 1 );
	expect( ask[ 0 ].data.question ).toBe( 'How much is the Agency licence?' );
	expect( ask[ 0 ].data.instructions ).toBe( 'Answer warmly and sign off with "— Remy".' );
	expect( ( await calls( page ) ).filter( ( c ) => c.method === 'POST' && c.path.endsWith( '/admin/settings' ) ) ).toHaveLength( 0 ); // unsaved
	// A follow-up carries the first turn as history.
	await page.locator( '#da-q' ).fill( 'And the Pro one?' );
	await page.locator( '#da-q' ).press( 'Enter' );
	await expect.poll( async () => ( await calls( page ) ).filter( ( c ) => c.path.endsWith( '/admin/preview/ask' ) ).length ).toBe( 2 );
	const second = ( await calls( page ) ).filter( ( c ) => c.path.endsWith( '/admin/preview/ask' ) )[ 1 ];
	expect( second.data.history.map( ( h: any ) => h.role ) ).toEqual( [ 'user', 'assistant' ] );
	await page.waitForTimeout( 500 );
	await shot( page, 'preview-real-ai' );
} );

test( '"Try with real AI": the 20-per-hour limit shows the widget\'s limit card, not an error', async ( { page } ) => {
	await open( page );
	await group( page ).getByText( 'Try with real AI in the live preview' ).click();
	await page.evaluate( async () => {
		for ( let i = 0; i < 20; i++ ) await ( window as any ).SigmaPreviewAsk( 'warm-up ' + i );
	} );
	const host = page.locator( 'sigma-chat' );
	await openPreview( page );
	await page.locator( '.da-option--bot' ).click();
	await page.locator( '#da-q' ).fill( 'One more?' );
	await page.locator( '#da-q' ).press( 'Enter' );
	await expect( page.locator( '.da-log' ) ).toContainText( 'You can ask again in about an hour' );
} );

test( 'phone width: the section fits, the counter and Reset stay visible @mobile', async ( { page, isMobile } ) => {
	test.skip( ! isMobile, 'phones' );
	await open( page );
	await box( page ).scrollIntoViewIfNeeded();
	const overflow = await page.evaluate( () => document.documentElement.scrollWidth - document.documentElement.clientWidth );
	expect( overflow ).toBeLessThanOrEqual( 1 );
	const b = await box( page ).boundingBox();
	expect( b!.width ).toBeLessThanOrEqual( page.viewportSize()!.width );
	await group( page ).getByRole( 'button', { name: 'Reset to default' } ).scrollIntoViewIfNeeded();
	await expect( group( page ).getByRole( 'button', { name: 'Reset to default' } ) ).toBeInViewport();
	await shot( page, 'phone' );
} );

test( 'the live preview opens by itself on desktop, but not on phones, where it would cover the settings @mobile', async ( { page, isMobile } ) => {
	await open( page );
	await expect( page.locator( 'sigma-chat .da-launcher' ) ).toBeAttached();
	await page.waitForTimeout( 800 );
	if ( isMobile ) {
		await expect( page.locator( '#da-panel' ) ).toBeHidden();
		await expect( page.locator( 'sigma-chat .da-launcher' ) ).toBeVisible();
		await box( page ).click(); // the settings are reachable
		await page.locator( 'sigma-chat .da-launcher' ).click();
		await expect( page.locator( '#da-panel' ) ).toBeVisible();
	} else {
		await expect( page.locator( '#da-panel' ) ).toBeVisible();
	}
} );
