import { useCallback, useEffect, useState, type CSSProperties, type ReactNode } from 'react';
import { adminPage, errorText, get, post } from './api';
import { onBrandContrast, pairContrast, PALETTE_DEFAULTS } from './contrast';
import { LivePreview, pickedThumbs } from './Preview';
import { SetupScreen } from './SetupScreen';
import type { IconName } from './icons';
import type { CheckStep, Corpus, Settings, SettingsResponse, TeamMember } from './types';
import { AppNotices, Check, LoadError, MakerCard, Group, Icon, Notice, Row, Sections, Shell, SkeletonRows, Spinner, Toast, Toggle } from './ui';
import { createInterpolateElement } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

export type Draft = Omit< Partial< Settings >, 'anthropic_key' | 'openai_key' | 'smtp_password' | 'turnstile_secret' > & { anthropic_key?: string; openai_key?: string; smtp_password?: string; turnstile_secret?: string };
export type SetFn = < K extends keyof Draft >( key: K, value: Draft[ K ] ) => void;

const AI_STATUS: Record< string, string > = {
	'': __( 'Answering from your content.', 'sigma' ),
	ai_off: __( 'AI answers are switched off.', 'sigma' ),
	provider_not_allowed: __( 'The chosen provider is not available on this site.', 'sigma' ),
	no_sources: __( 'Add an active knowledge base entry (Sigma → Knowledge) or choose a post type (AI agent → Knowledge).', 'sigma' ),
	no_key: __( 'The AI provider has no API key (AI agent → Connection).', 'sigma' ),
};

type Tab = 'chat-box' | 'agent' | 'human';

/** Remy's icon (§8.8): one setting (`launcher_icon`) for the launcher, the teaser, the cards, the chat and the menu. */
const REMY_ICONS: { id: Exclude< NonNullable< Settings[ 'launcher_icon' ] >, 'image' >; label: string }[] = [
	{ id: 'assistant', label: __( 'Assistant', 'sigma' ) },
	{ id: 'remy', label: __( 'Bubble face', 'sigma' ) },
	{ id: 'chat', label: __( 'Chat', 'sigma' ) },
	{ id: 'sparkles', label: __( 'Sparkles', 'sigma' ) },
	{ id: 'help', label: __( 'Help', 'sigma' ) },
];
const TABS: { id: Tab; label: string; icon: IconName }[] = [
	{ id: 'chat-box', label: __( 'Chat box', 'sigma' ), icon: 'message-circle' },
	{ id: 'agent', label: __( 'AI agent', 'sigma' ), icon: 'sparkles-2' },
	{ id: 'human', label: __( 'Human support', 'sigma' ), icon: 'headset' },
];

/** #chat-box, #agent, #human or #setup; anything else is the first tab. */
function hashView(): Tab | 'setup' {
	const h = window.location.hash.replace( '#', '' );
	return h === 'setup' || TABS.some( ( t ) => t.id === h ) ? ( h as Tab | 'setup' ) : 'chat-box';
}

export function SettingsScreen() {
	const [ data, setData ] = useState< SettingsResponse | null >( null );
	const [ draft, setDraft ] = useState< Draft >( {} );
	const [ error, setError ] = useState( '' );
	const [ saved, setSaved ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ view, setView ] = useState( hashView() );
	// §7.7: "Try with real AI" in the live preview. Page state only, off on every load (it costs like a question).
	const [ realAi, setRealAi ] = useState( false );

	useEffect( () => {
		const onHash = () => {
			setView( hashView() );
			window.scrollTo( 0, 0 );
		};
		window.addEventListener( 'hashchange', onHash );
		return () => window.removeEventListener( 'hashchange', onHash );
	}, [] );

	// First paint uses the copy printed into the page (preloaded), so this resolves without a request.
	const [ attempt, setAttempt ] = useState( 0 );
	useEffect( () => {
		get< SettingsResponse >( '/admin/settings' )
			.then( setData )
			.catch( ( e ) => setError( errorText( e ) ) );
	}, [ attempt ] );
	const retry = () => {
		setError( '' );
		setAttempt( ( n ) => n + 1 );
	};

	const clearSaved = useCallback( () => setSaved( '' ), [] );

	if ( ! data ) {
		return <SettingsLoading error={ error } onRetry={ retry } />;
	}

	const { anthropic_key: _a, openai_key: _o, smtp_password: _p, turnstile_secret: _t, ...plain } = draft;
	const s: Settings = { ...data.settings, ...plain };
	const set: SetFn = ( key, value ) => setDraft( ( d ) => ( { ...d, [ key ]: value } ) );
	const dirty = Object.keys( draft ).length > 0;

	/** Saves the draft (or the given changes) and takes the new state from the answer. */
	const save = async ( changes: Draft = draft ): Promise< boolean > => {
		setBusy( true );
		setError( '' );
		try {
			const next = await post< SettingsResponse >( '/admin/settings', changes );
			setData( next );
			setDraft( {} );
			setSaved( __( 'Changes saved.', 'sigma' ) );
			return true;
		} catch ( e ) {
			setError( errorText( e ) );
			return false;
		} finally {
			setBusy( false );
		}
	};

	if ( view === 'setup' ) {
		return (
			<Shell topbar={ false }>
				<LivePreview s={ s } data={ data } visible={ false } />
				<SetupScreen data={ data } s={ s } draft={ draft } set={ set } save={ save } busy={ busy } error={ error } />
			</Shell>
		);
	}

	const tab = view;
	const props: TabProps = { data, s, set, setData, draft, dirty, busy, setBusy, setError, setSaved, realAi, setRealAi };
	const live = ! data.killed && ! data.settings.admins_only;

	return (
		<Shell>
			<LivePreview s={ s } data={ data } visible realAi={ realAi } instructions={ instructionsText( s, draft, data ) } />
			<div className="plugin__body">
				<AppNotices />
				{ error && <Notice kind="danger">{ error }</Notice> }
				<div className="columns">
					<div className="columns__main">
						<section className="card card--flush">
							<div className="card__header">
								<header className="page-header">
									<h2 className="page-header__title">{ __( 'Settings', 'sigma' ) }</h2>
									<p className="page-header__lead">{ __( 'The chat box on your site: an AI agent that answers from your content, and your team for the rest.', 'sigma' ) }</p>
								</header>
							</div>
							<nav className="tabs tabs--card tabs--nav tabs--sticky" aria-label={ __( 'Settings sections', 'sigma' ) }>
								{ TABS.map( ( t ) => (
									<a key={ t.id } className={ `tabs__tab${ t.id === tab ? ' tabs__tab--current' : '' }` } href={ `#${ t.id }` } aria-current={ t.id === tab ? 'page' : undefined }>
										<Icon name={ t.icon } />
										{ t.label }
									</a>
								) ) }
							</nav>
							<div className="card__body card__body--sections">
								{ tab === 'chat-box' && (
									<Notice kind={ data.killed ? 'warning' : 'info' }>
										<strong>{ data.killed ? __( 'The chat box is off.', 'sigma' ) : live ? __( 'The chat box is live for every visitor.', 'sigma' ) : __( 'The chat box shows to administrators only.', 'sigma' ) }</strong> { /* translators: %s: AI status sentence. */ sprintf( __( 'AI: %s', 'sigma' ), AI_STATUS[ data.ai_status ] ?? data.ai_status ) }
									</Notice>
								) }
								{ tab === 'chat-box' && <ChatBoxTab { ...props } /> }
								{ tab === 'agent' && <AgentTab { ...props } /> }
								{ tab === 'human' && <HumanTab { ...props } /> }
								<Notice kind="warning">{ __( 'The look and texts are part of every page. After you save, clear your page cache (Cloudflare or a cache plugin) so visitors see the change.', 'sigma' ) }</Notice>
							</div>
						</section>
					</div>
					<aside className="columns__aside" aria-label={ __( 'Sigma', 'sigma' ) }>
						<SetupCard data={ data } />
						<MakerCard />
					</aside>
				</div>
			</div>
			{ dirty && (
				<div className="save-bar">
					<span className="save-bar__message">
						<Icon name="alert-circle" />
						{ __( 'Unsaved changes', 'sigma' ) }
					</span>
					<div className="save-bar__actions">
						<button className="button" type="button" disabled={ busy } onClick={ () => setDraft( {} ) }>
							{ __( 'Discard', 'sigma' ) }
						</button>
						<button className="button button--primary" type="button" disabled={ busy } onClick={ () => void save() }>
							{ busy ? __( 'Saving…', 'sigma' ) : __( 'Save changes', 'sigma' ) }
						</button>
					</div>
				</div>
			) }
			{ saved && ! dirty && <Toast text={ saved } onDone={ clearSaved } /> }
		</Shell>
	);
}

interface TabProps {
	data: SettingsResponse;
	s: Settings;
	set: SetFn;
	setData: ( d: SettingsResponse ) => void;
	draft: Draft;
	dirty: boolean;
	busy: boolean;
	setBusy: ( b: boolean ) => void;
	setError: ( e: string ) => void;
	setSaved: ( t: string ) => void;
	realAi: boolean;
	setRealAi: ( on: boolean ) => void;
}

/* ------------------------------------------------------------------ Chat box */

