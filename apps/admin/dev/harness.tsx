/**
 * Test harness for the admin app (never shipped): supplies what WordPress gives the built admin.js on a real
 * screen, React as wp.element, ReactJSXRuntime, wp.apiFetch (here the in-memory mock) and SigmaAdmin,
 * then loads build/admin/admin.js exactly as built.
 */
import * as React from 'react';
import * as ReactDOMClient from 'react-dom/client';
import * as JSXRuntime from 'react/jsx-runtime';
import * as i18n from '@wordpress/i18n';
import { createInterpolateElement } from '@wordpress/element';
import { addFilter } from '@wordpress/hooks';
import apiFetch from '@wordpress/api-fetch';
import { mockApiFetch } from './mock-api';
import { TEST_LOCALE } from './test-locale';

const w = window as unknown as Record< string, unknown >;
// WordPress's own apiFetch (middlewares included), with the in-memory mock in place of the network.
apiFetch.setFetchHandler( ( options ) => mockApiFetch( options as { path?: string; method?: string; data?: unknown; headers?: Record< string, string > } ) );
w.wp = { element: { ...React, ...ReactDOMClient, createInterpolateElement }, apiFetch, i18n };
// Translations, as WordPress loads them: wp_set_script_translations() prints setLocaleData( <the JSON file>, 'sigma' )
// before admin.js. ?lang=de loads a small test dictionary; ?lang=pseudo brackets every translated string, so text that
// never went through __() stands out.
const lang = new URLSearchParams( location.search ).get( 'lang' );
if ( lang === 'de' ) i18n.setLocaleData( TEST_LOCALE, 'sigma' );
if ( lang === 'pseudo' ) {
	for ( const f of [ 'i18n.gettext_sigma', 'i18n.ngettext_sigma', 'i18n.gettext_with_context_sigma', 'i18n.ngettext_with_context_sigma' ] ) {
		addFilter( f, 'sigma/pseudo', ( t: string ) => `⟦${ t }⟧` );
	}
}
w.ReactJSXRuntime = JSXRuntime;
// adminUrl as WordPress sends it: admin_url( 'admin.php' ).
const q0 = new URLSearchParams( location.search );
w.SigmaAdmin = { canManage: true, adminUrl: `${ location.origin }/wp-admin/admin.php`, homeUrl: location.origin + '/', restRoot: '/wp-json/', nonce: 'test', botName: q0.get( 'bot' ) || 'Sigma', widgetI18n: q0.get( 'lang' ) === 'de' ? { 'Ask a question': 'Stellen Sie eine Frage' } : {} };

// ?notices=all: one of each notice PHP sends in SigmaAdmin.notices (sample texts for the harness).
if ( q0.get( 'notices' ) === 'all' ) {
	( w.SigmaAdmin as { notices: unknown[] } ).notices = [
		{ id: 'bot-renamed', tone: 'info', text: 'Your bot is now called Sigma (it was Remy). You can change the name under AI agent → Identity.', dismissible: true },
		{ id: 'build-stale', tone: 'danger', title: 'The plugin\'s built files are out of date.', text: 'If you installed Sigma from git, run `npm run build` in the plugin folder.', dismissible: true },
		{ id: 'turnstile-outage', tone: 'warning', text: 'Cloudflare Turnstile can\'t be reached, so messages to your team go through with stricter limits.', dismissible: true, action: { label: 'Spam protection', url: '/wp-admin/admin.php?page=sigma#human' } },
		{ id: 'legacy-constants', tone: 'warning', text: 'Please rename these constants in wp-config.php: `DELTA_ASSISTANT_ANTHROPIC_KEY` → `SIGMA_ANTHROPIC_KEY`.', dismissible: true },
		{ id: 'handoffs-failed', tone: 'warning', text: '2 handoff emails were not sent.', dismissible: true, action: { label: 'Conversations', url: '/wp-admin/admin.php?page=sigma-conversations' } },
		{ id: 'mail-failing', tone: 'danger', text: 'The last 3 emails failed.', dismissible: true, action: { label: 'Mail settings', url: '/wp-admin/admin.php?page=sigma#human' } },
	];
}

const q = new URLSearchParams( location.search );
const root = document.getElementById( 'sigma-admin' )!;
const screen = q.get( 'screen' ) || 'knowledge';
root.dataset.screen = screen;

// The screen's first requests, answered from the page as Admin_Page::preload_paths() + rest_preload_api_request do
// (?preload=0: no preload, every request goes to the "server").
const PRELOAD: Record< string, string > = {
	settings: '/sigma/v1/admin/settings',
	knowledge: '/sigma/v1/admin/knowledge?page=1&per_page=20&search=&status=all',
	conversations: '/sigma/v1/admin/conversations?page=1&per_page=20&search=&status=',
};
( async () => {
	if ( q.get( 'preload' ) !== '0' && PRELOAD[ screen ] ) {
		const body = await mockApiFetch( { path: PRELOAD[ screen ] } );
		apiFetch.use( apiFetch.createPreloadingMiddleware( { [ PRELOAD[ screen ] ]: { body, headers: {} } } ) );
		( window as unknown as { __kbCalls: unknown[] } ).__kbCalls.length = 0; // the page's own preload is not a request
	}
	const s = document.createElement( 'script' );
	s.src = '/build/admin/admin.js';
	document.body.appendChild( s );
} )();
