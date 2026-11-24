/**
 * Sigma → Knowledge (built to the design mockups): notes
 * Remy reads before it answers. A Alpha list-table with views, search and bulk actions; Edit, Add and Import in
 * side drawers; Delete in a confirm modal. Entries are plain Markdown, always shown as text (a textarea), never
 * rendered. Files are read in the browser; only their text is sent.
 */
import { useCallback, useEffect, useRef, useState, type DragEvent } from 'react';
import { del, errorField, errorText, get, post, put } from './api';
import {
	ACCEPT,
	MAX_BYTES,
	MAX_FILES,
	PROBLEM_TEXT,
	byteLength,
	countWords,
	formatWords,
	readKnowledgeFile,
	titleKey,
	type ReadFile,
} from './knowledge-files';
import type { KnowledgeEntry, KnowledgeImportResult, KnowledgeList } from './types';
import { AppNotices, Drawer, Icon, LoadError, Modal, Notice, Shell, SkeletonRows, Spinner, Toast, Toggle, focusOpener } from './ui';
import { __, _n, sprintf } from '@wordpress/i18n';

type Status = 'all' | 'active' | 'draft';
type Bulk = '' | 'activate' | 'draft' | 'delete';
type Panel = { kind: 'edit'; id: number } | { kind: 'new' } | { kind: 'import' } | null;

const VIEWS: { value: Status; label: string }[] = [
	{ value: 'all', label: __( 'All', 'sigma' ) },
	{ value: 'active', label: __( 'Active', 'sigma' ) },
	{ value: 'draft', label: __( 'Draft', 'sigma' ) },
];

const minute = ( t: string | null ) => ( t ? t.slice( 0, 16 ) : '' );
const day = ( t: string | null ) => ( t ? t.slice( 0, 10 ) : '' );
/* translators: %s: number of entries. */
const entries = ( n: number ) => sprintf( _n( '%s entry', '%s entries', n, 'sigma' ), n.toLocaleString() );

/** ?edit={id}: the deep link Conversations "Used:" opens. */
function editParam(): number {
	const v = Number( new URLSearchParams( window.location.search ).get( 'edit' ) );
	return Number.isInteger( v ) && v > 0 ? v : 0;
}
function clearEditParam() {
	const u = new URL( window.location.href );
	if ( ! u.searchParams.has( 'edit' ) ) return;
	u.searchParams.delete( 'edit' );
	window.history.replaceState( null, '', u.toString() );
}

