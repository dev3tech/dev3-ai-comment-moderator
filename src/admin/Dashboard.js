import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import { Badge, Button, Card, Columns, EmptyState, ErrorNotice, Notice, PageHeader, SkeletonCard, Stat, StatGrid, formatNumber } from '../core3/ui';
import BarChart from './BarChart';
import { CODES, MoodBar, Reasons, SOURCES, Sentiment, ago } from './helpers';

/**
 * Dashboard: KPIs, 14-day chart, reasons, most negative discussions, latest held items.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.settings Settings.
 * @param {Function} props.navigate Router.
 * @param {Function} props.onCounts Counts changed.
 * @return {Element} Dashboard.
 */
export default function Dashboard( { settings, navigate, onCounts } ) {
	const [ stats, setStats ] = useState( null );
	const [ error, setError ] = useState( null );

	const load = () => {
		setError( null );
		request( 'stats' )
			.then( ( s ) => {
				setStats( s );
				onCounts( s.counts );
			} )
			.catch( setError );
	};
	useEffect( load, [] );

	const codes = stats ? Object.entries( stats.codes || {} ).slice( 0, 8 ) : [];
	const maxCode = codes.length ? codes[ 0 ][ 1 ] : 1;

	return (
		<>
			<PageHeader
				title={ __( 'Dashboard', 'dev3-ai-comment-moderator' ) }
				description={ __( 'What your community posted in the last 30 days and what the moderator caught.', 'dev3-ai-comment-moderator' ) }
				actions={
					<Button variant="primary" icon="inbox" onClick={ () => navigate( 'queue' ) }>
						{ __( 'Open queue', 'dev3-ai-comment-moderator' ) }
					</Button>
				}
			/>
			{ ! settings.api_key_set && (
				<Notice
					tone="ai"
					title={ __( 'Connect DEV3 AI for toxicity, harassment and off-topic detection', 'dev3-ai-comment-moderator' ) }
					actions={
						<Button size="sm" variant="ai" href="#/api">
							{ __( 'Add API key', 'dev3-ai-comment-moderator' ) }
						</Button>
					}
				>
					{ __( 'Rules, the queue, reply templates, lexicon sentiment and the local "be kind" nudge already work without AI.', 'dev3-ai-comment-moderator' ) }
				</Notice>
			) }
			{ error && <ErrorNotice error={ error } onRetry={ load } /> }
			{ ! stats && ! error && <SkeletonCard lines={ 6 } /> }
			{ stats && (
				<>
					<StatGrid min={ 170 }>
						<Stat label={ __( 'Needs review', 'dev3-ai-comment-moderator' ) } value={ formatNumber( stats.needs ) } icon="inbox" tone={ stats.needs ? 'warning' : 'success' } hint={ __( 'Held or flagged, not reviewed', 'dev3-ai-comment-moderator' ) } />
						<Stat label={ __( 'Screened (30 days)', 'dev3-ai-comment-moderator' ) } value={ formatNumber( stats.screened ) } icon="check" tone="brand" />
						<Stat label={ __( 'Held (30 days)', 'dev3-ai-comment-moderator' ) } value={ formatNumber( stats.held ) } icon="pause" tone="warning" />
						<Stat label={ __( 'Spam caught (30 days)', 'dev3-ai-comment-moderator' ) } value={ formatNumber( stats.spam ) } icon="alert" tone="danger" />
						<Stat
							label={ __( 'Average sentiment', 'dev3-ai-comment-moderator' ) }
							value={ stats.sentiment === null ? '–' : ( stats.sentiment > 0 ? '+' : '' ) + stats.sentiment.toFixed( 2 ) }
							icon="users"
							tone={ stats.sentiment === null ? 'neutral' : stats.sentiment >= 0.25 ? 'success' : stats.sentiment <= -0.25 ? 'danger' : 'neutral' }
							hint={ /* translators: %d: AI classified count */ sprintf( __( '%d classified by DEV3 AI', 'dev3-ai-comment-moderator' ), stats.classified ) }
						/>
					</StatGrid>
					<Columns min={ 320 }>
						<Card title={ __( 'Last 14 days', 'dev3-ai-comment-moderator' ) } description={ __( 'New comments, reviews and forum posts by outcome.', 'dev3-ai-comment-moderator' ) }>
							<BarChart data={ stats.series } />
						</Card>
						<Card title={ __( 'Top reasons', 'dev3-ai-comment-moderator' ) } description={ __( 'Rules and DEV3 AI labels that fired (30 days).', 'dev3-ai-comment-moderator' ) }>
							{ codes.length ? (
								<ul className="dev3cm-hbars">
									{ codes.map( ( [ code, n ] ) => (
										<li key={ code }>
											<span className="dev3cm-hbars__label">{ CODES[ code ] || code }</span>
											<span className="dev3cm-hbars__track" aria-hidden="true">
												<span className="dev3cm-hbars__fill" style={ { width: `${ ( n / maxCode ) * 100 }%` } } />
											</span>
											<span className="dev3cm-hbars__value">{ formatNumber( n ) }</span>
										</li>
									) ) }
								</ul>
							) : (
								<EmptyState compact icon="check" title={ __( 'Nothing caught yet', 'dev3-ai-comment-moderator' ) } description={ __( 'Reasons appear here as soon as a rule or DEV3 AI flags something.', 'dev3-ai-comment-moderator' ) } />
							) }
						</Card>
					</Columns>
					<Columns min={ 320 }>
						<Card
							title={ __( 'Waiting for review', 'dev3-ai-comment-moderator' ) }
							flush
							actions={
								<Button size="sm" variant="ghost" iconRight="chevronRight" onClick={ () => navigate( 'queue' ) }>
									{ __( 'All', 'dev3-ai-comment-moderator' ) }
								</Button>
							}
						>
							{ stats.latest.length ? (
								<ul className="dev3cm-list">
									{ stats.latest.map( ( item ) => (
										<li key={ item.id }>
											<button type="button" className="dev3cm-list__row" onClick={ () => navigate( `queue/${ item.id }` ) }>
												<span className="dev3cm-list__main">
													<span className="dev3cm-clamp">{ item.excerpt }</span>
													<span className="dev3cm-muted">
														{ item.author_name || __( 'Anonymous', 'dev3-ai-comment-moderator' ) } · { SOURCES[ item.source ] } · { ago( item.created_at ) }
													</span>
													<Reasons reasons={ item.reasons } max={ 2 } />
												</span>
											</button>
										</li>
									) ) }
								</ul>
							) : (
								<EmptyState compact icon="check" title={ __( 'All caught up', 'dev3-ai-comment-moderator' ) } description={ __( 'Nothing is waiting for a moderator.', 'dev3-ai-comment-moderator' ) } />
							) }
						</Card>
						<Card
							title={ __( 'Most negative discussions', 'dev3-ai-comment-moderator' ) }
							flush
							actions={
								<Button size="sm" variant="ghost" iconRight="chevronRight" onClick={ () => navigate( 'discussions' ) }>
									{ __( 'All', 'dev3-ai-comment-moderator' ) }
								</Button>
							}
						>
							{ stats.negative.length ? (
								<ul className="dev3cm-list">
									{ stats.negative.map( ( t ) => (
										<li key={ t.post_id }>
											<button type="button" className="dev3cm-list__row" onClick={ () => navigate( `discussions/${ t.post_id }` ) }>
												<span className="dev3cm-list__main">
													<span className="dev3cm-strong dev3cm-clamp">{ t.title }</span>
													<span className="dev3cm-muted">
														{ /* translators: %d: comments */ sprintf( __( '%d comments', 'dev3-ai-comment-moderator' ), t.total ) }
													</span>
												</span>
												<span className="dev3cm-list__side">
													<Sentiment value={ t.sentiment } />
													<MoodBar positive={ t.positive } neutral={ t.neutral } negative={ t.negative } />
												</span>
											</button>
										</li>
									) ) }
								</ul>
							) : (
								<EmptyState compact icon="users" title={ __( 'No discussions yet', 'dev3-ai-comment-moderator' ) } description={ __( 'Run a scan in Policy & rules to include existing comments.', 'dev3-ai-comment-moderator' ) } />
							) }
						</Card>
					</Columns>
					<Card title={ __( 'Connected sources', 'dev3-ai-comment-moderator' ) }>
						<div className="dev3cm-chips">
							<Badge tone={ settings.src_comments ? 'success' : 'neutral' } dot>
								{ __( 'WordPress comments', 'dev3-ai-comment-moderator' ) }
							</Badge>
							<Badge tone={ config.woo && settings.src_reviews ? 'success' : 'neutral' } dot>
								{ config.woo ? __( 'WooCommerce reviews', 'dev3-ai-comment-moderator' ) : __( 'WooCommerce not active', 'dev3-ai-comment-moderator' ) }
							</Badge>
							<Badge tone={ config.bbpress && settings.src_bbpress ? 'success' : 'neutral' } dot>
								{ config.bbpress ? __( 'bbPress topics & replies', 'dev3-ai-comment-moderator' ) : __( 'bbPress not active', 'dev3-ai-comment-moderator' ) }
							</Badge>
							<Badge tone={ config.akismet ? 'success' : 'neutral' } dot>
								{ config.akismet ? __( 'Akismet verdicts respected', 'dev3-ai-comment-moderator' ) : __( 'Akismet not active', 'dev3-ai-comment-moderator' ) }
							</Badge>
							<Badge tone={ stats.ai.available ? 'brand' : 'neutral' } dot>
								{ stats.ai.available
									? /* translators: %s: sync|async */ sprintf( __( 'DEV3 AI on (%s)', 'dev3-ai-comment-moderator' ), stats.ai.mode === 'sync' ? __( 'before publishing', 'dev3-ai-comment-moderator' ) : __( 'in the background', 'dev3-ai-comment-moderator' ) )
									: __( 'DEV3 AI off', 'dev3-ai-comment-moderator' ) }
							</Badge>
						</div>
					</Card>
				</>
			) }
		</>
	);
}
