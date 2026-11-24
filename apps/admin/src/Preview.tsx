/**
 * Settings: a live preview of the real visitor chat box, at the bottom corner of the screen exactly as on the site.
 *
 * Loads the site's own widget.js once, in preview mode (BootData.preview, apps/widget/src/types.ts): it opens
 * its panel, sends nothing and stores nothing. Every change on the screen is
 * sent to it as the window event "sigma:preview" with the /config shape the widget expects, built here
 * from the unsaved settings (placeholders filled in the way the server fills them).
 */
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { post } from './api';
import type { Settings, SettingsResponse } from './types';
import { __ } from '@wordpress/i18n';

type PreviewConfig = Record< string, any >; // eslint-disable-line @typescript-eslint/no-explicit-any -- the widget's /config shape, built here
type Win = Window & { SigmaWidget?: Record< string, unknown >; __daBooted?: 1 };

/**
 * Media Library thumbnails of images picked on this screen and not saved yet, by attachment id. The live
 * preview shows a newly picked Remy image at once with it (admin only; visitors only ever get the saved
 * local copy, §8.8).
 */
export const pickedThumbs = new Map< number, string >();

/** Whether the preview chat box has drawn its launcher. */
function launcherDrawn(): boolean {
	return !! document.querySelector( 'body > sigma-chat' )?.shadowRoot?.querySelector( '.da-launcher' );
}

/** The site title, from the admin page title ("… ‹ Site name — WordPress"). */
function siteTitle(): string {
	const m = document.title.match( /‹\s*(.+?)\s*—/ );
	return m ? m[ 1 ] : '';
}

/** The /config the widget would get from the server, from the settings as they are on screen. */
export function previewConfig( s: Settings, data: SettingsResponse ): PreviewConfig {
	const d = data.settings.text_defaults;
	const bot = s.bot_name.trim() || 'Sigma'; // The default name (0.2.5), a name: not translated.
	const product = s.brand_name.trim() || siteTitle();
	const fill = ( value: string, key: string ) =>
		( value.trim() || d[ key ] || '' ).replace( /\{bot_name\}/g, bot ).replace( /\{product\}/g, product ).replace( /\{name\}/g, product ).replace( /\{days\}/g, String( s.retention_days ) );
	const avatarSaved = s.bot_avatar === data.settings.bot_avatar;
	const team = s.team_avatars ? data.team.slice( 0, 4 ).map( ( m ) => ( { name: m.chat_name || m.name, avatar_url: m.avatar_copy || '' } ) ) : data.team.slice( 0, 4 ).map( ( m ) => ( { name: m.chat_name || m.name, avatar_url: '' } ) );
	return {
		enabled: s.enabled,
		ai_enabled: s.ai_enabled,
		human_enabled: s.human_enabled,
		greeting: fill( s.greeting, 'greeting' ),
		intro: fill( s.intro, 'intro' ),
		privacy_url: s.privacy_url,
		brand: {
			name: product,
			logo_url: s.logo_url || null,
			color_light: s.color_light,
			color_dark: s.color_dark,
			// Panel palette (#39 follow-up): empty = Delta's colours, so the widget generates nothing.
			bg_light: s.color_bg_light || '',
			bg_dark: s.color_bg_dark || '',
			text_light: s.color_text_light || '',
			text_dark: s.color_text_dark || '',
			launcher_icon: s.launcher_icon || 'assistant',
			scheme: s.scheme,
			position: s.position,
			offset_x: s.offset_x,
			offset_y: s.offset_y,
			font: s.font,
		},
		human_reply_text: fill( s.human_reply_text, 'human_reply_text' ),
		privacy_text_ai: fill( s.privacy_text_ai, 'privacy_text_ai' ),
		privacy_text_human: fill( s.privacy_text_human, 'privacy_text_human' ),
		confirmation_email: false,
		retention_days: s.retention_days,
		agents_online: 0,
		bot_name: bot,
		voice_input: s.voice_input !== false, // §19.4: on by default
		bot_avatar_url: avatarSaved ? ( data.bot_avatar.default ? '' : data.bot_avatar.url ) : pickedThumbs.get( s.bot_avatar ) || '',
		human_card_title: fill( s.human_card_title, 'human_card_title' ),
		bot_card_title: fill( s.bot_card_title, 'bot_card_title' ),
		bot_card_text: fill( s.bot_card_text, 'bot_card_text' ),
		bot_intro: fill( s.bot_intro, 'bot_intro' ),
		continue_card_title: fill( s.continue_card_title, 'continue_card_title' ),
		continue_human_title: fill( s.continue_human_title, 'continue_human_title' ),
		team,
		team_total: data.team.length,
	};
}