export function KnowledgeScreen() {
	const [ status, setStatus ] = useState< Status >( 'all' );
	const [ search, setSearch ] = useState( '' );
	const [ query, setQuery ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState< KnowledgeList | null >( null );
	const [ error, setError ] = useState( '' );
	const [ selected, setSelected ] = useState< number[] >( [] );
	const [ bulk, setBulk ] = useState< Bulk >( '' );
	const [ panel, setPanel ] = useState< Panel >( () => ( editParam() ? { kind: 'edit', id: editParam() } : null ) );
	const [ deleting, setDeleting ] = useState< { ids: number[]; title?: string } | null >( null );
	const [ toast, setToast ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ tick, setTick ] = useState( 0 );

	const reload = useCallback( () => setTick( ( n ) => n + 1 ), [] );

	useEffect( () => {
		const params = new URLSearchParams( { status, search: query, page: String( page ), per_page: '20' } );
		setError( '' );
		get< KnowledgeList >( `/admin/knowledge?${ params.toString() }` )
			.then( ( d ) => {
				setData( d );
				setSelected( ( s ) => s.filter( ( id ) => d.items.some( ( e ) => e.id === id ) ) );
			} )
			.catch( ( e ) => setError( errorText( e ) ) );
	}, [ status, query, page, tick ] );

	const closePanel = () => {
		setPanel( null );
		clearEditParam();
	};

	async function setEntryStatus( e: KnowledgeEntry, next: 'active' | 'draft' ) {
		setBusy( true );
		try {
			await put( `/admin/knowledge/${ e.id }`, { status: next } );
			setToast( next === 'active' ? /* translators: %s: entry title. */ sprintf( __( '“%s” is active.', 'sigma' ), e.title ) : /* translators: %s: entry title. */ sprintf( __( '“%s” moved to draft.', 'sigma' ), e.title ) );
			reload();
		} catch ( err ) {
			setError( errorText( err ) );
		} finally {
			setBusy( false );
		}
	}

	async function applyBulk( action: Bulk, ids: number[] ) {
		if ( ! action || ! ids.length ) return;
		if ( action === 'delete' ) {
			setDeleting( { ids } );
			return;
		}
		setBusy( true );
		try {
			const r = await post< { done: number[]; failed: { id: number }[] } >( '/admin/knowledge/bulk', { action, ids } );
			setToast( action === 'activate' ? /* translators: %s: number of entries. */ sprintf( _n( '%s entry made active.', '%s entries made active.', r.done.length, 'sigma' ), r.done.length.toLocaleString() ) : /* translators: %s: number of entries. */ sprintf( _n( '%s entry moved to draft.', '%s entries moved to draft.', r.done.length, 'sigma' ), r.done.length.toLocaleString() ) );
			setSelected( [] );
			setBulk( '' );
			reload();
		} catch ( err ) {
			setError( errorText( err ) );
		} finally {
			setBusy( false );
		}
	}

	async function confirmDelete() {
		if ( ! deleting ) return;
		setBusy( true );
		try {
			if ( deleting.ids.length === 1 && deleting.title !== undefined ) {
				await del( `/admin/knowledge/${ deleting.ids[ 0 ] }` );
				setToast( /* translators: %s: entry title. */ sprintf( __( '“%s” deleted.', 'sigma' ), deleting.title ) );
			} else {
				const r = await post< { done: number[] } >( '/admin/knowledge/bulk', { action: 'delete', ids: deleting.ids } );
				setToast( /* translators: %s: number of entries. */ sprintf( _n( '%s entry deleted.', '%s entries deleted.', r.done.length, 'sigma' ), r.done.length.toLocaleString() ) );
			}
			setSelected( ( s ) => s.filter( ( id ) => ! deleting.ids.includes( id ) ) );
			setBulk( '' );
			setDeleting( null );
			if ( panel?.kind === 'edit' && deleting.ids.includes( panel.id ) ) closePanel();
			reload();
		} catch ( err ) {
			setError( errorText( err ) );
			setDeleting( null );
		} finally {
			setBusy( false );
		}
	}

	const counts = data?.counts;
	const empty = !! data && data.counts.all === 0;
	const items = data?.items ?? [];
	const allChecked = items.length > 0 && items.every( ( e ) => selected.includes( e.id ) );

	return (
		<Shell>
			<div className="plugin__body plugin__body--full">
				<AppNotices />
				<section className="card card--flush">
					<div className="card__header">
						<header className="page-header">
							<h2 className="page-header__title">{ __( 'Knowledge', 'sigma' ) }</h2>
							<p className="page-header__lead">{ __( 'Notes the agent reads before it answers: prices, policies, how-tos. Write them in Markdown, or import .md files.', 'sigma' ) }</p>
						</header>
						{ ! empty && (
							<div className="card__header-actions">
								<button className="button" type="button" onClick={ ( e ) => {
									focusOpener( e );
									setPanel( { kind: 'import' } );
								} }>
									{ __( 'Import files', 'sigma' ) }
								</button>
								<button className="button button--primary" type="button" onClick={ ( e ) => {
									focusOpener( e );
									setPanel( { kind: 'new' } );
								} }>
									{ __( 'Add entry', 'sigma' ) }
								</button>
							</div>
						) }
					</div>
					<div className="knowledge__notice">
						<Notice kind="warning">{ __( 'Active entries are sent to your AI provider with every question, and the bot can share anything in them with any visitor. Don\'t put passwords, internal contacts or anything private here.', 'sigma' ) }</Notice>
					</div>
					{ error && (
						<div className="knowledge__notice">
							<LoadError
								message={ error }
								onRetry={ () => {
									setError( '' );
									reload();
								} }
							/>
						</div>
					) }
					{ ! data && ! error && (
						<div className="knowledge__notice">
							<SkeletonRows rows={ 6 } label={ __( 'Loading entries', 'sigma' ) } />
						</div>
					) }
					{ empty ? (
						<div className="empty-state">
							<div className="empty-state__icon" aria-hidden="true">
								<Icon name="book" size="lg" />
							</div>
							<h3 className="empty-state__title">{ __( 'No knowledge yet', 'sigma' ) }</h3>
							<p className="empty-state__description">{ __( 'Add what the agent should know that your site pages do not say: prices, policies, opening hours. Write an entry, or import the .md files you already have.', 'sigma' ) }</p>
							<div className="empty-state__actions">
								<button className="button" type="button" onClick={ ( e ) => {
									focusOpener( e );
									setPanel( { kind: 'import' } );
								} }>
									{ __( 'Import files', 'sigma' ) }
								</button>
								<button className="button button--primary" type="button" onClick={ ( e ) => {
									focusOpener( e );
									setPanel( { kind: 'new' } );
								} }>
									{ __( 'Add entry', 'sigma' ) }
								</button>
							</div>
						</div>
					) : (
						data && (
							<>
								<ul className="views" aria-label={ __( 'Filter by status', 'sigma' ) }>
									{ VIEWS.map( ( v ) => (
										<li key={ v.value } className="views__item">
											<a
												className={ `views__view${ v.value === status ? ' views__view--current' : '' }` }
												href="#"
												aria-current={ v.value === status ? 'true' : undefined }
												onClick={ ( e ) => {
													e.preventDefault();
													setStatus( v.value );
													setPage( 1 );
													setSelected( [] );
												} }
											>
												{ v.label } <span className="views__count">({ counts ? counts[ v.value ] : 0 })</span>
											</a>
										</li>
									) ) }
								</ul>
								<div className="list-table list-table--flush">
									<div className="list-table__toolbar">
										<form
											className="list-table__group"
											onSubmit={ ( e ) => {
												e.preventDefault();
												void applyBulk( bulk, selected );
											} }
										>
											<select className="field__select" aria-label={ __( 'Bulk actions', 'sigma' ) } value={ bulk } onChange={ ( e ) => setBulk( e.target.value as Bulk ) }>
												<option value="">{ __( 'Bulk actions', 'sigma' ) }</option>
												<option value="activate">{ __( 'Make active', 'sigma' ) }</option>
												<option value="draft">{ __( 'Move to draft', 'sigma' ) }</option>
												<option value="delete">{ __( 'Delete', 'sigma' ) }</option>
											</select>
											<button className="button" type="submit" disabled={ busy || ! bulk || ! selected.length }>
												{ __( 'Apply', 'sigma' ) }
											</button>
										</form>
										<form
											className="list-table__group"
											role="search"
											onSubmit={ ( e ) => {
												e.preventDefault();
												setQuery( search.trim() );
												setPage( 1 );
												setSelected( [] );
											} }
										>
											<input className="field__input field__input--search" type="search" value={ search } onChange={ ( e ) => setSearch( e.target.value ) } placeholder={ __( 'Search knowledge', 'sigma' ) } aria-label={ __( 'Search titles and text', 'sigma' ) } />
											<button className="button" type="submit">
												{ __( 'Search', 'sigma' ) }
											</button>
										</form>
										<span className="list-table__count">{ entries( data.total ) }</span>
									</div>
									<div className="knowledge-table-wrap">
									<table className="list-table__table">
										<thead>
											<tr>
												<td className="list-table__check-cell">
													<input
														className="checkbox__input"
														type="checkbox"
														aria-label={ __( 'Select all', 'sigma' ) }
														checked={ allChecked }
														onChange={ ( e ) => setSelected( e.target.checked ? items.map( ( i ) => i.id ) : [] ) }
													/>
												</td>
												<th className="list-table__head-cell">{ __( 'Title', 'sigma' ) }</th>
												<th className="list-table__head-cell">{ __( 'Source', 'sigma' ) }</th>
												<th className="list-table__head-cell">{ __( 'Length', 'sigma' ) }</th>
												<th className="list-table__head-cell">{ __( 'Status', 'sigma' ) }</th>
												<th className="list-table__head-cell">{ __( 'Updated (UTC)', 'sigma' ) }</th>
											</tr>
										</thead>
										<tbody>
											{ items.length === 0 && (
												<tr className="list-table__row">
													<td className="list-table__cell" colSpan={ 6 }>
														{ query ? /* translators: %s: search text. */ sprintf( __( 'No entries match “%s”.', 'sigma' ), query ) : status === 'active' ? __( 'No active entries.', 'sigma' ) : __( 'No drafts.', 'sigma' ) }
													</td>
												</tr>
											) }
											{ items.map( ( e ) => (
												<tr key={ e.id } className="list-table__row">
													<td className="list-table__check-cell">
														<input
															className="checkbox__input"
															type="checkbox"
															aria-label={ /* translators: %s: entry title. */ sprintf( __( 'Select %s', 'sigma' ), e.title ) }
															checked={ selected.includes( e.id ) }
															onChange={ ( ev ) => setSelected( ( s ) => ( ev.target.checked ? [ ...s, e.id ] : s.filter( ( id ) => id !== e.id ) ) ) }
														/>
													</td>
													<td className="list-table__cell">
														<a
															className="list-table__primary"
															href={ `?page=sigma-knowledge&edit=${ e.id }` }
															onClick={ ( ev ) => {
																ev.preventDefault();
																focusOpener( ev );
																setPanel( { kind: 'edit', id: e.id } );
															} }
														>
															{ e.title }
														</a>
														<div className="list-table__actions">
															<a
																className="list-table__action"
																href="#"
																aria-label={ /* translators: %s: entry title. */ sprintf( __( 'Edit %s', 'sigma' ), e.title ) }
																onClick={ ( ev ) => {
																	ev.preventDefault();
																	focusOpener( ev );
																	setPanel( { kind: 'edit', id: e.id } );
																} }
															>
																{ __( 'Edit', 'sigma' ) }
															</a>
															<span className="list-table__actions-sep">|</span>
															<a
																className="list-table__action"
																href="#"
																aria-label={ e.status === 'active' ? /* translators: %s: entry title. */ sprintf( __( 'Move to draft: %s', 'sigma' ), e.title ) : /* translators: %s: entry title. */ sprintf( __( 'Make active: %s', 'sigma' ), e.title ) }
																onClick={ ( ev ) => {
																	ev.preventDefault();
																	if ( ! busy ) void setEntryStatus( e, e.status === 'active' ? 'draft' : 'active' );
																} }
															>
																{ e.status === 'active' ? __( 'Move to draft', 'sigma' ) : __( 'Make active', 'sigma' ) }
															</a>
															<span className="list-table__actions-sep">|</span>
															<a
																className="list-table__action list-table__action--danger"
																href="#"
																aria-label={ /* translators: %s: entry title. */ sprintf( __( 'Delete %s', 'sigma' ), e.title ) }
																onClick={ ( ev ) => {
																	ev.preventDefault();
																	focusOpener( ev );
																	setDeleting( { ids: [ e.id ], title: e.title } );
																} }
															>
																{ __( 'Delete', 'sigma' ) }
															</a>
														</div>
													</td>
													<td className="list-table__cell list-table__cell--muted">{ e.source || __( 'Written here', 'sigma' ) }</td>
													<td className="list-table__cell">{ formatWords( e.words ) }</td>
													<td className="list-table__cell">{ e.status === 'active' ? <span className="badge badge--success">{ __( 'Active', 'sigma' ) }</span> : <span className="badge">{ __( 'Draft', 'sigma' ) }</span> }</td>
													<td className="list-table__cell list-table__cell--muted">{ minute( e.updated_at ) }</td>
												</tr>
											) ) }
										</tbody>
									</table>
									</div>
									<div className="list-table__toolbar list-table__toolbar--bottom">
										<span className="list-table__count knowledge__footer">
											{ data.counts.active > 0
												? /* translators: 1: number of active entries, 2: their word count, e.g. "1,200 words". */ sprintf( _n( 'The agent uses the %1$s active entry · %2$s', 'The agent uses the %1$s active entries · %2$s', data.counts.active, 'sigma' ), data.counts.active.toLocaleString(), formatWords( data.active_words ) )
												: __( 'The agent uses no entries yet. Make one active to use it.', 'sigma' ) }
										</span>
										{ data.pages > 1 && (
											<div className="list-table__pagination">
												<button className="button button--small" type="button" disabled={ page <= 1 } onClick={ () => setPage( page - 1 ) }>
													{ __( 'Previous', 'sigma' ) }
												</button>
												<span className="list-table__count">
													{ /* translators: 1: current page, 2: number of pages. */ sprintf( __( '%1$d of %2$d', 'sigma' ), page, data.pages ) }
												</span>
												<button className="button button--small" type="button" disabled={ page >= data.pages } onClick={ () => setPage( page + 1 ) }>
													{ __( 'Next', 'sigma' ) }
												</button>
											</div>
										) }
									</div>
								</div>
							</>
						)
					) }
				</section>
			</div>
			{ toast && (
				<div className="knowledge__toast">
					<Toast text={ toast } onDone={ () => setToast( '' ) } />
				</div>
			) }
			{ panel?.kind === 'edit' && (
				<EntryDrawer
					key={ `edit-${ panel.id }` }
					id={ panel.id }
					onClose={ closePanel }
					onSaved={ ( t ) => {
						setToast( t );
						closePanel();
						reload();
					} }
					onDelete={ ( e ) => setDeleting( { ids: [ e.id ], title: e.title } ) }
				/>
			) }
			{ panel?.kind === 'new' && (
				<EntryDrawer
					key="new"
					onClose={ closePanel }
					onSaved={ ( t ) => {
						setToast( t );
						closePanel();
						reload();
					} }
				/>
			) }
			{ panel?.kind === 'import' && (
				<ImportDrawer
					onClose={ closePanel }
					onDone={ ( t, close ) => {
						setToast( t );
						if ( close ) closePanel();
						reload();
					} }
				/>
			) }
			{ deleting && (
				<Modal
					title={ deleting.title !== undefined ? __( 'Delete this entry?', 'sigma' ) : /* translators: %s: number of entries. */ sprintf( _n( 'Delete %s entry?', 'Delete %s entries?', deleting.ids.length, 'sigma' ), deleting.ids.length.toLocaleString() ) }
					onClose={ () => setDeleting( null ) }
					footer={
						<>
							<button className="button" type="button" onClick={ () => setDeleting( null ) }>
								{ __( 'Cancel', 'sigma' ) }
							</button>
							<button className="button button--danger" type="button" disabled={ busy } onClick={ () => void confirmDelete() }>
								{ __( 'Delete', 'sigma' ) }
							</button>
						</>
					}
				>
					{ deleting.title !== undefined && <p className="knowledge__modal-name">“{ deleting.title }”</p> }
					{ _n( 'The agent stops using it at once. You cannot undo this. To keep it without using it, move it to draft.', 'The agent stops using them at once. You cannot undo this. To keep them without using them, move them to draft.', deleting.ids.length, 'sigma' ) }
				</Modal>
			) }
		</Shell>
	);
}

/** A file picker that reads one .md/.txt file in the browser. */
function FilePick( { label, hint, onRead }: { label: string; hint: string; onRead: ( f: ReadFile ) => void } ) {
	const [ problem, setProblem ] = useState( '' );
	return (
		<div className={ problem ? 'field field--error' : 'field' }>
			<span className="field__label">{ label }</span>
			<div className="upload">
				<input
					className="upload__input"
					type="file"
					accept={ ACCEPT }
					aria-label={ /* translators: %s: field label. */ sprintf( __( '%s: choose an .md or .txt file', 'sigma' ), label ) }
					onChange={ async ( e ) => {
						const file = e.target.files?.[ 0 ];
						e.target.value = '';
						if ( ! file ) return;
						const r = await readKnowledgeFile( file );
						setProblem( r.ok ? '' : /* translators: 1: file name, 2: the problem. */ sprintf( __( '%1$s: %2$s.', 'sigma' ), file.name, PROBLEM_TEXT[ r.problem ?? 'empty' ] ) );
						if ( r.ok ) onRead( r );
					} }
				/>
			</div>
			{ problem ? (
				<span className="field__message" role="alert">
					{ problem }
				</span>
			) : (
				<span className="field__hint">{ hint }</span>
			) }
		</div>
	);
}

/** Edit (with an id) or Add (without). */
function EntryDrawer( { id, onClose, onSaved, onDelete }: { id?: number; onClose: () => void; onSaved: ( toast: string ) => void; onDelete?: ( e: KnowledgeEntry ) => void } ) {
	const editing = id !== undefined;
	const [ entry, setEntry ] = useState< KnowledgeEntry | null >( null );
	const [ title, setTitle ] = useState( '' );
	const [ content, setContent ] = useState( '' );
	const [ active, setActive ] = useState( true );
	const [ source, setSource ] = useState< string | null >( null ); // a file picked in this drawer
	const [ errors, setErrors ] = useState< { title?: string; content?: string } >( {} );
	const [ failed, setFailed ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const titleRef = useRef< HTMLInputElement >( null );

	useEffect( () => {
		if ( ! editing ) return;
		get< KnowledgeEntry >( `/admin/knowledge/${ id }` )
			.then( ( e ) => {
				setEntry( e );
				setTitle( e.title );
				setContent( e.content ?? '' );
				setActive( e.status === 'active' );
			} )
			.catch( ( e ) => setFailed( ( e as { code?: string } )?.code === 'not_found' ? __( 'This entry no longer exists.', 'sigma' ) : errorText( e ) ) );
	}, [ editing, id ] );

	// The title field renders once the entry has loaded: move focus there then (the drawer focused Close first).
	useEffect( () => {
		if ( entry ) titleRef.current?.focus();
	}, [ entry ] );

	function check(): boolean {
		const next: typeof errors = {};
		if ( ! title.trim() ) next.title = __( 'Enter a title.', 'sigma' );
		else if ( title.trim().length > 200 ) next.title = __( 'Keep the title under 200 characters.', 'sigma' );
		if ( ! content.trim() ) next.content = __( 'Write what the agent should know.', 'sigma' );
		else if ( byteLength( content ) > MAX_BYTES ) next.content = __( 'Keep the content under 1 MB.', 'sigma' );
		setErrors( next );
		if ( next.title ) titleRef.current?.focus();
		else if ( next.content ) document.getElementById( editing ? 'da-kb-edit-content' : 'da-kb-new-content' )?.focus();
		return ! next.title && ! next.content;
	}

	async function save() {
		if ( ! check() ) return;
		setSaving( true );
		setFailed( '' );
		const body: Record< string, unknown > = { title: title.trim(), content, status: active ? 'active' : 'draft' };
		if ( source !== null ) body.source = source;
		try {
			if ( editing ) {
				await put( `/admin/knowledge/${ id }`, body );
				onSaved( /* translators: %s: entry title. */ sprintf( __( '“%s” saved.', 'sigma' ), title.trim() ) );
			} else {
				await post( '/admin/knowledge', body );
				onSaved( active ? /* translators: %s: entry title. */ sprintf( __( '“%s” added.', 'sigma' ), title.trim() ) : /* translators: %s: entry title. */ sprintf( __( '“%s” added as a draft.', 'sigma' ), title.trim() ) );
			}
		} catch ( e ) {
			const f = errorField( e );
			if ( f === 'title' || f === 'content' ) setErrors( { [ f ]: errorText( e ) } );
			else setFailed( errorText( e ) );
			setSaving( false );
		}
	}

	const prefix = editing ? 'da-kb-edit' : 'da-kb-new';
	const loading = editing && ! entry && ! failed;
	const words = countWords( content );

	return (
		<Drawer
			title={ editing ? __( 'Edit entry', 'sigma' ) : __( 'Add entry', 'sigma' ) }
			wide
			onClose={ onClose }
			footer={
				<>
					{ editing && entry && onDelete && (
						<button className="button button--link knowledge__delete" type="button" onClick={ () => onDelete( entry ) }>
							{ __( 'Delete', 'sigma' ) }
						</button>
					) }
					<button className="button" type="button" onClick={ onClose }>
						{ __( 'Cancel', 'sigma' ) }
					</button>
					<button className="button button--primary" type="button" disabled={ saving || loading || ( editing && ! entry ) } onClick={ () => void save() }>
						{ saving ? __( 'Saving…', 'sigma' ) : editing ? __( 'Save', 'sigma' ) : __( 'Add entry', 'sigma' ) }
					</button>
				</>
			}
		>
			{ failed && <Notice kind="danger">{ failed }</Notice> }
			{ loading ? (
				<Spinner />
			) : editing && ! entry ? null : (
				<>
					<div className="drawer__section">
						<div className="stack">
							{ ! editing && (
								<FilePick
									label={ __( 'Start from a file (optional)', 'sigma' ) }
									hint={ __( 'Fills the title and content from an .md file.', 'sigma' ) }
									onRead={ ( f ) => {
										setTitle( f.title );
										setContent( f.content );
										setSource( f.name );
										setErrors( {} );
									} }
								/>
							) }
							<div className={ errors.title ? 'field field--error' : 'field' }>
								<label className="field__label" htmlFor={ `${ prefix }-title` }>
									{ __( 'Title', 'sigma' ) }
								</label>
								<input
									ref={ titleRef }
									className="field__input"
									id={ `${ prefix }-title` }
									type="text"
									value={ title }
									maxLength={ 200 }
									placeholder={ editing ? undefined : __( 'For example: Opening hours', 'sigma' ) }
									aria-invalid={ errors.title ? 'true' : undefined }
									aria-describedby={ errors.title ? `${ prefix }-title-error` : undefined }
									data-autofocus
									onChange={ ( e ) => setTitle( e.target.value ) }
								/>
								{ errors.title && (
									<span className="field__message" id={ `${ prefix }-title-error` }>
										{ errors.title }
									</span>
								) }
							</div>
							<div className={ errors.content ? 'field field--error' : 'field' }>
								<label className="field__label" htmlFor={ `${ prefix }-content` }>
									{ __( 'Content', 'sigma' ) }
								</label>
								<textarea
									className="field__textarea field__textarea--code"
									id={ `${ prefix }-content` }
									rows={ 14 }
									value={ content }
									spellCheck={ false }
									placeholder={ editing ? undefined : __( 'Write what the agent should know. Markdown works: # headings, lists, **bold**.', 'sigma' ) }
									aria-invalid={ errors.content ? 'true' : undefined }
									aria-describedby={ `${ prefix }-content-hint` }
									onChange={ ( e ) => setContent( e.target.value ) }
								/>
								{ errors.content ? (
									<span className="field__message" id={ `${ prefix }-content-hint` }>
										{ errors.content }
									</span>
								) : (
									<span className="field__hint" id={ `${ prefix }-content-hint` }>
										{ editing ? __( 'Markdown. The agent reads it as written.', 'sigma' ) : __( 'Short, clear facts work best. One topic per entry.', 'sigma' ) } { formatWords( words ) }.
									</span>
								) }
							</div>
							{ editing && (
								<FilePick
									label={ __( 'Replace from a file', 'sigma' ) }
									hint={ __( 'The file\'s text replaces the content above. Check it, then save.', 'sigma' ) }
									onRead={ ( f ) => {
										setContent( f.content );
										setSource( f.name );
										setErrors( {} );
									} }
								/>
							) }
						</div>
					</div>
					<div className="drawer__section">
						<h4 className="drawer__section-title">{ __( 'Status', 'sigma' ) }</h4>
						<Toggle label={ __( 'The agent uses this entry', 'sigma' ) } hint={ __( 'Turn off to keep it as a draft.', 'sigma' ) } checked={ active } onChange={ setActive } />
					</div>
					{ ( editing || source ) && (
						<div className="drawer__section">
							<h4 className="drawer__section-title">{ __( 'Source', 'sigma' ) }</h4>
							<p className="drawer__text">
								{ source
									? /* translators: %s: file name. */ sprintf( __( 'From %s. Save to keep it. Edits here do not change the file.', 'sigma' ), source )
									: entry && entry.source
										? entry.imported_at ? /* translators: 1: file name, 2: date. */ sprintf( __( 'Imported from %1$s on %2$s. Edits here do not change the file.', 'sigma' ), entry.source, day( entry.imported_at ) ) : /* translators: %s: file name. */ sprintf( __( 'Imported from %s. Edits here do not change the file.', 'sigma' ), entry.source )
										: __( 'Written here.', 'sigma' ) }
							</p>
						</div>
					) }
				</>
			) }
		</Drawer>
	);
}

interface Pending extends ReadFile {
	key: string;
	replaces: boolean;
	duplicate: boolean;
	use: boolean;
}

function ImportDrawer( { onClose, onDone }: { onClose: () => void; onDone: ( toast: string, close: boolean ) => void } ) {
	const [ titles, setTitles ] = useState< Set< string > | null >( null );
	const [ files, setFiles ] = useState< Pending[] >( [] );
	const [ active, setActive ] = useState( false );
	const [ over, setOver ] = useState( false );
	const [ tooMany, setTooMany ] = useState( 0 );
	const [ failed, setFailed ] = useState( '' );
	const [ refused, setRefused ] = useState< KnowledgeImportResult[] >( [] );
	const [ sending, setSending ] = useState( false );

	useEffect( () => {
		get< { id: number; title: string }[] >( '/admin/knowledge/titles' )
			.then( ( t ) => setTitles( new Set( t.map( ( x ) => titleKey( x.title ) ) ) ) )
			.catch( ( e ) => setFailed( errorText( e ) ) );
	}, [] );

	/** Re-derive "replaces" and in-batch duplicates (the later file of two with one title is left out). */
	const mark = useCallback(
		( list: Pending[] ): Pending[] => {
			const seen = new Set< string >();
			return list.map( ( f ) => {
				if ( ! f.ok ) return f;
				const k = titleKey( f.title );
				const duplicate = seen.has( k );
				seen.add( k );
				return { ...f, replaces: !! titles?.has( k ), duplicate, use: duplicate ? false : f.use };
			} );
		},
		[ titles ]
	);

	useEffect( () => setFiles( ( l ) => mark( l ) ), [ mark ] );

	async function add( list: FileList | File[] ) {
		const incoming = Array.from( list );
		const room = MAX_FILES - files.length;
		setTooMany( incoming.length > room ? incoming.length - Math.max( room, 0 ) : 0 );
		const read = await Promise.all( incoming.slice( 0, Math.max( room, 0 ) ).map( readKnowledgeFile ) );
		setRefused( [] );
		setFiles( ( l ) =>
			mark( [
				...l,
				...read.map( ( r, i ) => ( { ...r, key: `${ Date.now() }-${ i }-${ r.name }`, replaces: false, duplicate: false, use: r.ok } ) ),
			] )
		);
	}

	const usable = files.filter( ( f ) => f.ok && f.use );
	const replacing = usable.filter( ( f ) => f.replaces ).length;

	async function run() {
		if ( ! usable.length ) return;
		setSending( true );
		setFailed( '' );
		try {
			const r = await post< { results: KnowledgeImportResult[] } >( '/admin/knowledge/import', {
				files: usable.map( ( f ) => ( { name: f.name, title: f.title, content: f.content } ) ),
				active,
			} );
			const created = r.results.filter( ( x ) => x.result === 'created' ).length;
			const replaced = r.results.filter( ( x ) => x.result === 'replaced' ).length;
			const no = r.results.filter( ( x ) => x.result === 'refused' );
			const total = created + replaced;
			const summary =
				created && replaced
					? /* translators: 1: files imported, 2: of them new, 3: of them replacing an entry's text. */ sprintf( _n( 'Imported %1$s file: %2$s new, %3$s replaced.', 'Imported %1$s files: %2$s new, %3$s replaced.', total, 'sigma' ), total.toLocaleString(), created.toLocaleString(), replaced.toLocaleString() )
					: created
					? /* translators: 1: files imported, 2: of them new. */ sprintf( _n( 'Imported %1$s file: %2$s new.', 'Imported %1$s files: %2$s new.', total, 'sigma' ), total.toLocaleString(), created.toLocaleString() )
					: /* translators: 1: files imported, 2: of them replacing an entry's text. */ sprintf( _n( 'Imported %1$s file: %2$s replaced.', 'Imported %1$s files: %2$s replaced.', total, 'sigma' ), total.toLocaleString(), replaced.toLocaleString() );
			if ( no.length ) {
				// Keep the drawer open on what the server refused; the rest is done.
				setRefused( no );
				setFiles( ( l ) => l.filter( ( f ) => no.some( ( x ) => x.name === f.name ) ) );
				setSending( false );
				if ( created + replaced ) onDone( summary, false );
				return;
			}
			onDone( summary, true );
		} catch ( e ) {
			setFailed( errorText( e ) );
			setSending( false );
		}
	}

	const onDrop = ( e: DragEvent< HTMLLabelElement > ) => {
		e.preventDefault();
		setOver( false );
		if ( e.dataTransfer.files.length ) void add( e.dataTransfer.files );
	};

	return (
		<Drawer
			title={ __( 'Import files', 'sigma' ) }
			onClose={ onClose }
			footer={
				<>
					<button className="button" type="button" onClick={ onClose }>
						{ __( 'Cancel', 'sigma' ) }
					</button>
					<button className="button button--primary" type="button" disabled={ ! usable.length || sending || ! titles } onClick={ () => void run() }>
						{ sending ? __( 'Importing…', 'sigma' ) : /* translators: %s: number of files. */ sprintf( _n( 'Import %s file', 'Import %s files', usable.length, 'sigma' ), usable.length.toLocaleString() ) }
					</button>
				</>
			}
		>
			{ failed && <Notice kind="danger">{ failed }</Notice> }
			<div className="drawer__section">
				<label
					className={ `upload upload--dropzone${ over ? ' upload--over' : '' }` }
					onDragOver={ ( e ) => {
						e.preventDefault();
						setOver( true );
					} }
					onDragLeave={ () => setOver( false ) }
					onDrop={ onDrop }
				>
					<input
						className="upload__input"
						type="file"
						accept={ ACCEPT }
						multiple
						data-autofocus
						onChange={ ( e ) => {
							if ( e.target.files?.length ) void add( e.target.files );
							e.target.value = '';
						} }
					/>
					<Icon name="cloud-upload" size="lg" />
					<span className="upload__text">{ __( 'Choose .md files or drag them here', 'sigma' ) }</span>
					<span className="upload__hint">{ __( 'Each file becomes one entry. Up to 1 MB per file.', 'sigma' ) }</span>
				</label>
				<p className="drawer__text">{ __( 'The title comes from the file\'s first # heading, or from its name.', 'sigma' ) }</p>
				{ tooMany > 0 && <Notice kind="warning">{ /* translators: 1: files left out, 2: the most files at a time. */ sprintf( _n( '%1$s file was left out: up to %2$s files at a time.', '%1$s files were left out: up to %2$s files at a time.', tooMany, 'sigma' ), tooMany.toLocaleString(), MAX_FILES.toLocaleString() ) }</Notice> }
			</div>
			{ files.length > 0 && (
				<div className="drawer__section">
					<h4 className="drawer__section-title">{ /* translators: %s: number of files. */ sprintf( __( 'Ready to import (%s)', 'sigma' ), usable.length.toLocaleString() ) }</h4>
					<div className="stack stack--tight">
						{ files.map( ( f ) => (
							<label key={ f.key } className="checkbox checkbox--hint-below">
								<input
									className="checkbox__input"
									type="checkbox"
									checked={ f.ok && f.use }
									disabled={ ! f.ok || f.duplicate }
									onChange={ ( e ) => setFiles( ( l ) => l.map( ( x ) => ( x.key === f.key ? { ...x, use: e.target.checked } : x ) ) ) }
								/>
								<span>
									{ f.ok ? f.title : f.name }
									<span className="checkbox__hint">
										{ f.ok
											? /* translators: 1: file name, 2: word count, 3: what happens to it. */ sprintf( __( '%1$s · %2$s · %3$s', 'sigma' ), f.name, formatWords( f.words ), f.duplicate ? __( 'same title as a file above, left out', 'sigma' ) : f.replaces ? __( 'replaces the current text', 'sigma' ) : __( 'new', 'sigma' ) )
											: /* translators: 1: file name, 2: the problem. */ sprintf( __( '%1$s · can\'t import: %2$s', 'sigma' ), f.name, PROBLEM_TEXT[ f.problem ?? 'empty' ] ) }
									</span>
								</span>
							</label>
						) ) }
					</div>
					{ replacing > 0 && (
						<Notice kind="warning">
							{ /* translators: %s: number of files. */ sprintf( _n( '%s file has the same title as an entry. Importing replaces that entry’s text.', '%s files have the same title as entries. Importing replaces those entries’ text.', replacing, 'sigma' ), replacing.toLocaleString() ) }
						</Notice>
					) }
					{ refused.length > 0 && (
						<Notice kind="danger">
							{ /* translators: %s: list of files and reasons. */ sprintf( __( 'Not imported: %s.', 'sigma' ), refused.map( ( r ) => `${ r.name } (${ PROBLEM_TEXT[ r.reason ?? 'empty' ] ?? r.reason })` ).join( ', ' ) ) }
						</Notice>
					) }
				</div>
			) }
			<div className="drawer__section">
				<h4 className="drawer__section-title">{ __( 'Status', 'sigma' ) }</h4>
				<Toggle label={ __( 'Make new entries active', 'sigma' ) } hint={ __( 'Off: new entries start as drafts, so you can check them first.', 'sigma' ) } checked={ active } onChange={ setActive } />
			</div>
		</Drawer>
	);
}
