import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import { AiButton, AiDraftReview, Badge, Button, Drawer, EmptyState, ErrorNotice, Notice, SkeletonCard, Stack, Toggle, useToast } from '../core3/ui';
import { MOOD, MoodBar, Reasons, STATUS, Sentiment, ago, bucket } from './helpers';

const FIELDS = [ { key: 'summary', label: __( 'Summary', 'dev3-ai-comment-moderator' ), multiline: true, maxLength: 1200 } ];

/**
 * Themes as chips.
 *
 * @param {Object} props        Props.
 * @param {Array}  props.themes Themes.
 * @return {Element|null} Chips.
 */
function Themes( { themes = [] } ) {
	if ( ! themes.length ) {
		return null;
	}
	return (
		<div className="dev3cm-chips">
			{ themes.map( ( t ) => (
				<Badge key={ t.label } tone={ MOOD[ bucket( t.sentiment ) ].tone }>
					{ t.label }
					{ t.share ? ` · ${ Math.round( t.share * 100 ) }%` : '' }
				</Badge>
			) ) }
		</div>
	);
}

/**
 * Bullet list with a heading.
 *
 * @param {Object} props       Props.
 * @param {string} props.title Title.
 * @param {Array}  props.items Lines.
 * @return {Element|null} List.
 */
function Lines( { title, items = [] } ) {
	if ( ! items.length ) {
		return null;
	}
	return (
		<div>
			<h4 className="dev3cm-h4">{ title }</h4>
			<ul className="dev3cm-bullets">
				{ items.map( ( q ) => (
					<li key={ q }>{ q }</li>
				) ) }
			</ul>
		</div>
	);
}

/**
 * Discussion drawer: sentiment, keywords, AI summary (draft → accept), recent items.
 *
 * @param {Object}   props           Props.
 * @param {number}   props.postId    Post (0 = closed).
 * @param {Object}   props.settings  Settings.
 * @param {Function} props.onClose   Close.
 * @param {Function} props.onChanged Saved.
 * @param {Function} props.onUsage   AI used.
 * @param {Function} props.navigate  Router.
 * @return {Element} Drawer.
 */