function ChatBoxTab( { data, s, set, setData, busy, setBusy, setError, setSaved }: TabProps ) {

	const refreshRanges = async () => {
		setBusy( true );
		try {
			const cloudflare = await post< SettingsResponse[ 'cloudflare' ] >( '/admin/cloudflare-ranges/refresh' );
			setData( { ...data, cloudflare } );
			setSaved( /* translators: %s: number of IP ranges. */ sprintf( _n( 'Cloudflare ranges refreshed: %s range.', 'Cloudflare ranges refreshed: %s ranges.', cloudflare.count, 'sigma' ), cloudflare.count.toLocaleString() ) );
		} catch ( e ) {
			setError( errorText( e ) );
		} finally {
			setBusy( false );
		}
	};

	return (
		<Sections>
			<Group title={ __( 'Status', 'sigma' ) }>
				<Row label={ __( 'Chat box', 'sigma' ) } hint={ data.settings.killed_by_constant ? __( 'SIGMA_DISABLED is set in wp-config.php, so the chat box is off whatever this says.', 'sigma' ) : undefined }>
					<Toggle label={ __( 'Show the chat box', 'sigma' ) } hint={ __( 'Off hides the chat box everywhere and closes the visitor API.', 'sigma' ) } checked={ s.enabled } disabled={ data.settings.killed_by_constant } onChange={ ( v ) => set( 'enabled', v ) } />
				</Row>
				<Row label={ __( 'Testing', 'sigma' ) }>
					<Toggle label={ __( 'Show to admins only', 'sigma' ) } hint={ __( 'Only administrators see the chat box. Use it to test on a live site.', 'sigma' ) } checked={ s.admins_only } onChange={ ( v ) => set( 'admins_only', v ) } />
				</Row>
			</Group>

			<Group title={ __( 'Look', 'sigma' ) }>
				<Row label={ __( 'Name', 'sigma' ) } htmlFor="da-brand-name" hint={ __( 'Shown in the chat box header. Empty: the site title.', 'sigma' ) }>
					<input id="da-brand-name" className="field__input" type="text" maxLength={ 32 } value={ s.brand_name } onChange={ ( e ) => set( 'brand_name', e.target.value ) } />
				</Row>
				<LogoRow s={ s } set={ set } />
				<ColoursRow s={ s } set={ set } />
				<Row label={ __( 'Colour scheme', 'sigma' ) } htmlFor="da-scheme" hint={ __( 'Whether the chat box is light or dark. “Follow” matches your page\'s background, else the visitor\'s system setting.', 'sigma' ) }>
					<select id="da-scheme" className="field__select" value={ s.scheme } onChange={ ( e ) => set( 'scheme', e.target.value as Settings[ 'scheme' ] ) }>
						<option value="auto">{ __( 'Follow the page', 'sigma' ) }</option>
						<option value="light">{ __( 'Light', 'sigma' ) }</option>
						<option value="dark">{ __( 'Dark', 'sigma' ) }</option>
					</select>
				</Row>
				<Row label={ __( 'Font', 'sigma' ) } htmlFor="da-font">
					<select id="da-font" className="field__select" value={ s.font } onChange={ ( e ) => set( 'font', e.target.value as Settings[ 'font' ] ) }>
						<option value="inherit">{ __( 'The site\'s font', 'sigma' ) }</option>
						<option value="system">{ __( 'System font', 'sigma' ) }</option>
					</select>
				</Row>
			</Group>

			<Group title={ __( 'Launcher', 'sigma' ) }>
				<Row label={ __( 'Position', 'sigma' ) } htmlFor="da-position">
					<div className="setting__inline">
						<select id="da-position" className="field__select" value={ s.position } onChange={ ( e ) => set( 'position', e.target.value as Settings[ 'position' ] ) }>
							<option value="right">{ __( 'Bottom right', 'sigma' ) }</option>
							<option value="left">{ __( 'Bottom left', 'sigma' ) }</option>
						</select>
						<input className="field__input field__input--narrow" type="number" min={ 0 } max={ 200 } aria-label={ __( 'Offset from the side, px', 'sigma' ) } value={ s.offset_x } onChange={ ( e ) => set( 'offset_x', Number( e.target.value ) ) } />
						<input className="field__input field__input--narrow" type="number" min={ 0 } max={ 200 } aria-label={ __( 'Offset from the bottom, px', 'sigma' ) } value={ s.offset_y } onChange={ ( e ) => set( 'offset_y', Number( e.target.value ) ) } />
						<span className="setting__note">{ __( 'px from the side and bottom', 'sigma' ) }</span>
					</div>
				</Row>
				<Row label={ __( 'Pulse', 'sigma' ) }>
					<Toggle label={ __( 'Pulse the launcher', 'sigma' ) } hint={ __( 'A ring every 12 s until the chat is opened. Off for visitors who prefer less motion.', 'sigma' ) } checked={ s.launcher_pulse } onChange={ ( v ) => set( 'launcher_pulse', v ) } />
				</Row>
				<Row label={ __( 'Teaser', 'sigma' ) }>
					<Toggle label={ __( 'Show a teaser', 'sigma' ) } hint={ __( 'A short message above the launcher, once per visit, 3 s after the first page.', 'sigma' ) } checked={ s.teaser_enabled } onChange={ ( v ) => set( 'teaser_enabled', v ) } />
				</Row>
				{ s.teaser_enabled && <TextRow name="teaser_text" label={ __( 'Teaser text', 'sigma' ) } max={ 120 } s={ s } set={ set } defaults={ data.settings.text_defaults } help={ __( 'Emoji are fine.', 'sigma' ) } /> }
			</Group>

			<Group title={ __( 'Home screen', 'sigma' ) }>
				<TextRow name="greeting" label={ __( 'Greeting', 'sigma' ) } max={ 80 } s={ s } set={ set } defaults={ data.settings.text_defaults } />
				<TextRow name="intro" label={ __( 'Intro', 'sigma' ) } max={ 160 } s={ s } set={ set } defaults={ data.settings.text_defaults } />
			</Group>

			<Group title={ __( 'Data and privacy', 'sigma' ) }>
				<Row label={ __( 'Keep chats for', 'sigma' ) } htmlFor="da-retention">
					<div className="setting__inline">
						<input id="da-retention" className="field__input field__input--narrow" type="number" min={ 1 } max={ 3650 } value={ s.retention_days } onChange={ ( e ) => set( 'retention_days', Number( e.target.value ) ) } />
						<span className="setting__note">{ __( 'days', 'sigma' ) }</span>
					</div>
				</Row>
				<Row label={ __( 'Privacy page', 'sigma' ) } htmlFor="da-privacy-url">
					<input id="da-privacy-url" className="field__input" type="url" placeholder={ __( 'The site\'s privacy policy page', 'sigma' ) } value={ s.privacy_url } onChange={ ( e ) => set( 'privacy_url', e.target.value ) } />
				</Row>
				<Row label={ __( 'On delete', 'sigma' ) }>
					<Toggle label={ __( 'Delete chats and settings', 'sigma' ) } hint={ __( 'When the plugin is deleted. This cannot be undone.', 'sigma' ) } checked={ s.delete_on_uninstall } onChange={ ( v ) => set( 'delete_on_uninstall', v ) } />
				</Row>
			</Group>

			<DevPanel>
				<Row
					label={ __( 'Visitor IP from', 'sigma' ) }
					htmlFor="da-ip"
					hint={
						<>
							{ createInterpolateElement( __( 'Used for rate limits, never stored. Automatic reads CF-Connecting-IP only when the request comes from one of Cloudflare\'s own addresses, and the connection address otherwise, so it is safe with or without Cloudflare. Behind another proxy, a developer can pass the real IP with the <code>sigma_client_ip</code> filter.', 'sigma' ), { code: <code /> } ) }{ ' ' }
							{ data.cloudflare.source === 'refreshed'
								? /* translators: 1: number of IP ranges, 2: date. */ sprintf( _n( '%1$s Cloudflare range (refreshed %2$s).', '%1$s Cloudflare ranges (refreshed %2$s).', data.cloudflare.count, 'sigma' ), data.cloudflare.count.toLocaleString(), data.cloudflare.fetched_at )
								: /* translators: 1: number of IP ranges, 2: date. */ sprintf( _n( '%1$s Cloudflare range (bundled, %2$s).', '%1$s Cloudflare ranges (bundled, %2$s).', data.cloudflare.count, 'sigma' ), data.cloudflare.count.toLocaleString(), data.cloudflare.fetched_at ) }
						</>
					}
				>
					<select id="da-ip" className="field__select" value={ s.ip_source } onChange={ ( e ) => set( 'ip_source', e.target.value as Settings[ 'ip_source' ] ) }>
						<option value="auto">{ __( 'Automatic (recommended)', 'sigma' ) }</option>
						<option value="remote_addr">{ __( 'The connection (REMOTE_ADDR)', 'sigma' ) }</option>
						<option value="cloudflare">{ __( 'Cloudflare (CF-Connecting-IP)', 'sigma' ) }</option>
					</select>
					{ s.ip_source === 'remote_addr' && data.cloudflare.behind && (
						<Notice kind="warning">{ __( 'Requests reach this site through Cloudflare, so with this setting every visitor shares a few Cloudflare addresses and the rate limits hit everyone at once. Choose Automatic.', 'sigma' ) }</Notice>
					) }
					{ s.ip_source !== 'remote_addr' && data.cloudflare.stale && <Notice kind="warning">{ /* translators: %s: age in days. */ sprintf( _n( 'The ranges are %s day old. Refresh them.', 'The ranges are %s days old. Refresh them.', data.cloudflare.age_days, 'sigma' ), data.cloudflare.age_days.toLocaleString() ) }</Notice> }
					{ s.ip_source !== 'remote_addr' && (
						<div className="setting__inline">
							<button type="button" className="button" disabled={ busy } onClick={ refreshRanges }>
								{ __( 'Refresh ranges', 'sigma' ) }
							</button>
						</div>
					) }
				</Row>
			</DevPanel>
		</Sections>
	);
}

type ColourKey = 'color_bg_light' | 'color_bg_dark' | 'color_text_light' | 'color_text_dark' | 'color_light' | 'color_dark';

/**
 * The chat box's colours (#39 follow-up): Background, Text and Brand, per scheme. Only the scheme(s) the chat box
 * can show are listed: one column for Light or Dark, two for "Follow the page". Everything else in the panel
 * (tints, hovers, borders, muted text) is generated from these by the widget.
 */
