import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import { Badge, Card, ErrorNotice, PageHeader, ResponsiveTable, Select, TextField } from '../core3/ui';
import ThreadDrawer from './ThreadDrawer';
import { MoodBar, SOURCES, Sentiment, ago } from './helpers';

const PER_PAGE = 20;

/**
 * Per-post sentiment and summaries.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.settings Settings.
 * @param {Function} props.navigate Router.
 * @param {number}   props.openId   Post open in the drawer.
 * @param {Function} props.onUsage  AI used.
 * @return {Element} Screen.
 */
export default function Discussions( { settings, navigate, openId, onUsage } ) {
	const [ sort, setSort ] = useState( 'recent' );
	const [ source, setSource ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ query, setQuery ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState( { items: [], total: 0 } );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const timer = useRef( null );

	const load = () => {
		setLoading( true );
		setError( null );
		request( `threads?sort=${ sort }&source=${ source }&page=${ page }&per_page=${ PER_PAGE }&search=${ encodeURIComponent( query ) }` )
			.then( setData )
			.catch( setError )
			.finally( () => setLoading( false ) );
	};
	useEffect( load, [ sort, source, page, query ] );
	useEffect( () => {
		clearTimeout( timer.current );
		timer.current = setTimeout( () => {
			setPage( 1 );
			setQuery( search.trim() );
		}, 300 );
		return () => clearTimeout( timer.current );
	}, [ search ] );

	const sourceOptions = [ { value: '', label: __( 'All sources', 'dev3-ai-comment-moderator' ) } ].concat(
		Object.keys( SOURCES )
			.filter( ( s ) => s === 'comment' || ( s === 'review' && config.woo ) || ( s === 'bbp_reply' && config.bbpress ) )
			.map( ( s ) => ( { value: s, label: s === 'bbp_reply' ? __( 'Forum topics', 'dev3-ai-comment-moderator' ) : SOURCES[ s ] } ) )
	);

	const columns = [
		{
			key: 'title',
			label: __( 'Post', 'dev3-ai-comment-moderator' ),
			primary: true,
			render: ( t ) => (
				<span className="dev3cm-qcell">
					<span className="dev3cm-qcell__text dev3cm-clamp">{ t.title }</span>
					<span className="dev3cm-muted">
						{ SOURCES[ t.source ] || t.source } · { ago( t.last_at ) }
					</span>
				</span>
			),
		},
		{
			key: 'total',
			label: __( 'Items', 'dev3-ai-comment-moderator' ),
			align: 'end',
			nowrap: true,
			render: ( t ) => (
				<span className="dev3cm-counts">
					<span>{ t.total }</span>
					{ t.held > 0 && <Badge tone="warning">{ `${ t.held } ${ __( 'held', 'dev3-ai-comment-moderator' ) }` }</Badge> }
					{ t.spam > 0 && <Badge tone="danger">{ `${ t.spam } ${ __( 'spam', 'dev3-ai-comment-moderator' ) }` }</Badge> }
				</span>
			),
		},
		{ key: 'sentiment', label: __( 'Sentiment', 'dev3-ai-comment-moderator' ), nowrap: true, render: ( t ) => <Sentiment value={ t.sentiment } /> },
		{ key: 'mood', label: __( 'Mix', 'dev3-ai-comment-moderator' ), hideOnMobile: true, render: ( t ) => <MoodBar positive={ t.positive } neutral={ t.neutral } negative={ t.negative } /> },
		{
			key: 'summary',
			label: __( 'Summary', 'dev3-ai-comment-moderator' ),
			nowrap: true,
			render: ( t ) => {
				if ( ! t.summary ) {
					return <span className="dev3cm-muted">{ __( 'None', 'dev3-ai-comment-moderator' ) }</span>;
				}
				if ( t.summary.stale ) {
					return <Badge tone="warning">{ __( 'Out of date', 'dev3-ai-comment-moderator' ) }</Badge>;
				}
				return <Badge tone={ t.summary.show_public ? 'success' : 'info' }>{ t.summary.show_public ? __( 'On site', 'dev3-ai-comment-moderator' ) : __( 'Saved', 'dev3-ai-comment-moderator' ) }</Badge>;
			},
		},
	];

	return (
		<>
			<PageHeader title={ __( 'Discussions', 'dev3-ai-comment-moderator' ) } description={ __( 'Sentiment per post, product and forum topic. Open one to see themes and create a summary.', 'dev3-ai-comment-moderator' ) } />
			<Card flush>
				<div className="dev3cm-toolbar">
					<TextField label={ __( 'Search posts', 'dev3-ai-comment-moderator' ) } hideLabel type="search" value={ search } onChange={ setSearch } placeholder={ __( 'Search by post title…', 'dev3-ai-comment-moderator' ) } />
					<Select
						label={ __( 'Sort', 'dev3-ai-comment-moderator' ) }
						hideLabel
						value={ sort }
						onChange={ ( v ) => {
							setSort( v );
							setPage( 1 );
						} }
						options={ [
							{ value: 'recent', label: __( 'Latest activity', 'dev3-ai-comment-moderator' ) },
							{ value: 'active', label: __( 'Most active', 'dev3-ai-comment-moderator' ) },
							{ value: 'negative', label: __( 'Most negative', 'dev3-ai-comment-moderator' ) },
							{ value: 'positive', label: __( 'Most positive', 'dev3-ai-comment-moderator' ) },
						] }
					/>
					<Select
						label={ __( 'Source', 'dev3-ai-comment-moderator' ) }
						hideLabel
						value={ source }
						onChange={ ( v ) => {
							setSource( v );
							setPage( 1 );
						} }
						options={ sourceOptions }
					/>
				</div>
				{ error && <ErrorNotice error={ error } onRetry={ load } /> }
				<ResponsiveTable
					caption={ __( 'Discussions', 'dev3-ai-comment-moderator' ) }
					columns={ columns }
					rows={ data.items }
					rowKey="post_id"
					loading={ loading }
					onRowClick={ ( t ) => navigate( `discussions/${ t.post_id }` ) }
					pagination={ { page, perPage: PER_PAGE, total: data.total, onPageChange: setPage } }
					empty={ { icon: 'users', title: __( 'No discussions yet', 'dev3-ai-comment-moderator' ), description: __( 'Posts appear here once comments are screened. Run a scan in Policy & rules to include older comments.', 'dev3-ai-comment-moderator' ) } }
				/>
			</Card>
			<ThreadDrawer postId={ openId } settings={ settings } onClose={ () => navigate( 'discussions' ) } onChanged={ load } onUsage={ onUsage } navigate={ navigate } />
		</>
	);
}
