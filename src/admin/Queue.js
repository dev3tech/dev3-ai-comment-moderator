import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import { Badge, Button, Card, ErrorNotice, IconButton, Inline, JobProgress, Modal, Notice, PageHeader, ResponsiveTable, Select, Tabs, TextField, useToast } from '../core3/ui';
import ItemDrawer from './ItemDrawer';
import { CODES, Reasons, SOURCES, STATUS, Sentiment, ago } from './helpers';

const PER_PAGE = 20;

export const VIEWS = [
	{ id: 'needs', label: __( 'Needs review', 'dev3-ai-comment-moderator' ) },
	{ id: 'held', label: __( 'Held', 'dev3-ai-comment-moderator' ) },
	{ id: 'flagged', label: __( 'Flagged but live', 'dev3-ai-comment-moderator' ) },
	{ id: 'spam', label: __( 'Spam', 'dev3-ai-comment-moderator' ) },
	{ id: 'reviewed', label: __( 'Reviewed', 'dev3-ai-comment-moderator' ) },
	{ id: 'trash', label: __( 'Trash', 'dev3-ai-comment-moderator' ) },
	{ id: 'all', label: __( 'All', 'dev3-ai-comment-moderator' ) },
];

/**
 * Downloads a CSV string.
 *
 * @param {string} filename File name.
 * @param {string} csv      CSV.
 */
function download( filename, csv ) {
	const blob = new window.Blob( [ csv ], { type: 'text/csv;charset=utf-8' } );
	const url = window.URL.createObjectURL( blob );
	const a = document.createElement( 'a' );
	a.href = url;
	a.download = filename;
	document.body.appendChild( a );
	a.click();
	a.remove();
	setTimeout( () => window.URL.revokeObjectURL( url ), 1000 );
}

/**
 * Moderation queue.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.settings Settings.
 * @param {Function} props.navigate Router.
 * @param {number}   props.openId   Item open in the drawer.
 * @param {Function} props.onCounts Counts changed.
 * @param {Function} props.onUsage  AI used.
 * @return {Element} Queue.
 */