function ColoursRow( { s, set }: { s: Settings; set: SetFn } ) {
	const schemes = s.scheme === 'light' ? ( [ 'light' ] as const ) : s.scheme === 'dark' ? ( [ 'dark' ] as const ) : ( [ 'light', 'dark' ] as const );
	const value = ( key: ColourKey, scheme: 'light' | 'dark', kind: 'bg' | 'text' | 'brand' ): string =>
		( s[ key ] as string | undefined ) || ( kind === 'brand' ? '' : PALETTE_DEFAULTS[ scheme ][ kind ] );
	const rows: { label: string; tip: string; kind: 'bg' | 'text' | 'brand'; key: ( sc: 'light' | 'dark' ) => ColourKey }[] = [
		{ label: __( 'Background', 'sigma' ), tip: __( 'The chat panel. Its tints, hovers and borders are worked out from this colour.', 'sigma' ), kind: 'bg', key: ( sc ) => `color_bg_${ sc }` as ColourKey },
		{ label: __( 'Text', 'sigma' ), tip: __( 'All text in the chat panel. Muted text is a lighter mix of it.', 'sigma' ), kind: 'text', key: ( sc ) => `color_text_${ sc }` as ColourKey },
		{ label: __( 'Brand', 'sigma' ), tip: __( 'The launcher, the main buttons and the visitor’s messages. The text on it turns black or white by itself.', 'sigma' ), kind: 'brand', key: ( sc ) => `color_${ sc }` as ColourKey },
	];
	// Compact contrast check per scheme: text on background (4.5:1), text on brand (4.5:1), brand against background (3:1).
	const issues = schemes
		.map( ( sc ) => {
			const bg = value( `color_bg_${ sc }` as ColourKey, sc, 'bg' );
			const brandColour = value( `color_${ sc }` as ColourKey, sc, 'brand' );
			const out: string[] = [];
			const text = pairContrast( value( `color_text_${ sc }` as ColourKey, sc, 'text' ), bg );
			if ( text < 4.5 ) out.push( /* translators: %s: contrast ratio. */ sprintf( __( 'text %s:1', 'sigma' ), text.toFixed( 1 ) ) );
			const onBrand = onBrandContrast( brandColour );
			if ( onBrand < 4.5 ) out.push( /* translators: %s: contrast ratio. */ sprintf( __( 'text on brand %s:1', 'sigma' ), onBrand.toFixed( 1 ) ) );
			const stand = pairContrast( brandColour, bg );
			if ( stand < 3 ) out.push( /* translators: %s: contrast ratio. */ sprintf( __( 'brand %s:1', 'sigma' ), stand.toFixed( 1 ) ) );
			return out.length ? /* translators: 1: Light or Dark, 2: list of low contrasts. */ sprintf( __( '%1$s: %2$s', 'sigma' ), sc === 'light' ? __( 'Light', 'sigma' ) : __( 'Dark', 'sigma' ), out.join( ', ' ) ) : '';
		} )
		.filter( Boolean );
	// Any colour off its default, in either scheme (hidden columns count too: reset covers both).
	const custom = ( [ 'light', 'dark' ] as const ).some(
		( sc ) => s[ `color_bg_${ sc }` as ColourKey ] || s[ `color_text_${ sc }` as ColourKey ] || ( s[ `color_${ sc }` as ColourKey ] || '' ).toLowerCase() !== PALETTE_DEFAULTS[ sc ].brand
	);
	return (
		<Row
			label={ __( 'Colours', 'sigma' ) }

		>
			<table className="table colour-table">
				<thead>
					<tr>
						<th className="table__head-cell">
							<span hidden>{ __( 'Colour', 'sigma' ) }</span>
						</th>
						{ schemes.map( ( sc ) => (
							<th key={ sc } className="table__head-cell">
								{ sc === 'light' ? __( 'Light', 'sigma' ) : __( 'Dark', 'sigma' ) }
							</th>
						) ) }
					</tr>
				</thead>
				<tbody>
					{ rows.map( ( r ) => (
						<tr key={ r.label } className="table__row">
							<th className="table__cell" scope="row">
								<span className="cluster">
									{ r.label }
									<span className="tooltip" data-tip={ r.tip } tabIndex={ 0 } aria-label={ r.tip } role="img">
										<Icon name="info-circle" />
									</span>
								</span>
							</th>
							{ schemes.map( ( sc ) => {
								const key = r.key( sc );
								const v = value( key, sc, r.kind );
								return (
									<td key={ sc } className="table__cell">
										<span className="cluster">
											<input type="color" aria-label={ /* translators: 1: colour name, 2: light or dark. */ sprintf( __( '%1$s, %2$s mode', 'sigma' ), r.label, sc === 'light' ? __( 'light', 'sigma' ) : __( 'dark', 'sigma' ) ) } value={ v } onChange={ ( e ) => set( key, e.target.value ) } />
											<span className="setting__note">{ v }</span>
										</span>
									</td>
								);
							} ) }
						</tr>
					) ) }
				</tbody>
			</table>
			{ issues.length > 0 && (
				<Notice kind="warning">
					<strong>{ __( 'Low contrast', 'sigma' ) }</strong> · { issues.join( ' · ' ) }
				</Notice>
			) }
			{ custom && (
				<div className="setting__inline">
					<button
						type="button"
						className="button button--link"
						onClick={ () => {
							for ( const sc of [ 'light', 'dark' ] as const ) {
								set( `color_bg_${ sc }` as ColourKey, '' );
								set( `color_text_${ sc }` as ColourKey, '' );
								set( `color_${ sc }` as ColourKey, PALETTE_DEFAULTS[ sc ].brand );
							}
						} }
					>
						{ __( 'Reset to defaults', 'sigma' ) }
					</button>
				</div>
			) }
		</Row>
	);
}

/* ---------------------------------------------------------------- AI agent */

