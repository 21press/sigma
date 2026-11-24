import { useEffect, useState } from 'react';
import { adminPage, errorText, get, post } from './api';
import type { Conversation, Message } from './types';
import { AppNotices, LoadError, MakerCard, Icon, Notice, Shell, SkeletonRows } from './ui';
import { createInterpolateElement } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

interface ListResponse {
	items: Conversation[];
	total: number;
	pages: number;
}

// Live chat (waiting, active) is R2: the server keeps those states, but no 0.2.5 conversation is in them, so they
// have no filter or badge here.
const STATUS_LABELS: Record< string, string > = {
	'': __( 'All', 'sigma' ),
	open: __( 'AI only', 'sigma' ),
	emailed: __( 'Emailed to support', 'sigma' ),
	closed: __( 'Closed', 'sigma' ),
};

const STATUS_BADGE: Record< string, string > = {
	open: 'badge',
	emailed: 'badge badge--accent',
	closed: 'badge',
};

/** The uuid in the hash (#/<uuid>), so a transcript has a link (the handoff email uses it). */
function hashUuid(): string {
	const m = window.location.hash.match( /^#\/([0-9a-f-]{36})$/ );
	return m ? m[ 1 ] : '';
}

export function ConversationsScreen() {
	const [ uuid, setUuid ] = useState( hashUuid() );

	useEffect( () => {
		const onHash = () => setUuid( hashUuid() );
		window.addEventListener( 'hashchange', onHash );
		return () => window.removeEventListener( 'hashchange', onHash );
	}, [] );

	return <Shell>{ uuid ? <Transcript uuid={ uuid } /> : <List /> }</Shell>;
}

function StatusBadge( { c }: { c: Conversation } ) {
	return (
		<span className="setting__inline">
			<span className={ STATUS_BADGE[ c.status ] ?? 'badge' }>{ STATUS_LABELS[ c.status ] ?? c.status }</span>
			{ c.email_status === 'failed' && <span className="badge badge--danger">{ __( 'Email not sent', 'sigma' ) }</span> }
		</span>
	);
}

function List() {
	const [ page, setPage ] = useState( 1 );
	const [ status, setStatus ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ query, setQuery ] = useState( '' );
	const [ data, setData ] = useState< ListResponse | null >( null );
	const [ error, setError ] = useState( '' );
	const [ attempt, setAttempt ] = useState( 0 );

	useEffect( () => {
		const params = new URLSearchParams( { page: String( page ), per_page: '20', status, search: query } );
		setError( '' );
		get< ListResponse >( `/admin/conversations?${ params.toString() }` )
			.then( setData )
			.catch( ( e ) => setError( errorText( e ) ) );
	}, [ page, status, query, attempt ] );

	const filtered = status !== '' || query !== '';
	const settingsUrl = adminPage( 'sigma' );

	return (
		<div className="plugin__body plugin__body--full">
			<AppNotices />
			<section className="card card--flush">
				<div className="card__header">
					<header className="page-header">
						<h2 className="page-header__title">{ __( 'Conversations', 'sigma' ) }</h2>
						<p className="page-header__lead">
							{ createInterpolateElement( __( 'Read-only transcripts. Chats are deleted automatically after the period set in <settings>Settings</settings>.', 'sigma' ), {
								settings: window.SigmaAdmin.canManage ? <a href={ `${ settingsUrl }#chat-box` } /> : <span />,
							} ) }
						</p>
					</header>
				</div>
				<ul className="views" aria-label={ __( 'Filter by status', 'sigma' ) }>
					{ Object.entries( STATUS_LABELS ).map( ( [ value, label ] ) => (
						<li key={ value } className="views__item">
							<a
								className={ `views__view${ value === status ? ' views__view--current' : '' }` }
								href="#"
								aria-current={ value === status ? 'true' : undefined }
								onClick={ ( e ) => {
									e.preventDefault();
									setStatus( value );
									setPage( 1 );
								} }
							>
								{ label }
								{ value === status && data && <span className="views__count"> ({ data.total })</span> }
							</a>
						</li>
					) ) }
				</ul>
				{ error && (
					<LoadError
						message={ error }
						onRetry={ () => {
							setError( '' );
							setAttempt( ( n ) => n + 1 );
						} }
					/>
				) }
				{ data && data.total === 0 && ! filtered ? (
					<div className="empty-state">
						<div className="empty-state__icon" aria-hidden="true">
							<Icon name="inbox" size="lg" />
						</div>
						<h3 className="empty-state__title">{ __( 'No conversations yet', 'sigma' ) }</h3>
						<p className="empty-state__description">{ __( 'Chats show up here when people use the chat box. While “Show to admins only” is on, only administrators\' test chats appear.', 'sigma' ) }</p>
						{ window.SigmaAdmin.canManage && (
							<div className="empty-state__actions">
								<a className="button button--primary" href={ settingsUrl }>
									{ __( 'Check setup', 'sigma' ) }
								</a>
							</div>
						) }
					</div>
				) : (
					<div className="list-table">
						<div className="list-table__toolbar">
							<form
								className="list-table__group"
								onSubmit={ ( e ) => {
									e.preventDefault();
									setQuery( search );
									setPage( 1 );
								} }
							>
								<input className="field__input field__input--search" type="search" value={ search } onChange={ ( e ) => setSearch( e.target.value ) } placeholder={ __( 'Search conversations', 'sigma' ) } aria-label={ __( 'Search by email, name, page or text', 'sigma' ) } />
								<button type="submit" className="button">
									{ __( 'Search', 'sigma' ) }
								</button>
							</form>
							{ data && <span className="list-table__count">{ /* translators: %s: number of conversations. */ sprintf( _n( '%s item', '%s items', data.total, 'sigma' ), data.total.toLocaleString() ) }</span> }
						</div>
						<table className="list-table__table">
							<thead>
								<tr>
									<th className="list-table__head-cell">{ __( 'First question', 'sigma' ) }</th>
									<th className="list-table__head-cell">{ __( 'Visitor', 'sigma' ) }</th>
									<th className="list-table__head-cell">{ __( 'Status', 'sigma' ) }</th>
									<th className="list-table__head-cell">{ __( 'Messages', 'sigma' ) }</th>
									<th className="list-table__head-cell">{ __( 'Last activity (UTC)', 'sigma' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ ! data && ! error && (
									<tr className="list-table__row">
										<td className="list-table__cell" colSpan={ 5 }>
											<SkeletonRows rows={ 5 } label={ __( 'Loading conversations', 'sigma' ) } />
										</td>
									</tr>
								) }
								{ data && data.items.length === 0 && (
									<tr className="list-table__row">
										<td className="list-table__cell list-table__cell--muted" colSpan={ 5 }>
											{ __( 'No conversations match.', 'sigma' ) }
										</td>
									</tr>
								) }
								{ data?.items.map( ( c ) => (
									<tr key={ c.uuid } className={ `list-table__row${ c.email_status === 'failed' ? ' list-table__row--danger' : '' }` }>
										<td className="list-table__cell">
											<a className="list-table__primary" href={ `#/${ c.uuid }` }>
												{ c.first_question ? truncate( c.first_question, 90 ) : __( '(no question)', 'sigma' ) }
											</a>
										</td>
										<td className="list-table__cell list-table__cell--muted">{ c.visitor_email ? `${ c.visitor_name ?? '' } <${ c.visitor_email }>` : __( 'Anonymous', 'sigma' ) }</td>
										<td className="list-table__cell">
											<StatusBadge c={ c } />
										</td>
										<td className="list-table__cell">{ c.message_count ?? 0 }</td>
										<td className="list-table__cell list-table__cell--muted">{ c.updated_at }</td>
									</tr>
								) ) }
							</tbody>
						</table>
						{ data && data.pages > 1 && (
							<div className="list-table__toolbar list-table__toolbar--bottom">
								<span className="list-table__count">{ /* translators: %s: number of conversations. */ sprintf( _n( '%s item', '%s items', data.total, 'sigma' ), data.total.toLocaleString() ) }</span>
								<div className="list-table__pagination">
									<button className="button button--small" type="button" disabled={ page <= 1 } onClick={ () => setPage( page - 1 ) }>
										{ __( 'Newer', 'sigma' ) }
									</button>
									<span className="list-table__count">
										{ /* translators: 1: current page, 2: number of pages. */ sprintf( __( '%1$d of %2$d', 'sigma' ), page, data.pages ) }
									</span>
									<button className="button button--small" type="button" disabled={ page >= data.pages } onClick={ () => setPage( page + 1 ) }>
										{ __( 'Older', 'sigma' ) }
									</button>
								</div>
							</div>
						) }
					</div>
				) }
			</section>
		</div>
	);
}

function Transcript( { uuid }: { uuid: string } ) {
	const [ data, setData ] = useState< { conversation: Conversation; messages: Message[] } | null >( null );
	const [ error, setError ] = useState( '' );
	const [ retry, setRetry ] = useState< { kind: 'info' | 'success' | 'danger'; text: string } | null >( null );

	const retrySend = async () => {
		setRetry( { kind: 'info', text: __( 'Sending…', 'sigma' ) } );
		try {
			const r = await post< { sent: boolean; error: string } >( `/admin/conversations/${ uuid }/retry-email` );
			setRetry( r.sent ? { kind: 'success', text: __( 'Sent.', 'sigma' ) } : { kind: 'danger', text: /* translators: %s: the mail error. */ sprintf( __( 'Failed again: %s', 'sigma' ), r.error ) } );
			if ( r.sent && data ) {
				setData( { ...data, conversation: { ...data.conversation, email_status: 'sent' } } );
			}
		} catch ( e ) {
			setRetry( { kind: 'danger', text: errorText( e ) } );
		}
	};

	const [ attempt, setAttempt ] = useState( 0 );
	useEffect( () => {
		get< { conversation: Conversation; messages: Message[] } >( `/admin/conversations/${ uuid }` )
			.then( setData )
			.catch( ( e ) => setError( errorText( e ) ) );
	}, [ uuid, attempt ] );

	const c = data?.conversation;
	const title = data ? data.messages.find( ( m ) => m.sender === 'visitor' )?.body ?? __( '(no question)', 'sigma' ) : __( 'Conversation', 'sigma' );

	return (
		<div className="plugin__body">
			<AppNotices />
			<div className="columns">
				<div className="columns__main">
					<section className="card card--flush">
						<div className="card__header">
							<header className="page-header">
								<div className="page-header__breadcrumb">
									<a className="page-header__back" href="#" aria-label={ __( 'Back to conversations', 'sigma' ) }>
										<Icon name="chevron-left" />
									</a>
									<a href="#">{ __( 'Conversations', 'sigma' ) }</a>
									<span className="page-header__crumb-sep" aria-hidden="true">
										\
									</span>
									<span>{ truncate( title, 60 ) }</span>
								</div>
								<h2 className="page-header__title">{ truncate( title, 90 ) }</h2>
								<p className="page-header__lead">{ __( 'Read-only transcript.', 'sigma' ) }</p>
							</header>
						</div>
						<div className="card__body">
							{ error && (
								<LoadError
									message={ error }
									onRetry={ () => {
										setError( '' );
										setAttempt( ( n ) => n + 1 );
									} }
								/>
							) }
							{ ! data && ! error && <SkeletonRows rows={ 6 } label={ __( 'Loading the conversation', 'sigma' ) } /> }
							{ data && (
								<div className="chat chat--transcript">
									<div className="chat__log" role="log" aria-label={ __( 'Conversation', 'sigma' ) }>
										{ data.messages.map( ( m ) =>
											m.sender === 'system' ? (
												<Notice key={ m.id }>
													{ m.body } <span className="setting__note">{ /* translators: %s: date and time. */ sprintf( __( '%s UTC', 'sigma' ), m.created_at ) }</span>
												</Notice>
											) : (
												<div key={ m.id } className={ `chat__message${ m.sender === 'visitor' ? ' chat__message--user' : '' }` }>
													<div className="chat__body">
														<span className="chat__meta">
															{ m.sender === 'ai' ? window.SigmaAdmin?.botName?.trim() || SENDERS.ai : SENDERS[ m.sender ] }
															{ m.sender === 'ai' && (
																<>
																	{ ' ' }
																	<span className="badge badge--ai">
																		<Icon name="sparkles" />
																		{ __( 'AI', 'sigma' ) }
																	</span>
																</>
															) }{ ' ' }
															· { /* translators: %s: date and time. */ sprintf( __( '%s UTC', 'sigma' ), m.created_at ) }
															{ m.sender === 'ai' && m.meta.model && ` · ${ m.meta.model }${ typeof m.meta.cost === 'number' ? ` · $${ m.meta.cost.toFixed( 4 ) }` : '' }` }
														</span>
														{ /* Plain text: React escapes it. Model output never goes into innerHTML. */ }
														<div className="chat__bubble">{ m.body }</div>
														{ m.sender === 'ai' && Array.isArray( m.meta.kb ) && m.meta.kb.length > 0 && (
															// §7.6: the Knowledge entries this answer used, each opening its edit drawer.
															<span className="chat__meta chat__used">
																{ __( 'Used:', 'sigma' ) }{ ' ' }
																{ m.meta.kb.map( ( k, i ) => (
																	<span key={ k.id }>
																		{ i > 0 && ', ' }
																		{ window.SigmaAdmin.canManage ? (
																			<a href={ adminPage( 'sigma-knowledge', { edit: k.id } ) }>{ k.title }</a>
																		) : (
																			k.title
																		) }
																	</span>
																) ) }
															</span>
														) }
														{ m.sender === 'ai' && m.meta.answered === false && (
															<span className="cluster">
																<span className="badge badge--danger">{ __( 'Not in docs', 'sigma' ) }</span>
															</span>
														) }
													</div>
												</div>
											)
										) }
									</div>
								</div>
							) }
						</div>
					</section>
				</div>
				<aside className="columns__aside" aria-label={ __( 'Conversation details', 'sigma' ) }>
					{ c && (
						<section className="card">
							<div className="card__heading">
								<h3 className="card__title">{ __( 'Details', 'sigma' ) }</h3>
							</div>
							<dl className="facts">
								<div className="facts__item">
									<dt>{ __( 'Visitor', 'sigma' ) }</dt>
									<dd>{ c.visitor_email ? `${ c.visitor_name ?? '' } <${ c.visitor_email }>` : __( 'Anonymous (AI only)', 'sigma' ) }</dd>
								</div>
								<div className="facts__item">
									<dt>{ __( 'Status', 'sigma' ) }</dt>
									<dd>
										<StatusBadge c={ c } />
									</dd>
								</div>
								<div className="facts__item">
									<dt>{ __( 'Page', 'sigma' ) }</dt>
									<dd>
										{ c.page_url ? (
											<a href={ safeUrl( c.page_url ) } target="_blank" rel="noreferrer">
												{ c.page_url.replace( /^https?:\/\//i, '' ) }
											</a>
										) : (
											'—'
										) }
									</dd>
								</div>
								<div className="facts__item">
									<dt>{ __( 'Started', 'sigma' ) }</dt>
									<dd>{ shortTime( c.created_at ) }</dd>
								</div>
								{ c.emailed_at && (
									<div className="facts__item">
										<dt>{ __( 'Emailed', 'sigma' ) }</dt>
										<dd>{ shortTime( c.emailed_at ) }</dd>
									</div>
								) }
							</dl>
						</section>
					) }
					{ c && c.email_status === 'failed' && (
						<Notice kind="danger">
							{ __( 'The email to support was not sent.', 'sigma' ) }{ ' ' }
							{ window.SigmaAdmin.canManage && (
								<button type="button" className="button button--small" onClick={ retrySend } disabled={ retry?.kind === 'info' }>
									{ __( 'Retry send', 'sigma' ) }
								</button>
							) }
						</Notice>
					) }
					{ retry && <Notice kind={ retry.kind }>{ retry.text }</Notice> }
					<MakerCard />
				</aside>
			</div>
		</div>
	);
}

const SENDERS: Record< Message[ 'sender' ], string > = {
	visitor: __( 'Visitor', 'sigma' ),
	ai: __( 'Agent', 'sigma' ),
	agent: __( 'Support', 'sigma' ),
	system: __( 'System', 'sigma' ),
};

/** "2026-11-21 11:27:00" (UTC) → "2026-11-21 11:27 UTC". */
function shortTime( value: string ): string {
	return /* translators: %s: date and time. */ sprintf( __( '%s UTC', 'sigma' ), value.replace( /:\d{2}$/, '' ) );
}

function truncate( text: string, max: number ): string {
	return text.length > max ? text.slice( 0, max - 1 ) + '…' : text;
}

/** Only http(s) links leave the screen. */
function safeUrl( url: string ): string {
	return /^https?:\/\//i.test( url ) ? url : '#';
}