export default function Queue( { settings, navigate, openId, onCounts, onUsage } ) {
	const toast = useToast();
	const [ view, setView ] = useState( () => {
		try {
			return window.sessionStorage.getItem( 'dev3cm_view' ) || 'needs';
		} catch ( e ) {
			return 'needs';
		}
	} );
	const [ page, setPage ] = useState( 1 );
	const [ search, setSearch ] = useState( '' );
	const [ query, setQuery ] = useState( '' );
	const [ source, setSource ] = useState( '' );
	const [ label, setLabel ] = useState( '' );
	const [ data, setData ] = useState( { items: [], total: 0, counts: {}, queued: 0 } );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ selected, setSelected ] = useState( [] );
	const [ action, setAction ] = useState( '' );
	const [ confirm, setConfirm ] = useState( false );
	const [ job, setJob ] = useState( null );
	const [ busyRow, setBusyRow ] = useState( 0 );
	const timer = useRef( null );

	const qs = () => `view=${ view }&page=${ page }&per_page=${ PER_PAGE }&search=${ encodeURIComponent( query ) }&source=${ source }&label=${ label }`;
	const load = () => {
		setLoading( true );
		setError( null );
		request( `items?${ qs() }` )
			.then( ( res ) => {
				setData( res );
				onCounts( res.counts );
			} )
			.catch( setError )
			.finally( () => setLoading( false ) );
	};
	useEffect( load, [ view, page, query, source, label ] );
	useEffect( () => {
		clearTimeout( timer.current );
		timer.current = setTimeout( () => {
			setPage( 1 );
			setQuery( search.trim() );
		}, 300 );
		return () => clearTimeout( timer.current );
	}, [ search ] );

	const changeView = ( v ) => {
		setView( v );
		setPage( 1 );
		setSelected( [] );
		try {
			window.sessionStorage.setItem( 'dev3cm_view', v );
		} catch ( e ) {}
	};

	const toggle = ( id ) => setSelected( ( s ) => ( s.includes( id ) ? s.filter( ( x ) => x !== id ) : [ ...s, id ] ) );
	const allIds = data.items.map( ( q ) => q.id );
	const allOn = allIds.length > 0 && allIds.every( ( id ) => selected.includes( id ) );
	const aiReady = settings.api_key_set && settings.ai_mode !== 'off' && config.canGenerate;

	const runBulk = () => {
		setConfirm( false );
		request( 'items/bulk', { method: 'POST', data: { ids: selected, action } } )
			.then( ( res ) => {
				if ( res.job ) {
					setJob( res.job );
					toast.info( __( 'DEV3 AI is re-scanning the selected items in the background.', 'dev3-ai-comment-moderator' ) );
				} else {
					/* translators: %d: items */
					toast.success( sprintf( __( '%d items updated.', 'dev3-ai-comment-moderator' ), res.done ) );
				}
				setSelected( [] );
				setAction( '' );
				load();
			} )
			.catch( ( e ) => toast.error( e ) );
	};

	const quick = ( item, act ) => {
		setBusyRow( item.id );
		request( `items/${ item.id }/action`, { method: 'POST', data: { action: act } } )
			.then( () => {
				toast.success( act === 'approve' ? __( 'Approved.', 'dev3-ai-comment-moderator' ) : __( 'Moved to spam.', 'dev3-ai-comment-moderator' ) );
				load();
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setBusyRow( 0 ) );
	};

	const exportCsv = () => {
		request( `export/csv?view=${ view }&source=${ source }&label=${ label }&search=${ encodeURIComponent( query ) }` )
			.then( ( res ) => {
				download( res.filename, res.csv );
				/* translators: %d: rows */
				toast.success( sprintf( __( '%d rows exported.', 'dev3-ai-comment-moderator' ), res.rows ) );
			} )
			.catch( ( e ) => toast.error( e ) );
	};

	const actions = [
		{ value: 'approve', label: __( 'Approve', 'dev3-ai-comment-moderator' ) },
		{ value: 'hold', label: __( 'Hold', 'dev3-ai-comment-moderator' ) },
		{ value: 'spam', label: __( 'Mark as spam', 'dev3-ai-comment-moderator' ) },
		{ value: 'dismiss', label: __( 'Mark reviewed (keep status)', 'dev3-ai-comment-moderator' ) },
		{ value: 'rescan', label: __( 'Re-scan with DEV3 AI', 'dev3-ai-comment-moderator' ), disabled: ! aiReady },
		{ value: 'trash', label: __( 'Move to trash', 'dev3-ai-comment-moderator' ) },
	];

	const sourceOptions = [ { value: '', label: __( 'All sources', 'dev3-ai-comment-moderator' ) } ].concat(
		Object.keys( SOURCES )
			.filter( ( s ) => s === 'comment' || ( s === 'review' && config.woo ) || ( s.startsWith( 'bbp_' ) && config.bbpress ) )
			.map( ( s ) => ( { value: s, label: SOURCES[ s ] } ) )
	);
	const labelOptions = [ { value: '', label: __( 'All reasons', 'dev3-ai-comment-moderator' ) } ].concat( Object.keys( CODES ).map( ( c ) => ( { value: c, label: CODES[ c ] } ) ) );

	const columns = [
		{
			key: 'select',
			label: __( 'Select', 'dev3-ai-comment-moderator' ),
			width: '44px',
			render: ( q ) => (
				// eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions
				<span className="dev3cm-rowcheck" onClick={ ( e ) => e.stopPropagation() }>
					<input type="checkbox" checked={ selected.includes( q.id ) } onChange={ () => toggle( q.id ) } aria-label={ __( 'Select item', 'dev3-ai-comment-moderator' ) } />
				</span>
			),
		},
		{
			key: 'excerpt',
			label: __( 'Comment', 'dev3-ai-comment-moderator' ),
			primary: true,
			render: ( q ) => (
				<span className="dev3cm-qcell">
					<span className="dev3cm-clamp dev3cm-qcell__text">{ q.excerpt || __( '(empty)', 'dev3-ai-comment-moderator' ) }</span>
					<span className="dev3cm-muted">
						{ q.author_name || __( 'Anonymous', 'dev3-ai-comment-moderator' ) } · { SOURCES[ q.source ] || q.source }
						{ q.post ? ` · ${ q.post.title }` : '' }
					</span>
				</span>
			),
		},
		{
			key: 'reasons',
			label: __( 'Reasons', 'dev3-ai-comment-moderator' ),
			render: ( q ) =>
				q.reasons.length ? (
					<Reasons reasons={ q.reasons } max={ 3 } />
				) : (
					<span className="dev3cm-muted">{ q.ai_state === 'queued' ? __( 'DEV3 AI check pending…', 'dev3-ai-comment-moderator' ) : __( 'No issues', 'dev3-ai-comment-moderator' ) }</span>
				),
		},
		{
			key: 'status',
			label: __( 'Status', 'dev3-ai-comment-moderator' ),
			nowrap: true,
			render: ( q ) => (
				<span className="dev3cm-chips">
					<Badge tone={ ( STATUS[ q.status ] || {} ).tone }>{ ( STATUS[ q.status ] || {} ).label || q.status }</Badge>
					{ q.reviewed && <Badge tone="neutral">{ __( 'Reviewed', 'dev3-ai-comment-moderator' ) }</Badge> }
				</span>
			),
		},
		{ key: 'sentiment', label: __( 'Sentiment', 'dev3-ai-comment-moderator' ), nowrap: true, hideOnMobile: true, render: ( q ) => <Sentiment value={ q.sentiment } /> },
		{ key: 'created_at', label: __( 'Posted', 'dev3-ai-comment-moderator' ), align: 'end', nowrap: true, render: ( q ) => ago( q.created_at ) },
	];

	const tabs = VIEWS.map( ( v ) => ( { id: v.id, label: v.label, badge: data.counts[ v.id ] ? String( data.counts[ v.id ] ) : undefined } ) );

	return (
		<>
			<PageHeader
				title={ __( 'Moderation queue', 'dev3-ai-comment-moderator' ) }
				description={ __( 'Comments, reviews and forum posts that rules or DEV3 AI held, spammed or flagged, with the reasons.', 'dev3-ai-comment-moderator' ) }
				actions={
					<Button variant="secondary" icon="file" onClick={ exportCsv }>
						{ __( 'Export CSV', 'dev3-ai-comment-moderator' ) }
					</Button>
				}
			/>
			<div className="dev3cm-tabs-scroll">
				<Tabs tabs={ tabs } value={ view } onChange={ changeView } variant="pills" label={ __( 'Queue views', 'dev3-ai-comment-moderator' ) } />
			</div>
			{ data.queued > 0 && (
				<Notice tone="ai">
					{ /* translators: %d: items */ sprintf( __( '%d items are waiting for the DEV3 AI check. They are processed in the background (and while this screen is open).', 'dev3-ai-comment-moderator' ), data.queued ) }
				</Notice>
			) }
			{ job && (
				<JobProgress
					jobId={ job.id }
					job={ job }
					title={ __( 'Re-scanning with DEV3 AI', 'dev3-ai-comment-moderator' ) }
					onDone={ () => {
						load();
						onUsage();
					} }
				/>
			) }
			<Card flush>
				<div className="dev3cm-toolbar">
					<TextField label={ __( 'Search the queue', 'dev3-ai-comment-moderator' ) } hideLabel type="search" value={ search } onChange={ setSearch } placeholder={ __( 'Search text, names, emails or reasons…', 'dev3-ai-comment-moderator' ) } />
					<Select label={ __( 'Source', 'dev3-ai-comment-moderator' ) } hideLabel value={ source } onChange={ ( v ) => { setSource( v ); setPage( 1 ); } } options={ sourceOptions } />
					<Select label={ __( 'Reason', 'dev3-ai-comment-moderator' ) } hideLabel value={ label } onChange={ ( v ) => { setLabel( v ); setPage( 1 ); } } options={ labelOptions } />
				</div>
				<div className="dev3cm-toolbar dev3cm-toolbar--bulk">
					<label className="dev3cm-selectall">
						<input type="checkbox" checked={ allOn } onChange={ () => setSelected( allOn ? [] : allIds ) } />
						<span>{ selected.length ? sprintf( /* translators: %d: selected */ __( '%d selected', 'dev3-ai-comment-moderator' ), selected.length ) : __( 'Select all', 'dev3-ai-comment-moderator' ) }</span>
					</label>
					<Inline gap={ 8 } className="dev3cm-toolbar__bulk">
						<Select label={ __( 'Bulk action', 'dev3-ai-comment-moderator' ) } hideLabel value={ action } onChange={ setAction } placeholder={ __( 'Bulk actions', 'dev3-ai-comment-moderator' ) } options={ actions } disabled={ ! selected.length } />
						<Button size="sm" variant="secondary" disabled={ ! selected.length || ! action } onClick={ () => ( action === 'trash' ? setConfirm( true ) : runBulk() ) }>
							{ __( 'Apply', 'dev3-ai-comment-moderator' ) }
						</Button>
					</Inline>
				</div>
				{ error && <ErrorNotice error={ error } onRetry={ load } /> }
				<ResponsiveTable
					caption={ __( 'Moderation queue', 'dev3-ai-comment-moderator' ) }
					columns={ columns }
					rows={ data.items }
					loading={ loading }
					onRowClick={ ( q ) => navigate( `queue/${ q.id }` ) }
					rowActions={ ( q ) => (
						// eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions
						<span className="dev3cm-rowactions" onClick={ ( e ) => e.stopPropagation() }>
							{ q.status !== 'live' && <IconButton icon="check" label={ __( 'Approve', 'dev3-ai-comment-moderator' ) } size="sm" variant="ghost" disabled={ busyRow === q.id } onClick={ () => quick( q, 'approve' ) } /> }
							{ q.status !== 'spam' && <IconButton icon="alert" label={ __( 'Mark as spam', 'dev3-ai-comment-moderator' ) } size="sm" variant="ghost" disabled={ busyRow === q.id } onClick={ () => quick( q, 'spam' ) } /> }
						</span>
					) }
					pagination={ { page, perPage: PER_PAGE, total: data.total, onPageChange: setPage } }
					empty={ {
						icon: view === 'needs' ? 'check' : 'inbox',
						title: view === 'needs' ? __( 'All caught up', 'dev3-ai-comment-moderator' ) : __( 'Nothing here', 'dev3-ai-comment-moderator' ),
						description: query || source || label ? __( 'Try another search or filter.', 'dev3-ai-comment-moderator' ) : __( 'New comments, reviews and forum posts are screened as they arrive. Run a scan in Policy & rules to include older ones.', 'dev3-ai-comment-moderator' ),
					} }
				/>
			</Card>
			<ItemDrawer
				id={ openId }
				settings={ settings }
				onClose={ () => navigate( 'queue' ) }
				onChanged={ load }
				onUsage={ onUsage }
			/>
			<Modal
				open={ confirm }
				onClose={ () => setConfirm( false ) }
				size="sm"
				title={ __( 'Move to trash?', 'dev3-ai-comment-moderator' ) }
				description={ __( 'The selected items go to the WordPress trash, where they can be restored for 30 days.', 'dev3-ai-comment-moderator' ) }
				footer={
					<>
						<Button variant="ghost" onClick={ () => setConfirm( false ) } data-autofocus>
							{ __( 'Cancel', 'dev3-ai-comment-moderator' ) }
						</Button>
						<Button variant="danger" onClick={ runBulk }>
							{ __( 'Move to trash', 'dev3-ai-comment-moderator' ) }
						</Button>
					</>
				}
			/>
		</>
	);
}
