// 0.2.5 (owner): live chat is R2. Conversations offers no "Waiting" or "Live" filter or badge; the server keeps those
// states, but no 0.2.5 conversation is in them.
import { expect, test } from '@playwright/test';

test( 'Conversations filters are exactly All, AI only, Emailed to support and Closed @mobile', async ( { page } ) => {
	await page.goto( '/?screen=conversations' );
	const views = page.getByRole( 'list', { name: 'Filter by status' } );
	await expect( views.getByRole( 'link' ).first() ).toBeVisible();
	await expect( views.getByRole( 'link' ) ).toHaveText( [ /^All/, /^AI only/, /^Emailed to support/, /^Closed/ ] );
	await expect( page.locator( '.list-table__primary' ).first() ).toBeVisible();
	await expect( page.locator( '.sigma-admin' ) ).not.toContainText( /\bWaiting\b|\bLive\b/ );
} );