export default function ThreadDrawer( { postId, settings, onClose, onChanged, onUsage, navigate } ) {
	const toast = useToast();
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ draft, setDraft ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	const [ aiError, setAiError ] = useState( null );
	const [ show, setShow ] = useState( false );
	const aiReady = settings.api_key_set && config.canGenerate;

	const load = () => {
		setError( null );
		request( `threads/${ postId }` )
			.then( ( d ) => {
				setData( d );
				setShow( d.summary ? d.summary.show_public : false );
			} )
			.catch( setError );
	};
	useEffect( () => {
		setData( null );
		setDraft( null );
		setAiError( null );
		if ( postId ) {
			load();
		}
	}, [ postId ] );

	const summarize = () => {
		setBusy( 'draft' );
		setAiError( null );
		request( `threads/${ postId }/summarize`, { method: 'POST' } )
			.then( setDraft )
			.catch( setAiError )
			.finally( () => {
				setBusy( '' );
				onUsage();
			} );
	};

	const accept = ( value ) => {
		setBusy( 'save' );
		return request( `threads/${ postId }/summary`, {
			method: 'POST',
			data: { ...draft.draft, summary: value.summary, item_count: draft.item_count, model: draft.model, show_public: show },
		} )
			.then( () => {
				setDraft( null );
				toast.success( __( 'Summary saved.', 'dev3-ai-comment-moderator' ) );
				load();
				onChanged();
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setBusy( '' ) );
	};

	const toggleShow = ( value ) => {
		setShow( value );
		if ( ! data || ! data.summary ) {
			return;
		}
		request( `threads/${ postId }/summary`, { method: 'POST', data: { show_public: value } } )
			.then( () => {
				toast.success( value ? __( 'The summary now shows on the site.', 'dev3-ai-comment-moderator' ) : __( 'The summary is hidden from the site.', 'dev3-ai-comment-moderator' ) );
				load();
				onChanged();
			} )
			.catch( ( e ) => toast.error( e ) );
	};

	const remove = () => {
		request( `threads/${ postId }/summary`, { method: 'DELETE' } )
			.then( () => {
				toast.success( __( 'Summary removed.', 'dev3-ai-comment-moderator' ) );
				load();
				onChanged();
			} )
			.catch( ( e ) => toast.error( e ) );
	};

	const stats = data && data.stats;
	const summary = data && data.summary;

	return (
		<Drawer open={ !! postId } onClose={ onClose } size="lg" title={ data ? data.post.title : __( 'Discussion', 'dev3-ai-comment-moderator' ) }>
			{ error && <ErrorNotice error={ error } onRetry={ load } /> }
			{ ! data && ! error && <SkeletonCard lines={ 6 } /> }
			{ data && (
				<Stack gap={ 16 }>
					<div className="dev3cm-chips">
						<a href={ data.post.url } target="_blank" rel="noreferrer" className="dev3cm-link">
							{ __( 'View post', 'dev3-ai-comment-moderator' ) }
						</a>
						{ stats && <Sentiment value={ stats.sentiment } /> }
					</div>
					{ stats && (
						<div className="dev3cm-threadstats">
							<MoodBar positive={ stats.positive } neutral={ stats.neutral } negative={ stats.negative } />
							<p className="dev3cm-muted">
								{ /* translators: 1: total, 2: live, 3: held, 4: spam */ sprintf( __( '%1$d screened · %2$d live · %3$d held · %4$d spam', 'dev3-ai-comment-moderator' ), stats.total, stats.live, stats.held, stats.spam ) }
							</p>
						</div>
					) }
					{ data.insights.keywords.length > 0 && (
						<div>
							<h4 className="dev3cm-h4">{ __( 'Frequent words', 'dev3-ai-comment-moderator' ) }</h4>
							<div className="dev3cm-chips">
								{ data.insights.keywords.map( ( k ) => (
									<Badge key={ k.word } tone="neutral">
										{ k.word } · { k.count }
									</Badge>
								) ) }
							</div>
						</div>
					) }

					<section className="dev3cm-section dev3cm-section--ai" aria-labelledby="dev3cm-sum-h">
						<div className="dev3cm-section__head">
							<h3 id="dev3cm-sum-h">{ __( 'Discussion summary', 'dev3-ai-comment-moderator' ) }</h3>
							<AiButton size="sm" loading={ busy === 'draft' } disabled={ ! aiReady } onClick={ summarize } loadingText={ __( 'Summarizing…', 'dev3-ai-comment-moderator' ) }>
								{ summary ? __( 'Refresh with DEV3 AI', 'dev3-ai-comment-moderator' ) : __( 'Summarize with DEV3 AI', 'dev3-ai-comment-moderator' ) }
							</AiButton>
						</div>
						{ ! aiReady && <p className="dev3cm-muted">{ __( 'Add a Gemini API key in API Config to summarize discussions. Frequent words and sentiment above work without AI.', 'dev3-ai-comment-moderator' ) }</p> }
						{ aiError && <ErrorNotice error={ aiError } onDismiss={ () => setAiError( null ) } /> }
						{ draft && (
							<Stack gap={ 12 }>
								<Themes themes={ draft.draft.themes } />
								<AiDraftReview
									key={ draft.draft.summary }
									fields={ FIELDS }
									draft={ { summary: draft.draft.summary } }
									original={ summary ? { summary: summary.summary } : undefined }
									acceptLabel={ __( 'Save summary', 'dev3-ai-comment-moderator' ) }
									accepting={ busy === 'save' }
									regenerating={ busy === 'draft' }
									onAccept={ accept }
									onReject={ () => setDraft( null ) }
									onRegenerate={ summarize }
									meta={ { model: draft.model, tokens: draft.tokens, cached: draft.cached } }
								/>
								<Lines title={ __( 'Open questions', 'dev3-ai-comment-moderator' ) } items={ draft.draft.questions } />
								<Lines title={ __( 'Suggested follow-ups', 'dev3-ai-comment-moderator' ) } items={ draft.draft.actions } />
							</Stack>
						) }
						{ ! draft && summary && (
							<Stack gap={ 12 }>
								<p className="dev3cm-summarytext">{ summary.summary }</p>
								<Themes themes={ summary.themes } />
								<Lines title={ __( 'Open questions', 'dev3-ai-comment-moderator' ) } items={ summary.questions } />
								<Lines title={ __( 'Suggested follow-ups', 'dev3-ai-comment-moderator' ) } items={ summary.actions } />
								<p className="dev3cm-muted">
									{ /* translators: 1: comments, 2: time */ sprintf( __( 'Based on %1$d comments · saved %2$s', 'dev3-ai-comment-moderator' ), summary.item_count, ago( summary.updated_at ) ) }
								</p>
								{ stats && stats.live > summary.item_count && <Notice tone="warning">{ __( 'New comments arrived since this summary. Refresh it to include them.', 'dev3-ai-comment-moderator' ) }</Notice> }
								<div className="dev3cm-actions">
									<Button size="sm" variant="ghost" icon="trash" onClick={ remove }>
										{ __( 'Remove summary', 'dev3-ai-comment-moderator' ) }
									</Button>
								</div>
							</Stack>
						) }
						{ ! draft && ! summary && aiReady && <EmptyState compact icon="sparkles" title={ __( 'No summary yet', 'dev3-ai-comment-moderator' ) } description={ __( 'DEV3 AI reads the approved comments and drafts a short summary with themes and open questions. You review it before it is saved.', 'dev3-ai-comment-moderator' ) } /> }
						<Toggle
							label={ __( 'Show the saved summary on the site', 'dev3-ai-comment-moderator' ) }
							help={ __( 'Appears via the Discussion summary block, the [dev3cm_summary] shortcode, or after the content when enabled in Front-end.', 'dev3-ai-comment-moderator' ) }
							checked={ show }
							onChange={ toggleShow }
						/>
					</section>

					<section className="dev3cm-section" aria-labelledby="dev3cm-recent-h">
						<h3 id="dev3cm-recent-h">{ __( 'Latest items', 'dev3-ai-comment-moderator' ) }</h3>
						<ul className="dev3cm-list dev3cm-list--plain">
							{ data.recent.map( ( item ) => (
								<li key={ item.id }>
									<button type="button" className="dev3cm-list__row" onClick={ () => navigate( `queue/${ item.id }` ) }>
										<span className="dev3cm-list__main">
											<span className="dev3cm-clamp">{ item.excerpt }</span>
											<span className="dev3cm-muted">
												{ item.author_name || __( 'Anonymous', 'dev3-ai-comment-moderator' ) } · { ago( item.created_at ) } · { ( STATUS[ item.status ] || {} ).label }
											</span>
											<Reasons reasons={ item.reasons } max={ 2 } />
										</span>
										<span className="dev3cm-list__side">
											<Sentiment value={ item.sentiment } />
										</span>
									</button>
								</li>
							) ) }
						</ul>
					</section>
				</Stack>
			) }
		</Drawer>
	);
}
