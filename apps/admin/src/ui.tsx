/**
 * Small React wrappers around Alpha markup (apps/admin/alpha, scoped in src/alpha.css).
 * Screens compose these; they add no styles of their own.
 */
import { Children, Fragment, useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { SPRITE, type IconName } from './icons';
import { post } from './api';
import { __ } from '@wordpress/i18n';

export function Icon( { name, size = 'sm' }: { name: IconName; size?: 'sm' | 'md' | 'lg' } ) {
	return (
		<svg className={ size === 'md' ? 'icon' : `icon icon--${ size }` } aria-hidden="true">
			<use href={ `#da-i-${ name }` } />
		</svg>
	);
}

const THEME_KEY = 'sigma-admin-theme';

function readTheme(): '' | 'dark' {
	try {
		return window.localStorage.getItem( THEME_KEY ) === 'dark' ? 'dark' : '';
	} catch {
		return '';
	}
}

/** The plugin frame: wrapper (scope + dark mode), icon sprite, top bar. */
export function Shell( { children, topbar = true }: { children: ReactNode; topbar?: boolean } ) {
	const [ theme, setTheme ] = useState< '' | 'dark' >( readTheme );
	// Give the page the same light/dark signal a site's switcher gives (<html data-theme>), so the live preview
	// chat box on "Follow the page" follows the admin's own mode. wp-admin's styles don't use this attribute.
	useEffect( () => {
		document.documentElement.setAttribute( 'data-theme', theme === 'dark' ? 'dark' : 'light' );
	}, [ theme ] );
	// While switching, the page's colours ease over (data-theme-switching, styles.css); never at load.
	const [ switching, setSwitching ] = useState( false );
	useEffect( () => {
		if ( ! switching ) return;
		const t = window.setTimeout( () => setSwitching( false ), 300 );
		return () => window.clearTimeout( t );
	}, [ switching, theme ] );
	const toggle = () => {
		const next = theme === 'dark' ? '' : 'dark';
		setSwitching( true );
		setTheme( next );
		try {
			window.localStorage.setItem( THEME_KEY, next );
		} catch {
			// Private mode: the choice lasts for this page only.
		}
	};
	return (
		<div className="sigma-admin" data-theme={ theme === 'dark' ? 'dark' : 'light' } data-theme-switching={ switching ? '' : undefined }>
			<div hidden aria-hidden="true">
				<svg dangerouslySetInnerHTML={ { __html: SPRITE } } />
			</div>
			{ topbar && (
				<header className="plugin__topbar">
					<h1 className="plugin__brand">
						<span className="cluster">
							{ window.SigmaAdmin?.remyIcon?.url ? (
								// Remy's icon (§8.8): the same one the chat box and the wp-admin menu show.
								<img className="brand-mark brand-mark--remy" src={ window.SigmaAdmin.remyIcon.url } alt="" width={ 24 } height={ 24 } />
							) : (
								<svg className="brand-mark" aria-hidden="true">
									<use href="#da-i-assistant" />
								</svg>
							) }
							{ __( 'Sigma', 'sigma' ) }
						</span>
					</h1>
					<div className="plugin__topbar-actions">
						{ /* Moon in light ("switch to dark"), sun in dark ("switch to light"): shown by CSS from data-theme,
						     so the PHP frame (Admin_Page::skeleton) and the app agree on the first paint. */ }
						<button className="plugin__theme-toggle" type="button" aria-label={ theme === 'dark' ? __( 'Switch to light look', 'sigma' ) : __( 'Switch to dark look', 'sigma' ) } onClick={ toggle }>
							<span className="theme-toggle__icon theme-toggle__icon--moon">
								<Icon name="moon" />
							</span>
							<span className="theme-toggle__icon theme-toggle__icon--sun">
								<Icon name="sun" />
							</span>
						</button>
					</div>
				</header>
			) }
			{ children }
		</div>
	);
}

/** Groups separated by a divider (the body carries card__body--sections). */
export function Sections( { children }: { children: ReactNode } ) {
	const items = Children.toArray( children ).filter( Boolean );
	return (
		<>
			{ items.map( ( item, i ) => (
				<Fragment key={ i }>
					{ i > 0 && <div className="card__divider" /> }
					{ item }
				</Fragment>
			) ) }
		</>
	);
}

export function Group( { title, children }: { title: string; children: ReactNode } ) {
	return (
		<div className="stack">
			<h3 className="card__section-title">{ title }</h3>
			<div className="settings">{ children }</div>
		</div>
	);
}

/** A settings row: label left, control right, hint below the control. */
export function Row( { label, htmlFor, hint, children }: { label: ReactNode; htmlFor?: string; hint?: ReactNode; children: ReactNode } ) {
	return (
		<div className="setting">
			{ htmlFor ? (
				<label className="setting__label" htmlFor={ htmlFor }>
					{ label }
				</label>
			) : (
				<div className="setting__label">{ label }</div>
			) }
			<div className="setting__control stack stack--tight">
				{ children }
				{ hint && <p className="field__hint">{ hint }</p> }
			</div>
		</div>
	);
}

/** Sentence case with a full stop, for hints shown on their own line. */
function sentence( text: string ): string {
	const t = text.charAt( 0 ).toUpperCase() + text.slice( 1 );
	return /[.!?]$/.test( t ) ? t : `${ t }.`;
}

/** BIOS toggle; a hint sits on its own line below the label, in muted text (toggle--hint-below). */
export function Toggle( { label, hint, checked, disabled, onChange }: { label: string; hint?: string; checked: boolean; disabled?: boolean; onChange: ( v: boolean ) => void } ) {
	return (
		<label className={ hint ? 'toggle toggle--hint-below' : 'toggle' }>
			<input className="toggle__input" type="checkbox" checked={ checked } disabled={ disabled } onChange={ ( e ) => onChange( e.target.checked ) } />
			<span className="toggle__track" aria-hidden="true" />
			<span>
				{ label }
				{ hint && <span className="toggle__hint">{ sentence( hint ) }</span> }
			</span>
		</label>
	);
}

export function Check( { label, hint, checked, onChange, disabled = false }: { label: string; hint?: string; checked: boolean; onChange: ( v: boolean ) => void; disabled?: boolean } ) {
	return (
		<label className="checkbox">
			<input className="checkbox__input" type="checkbox" checked={ checked } disabled={ disabled } onChange={ ( e ) => onChange( e.target.checked ) } />
			<span>
				{ label }
				{ hint && <span className="checkbox__hint"> — { hint }</span> }
			</span>
		</label>
	);
}

export type NoticeKind = 'info' | 'success' | 'warning' | 'danger';

export function Notice( { kind = 'info', children }: { kind?: NoticeKind; children: ReactNode } ) {
	return (
		// `inline`: wp-admin's common.js moves every div.notice present at page load up under the screen's first
		// heading (here, into the top bar) unless it has .inline (found on a live site, 0.2.0).
		<div className={ kind === 'info' ? 'notice inline' : `notice notice--${ kind } inline` } role={ kind === 'danger' ? 'alert' : 'status' }>
			{ children }
		</div>
	);
}

/** Transient result of an action (BIOS toast), gone after a few seconds. */
export function Toast( { text, onDone }: { text: string; onDone: () => void } ) {
	useEffect( () => {
		const t = window.setTimeout( onDone, 4000 );
		return () => window.clearTimeout( t );
	}, [ text, onDone ] );
	return (
		<div className="toast toast--success" role="status">
			<span className="toast__message">{ text }</span>
			<button className="toast__dismiss" type="button" aria-label={ __( 'Dismiss', 'sigma' ) } onClick={ onDone }>
				<Icon name="x" />
			</button>
		</div>
	);
}

/** Plain text with `backticks` as <code>: the only markup a notice's text may carry. React escapes the rest. */
function withCode( text: string ) {
	return text.split( /`([^`]+)`/ ).map( ( part, i ) => ( i % 2 ? <code key={ i }>{ part }</code> : part ) );
}

/**
 * The plugin's own notices (0.2.5): the bot rename, a stale build, the Turnstile outage, old constant names, failed
 * handoff emails, failing mail. PHP sends them in window.SigmaAdmin.notices; they render here, first in the page
 * body, aligned with the cards, in light and dark. wp-admin's admin_notices would put them in the header instead.
 * Dismissing hides it at once and tells the server (POST /admin/notices/{id}/dismiss).
 */
export function AppNotices() {
	// The build notice is printed by PHP in the page frame (it must show even when this app is old or missing);
	// when it is there, it is not drawn twice.
	const [ list, setList ] = useState< AppNotice[] >( () => ( window.SigmaAdmin?.notices ?? [] ).filter( ( n ) => ! ( n.id === 'build-stale' && document.getElementById( 'sigma-build-notice' ) ) ) );
	if ( ! list.length ) return null;
	const dismiss = ( id: string ) => {
		setList( ( l ) => l.filter( ( n ) => n.id !== id ) );
		if ( window.SigmaAdmin?.notices ) window.SigmaAdmin.notices = window.SigmaAdmin.notices.filter( ( n ) => n.id !== id ); // gone on other screens of this page too
		post( `/admin/notices/${ encodeURIComponent( id ) }/dismiss` ).catch( () => {} ); // hidden either way
	};
	return (
		<div className="app-notices stack stack--tight">
			{ list.map( ( n ) => (
				<Notice key={ n.id } kind={ n.tone }>
					<span className="app-notice" data-notice={ n.id }>
						<span className="app-notice__text">
							{ n.title && <strong>{ n.title } </strong> }
							{ withCode( n.text ) }
						</span>
						<span className="app-notice__actions">
							{ n.action && (
								<a className="button button--small" href={ n.action.url }>
									{ n.action.label }
								</a>
							) }
							{ n.dismissible && (
								<button type="button" className="app-notice__dismiss" aria-label={ __( 'Dismiss this notice', 'sigma' ) } onClick={ () => dismiss( n.id ) }>
									<Icon name="x" />
								</button>
							) }
						</span>
					</span>
				</Notice>
			) ) }
		</div>
	);
}

/** Placeholder rows (Alpha skeleton), the same as the page frame PHP prints before the app runs. */
export function SkeletonRows( { rows = 5, label }: { rows?: number; label?: string } ) {
	const widths = [ '', ' skeleton__line--half', '', ' skeleton__line--third', '' ];
	return (
		<div className="skeleton" role="status" aria-label={ label ?? __( 'Loading', 'sigma' ) }>
			{ Array.from( { length: rows }, ( _, i ) => (
				<span key={ i } className={ `skeleton__line${ widths[ i % widths.length ] }` } />
			) ) }
		</div>
	);
}

/** A failed or timed-out load: the reason and "Try again". Never a screen stuck on loading. */
export function LoadError( { message, onRetry }: { message: string; onRetry: () => void } ) {
	return (
		<Notice kind="danger">
			<span className="load-error">
				<span>{ message }</span>
				<button type="button" className="button button--small" onClick={ onRetry }>
					{ __( 'Try again', 'sigma' ) }
				</button>
			</span>
		</Notice>
	);
}

export function Spinner() {
	return <span className="spinner spinner--sm" role="status" aria-label={ __( 'Loading', 'sigma' ) } />;
}

/** The maker's card in every aside (§19.3 item 6): neutral, links to 21press and Delta. A plain card, no tint. */
export function MakerCard() {
	return (
		<section className="card">
			<div className="card__header card__header--stacked">
				<div className="card__icon card__icon--ink" aria-hidden="true">
					<Icon name="delta" size="lg" />
				</div>
				<div className="card__heading">
					<h3 className="card__title">{ __( 'Made by 21press', 'sigma' ) }</h3>
					<p className="card__description">{ __( 'Sigma is free and open source. We also make Delta, the WordPress framework for AI agents.', 'sigma' ) }</p>
				</div>
			</div>
			<div className="card__footer cluster">
				<a className="button button--primary" href="https://21press.com" target="_blank" rel="noopener noreferrer">
					21press.com<span className="screen-reader-text">{ ' ' }{ __( '(opens in a new tab)', 'sigma' ) }</span>
				</a>
				<a className="button" href="https://deltatheme.com" target="_blank" rel="noopener noreferrer">
					{ __( 'About Delta', 'sigma' ) }<span className="screen-reader-text">{ ' ' }{ __( '(opens in a new tab)', 'sigma' ) }</span>
				</a>
			</div>
		</section>
	);
}

const FOCUSABLE = __( 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])', 'sigma' );

/**
 * A modal dialog's focus: moved in on open (to [data-autofocus], else the first control), kept inside with Tab,
 * Escape closes, and focus goes back to what opened it.
 */
function useDialog( onClose: () => void ) {
	const ref = useRef< HTMLDivElement >( null );
	const close = useRef( onClose );
	close.current = onClose;
	useEffect( () => {
		const back = document.activeElement as HTMLElement | null;
		const root = ref.current;
		const first = root?.querySelector< HTMLElement >( '[data-autofocus]' ) || root?.querySelector< HTMLElement >( FOCUSABLE );
		first?.focus();
		const onKey = ( e: KeyboardEvent ) => {
			if ( e.key === 'Escape' ) {
				e.preventDefault();
				close.current();
				return;
			}
			if ( e.key !== 'Tab' || ! root ) return;
			const items = Array.from( root.querySelectorAll< HTMLElement >( FOCUSABLE ) ).filter( ( el ) => el.getClientRects().length > 0 );
			if ( ! items.length ) return;
			const a = items[ 0 ];
			const z = items[ items.length - 1 ];
			if ( e.shiftKey && document.activeElement === a ) {
				e.preventDefault();
				z.focus();
			} else if ( ! e.shiftKey && document.activeElement === z ) {
				e.preventDefault();
				a.focus();
			}
		};
		// Focus that lands outside (Safari's Tab skips links and buttons, so the Tab check above can miss the edge)
		// comes back to the first control.
		const onFocusIn = ( e: FocusEvent ) => {
			if ( root && e.target instanceof Node && ! root.contains( e.target ) ) ( root.querySelector< HTMLElement >( FOCUSABLE ) ?? root ).focus();
		};
		document.addEventListener( 'keydown', onKey );
		document.addEventListener( 'focusin', onFocusIn );
		return () => {
			document.removeEventListener( 'keydown', onKey );
			document.removeEventListener( 'focusin', onFocusIn );
			if ( back && document.contains( back ) ) back.focus();
		};
	}, [] );
	return ref;
}

/** Alpha side drawer (a modal dialog). `wide` is the plugin's drawer--wide for long Markdown. */
export function Drawer( { title, wide, onClose, footer, children }: { title: string; wide?: boolean; onClose: () => void; footer: ReactNode; children: ReactNode } ) {
	const ref = useDialog( onClose );
	const id = useId();
	return (
		<div
			className="drawer-overlay"
			ref={ ref }
			onMouseDown={ ( e ) => {
				if ( e.target === e.currentTarget ) onClose();
			} }
		>
			<aside className={ `drawer${ wide ? ' drawer--wide' : '' }` } role="dialog" aria-modal="true" aria-labelledby={ id }>
				<div className="drawer__header">
					<h3 className="drawer__title" id={ id }>
						{ title }
					</h3>
					<button className="drawer__close" type="button" aria-label={ __( 'Close', 'sigma' ) } onClick={ onClose }>
						<Icon name="x" size="md" />
					</button>
				</div>
				<div className="drawer__body">{ children }</div>
				<div className="drawer__footer">{ footer }</div>
			</aside>
		</div>
	);
}

/** Alpha confirm modal. */
export function Modal( { title, onClose, footer, children }: { title: string; onClose: () => void; footer: ReactNode; children: ReactNode } ) {
	const ref = useDialog( onClose );
	const id = useId();
	return (
		<div
			className="modal-overlay"
			ref={ ref }
			onMouseDown={ ( e ) => {
				if ( e.target === e.currentTarget ) onClose();
			} }
		>
			<div className="modal" role="dialog" aria-modal="true" aria-labelledby={ id }>
				<div className="modal__header">
					<h3 className="modal__title" id={ id }>
						{ title }
					</h3>
					<button className="modal__close" type="button" aria-label={ __( 'Close', 'sigma' ) } onClick={ onClose }>
						<Icon name="x" size="md" />
					</button>
				</div>
				<div className="modal__body">{ children }</div>
				<div className="modal__footer">{ footer }</div>
			</div>
		</div>
	);
}

/** Focus what was clicked before opening a dialog from it: Safari doesn't focus links and buttons on click, and the
 *  dialog returns focus to the element that had it. */
export const focusOpener = ( e: { currentTarget: EventTarget } ) => ( e.currentTarget as HTMLElement ).focus();