function AgentTab( { data, s, set, setData, draft, busy, setBusy, setError, setSaved, realAi, setRealAi }: TabProps ) {
	const provider = s.provider;
	const keyField = provider === 'openai' ? 'openai_key' : 'anthropic_key';
	const keyStatus = provider === 'openai' ? data.settings.openai_key : data.settings.anthropic_key;
	const keyConstant = provider === 'openai' ? 'SIGMA_OPENAI_KEY' : 'SIGMA_ANTHROPIC_KEY';

	const rebuild = async () => {
		setBusy( true );
		try {
			const corpus = await post< Corpus >( '/admin/corpus/rebuild' );
			setData( { ...data, corpus: { ...corpus, rebuild_pending: true } } );
			setSaved( 'Rebuild started. It runs in the background as a logged-out visitor; reload in a minute to see the new index.' );
		} catch ( e ) {
			setError( errorText( e ) );
		} finally {
			setBusy( false );
		}
	};

	return (
		<Sections>
			{ data.ai_status && data.ai_status !== 'ai_off' && (
				<Notice kind="warning">
					<strong>{ __( 'Visitors don\'t see the agent yet.', 'sigma' ) }</strong> { AI_STATUS[ data.ai_status ] ?? data.ai_status } { __( 'Until then the chat box offers only “Talk to a human”, so the agent\'s name and avatar don\'t show.', 'sigma' ) }
				</Notice>
			) }
			<Group title={ __( 'Status', 'sigma' ) }>
				<Row label={ __( 'AI answers', 'sigma' ) }>
					<Toggle label={ __( 'Answer with AI', 'sigma' ) } hint={ __( 'The agent answers visitors from your content.', 'sigma' ) } checked={ s.ai_enabled } onChange={ ( v ) => set( 'ai_enabled', v ) } />
				</Row>
			</Group>

			<Group title={ __( 'Identity', 'sigma' ) }>
				<Row label={ __( 'Name', 'sigma' ) } htmlFor="da-bot-name" hint={ __( 'Shown in the chat with an “AI” badge, on its home card and in the teaser.', 'sigma' ) }>
					<input id="da-bot-name" className="field__input" type="text" maxLength={ 30 } placeholder="Sigma" value={ s.bot_name } onChange={ ( e ) => set( 'bot_name', e.target.value ) } />
				</Row>
				<RemyIconRow data={ data } s={ s } set={ set } />
			</Group>

			<Group title={ __( 'Voice input', 'sigma' ) }>
				<Row label={ __( 'Voice input (dictation)', 'sigma' ) }>
					<Toggle
						label={ __( 'Let visitors dictate their question', 'sigma' ) }
						hint={ __( 'Adds a microphone button to the AI chat. The visitor\'s browser turns speech into text, and nothing is sent until they press Send. In Chrome and Edge the audio is processed by the browser maker (Google or Microsoft); this site never receives it. Not available in Firefox.', 'sigma' ) }
						checked={ s.voice_input }
						onChange={ ( v ) => set( 'voice_input', v ) }
					/>
				</Row>
			</Group>

			<InstructionsGroup data={ data } draft={ draft } s={ s } set={ set } realAi={ realAi } setRealAi={ setRealAi } />

			<Group title={ __( 'Connection', 'sigma' ) }>
				<Row label={ __( 'Provider', 'sigma' ) } htmlFor="da-provider">
					<select id="da-provider" className="field__select field__input--wide" value={ provider } onChange={ ( e ) => set( 'provider', e.target.value as Settings[ 'provider' ] ) }>
						<option value="anthropic">{ __( 'Anthropic (Claude)', 'sigma' ) }</option>
						<option value="openai">{ __( 'OpenAI', 'sigma' ) }</option>
						{ data.settings.mock_allowed && <option value="mock">{ __( 'Test provider (no AI, local sites only)', 'sigma' ) }</option> }
					</select>
				</Row>
				{ provider !== 'mock' && (
					<>
						<ModelRow provider={ provider } s={ s } set={ set } />
						<Row label={ __( 'API key', 'sigma' ) } htmlFor="da-key" hint={ keyStatus.source === 'constant' ? undefined : __( 'Stored encrypted. It is never shown again or sent to the browser.', 'sigma' ) }>
							{ keyStatus.source === 'unreadable' && <Notice kind="warning">{ __( 'The saved key can\'t be read (the site\'s security keys changed). Paste it again.', 'sigma' ) }</Notice> }
							{ keyStatus.source === 'constant' ? (
								<p className="setting__note">{ /* translators: %s: the key's last 4 characters. */ sprintf( __( 'Set in wp-config.php (…%s).', 'sigma' ), keyStatus.last4 ) }</p>
							) : (
								<div className="setting__inline">
									<input id="da-key" className="field__input field__input--wide" type="password" autoComplete="off" placeholder={ keyStatus.set ? /* translators: %s: the key's last 4 characters. */ sprintf( __( 'Saved (…%s). Type to replace.', 'sigma' ), keyStatus.last4 ) : __( 'Paste the key', 'sigma' ) } value={ ( draft[ keyField ] as string ) ?? '' } onChange={ ( e ) => set( keyField, e.target.value ) } />
									{ keyStatus.set && (
										<button type="button" className="button button--link" onClick={ () => set( keyField, '' ) }>
											{ __( 'Remove saved key', 'sigma' ) }
										</button>
									) }
								</div>
							) }
						</Row>
					</>
				) }
				<Row label={ __( 'Streaming', 'sigma' ) } hint={ __( 'Shows whether answers can appear word by word on this server.', 'sigma' ) }>
					<StreamTest />
				</Row>
			</Group>

			<Group title={ __( 'Knowledge', 'sigma' ) }>
				<Row label={ __( 'Answer from', 'sigma' ) } hint={ __( 'The knowledge base, plus the published, public posts of the types you pick. Password-protected posts are left out.', 'sigma' ) }>
					<div className="stack stack--tight">
						{ /* §7.6: always on, Remy's base source; entries are managed under Sigma → Knowledge. */ }
						<Check
							label={ __( 'Knowledge base', 'sigma' ) }
							hint={ /* translators: %s: number of active entries. */ sprintf( _n( 'always on, %s active entry', 'always on, %s active entries', data.knowledge?.active ?? 0, 'sigma' ), ( data.knowledge?.active ?? 0 ).toLocaleString() ) }
							checked
							disabled
							onChange={ () => undefined }
						/>
						{ data.post_types.map( ( t ) => (
							<Check key={ t.name } label={ t.label } hint={ /* translators: 1: post type name, 2: number of published posts. */ sprintf( __( '%1$s, %2$d published', 'sigma' ), t.name, t.published ) } checked={ s.sources.includes( t.name ) } onChange={ ( on ) => set( 'sources', on ? [ ...s.sources, t.name ] : s.sources.filter( ( x ) => x !== t.name ) ) } />
						) ) }
					</div>
				</Row>
				<Row label={ __( 'Index', 'sigma' ) } hint={ __( 'It also rebuilds by itself after you publish or edit content.', 'sigma' ) }>
					<CorpusStatus corpus={ data.corpus } />
					<div className="setting__inline">
						<button type="button" className="button" disabled={ busy } onClick={ rebuild }>
							{ __( 'Rebuild now', 'sigma' ) }
						</button>
					</div>
				</Row>
			</Group>

			<Group title={ __( 'Texts', 'sigma' ) }>
				<TextRow name="bot_card_title" label={ __( 'Home card title', 'sigma' ) } max={ 40 } s={ s } set={ set } defaults={ data.settings.text_defaults } />
				<TextRow name="bot_card_text" label={ __( 'Home card text', 'sigma' ) } max={ 80 } s={ s } set={ set } defaults={ data.settings.text_defaults } />
				<TextRow name="bot_intro" label={ __( 'Intro message', 'sigma' ) } max={ 300 } long s={ s } set={ set } defaults={ data.settings.text_defaults } help={ __( 'The first message of every new chat. Never sent to the AI or stored.', 'sigma' ) } />
				<TextRow name="continue_card_title" label={ __( '“Continue” title', 'sigma' ) } max={ 40 } s={ s } set={ set } defaults={ data.settings.text_defaults } />
				<TextRow name="privacy_text_ai" label={ __( 'Privacy line', 'sigma' ) } max={ 200 } s={ s } set={ set } defaults={ data.settings.text_defaults } />
			</Group>

			<div className="card__well">
				{ createInterpolateElement( __( 'In the texts: <bot/> is the agent\'s name, <product/> the site name, <days/> how long chats are kept.', 'sigma' ), { bot: <code>{ '{bot_name}' }</code>, product: <code>{ '{product}' }</code>, days: <code>{ '{days}' }</code> } ) }
			</div>

			<Group title={ __( 'Pricing and cost', 'sigma' ) }>
				<Row label={ __( 'Pricing page', 'sigma' ) } htmlFor="da-pricing" hint={ __( 'The agent links it for pricing and billing questions and offers a human. Empty: it always offers a person.', 'sigma' ) }>
					<input id="da-pricing" className="field__input" type="url" placeholder="https://" value={ s.pricing_url } onChange={ ( e ) => set( 'pricing_url', e.target.value ) } />
				</Row>
				<Row label={ __( 'Usage (estimate)', 'sigma' ) } hint={ __( 'No spending cap in the plugin. Rate limits stop abuse; you can also set a monthly limit in the provider\'s console.', 'sigma' ) }>
					<table className="table">
						<thead>
							<tr>
								<th className="table__head-cell">{ __( 'Month', 'sigma' ) }</th>
								<th className="table__head-cell">{ __( 'Answers', 'sigma' ) }</th>
								<th className="table__head-cell">{ __( 'Cost', 'sigma' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ [ data.usage.this, data.usage.last ].map( ( u ) => (
								<tr key={ u.month } className="table__row">
									<td className="table__cell">{ u.month }</td>
									<td className="table__cell">{ u.calls }</td>
									<td className="table__cell">${ u.cost.toFixed( 2 ) }</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</Row>
			</Group>

			{ provider !== 'mock' && keyStatus.source !== 'constant' && (
				<DevPanel>
					<Row label={ __( 'Key in wp-config.php', 'sigma' ) } hint={ __( 'Optional. A key defined there replaces the one above.', 'sigma' ) }>
						<CopyField value={ keyConstant } />
					</Row>
				</DevPanel>
			) }
		</Sections>
	);
}

/**
 * Model (plan v1.17): a select of the provider's curated models (server list, newest first), each as
 * "label (id)". A stored model that isn't in the list (older or custom) stays selectable as an extra
 * option, so saving never changes it silently.
 */
function ModelRow( { provider, s, set }: { provider: 'anthropic' | 'openai'; s: Settings; set: SetFn } ) {
	const field = provider === 'openai' ? 'openai_model' : 'anthropic_model';
	const list = s.models?.[ provider ] ?? [];
	const fallback = list.find( ( m ) => m.default )?.id ?? list[ 0 ]?.id ?? '';
	const value = s[ field ] || fallback;
	const listed = list.some( ( m ) => m.id === value );
	return (
		<Row label={ __( 'Model', 'sigma' ) } htmlFor="da-model" hint={ __( 'Faster models answer sooner and cost less; the most capable ones are slower and cost more.', 'sigma' ) }>
			<select id="da-model" className="field__select field__input--wide" value={ value } onChange={ ( e ) => set( field, e.target.value ) }>
				{ list.map( ( m ) => (
					<option key={ m.id } value={ m.id }>
						{ m.default ? /* translators: 1: model name, 2: model id. */ sprintf( __( '%1$s (%2$s, default)', 'sigma' ), m.label, m.id ) : /* translators: 1: model name, 2: model id. */ sprintf( __( '%1$s (%2$s)', 'sigma' ), m.label, m.id ) }
					</option>
				) ) }
				{ ! listed && value ? <option value={ value }>{ /* translators: %s: model id. */ sprintf( __( '%s (current, not in the list)', 'sigma' ), value ) }</option> : null }
			</select>
		</Row>
	);
}

/** Chat box header logo: picked from the Media Library and stored as its URL (logo_url). None = no icon. */
function LogoRow( { s, set }: { s: Settings; set: SetFn } ) {
	const media = ( window as unknown as { wp?: { media?: ( o: object ) => MediaFrame } } ).wp?.media;
	const pick = () => {
		if ( ! media ) {
			return;
		}
		const frame = media( { title: __( 'Chat box logo', 'sigma' ), library: { type: 'image' }, multiple: false } );
		frame.on( 'select', () => {
			const a = frame.state().get( 'selection' ).first().toJSON();
			set( 'logo_url', a.url );
		} );
		frame.open();
	};
	return (
		<Row label={ __( 'Logo', 'sigma' ) } hint={ __( 'Shown next to the name in the chat box header, 24 px. Square images work best. None: just the name.', 'sigma' ) }>
			<div className="setting__inline">
				{ s.logo_url ? <img className="logo-preview" src={ s.logo_url } alt="" width={ 32 } height={ 32 } /> : <span className="badge">{ __( 'No logo', 'sigma' ) }</span> }
				{ media ? (
					<button type="button" className="button" onClick={ pick }>
						{ s.logo_url ? __( 'Change image', 'sigma' ) : __( 'Choose image', 'sigma' ) }
					</button>
				) : (
					<span className="setting__note">{ __( 'You need permission to upload files to choose an image.', 'sigma' ) }</span>
				) }
				{ s.logo_url ? (
					<button type="button" className="button button--link" onClick={ () => set( 'logo_url', '' ) }>
						{ __( 'Remove', 'sigma' ) }
					</button>
				) : null }
			</div>
		</Row>
	);
}

type MediaFrame = { on: ( event: string, cb: () => void ) => void; open: () => void; state: () => { get: ( k: string ) => { first: () => { toJSON: () => { id: number; url: string; sizes?: { thumbnail?: { url: string } } } } } } };

function RemyIconRow( { data, s, set }: { data: SettingsResponse; s: Settings; set: SetFn } ) {
	const [ preview, setPreview ] = useState( data.bot_avatar.preview );
	useEffect( () => setPreview( data.bot_avatar.preview ), [ data.bot_avatar.preview ] );
	const media = ( window as unknown as { wp?: { media?: ( o: object ) => MediaFrame } } ).wp?.media;
	const icon = s.launcher_icon || 'assistant';
	const pick = () => {
		if ( ! media ) {
			return;
		}
		const frame = media( { title: __( 'Bot icon', 'sigma' ), library: { type: 'image' }, multiple: false } );
		frame.on( 'select', () => {
			const a = frame.state().get( 'selection' ).first().toJSON();
			const thumb = a.sizes?.thumbnail?.url || a.url;
			pickedThumbs.set( a.id, thumb ); // The live preview shows it before Save.
			set( 'bot_avatar', a.id );
			set( 'launcher_icon', 'image' );
			setPreview( thumb );
		} );
		frame.open();
	};
	const unsaved = s.bot_avatar !== data.settings.bot_avatar;
	const image = s.bot_avatar ? preview || data.bot_avatar.url : '';
	let hint: string = __( 'Used everywhere the bot appears: the chat button, the teaser, the home cards, the chat and next to each answer, and the wp-admin menu.', 'sigma' );
	if ( 'image' === icon ) {
		hint = ! s.bot_avatar ? __( 'Choose an image from the Media Library. Square images work best.', 'sigma' ) : unsaved ? __( 'Save to make the copy the chat box uses (96×96, kept on this site).', 'sigma' ) : data.bot_avatar.message;
	}
	return (
		<Row label={ __( 'Bot icon', 'sigma' ) } hint={ hint }>
			<div className="setting__inline" role="radiogroup" aria-label={ __( 'Bot icon', 'sigma' ) }>
				{ REMY_ICONS.map( ( o ) => (
					<label key={ o.id } className="radio">
						<input className="radio__input" type="radio" name="da-remy-icon" checked={ icon === o.id } onChange={ () => set( 'launcher_icon', o.id ) } />
						<span className="cluster">
							<img className="avatar avatar--sm" src={ data.remy_icons[ o.id ] } alt="" width={ 24 } height={ 24 } />
							{ o.label }
						</span>
					</label>
				) ) }
				<label className="radio">
					<input
						className="radio__input"
						type="radio"
						name="da-remy-icon"
						checked={ icon === 'image' }
						onChange={ () => {
							set( 'launcher_icon', 'image' );
							if ( ! s.bot_avatar ) {
								pick();
							}
						} }
					/>
					<span className="cluster">
						{ image ? <img className="avatar avatar--sm" src={ image } alt="" width={ 24 } height={ 24 } /> : null }
						{ __( 'Upload image', 'sigma' ) }
					</span>
				</label>
				{ 'image' === icon ? (
					media ? (
						<button type="button" className="button" onClick={ pick }>
							{ s.bot_avatar ? __( 'Change image', 'sigma' ) : __( 'Choose image', 'sigma' ) }
						</button>
					) : (
						<span className="setting__note">{ __( 'You need permission to upload files to choose an image.', 'sigma' ) }</span>
					)
				) : null }
			</div>
		</Row>
	);
}

/**
 * "Test streaming" (§8.3): reads a canned 5-second stream from this server and compares when the
 * first part arrived with when the last did. Together means a proxy or the server buffers responses.
 */
function StreamTest() {
	const [ state, setState ] = useState< { kind: 'idle' | 'running' | 'ok' | 'buffered' | 'error'; text: string } >( { kind: 'idle', text: '' } );

	const run = async () => {
		setState( { kind: 'running', text: '' } );
		const started = performance.now();
		let first = 0;
		let parts = 0;
		try {
			const res = await fetch( `${ window.SigmaAdmin.restRoot }admin/stream-test`, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': window.SigmaAdmin.nonce, Accept: 'text/event-stream' },
			} );
			if ( ! res.ok || ! ( res.headers.get( 'content-type' ) || '' ).includes( 'text/event-stream' ) ) {
				throw new Error( /* translators: 1: HTTP status, 2: content type. */ sprintf( __( 'The server answered %1$s %2$s', 'sigma' ), String( res.status ), res.headers.get( 'content-type' ) || '' ) );
			}
			const reader = res.body!.getReader();
			const decoder = new TextDecoder();
			for ( ;; ) {
				const { done, value } = await reader.read();
				if ( done ) {
					break;
				}
				const chunk = decoder.decode( value, { stream: true } );
				const found = ( chunk.match( /event: delta/g ) || [] ).length;
				if ( found && ! first ) {
					first = performance.now() - started;
				}
				parts += found;
			}
			const total = performance.now() - started;
			/* translators: %s: seconds. */
			const sec = ( ms: number ) => sprintf( __( '%s s', 'sigma' ), ( ms / 1000 ).toFixed( 1 ) );
			if ( parts >= 10 && first < total - 2500 ) {
				setState( { kind: 'ok', text: /* translators: 1: time to the first part, 2: number of parts, 3: total time. */ sprintf( __( 'Streaming works: first part after %1$s, all %2$s parts after %3$s.', 'sigma' ), sec( first ), String( parts ), sec( total ) ) } );
			} else {
				setState( {
					kind: 'buffered',
					/* translators: 1: time to the first part, 2: total time. */
					text: sprintf( __( 'Your server or proxy buffers responses: answers will appear all at once. First part after %1$s, last after %2$s. Check: nginx "fastcgi_buffering off" or honouring X-Accel-Buffering; Apache mod_deflate or mod_proxy_fcgi flushpackets; LiteSpeed "X-LiteSpeed-Cache-Control: no-cache" and its response buffering; a Cloudflare rule that compresses or caches /wp-json/.', 'sigma' ), sec( first ), sec( total ) ),
				} );
			}
		} catch ( e ) {
			setState( { kind: 'error', text: /* translators: %s: error message. */ sprintf( __( 'The test could not run: %s', 'sigma' ), e instanceof Error ? e.message : String( e ) ) } );
		}
	};

	return (
		<>
			<div className="setting__inline">
				<button type="button" className="button" onClick={ run } disabled={ state.kind === 'running' }>
					{ state.kind === 'running' ? __( 'Testing…', 'sigma' ) : __( 'Test streaming', 'sigma' ) }
				</button>
				{ state.kind === 'running' ? <Spinner /> : <span className="setting__note">{ __( '5 seconds, no AI, no cost.', 'sigma' ) }</span> }
			</div>
			{ state.text && <Notice kind={ state.kind === 'ok' ? 'success' : 'warning' }>{ state.text }</Notice> }
		</>
	);
}

function CorpusStatus( { corpus }: { corpus: Corpus } ) {
	if ( ! corpus.built ) {
		return (
			<div className="setting__inline">
				<span className="badge">{ __( 'Not built yet', 'sigma' ) }</span>
			</div>
		);
	}
	return (
		<>
			<div className="setting__inline">
				<span className="badge badge--success">{ /* translators: %s: number of pages. */ sprintf( _n( '%s page', '%s pages', corpus.docs.length, 'sigma' ), corpus.docs.length.toLocaleString() ) }</span>
				<span className="badge">{ corpus.mode === 'retrieval' ? __( 'Retrieval', 'sigma' ) : __( 'Whole docs', 'sigma' ) }</span>
				{ corpus.rebuild_pending && <span className="badge badge--accent">{ __( 'Rebuild scheduled', 'sigma' ) }</span> }
			</div>
			<p className="setting__note">
				{ typeof corpus.tokens_exact === 'number'
					? /* translators: 1: tokens, 2: model, 3: tokens with room for the chat, 4: tokens allowed, 5: date. */ sprintf( __( '%1$s tokens with the rules (exact, %2$s), %3$s with room for the chat, of %4$s allowed. Built %5$s UTC.', 'sigma' ), corpus.tokens_exact.toLocaleString(), corpus.tokens_model ?? '', ( corpus.budget_tokens ?? 0 ).toLocaleString(), corpus.context_budget.toLocaleString(), corpus.built_at ?? '' )
					: /* translators: 1: tokens, 2: tokens with room for the chat, 3: tokens allowed, 4: date. */ sprintf( __( 'About %1$s tokens (estimate), %2$s with room for the chat, of %3$s allowed. Built %4$s UTC.', 'sigma' ), corpus.tokens.toLocaleString(), ( corpus.budget_tokens ?? 0 ).toLocaleString(), corpus.context_budget.toLocaleString(), corpus.built_at ?? '' ) }{ ' ' }
				{ corpus.mode === 'retrieval' ? __( 'The content is larger than the budget, so each question gets the best-matching sections and a map of every page.', 'sigma' ) : __( 'Every question gets all the content, cached between questions.', 'sigma' ) }
			</p>
			{ corpus.tokens_error && <Notice kind="warning">{ /* translators: %s: error message. */ sprintf( __( 'The exact token count failed: %s The estimate is used.', 'sigma' ), corpus.tokens_error ) }</Notice> }
		</>
	);
}

/* ----------------------------------------------------------- Human support */

function HumanTab( { data, s, set, setData, draft, dirty }: TabProps ) {
	return (
		<Sections>
			<Group title={ __( 'Status', 'sigma' ) }>
				<Row label={ __( 'Talk to a human', 'sigma' ) }>
					<Toggle label={ __( 'Let visitors reach the team', 'sigma' ) } hint={ __( 'Visitors can leave a message, and it is emailed to the team.', 'sigma' ) } checked={ s.human_enabled } onChange={ ( v ) => set( 'human_enabled', v ) } />
				</Row>
			</Group>

			<TeamGroup initial={ data.team } s={ s } set={ set } onTeam={ ( team ) => setData( { ...data, team } ) } />

			<Group title={ __( 'Handoff email', 'sigma' ) }>
				<Row label={ __( 'Send handoffs to', 'sigma' ) } htmlFor="da-support-email" hint={ __( 'Replies go to the visitor (Reply-To). Empty: the site admin email.', 'sigma' ) }>
					<input id="da-support-email" className="field__input" type="email" placeholder={ data.settings.support_email_effective } value={ s.support_email } onChange={ ( e ) => set( 'support_email', e.target.value ) } />
				</Row>
			</Group>

			<MailGroup data={ data } s={ s } draft={ draft } set={ set } dirty={ dirty } />

			<SpamGroup data={ data } s={ s } draft={ draft } set={ set } dirty={ dirty } />

			<Group title={ __( 'Texts', 'sigma' ) }>
				<TextRow name="human_card_title" label={ __( 'Home card title', 'sigma' ) } max={ 40 } s={ s } set={ set } defaults={ data.settings.text_defaults } />
				<TextRow name="human_reply_text" label={ __( 'Home card text', 'sigma' ) } max={ 40 } s={ s } set={ set } defaults={ data.settings.text_defaults } />
				<TextRow name="continue_human_title" label={ __( '“Continue” title', 'sigma' ) } max={ 40 } s={ s } set={ set } defaults={ data.settings.text_defaults } help={ __( 'For a chat sent to the team.', 'sigma' ) } />
				<TextRow name="privacy_text_human" label={ __( 'Privacy line', 'sigma' ) } max={ 200 } s={ s } set={ set } defaults={ data.settings.text_defaults } />
			</Group>
		</Sections>
	);
}

/**
 * Spam protection (§10b): optional Cloudflare Turnstile on the handoff form. The switch stays off until
 * both keys are saved and "Test keys" has accepted the secret.
 */
function SpamGroup( { data, s, set, draft, dirty }: Pick< TabProps, 'data' | 's' | 'set' | 'draft' | 'dirty' > ) {
	const [ test, setTest ] = useState< { ok: boolean; codes: string[] } | null >( null );
	const [ testing, setTesting ] = useState( false );
	const secret = s.turnstile_secret;
	const keysChanged = draft.turnstile_site_key !== undefined || ( draft.turnstile_secret ?? '' ) !== '';
	const run = async () => {
		setTesting( true );
		try {
			setTest( await post< { ok: boolean; codes: string[] } >( '/admin/turnstile/test' ) );
		} catch ( e ) {
			setTest( { ok: false, codes: [ errorText( e ) ] } );
		} finally {
			setTesting( false );
		}
	};
	const ready = !! data.settings.turnstile_site_key && secret.set && ( test?.ok ?? data.settings.turnstile_enabled );
	return (
		<Group title={ __( 'Spam protection', 'sigma' ) }>
			<Row label={ __( 'Cloudflare Turnstile', 'sigma' ) }>
				<Toggle
					label={ __( 'Protect the form with Cloudflare Turnstile', 'sigma' ) }
					hint={ ready || s.turnstile_enabled ? __( 'Visitors confirm they are human before the message is sent.', 'sigma' ) : __( 'Save both keys and test them first.', 'sigma' ) }
					checked={ s.turnstile_enabled }
					disabled={ ! s.turnstile_enabled && ( ! ready || keysChanged ) }
					onChange={ ( v ) => set( 'turnstile_enabled', v ) }
				/>
			</Row>
			<Row
				label={ __( 'Site key', 'sigma' ) }
				htmlFor="da-ts-site"
				hint={
					<>
						{ createInterpolateElement( __( 'In the <link>Cloudflare Turnstile dashboard</link>, create a widget for your domain and paste both keys.', 'sigma' ), { link: <a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank" rel="noreferrer" /> } ) }
					</>
				}
			>
				<input id="da-ts-site" className="field__input field__input--wide" type="text" autoComplete="off" spellCheck={ false } placeholder="0x…" value={ s.turnstile_site_key } onChange={ ( e ) => set( 'turnstile_site_key', e.target.value.trim() ) } />
			</Row>
			<Row label={ __( 'Secret key', 'sigma' ) } htmlFor="da-ts-secret" hint={ secret.source === 'constant' ? undefined : __( 'Stored encrypted. It is never shown again or sent to the browser. SIGMA_TURNSTILE_SECRET in wp-config.php overrides it.', 'sigma' ) }>
				{ secret.source === 'unreadable' && <Notice kind="warning">{ __( 'The saved secret can\'t be read (the site\'s security keys changed). Paste it again.', 'sigma' ) }</Notice> }
				{ secret.source === 'constant' ? (
					<p className="setting__note">{ /* translators: %s: the key's last 4 characters. */ sprintf( __( 'Set in wp-config.php (…%s).', 'sigma' ), secret.last4 ) }</p>
				) : (
					<input id="da-ts-secret" className="field__input field__input--wide" type="password" autoComplete="new-password" placeholder={ secret.set ? /* translators: %s: the key's last 4 characters. */ sprintf( __( 'Saved (…%s). Type to replace.', 'sigma' ), secret.last4 ) : __( 'Paste the secret key', 'sigma' ) } value={ draft.turnstile_secret ?? '' } onChange={ ( e ) => set( 'turnstile_secret', e.target.value ) } />
				) }
			</Row>
			<Row label={ __( 'Test', 'sigma' ) }>
				<div className="setting__inline">
					<button type="button" className="button" disabled={ testing || dirty || ! data.settings.turnstile_site_key || ! secret.set } onClick={ run }>
						{ testing ? __( 'Testing…', 'sigma' ) : __( 'Test keys', 'sigma' ) }
					</button>
					{ dirty && <span className="field__hint">{ __( 'Save first.', 'sigma' ) }</span> }
				</div>
				{ test && ( test.ok ? <Notice kind="success">{ __( 'Secret accepted.', 'sigma' ) }</Notice> : <Notice kind="danger">{ /* translators: %s: Cloudflare's error codes. */ sprintf( __( 'Cloudflare refused it: %s.', 'sigma' ), test.codes.join( ', ' ) || __( 'no reason given', 'sigma' ) ) }</Notice> ) }
			</Row>
			<Row label={ __( 'Appearance', 'sigma' ) } htmlFor="da-ts-look">
				<select id="da-ts-look" className="field__select" value={ s.turnstile_appearance } onChange={ ( e ) => set( 'turnstile_appearance', e.target.value as Settings[ 'turnstile_appearance' ] ) }>
					<option value="auto">{ __( 'Auto (follow the chat box)', 'sigma' ) }</option>
					<option value="light">{ __( 'Light', 'sigma' ) }</option>
					<option value="dark">{ __( 'Dark', 'sigma' ) }</option>
				</select>
			</Row>
		</Group>
	);
}

/**
 * Who answers "Talk to a human" (§8.4). Authors and above only; drag the handle or use
 * Move up / Move down to order (the first three show in the chat box). Saving grants them the reply capability.
 */
function TeamGroup( { initial, s, set, onTeam }: { initial: TeamMember[]; s: Settings; set: SetFn; onTeam: ( team: TeamMember[] ) => void } ) {
	const [ members, setMembers ] = useState< TeamMember[] >( initial );
	const [ search, setSearch ] = useState( '' );
	const [ results, setResults ] = useState< TeamMember[] >( [] );
	const [ dragging, setDragging ] = useState< number | null >( null );
	const [ refreshing, setRefreshing ] = useState( false );
	const [ refreshError, setRefreshError ] = useState( '' );
	const unsaved = members.map( ( m ) => m.id ).join( ',' ) !== initial.map( ( m ) => m.id ).join( ',' );

	useEffect( () => setMembers( initial ), [ initial ] );
	useEffect( () => {
		const t = window.setTimeout( () => {
			get< TeamMember[] >( `/admin/team/candidates?search=${ encodeURIComponent( search ) }` )
				.then( setResults )
				.catch( () => setResults( [] ) );
		}, 250 );
		return () => window.clearTimeout( t );
	}, [ search ] );

	const refreshAvatars = async () => {
		setRefreshing( true );
		setRefreshError( '' );
		try {
			onTeam( await post< TeamMember[] >( '/admin/team/refresh-avatars' ) );
		} catch ( e ) {
			setRefreshError( errorText( e ) );
		} finally {
			setRefreshing( false );
		}
	};

	const update = ( next: TeamMember[] ) => {
		setMembers( next );
		set( 'team', next.map( ( m ) => m.id ) );
	};
	const move = ( from: number, to: number ) => {
		if ( to < 0 || to >= members.length || from === to ) {
			return;
		}
		const next = [ ...members ];
		const [ m ] = next.splice( from, 1 );
		next.splice( to, 0, m );
		update( next );
	};
	const chosen = new Set( members.map( ( m ) => m.id ) );
	const candidates = results.filter( ( r ) => ! chosen.has( r.id ) ).slice( 0, 8 );

	return (
		<div className="stack">
			<h3 className="card__section-title">{ __( 'Team', 'sigma' ) }</h3>
			<p className="field__hint">{ __( 'Who answers “Talk to a human”. The first three show in the chat box, in this order: drag a row to change it. Each person sets their chat name and avatar in Users → Profile → Sigma.', 'sigma' ) }</p>
			{ members.length === 0 ? (
				<p className="setting__note">{ __( 'No one yet. Add Authors, Editors or Administrators below.', 'sigma' ) }</p>
			) : (
				<table className="table">
					<thead>
						<tr>
							<th className="table__head-cell">
								<span hidden>{ __( 'Order', 'sigma' ) }</span>
							</th>
							<th className="table__head-cell">{ __( 'Person', 'sigma' ) }</th>
							<th className="table__head-cell">{ __( 'Role', 'sigma' ) }</th>
							<th className="table__head-cell">{ __( 'Avatar', 'sigma' ) }</th>
							<th className="table__head-cell">
								<span hidden>{ __( 'Remove', 'sigma' ) }</span>
							</th>
						</tr>
					</thead>
					<tbody>
						{ members.map( ( m, i ) => (
							<tr
								key={ m.id }
								className="table__row"
								draggable
								onDragStart={ () => setDragging( i ) }
								onDragEnd={ () => setDragging( null ) }
								onDragOver={ ( e ) => e.preventDefault() }
								onDrop={ () => {
									if ( dragging !== null ) {
										move( dragging, i );
									}
									setDragging( null );
								} }
							>
								<td className="table__cell" aria-label={ /* translators: %s: person's name. */ sprintf( __( 'Drag to reorder %s', 'sigma' ), m.name ) }>
									<Icon name="grip-vertical" />
								</td>
								<td className="table__cell">
									<span className="cluster">
										<img className="avatar avatar--sm" src={ m.avatar } alt="" width={ 24 } height={ 24 } />
										<span>
											<strong>{ m.name }</strong> <span className="setting__note">{ /* translators: %s: chat name. */ sprintf( __( 'as “%s”', 'sigma' ), m.chat_name ) }</span>
										</span>
									</span>
								</td>
								<td className="table__cell table__cell--muted">{ m.role }</td>
								<td className="table__cell">
									<AvatarBadge m={ m } />
								</td>
								<td className="table__cell">
									<button type="button" className="button button--link" aria-label={ /* translators: %s: person's name. */ sprintf( __( 'Remove %s', 'sigma' ), m.name ) } onClick={ () => update( members.filter( ( x ) => x.id !== m.id ) ) }>
										{ __( 'Remove', 'sigma' ) }
									</button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
			{ members.length > 0 && (
				<div className="setting__inline">
					<button type="button" className="button" disabled={ refreshing || unsaved } onClick={ refreshAvatars }>
						{ refreshing ? __( 'Refreshing…', 'sigma' ) : __( 'Refresh avatars', 'sigma' ) }
					</button>
					<span className="setting__note">{ unsaved ? __( 'Save the team first.', 'sigma' ) : __( 'The chat box keeps its own copies. They also refresh on save and daily.', 'sigma' ) }</span>
				</div>
			) }
			{ refreshError && <Notice kind="danger">{ refreshError }</Notice> }
			<div className="settings">
				<Row label={ __( 'Add', 'sigma' ) } htmlFor="da-team-search">
					<div className="field">
						<input id="da-team-search" className="field__input field__input--search" type="search" placeholder={ __( 'Search Authors and above', 'sigma' ) } value={ search } onChange={ ( e ) => setSearch( e.target.value ) } />
					</div>
					{ candidates.length > 0 && (
						<table className="table">
							<tbody>
								{ candidates.map( ( r ) => (
									<tr key={ r.id } className="table__row">
										<td className="table__cell">
											<span className="cluster">
												<img className="avatar avatar--sm" src={ r.avatar } alt="" width={ 24 } height={ 24 } />
												{ r.name }
											</span>
										</td>
										<td className="table__cell table__cell--muted">{ r.role }</td>
										<td className="table__cell">
											<button type="button" className="button button--small" onClick={ () => update( [ ...members, r ] ) }>
												{ __( 'Add', 'sigma' ) }
											</button>
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					) }
				</Row>
				<Row label={ __( 'Team avatars', 'sigma' ) }>
					<Toggle label={ __( 'Show team avatars', 'sigma' ) } hint={ __( 'Pictures and first names next to “Talk to a human”. Only copies kept on this site, never Gravatar links.', 'sigma' ) } checked={ s.team_avatars } onChange={ ( v ) => set( 'team_avatars', v ) } />
				</Row>
			</div>
		</div>
	);
}

/**
 * The chat box's copy of a member's avatar: one badge. The detail (source, last refresh, reason)
 * shows only when something went wrong. When there is a copy, the browser loads it here too:
 * if that fails, the chat box cannot show it either.
 */
function AvatarBadge( { m }: { m: TeamMember } ) {
	const [ loadFailed, setLoadFailed ] = useState( false );
	useEffect( () => setLoadFailed( false ), [ m.avatar_copy ] );
	if ( m.avatar_message === undefined ) {
		return null;
	}
	const st = m.avatar_status;
	const bad = loadFailed || !! ( st && ( st.result === 'failed' || st.result === 'kept' ) );
	const label = bad ? __( 'Problem', 'sigma' ) : m.avatar_copy ? __( 'Picture', 'sigma' ) : st ? __( 'Initials', 'sigma' ) : __( 'Not made yet', 'sigma' );
	return (
		<span className="stack stack--tight">
			<span className="setting__inline">
				<span className={ bad ? 'badge badge--danger' : m.avatar_copy ? 'badge badge--success' : 'badge' }>{ label }</span>
			</span>
			{ bad && (
				<span className="setting__note">
					{ m.avatar_message }
					{ loadFailed ? ' ' + __( 'The image failed to load in the browser.', 'sigma' ) : '' }
				</span>
			) }
			{ m.avatar_copy ? <img src={ m.avatar_copy } alt="" width={ 1 } height={ 1 } hidden onError={ () => setLoadFailed( true ) } onLoad={ () => setLoadFailed( false ) } /> : null }
		</span>
	);
}

const STEP_LABELS: Record< CheckStep[ 'step' ], string > = {
	dns: 'DNS',
	tcp: __( 'TCP connect', 'sigma' ),
	greeting: __( 'Server greeting', 'sigma' ),
	tls: __( 'TLS and certificate', 'sigma' ),
	auth: __( 'Login (AUTH)', 'sigma' ),
};

function MailGroup( { data, s, draft, set, dirty }: { data: SettingsResponse; s: Settings; draft: Draft; set: SetFn; dirty: boolean } ) {
	const [ report, setReport ] = useState< CheckStep[] | null >( null );
	const [ message, setMessage ] = useState< { kind: 'success' | 'danger'; text: string } | null >( null );
	const [ running, setRunning ] = useState( '' );
	const smtp = s.mail_transport === 'smtp';
	const mail = data.mail;

	const check = async () => {
		setRunning( 'check' );
		setMessage( null );
		try {
			const r = await post< { ok: boolean; steps: CheckStep[] } >( '/admin/mail/check' );
			setReport( r.steps );
		} catch ( e ) {
			setMessage( { kind: 'danger', text: errorText( e ) } );
		} finally {
			setRunning( '' );
		}
	};
	const test = async () => {
		setRunning( 'test' );
		setMessage( null );
		try {
			const r = await post< { sent: boolean; to: string; status: { last_error: string } } >( '/admin/mail/test' );
			setMessage( r.sent ? { kind: 'success', text: /* translators: %s: email address. */ sprintf( __( 'Test email sent to %s.', 'sigma' ), r.to ) } : { kind: 'danger', text: /* translators: %s: mail error. */ sprintf( __( 'The test email failed: %s', 'sigma' ), r.status.last_error ) } );
		} catch ( e ) {
			setMessage( { kind: 'danger', text: errorText( e ) } );
		} finally {
			setRunning( '' );
		}
	};

	return (
		<Group title={ __( 'Sending email', 'sigma' ) }>
			<Row label={ __( 'Send with', 'sigma' ) } htmlFor="da-transport" hint={ __( 'SMTP applies to this plugin\'s emails only; other emails on the site are not affected.', 'sigma' ) }>
				<select id="da-transport" className="field__select" value={ s.mail_transport } onChange={ ( e ) => set( 'mail_transport', e.target.value as Settings[ 'mail_transport' ] ) }>
					<option value="php">{ __( 'WordPress mail (as the site is set up)', 'sigma' ) }</option>
					<option value="smtp">{ __( 'SMTP (built in)', 'sigma' ) }</option>
				</select>
			</Row>
			{ smtp && (
				<>
					<Row label={ __( 'Host', 'sigma' ) } htmlFor="da-smtp-host">
						<input id="da-smtp-host" className="field__input" type="text" placeholder="smtp.example.com" value={ s.smtp_host } onChange={ ( e ) => set( 'smtp_host', e.target.value ) } />
					</Row>
					<Row label={ __( 'Port and encryption', 'sigma' ) } htmlFor="da-smtp-port">
						<div className="setting__inline">
							<input id="da-smtp-port" className="field__input field__input--narrow" type="number" min={ 1 } max={ 65535 } value={ s.smtp_port } onChange={ ( e ) => set( 'smtp_port', Number( e.target.value ) ) } />
							<select className="field__select" aria-label={ __( 'Encryption', 'sigma' ) } value={ s.smtp_encryption } onChange={ ( e ) => set( 'smtp_encryption', e.target.value as Settings[ 'smtp_encryption' ] ) }>
								<option value="starttls">{ __( 'STARTTLS (usually 587)', 'sigma' ) }</option>
								<option value="ssl">{ __( 'SSL/TLS (usually 465)', 'sigma' ) }</option>
								<option value="none">{ __( 'None', 'sigma' ) }</option>
							</select>
						</div>
					</Row>
					<Row label={ __( 'Authentication', 'sigma' ) }>
						<Toggle label={ __( 'Use a username and password', 'sigma' ) } checked={ s.smtp_auth } onChange={ ( v ) => set( 'smtp_auth', v ) } />
					</Row>
					{ s.smtp_auth && (
						<>
							<Row label={ __( 'Username', 'sigma' ) } htmlFor="da-smtp-user">
								<input id="da-smtp-user" className="field__input" type="text" autoComplete="off" value={ s.smtp_username } onChange={ ( e ) => set( 'smtp_username', e.target.value ) } />
							</Row>
							<Row label={ __( 'Password', 'sigma' ) } htmlFor="da-smtp-pass" hint={ s.smtp_password.source === 'constant' ? undefined : __( 'Stored encrypted and never shown again. You can define SIGMA_SMTP_PASSWORD in wp-config.php instead.', 'sigma' ) }>
								{ s.smtp_password.source === 'constant' ? (
									<p className="setting__note">{ __( 'Set in wp-config.php (SIGMA_SMTP_PASSWORD).', 'sigma' ) }</p>
								) : (
									<input id="da-smtp-pass" className="field__input field__input--wide" type="password" autoComplete="new-password" placeholder={ s.smtp_password.set ? __( 'Saved. Type to replace.', 'sigma' ) : __( 'SMTP password or key', 'sigma' ) } value={ draft.smtp_password ?? '' } onChange={ ( e ) => set( 'smtp_password', e.target.value ) } />
								) }
							</Row>
						</>
					) }
					<Row label={ __( 'From', 'sigma' ) } htmlFor="da-from" hint={ __( 'Replies always go to the visitor (Reply-To).', 'sigma' ) }>
						<div className="setting__inline">
							<input id="da-from" className="field__input" type="email" placeholder="help@example.com" value={ s.mail_from_email } onChange={ ( e ) => set( 'mail_from_email', e.target.value ) } />
							<input className="field__input" type="text" aria-label={ __( 'From name', 'sigma' ) } placeholder={ __( 'Name', 'sigma' ) } value={ s.mail_from_name } onChange={ ( e ) => set( 'mail_from_name', e.target.value ) } />
						</div>
					</Row>
				</>
			) }
			{ data.settings.mail_warnings.length > 0 && (
				<Row label={ __( 'Check', 'sigma' ) }>
					{ data.settings.mail_warnings.map( ( w ) => (
						<Notice key={ w } kind="warning">
							{ w }
						</Notice>
					) ) }
				</Row>
			) }
			<Row label={ __( 'Test', 'sigma' ) } hint={ dirty ? __( 'Save the settings first: the tests use the saved settings.', 'sigma' ) : __( 'Uses the saved settings.', 'sigma' ) }>
				<div className="setting__inline">
					{ smtp && (
						<button type="button" className="button" disabled={ dirty || !! running } onClick={ check }>
							{ running === 'check' ? __( 'Testing…', 'sigma' ) : __( 'Test connection', 'sigma' ) }
						</button>
					) }
					<button type="button" className="button" disabled={ dirty || !! running } onClick={ test }>
						{ running === 'test' ? __( 'Sending…', 'sigma' ) : __( 'Send test email', 'sigma' ) }
					</button>
				</div>
				{ message && <Notice kind={ message.kind }>{ message.text }</Notice> }
				{ report && (
					<table className="table">
						<tbody>
							{ report.map( ( st ) => (
								<tr key={ st.step } className="table__row">
									<th className="table__head-cell" scope="row">
										{ STEP_LABELS[ st.step ] }
									</th>
									<td className="table__cell">
										<span className={ st.ok === false ? 'badge badge--danger' : st.ok ? 'badge badge--success' : 'badge' }>{ st.ok === null ? __( 'Skipped', 'sigma' ) : st.ok ? __( 'Pass', 'sigma' ) : __( 'Fail', 'sigma' ) }</span>
									</td>
									<td className="table__cell">
										<code>{ st.detail }</code>
										{ st.hint && <p className="field__hint">{ st.hint }</p> }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				) }
			</Row>
			<Row label={ __( 'Last send', 'sigma' ) }>
				<p className="setting__note">
					{ mail.last_success_at ? /* translators: %s: date and time. */ sprintf( __( 'Last success %s UTC.', 'sigma' ), mail.last_success_at ) : __( 'No email sent yet.', 'sigma' ) } { mail.last_error_at && /* translators: 1: date and time, 2: mail error. */ sprintf( __( 'Last error %1$s UTC: %2$s', 'sigma' ), mail.last_error_at, mail.last_error ) }
				</p>
				{ mail.consecutive_failures >= 3 && <Notice kind="danger">{ /* translators: %s: number of emails. */ sprintf( __( 'The last %s emails failed.', 'sigma' ), mail.consecutive_failures.toLocaleString() ) }</Notice> }
				{ mail.failed_handoffs > 0 && (
					<Notice kind="warning">
						{ createInterpolateElement( /* translators: %s: number of emails. */ sprintf( _n( '%s handoff email not sent: retry it in <link>Conversations</link>.', '%s handoff emails not sent: retry them in <link>Conversations</link>.', mail.failed_handoffs, 'sigma' ), mail.failed_handoffs.toLocaleString() ), { link: <a href={ adminPage( 'sigma-conversations' ) } /> } ) }
					</Notice>
				) }
			</Row>
		</Group>
	);
}

/* ----------------------------------------------------------------- Shared */

type TextName = 'human_card_title' | 'human_reply_text' | 'bot_card_title' | 'bot_card_text' | 'bot_intro' | 'teaser_text' | 'continue_card_title' | 'continue_human_title' | 'greeting' | 'intro' | 'privacy_text_ai' | 'privacy_text_human';

/** A chat box text with its default as the placeholder (empty: the default). */
function TextRow( { name, label, max, s, set, defaults, help, long }: { name: TextName; label: string; max: number; s: Settings; set: SetFn; defaults: Record< string, string >; help?: string; long?: boolean } ) {
	const id = `da-text-${ name }`;
	return (
		<Row label={ label } htmlFor={ id } hint={ ( help ? `${ help } ` : '' ) + /* translators: %s: most characters. */ sprintf( __( 'Empty: the default. Up to %s characters, plain text.', 'sigma' ), max.toLocaleString() ) }>
			{ long ? (
				<textarea id={ id } className="field__textarea" rows={ 3 } maxLength={ max } placeholder={ defaults[ name ] } value={ s[ name ] } onChange={ ( e ) => set( name, e.target.value ) } />
			) : (
				<input id={ id } className="field__input" type="text" maxLength={ max } placeholder={ defaults[ name ] } value={ s[ name ] } onChange={ ( e ) => set( name, e.target.value ) } />
			) }
		</Row>
	);
}

function DevPanel( { children }: { children: ReactNode } ) {
	return (
		<details className="panel">
			<summary className="panel__header">
				{ __( 'For developers', 'sigma' ) }
				<svg className="panel__chevron icon icon--sm" aria-hidden="true">
					<use href="#da-i-chevron-down" />
				</svg>
			</summary>
			<div className="panel__body">
				<div className="settings">{ children }</div>
			</div>
		</details>
	);
}

function CopyField( { value }: { value: string } ) {
	const [ copied, setCopied ] = useState( false );
	return (
		<div className="copy-field">
			<input className="field__input copy-field__input" type="text" readOnly value={ value } aria-label={ __( 'Constant name', 'sigma' ) } />
			<button
				className="copy-field__button button"
				type="button"
				onClick={ () => {
					void navigator.clipboard?.writeText( value ).then( () => setCopied( true ) );
				} }
			>
				{ copied ? __( 'Copied', 'sigma' ) : __( 'Copy', 'sigma' ) }
			</button>
		</div>
	);
}

/** Setup progress, on every tab. Each step links to the tab that finishes it. */
export function setupSteps( data: SettingsResponse ): { done: boolean; label: string; href: string }[] {
	const st = data.settings;
	const key = st.provider === 'openai' ? st.openai_key : st.anthropic_key;
	const steps: { done: boolean; label: string; href: string }[] = [ { done: st.enabled && ! data.killed, label: __( 'Chat box switched on', 'sigma' ), href: '#chat-box' } ];
	if ( st.ai_enabled ) {
		steps.push( { done: st.provider === 'mock' || key.set, label: __( 'Add the AI provider key', 'sigma' ), href: '#agent' } );
		steps.push( { done: st.sources.length > 0, label: __( 'Choose what the agent answers from', 'sigma' ), href: '#agent' } );
	}
	if ( st.human_enabled ) {
		steps.push( { done: true, label: /* translators: %s: email address. */ sprintf( __( 'Handoffs go to %s', 'sigma' ), st.support_email || st.support_email_effective ), href: '#human' } );
		steps.push( { done: !! data.mail.last_success_at, label: __( 'Send a test email', 'sigma' ), href: '#human' } );
	}
	steps.push( { done: ! st.admins_only, label: __( 'Show the chat box to everyone', 'sigma' ), href: '#chat-box' } );
	return steps;
}

function SetupCard( { data }: { data: SettingsResponse } ) {
	const steps = setupSteps( data );
	const done = steps.filter( ( x ) => x.done ).length;
	const pct = Math.round( ( 100 * done ) / steps.length );
	return (
		<section className="card">
			<div className="card__heading">
				<h3 className="card__title">{ __( 'Setup', 'sigma' ) }</h3>
				<p className="card__description">{ done === steps.length ? __( 'All done. The chat box is ready.', 'sigma' ) : __( 'Finish these to get the chat box ready.', 'sigma' ) }</p>
			</div>
			<div className="progress">
				<div className="progress__head">
					<span className="progress__label">
						{ /* translators: 1: steps done, 2: all steps. */ sprintf( __( '%1$d of %2$d done', 'sigma' ), done, steps.length ) }
					</span>
					<span className="progress__value">{ pct }%</span>
				</div>
				<div className="progress__track" role="progressbar" aria-valuenow={ pct } aria-valuemin={ 0 } aria-valuemax={ 100 } aria-label={ __( 'Setup progress', 'sigma' ) }>
					<div className="progress__bar" style={ { '--fill': `${ pct }%` } as CSSProperties } />
				</div>
			</div>
			<ul className="link-list">
				{ steps.map( ( x ) => (
					<li key={ x.label }>
						{ x.done ? (
							<span className="link-list__done">
								<Icon name="circle-check" />
								{ x.label }
							</span>
						) : (
							<a className="link-list__link" href={ x.href }>
								<Icon name="alert-circle" />
								{ x.label }
							</a>
						) }
					</li>
				) ) }
			</ul>
			<div className="card__footer">
				<a className="button" href="#setup">
					{ __( 'Run setup', 'sigma' ) }
				</a>
			</div>
		</section>
	);
}

/* ------------------------------------------------------------------ Instructions (§7.7) */

const INSTRUCTIONS_MAX = 4000;

const RULES_LEAD = __( 'The following system rules always apply and override anything above:', 'sigma' );
/** The protected text exactly as appended to the prompt: the server's text already starts with the lead line. */
const systemRulesText = ( rules: string ) => ( rules.trimStart().startsWith( RULES_LEAD ) ? rules.trim() : `${ RULES_LEAD }\n\n${ rules.trim() }` );

/** The default instructions: from the server (placeholders unfilled). */
const instructionsDefault = ( data: SettingsResponse ) => data.instructions_default ?? '';

/**
 * The text in the box: a draft as typed (even empty), else the saved text, else the default. The server stores
 * '' for empty or default text, and fills the placeholders when it answers.
 */
export function instructionsText( s: Settings, draft: Draft, data: SettingsResponse ): string {
	if ( Object.prototype.hasOwnProperty.call( draft, 'instructions' ) ) return draft.instructions ?? '';
	return s.instructions || instructionsDefault( data );
}

function InstructionsGroup( { data, draft, s, set, realAi, setRealAi }: { data: SettingsResponse; draft: Draft; s: Settings; set: SetFn; realAi: boolean; setRealAi: ( on: boolean ) => void } ) {
	const def = instructionsDefault( data );
	const max = data.instructions_max ?? INSTRUCTIONS_MAX;
	const text = instructionsText( s, draft, data );
	const custom = text.trim() !== '' && text.trim() !== def.trim();
	const n = text.length;
	return (
		<Group title={ __( 'Instructions', 'sigma' ) }>
			<div className="instructions stack">
				<div className="instructions__head">
					<label className="field__label" htmlFor="da-instructions">
						{ __( 'How the bot answers', 'sigma' ) }
					</label>
					{ custom ? <span className="badge badge--accent">{ __( 'Customised', 'sigma' ) }</span> : <span className="badge">{ __( 'Default', 'sigma' ) }</span> }
				</div>
				<p className="field__hint">
					{ createInterpolateElement( __( 'Who the agent is, what it talks about, its tone, length and language. Plain text. You can use <bot/>, <product/> and <pricing/>; they are filled in for each answer.', 'sigma' ), { bot: <code>{ '{bot_name}' }</code>, product: <code>{ '{product}' }</code>, pricing: <code>{ '{pricing_url}' }</code> } ) }
				</p>
				<textarea
					id="da-instructions"
					className="field__textarea field__textarea--code"
					rows={ 16 }
					maxLength={ max }
					spellCheck={ false }
					value={ text }
					aria-describedby="da-instructions-count"
					onChange={ ( e ) => set( 'instructions', e.target.value ) }
				/>
				<div className="instructions__foot">
					<span id="da-instructions-count" className={ `field__hint${ n >= max - 200 ? ' instructions__count--near' : '' }` } aria-live="polite">
						{ text.trim() === '' ? __( 'Empty: the default is used. ', 'sigma' ) : '' }
						{ /* translators: 1: characters used, 2: most characters. */ sprintf( __( '%1$s / %2$s characters', 'sigma' ), n.toLocaleString( 'en' ), max.toLocaleString( 'en' ) ) }
					</span>
					<button type="button" className="button button--small" disabled={ ! custom && text === def } onClick={ () => set( 'instructions', def ) }>
						{ __( 'Reset to default', 'sigma' ) }
					</button>
				</div>
				<details className="panel instructions__rules">
					<summary className="panel__header">
						{ __( 'System rules: always applied', 'sigma' ) }
						<svg className="panel__chevron icon icon--sm" aria-hidden="true">
							<use href="#da-i-chevron-down" />
						</svg>
					</summary>
					<div className="panel__body stack stack--tight">
						<p className="field__hint">{ __( 'They keep the answer markers, the doc links and the protection against prompt injection working. They\'re added after your instructions, override them, and can\'t be edited.', 'sigma' ) }</p>
						<pre className="instructions__system" tabIndex={ 0 } aria-label={ __( 'System rules (read-only)', 'sigma' ) }>
							{ systemRulesText( data.system_rules ?? '' ) }
						</pre>
					</div>
				</details>
				<Toggle
					label={ __( 'Try with real AI in the live preview', 'sigma' ) }
					hint={ __( 'Questions in the preview get answers from your AI provider with these instructions, saved or not. It costs like a normal question; up to 20 an hour', 'sigma' ) }
					checked={ realAi }
					onChange={ setRealAi }
				/>
			</div>
		</Group>
	);
}

/**
 * The frame while /admin/settings loads, the same as the skeleton PHP prints (Admin_Page::render): no layout
 * jump when the app takes over. A failure shows its reason and "Try again" instead of loading for ever.
 */
function SettingsLoading( { error, onRetry }: { error: string; onRetry: () => void } ) {
	return (
		<Shell>
			<div className="plugin__body">
				<AppNotices />
				<div className="columns">
					<div className="columns__main">
						<section className="card card--flush">
							<div className="card__header">
								<header className="page-header">
									<h2 className="page-header__title">{ __( 'Settings', 'sigma' ) }</h2>
									<p className="page-header__lead">{ __( 'The chat box on your site: an AI agent that answers from your content, and your team for the rest.', 'sigma' ) }</p>
								</header>
							</div>
							<nav className="tabs tabs--card tabs--nav" aria-label={ __( 'Settings sections', 'sigma' ) }>
								{ TABS.map( ( t ) => (
									<span key={ t.id } className="tabs__tab">
										<Icon name={ t.icon } />
										{ t.label }
									</span>
								) ) }
							</nav>
							<div className="card__body">{ error ? <LoadError message={ error } onRetry={ onRetry } /> : <SkeletonRows rows={ 8 } label={ __( 'Loading settings', 'sigma' ) } /> }</div>
						</section>
					</div>
					{ ! error && (
						<aside className="columns__aside" aria-hidden="true">
							<section className="card">
								<div className="card__body">
									<SkeletonRows rows={ 4 } label="" />
								</div>
							</section>
						</aside>
					) }
				</div>
			</div>
		</Shell>
	);
}