/** widget.js sits next to the admin build: build/admin/admin.js → build/widget/widget.js. */
function widgetUrl(): string {
	const own = document.querySelector< HTMLScriptElement >( 'script[src*="/build/admin/admin.js"]' );
	return own ? own.src.replace( /\/build\/admin\/admin\.js.*$/, '/build/widget/widget.js' ) : '';
}

/** Renders nothing itself: the widget draws the chat box in its own shadow root on the page. */
/** A /admin/preview/ask reply (§7.7): already link-filtered and marker-stripped by the server, like /ask. */
interface PreviewReply {
	body: string;
	links: { url: string; title: string }[];
	answered: boolean;
	handoff_requested: boolean;
	kb?: { id: number; title: string }[];
}

export function LivePreview( { s, data, visible, realAi = false, instructions = '' }: { s: Settings; data: SettingsResponse; visible: boolean; realAi?: boolean; instructions?: string } ) {
	const config: PreviewConfig = { ...previewConfig( s, data ), preview_real_ai: realAi };

	// §7.7 "Try with real AI": the widget's preview api calls this hook with the question. It sends the unsaved
	// instructions ('' = the default) and the last few turns. History restarts when the switch or the text changes.
	const latest = useRef( instructions );
	const history = useRef< { role: 'user' | 'assistant'; content: string }[] >( [] );
	latest.current = instructions;
	useEffect( () => {
		history.current = [];
	}, [ realAi, instructions ] );
	// Installed only while the switch is on, at once: the widget asks the real AI exactly when the hook exists.
	// A layout effect: installed in the same commit as the switch, before the browser handles the next key press.
	useLayoutEffect( () => {
		const w = window as unknown as { SigmaPreviewAsk?: ( q: string ) => Promise< PreviewReply > };
		if ( ! realAi ) {
			delete w.SigmaPreviewAsk;
			return;
		}
		w.SigmaPreviewAsk = async ( question: string ) => {
			const reply = await post< PreviewReply >( '/admin/preview/ask', { question, instructions: latest.current, history: history.current.slice( -6 ) } );
			history.current = [ ...history.current, { role: 'user' as const, content: question }, { role: 'assistant' as const, content: reply.body } ].slice( -6 );
			return reply;
		};
		return () => {
			delete w.SigmaPreviewAsk;
		};
	}, [ realAi ] );
	const json = JSON.stringify( config );

	// The slow part of the screen: the chat box script. The rest of the page is usable meanwhile; a small card in
	// the launcher's corner says the preview is on its way (or that it couldn't load), until the launcher is drawn.
	const [ status, setStatus ] = useState< 'loading' | 'ready' | 'failed' >( () => ( launcherDrawn() ? 'ready' : 'loading' ) );
	useEffect( () => {
		if ( status !== 'loading' ) return;
		const started = Date.now();
		const t = window.setInterval( () => {
			if ( launcherDrawn() ) setStatus( 'ready' );
			else if ( Date.now() - started > 20000 ) setStatus( 'failed' );
		}, 100 );
		return () => window.clearInterval( t );
	}, [ status ] );

	// Load the widget once, in preview mode.
	useEffect( () => {
		const w = window as Win;
		if ( w.__daBooted ) return;
		const src = widgetUrl();
		if ( ! src ) {
			setStatus( 'failed' );
			return;
		}
		w.SigmaWidget = {
			root: window.SigmaAdmin.restRoot,
			bot_name: config.bot_name,
			bot_avatar_url: config.bot_avatar_url,
			brand: config.brand,
			pulse: false,
			teaser: { enabled: false },
			i18n: window.SigmaAdmin.widgetI18n ?? {}, // The chat box's strings in the site's language (§19.3).
			preview: config,
		};
		// The open panel stops below Sigma's header (wp-admin bar + top bar), so on a short screen it never covers the
		// header's controls (the light/dark switcher). Set before the widget loads; it inherits into its shadow root.
		const header = document.querySelector( '.plugin__topbar' );
		if ( header ) document.documentElement.style.setProperty( '--da-panel-reserve', `${ Math.ceil( header.getBoundingClientRect().bottom + window.scrollY ) }px` );
		const script = document.createElement( 'script' );
		script.src = src;
		script.async = true;
		script.onerror = () => setStatus( 'failed' );
		document.body.appendChild( script );
	}, [] );

	// Every change: update the boot data (for a panel that loads later) and tell the widget.
	useEffect( () => {
		const w = window as Win;
		const t = window.setTimeout( () => {
			if ( w.SigmaWidget ) w.SigmaWidget.preview = config;
			window.dispatchEvent( new CustomEvent( 'sigma:preview', { detail: config } ) );
		}, 120 );
		return () => window.clearTimeout( t );
	}, [ json ] );

	// Keep the save bar's buttons out of the launcher's corner: launcher 56 px + its offset + 16 px space.
	useEffect( () => {
		const wrap = document.querySelector< HTMLElement >( '.sigma-admin' );
		if ( ! wrap ) return;
		const clear = visible ? `${ Math.max( 0, s.offset_x ) + 56 + 16 }px` : '0px';
		wrap.style.setProperty( '--preview-clear-right', s.position === 'left' ? '0px' : clear );
		wrap.style.setProperty( '--preview-clear-left', s.position === 'left' ? clear : '0px' );
	}, [ visible, s.position, s.offset_x ] );

	// Shown on the settings tabs only (not on first-run setup), and hidden while a wp-admin modal is open
	// (the Media Library adds body.modal-open): the chat box sits at the top z-index, as on the site.
	useEffect( () => {
		const apply = () => {
			const host = document.querySelector< HTMLElement >( 'body > sigma-chat' );
			if ( ! host ) return false;
			const show = visible && ! document.body.classList.contains( 'modal-open' );
			host.style.setProperty( 'display', show ? 'block' : 'none', 'important' );

			return true;
		};
		const mo = new MutationObserver( () => apply() );
		mo.observe( document.body, { attributes: true, attributeFilter: [ 'class' ] } );
		let t = 0;
		if ( ! apply() ) t = window.setInterval( () => apply() && window.clearInterval( t ), 200 );
		return () => {
			mo.disconnect();
			window.clearInterval( t );
		};
	}, [ visible ] );

	if ( ! visible || status === 'ready' ) return null;
	const side = s.position === 'left' ? 'left' : 'right';
	return (
		<div className={ `preview-loading card preview-loading--${ side }` } style={ { [ side ]: `${ Math.max( 0, s.offset_x ) }px`, bottom: `${ Math.max( 0, s.offset_y ) }px` } } role="status">
			{ status === 'loading' ? (
				<>
					<span className="spinner spinner--sm" aria-hidden="true" />
					{ __( 'Loading preview…', 'sigma' ) }
				</>
			) : (
				__( 'The chat box preview couldn\'t load. The settings still work; reload the page to try again.', 'sigma' )
			) }
		</div>
	);
}
