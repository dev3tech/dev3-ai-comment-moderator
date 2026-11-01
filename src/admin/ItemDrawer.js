import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import { AiButton, AiDraftReview, Badge, Button, Checkbox, Drawer, ErrorNotice, Select, SkeletonCard, Stack, TextField, Textarea, useToast } from '../core3/ui';
import { ACTION_LABEL, ACTION_TONE, HISTORY, LABELS, SOURCES, STATUS, Sentiment, ago, byLabel } from './helpers';

/**
 * Score meters of the five AI labels.
 *
 * @param {Object} props        Props.
 * @param {Object} props.labels Labels.
 * @param {Object} props.settings Settings (thresholds).
 * @return {Element} Meters.
 */
export function LabelMeters( { labels, settings } ) {
	return (
		<ul className="dev3cm-meters">
			{ Object.keys( LABELS ).map( ( key ) => {
				const score = Number( labels[ key ] ) || 0;
				const threshold = Number( settings[ `ai_${ key }_threshold` ] ) || 1;
				const over = settings[ `ai_${ key }_action` ] !== 'off' && score >= threshold;
				return (
					<li key={ key } className={ over ? 'is-over' : '' }>
						<span className="dev3cm-meters__label">{ LABELS[ key ] }</span>
						<span className="dev3cm-meters__track" role="img" aria-label={ `${ LABELS[ key ] }: ${ Math.round( score * 100 ) }%` }>
							<span className="dev3cm-meters__fill" style={ { width: `${ score * 100 }%` } } />
							<span className="dev3cm-meters__mark" style={ { left: `${ threshold * 100 }%` } } />
						</span>
						<span className="dev3cm-meters__value">{ Math.round( score * 100 ) }%</span>
					</li>
				);
			} ) }
		</ul>
	);
}

/**
 * Item drawer: content, reasons, AI labels, actions, reply composer and history.
 *
 * @param {Object}   props           Props.
 * @param {number}   props.id        Item id (0 = closed).
 * @param {Object}   props.settings  Settings.
 * @param {Function} props.onClose   Close.
 * @param {Function} props.onChanged Item changed.
 * @param {Function} props.onUsage   AI used.
 * @return {Element} Drawer.
 */
export default function ItemDrawer( { id, settings, onClose, onChanged, onUsage } ) {
	const toast = useToast();
	const [ item, setItem ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	const [ reply, setReply ] = useState( '' );
	const [ approve, setApprove ] = useState( true );
	const [ instructions, setInstructions ] = useState( '' );
	const [ draft, setDraft ] = useState( null );
	const [ aiError, setAiError ] = useState( null );
	const aiReady = settings.api_key_set && config.canGenerate;

	useEffect( () => {
		setItem( null );
		setError( null );
		setDraft( null );
		setAiError( null );
		setReply( '' );
		setInstructions( '' );
		if ( id ) {
			request( `items/${ id }` ).then( setItem ).catch( setError );
		}
	}, [ id ] );

	const act = ( action ) => {
		setBusy( action );
		request( `items/${ id }/action`, { method: 'POST', data: { action } } )
			.then( ( next ) => {
				setItem( next );
				onChanged();
				toast.success( __( 'Done.', 'dev3-ai-comment-moderator' ) );
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setBusy( '' ) );
	};

	const rescan = () => {
		setBusy( 'classify' );
		setAiError( null );
		request( `items/${ id }/classify`, { method: 'POST' } )
			.then( ( next ) => {
				setItem( next );
				onChanged();
				toast.success( __( 'DEV3 AI check finished.', 'dev3-ai-comment-moderator' ) );
			} )
			.catch( setAiError )
			.finally( () => {
				setBusy( '' );
				onUsage();
			} );
	};

	const generate = () => {
		setBusy( 'draft' );
		setAiError( null );
		request( `items/${ id }/reply-draft`, { method: 'POST', data: { instructions } } )
			.then( setDraft )
			.catch( setAiError )
			.finally( () => {
				setBusy( '' );
				onUsage();
			} );
	};

	const send = ( content ) => {
		setBusy( 'reply' );
		return request( `items/${ id }/reply`, { method: 'POST', data: { content, approve: approve && item.status !== 'live' } } )
			.then( ( next ) => {
				setItem( next );
				setReply( '' );
				setDraft( null );
				onChanged();
				toast.success( __( 'Reply posted.', 'dev3-ai-comment-moderator' ) );
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setBusy( '' ) );
	};

	const status = item ? STATUS[ item.status ] || {} : {};
	const templates = item ? item.templates || [] : [];

	return (
		<Drawer
			open={ !! id }
			onClose={ onClose }
			size="lg"
			title={ item ? sprintf( /* translators: %s: source */ __( '%s details', 'dev3-ai-comment-moderator' ), SOURCES[ item.source ] || '' ) : __( 'Details', 'dev3-ai-comment-moderator' ) }
			footer={
				item && (
					<div className="dev3cm-drawer-actions">
						{ item.status !== 'live' && (
							<Button variant="primary" icon="check" loading={ busy === 'approve' } onClick={ () => act( 'approve' ) }>
								{ __( 'Approve', 'dev3-ai-comment-moderator' ) }
							</Button>
						) }
						{ item.status !== 'held' && (
							<Button variant="secondary" icon="pause" loading={ busy === 'hold' } onClick={ () => act( 'hold' ) }>
								{ __( 'Hold', 'dev3-ai-comment-moderator' ) }
							</Button>
						) }
						{ item.status !== 'spam' && (
							<Button variant="secondary" icon="alert" loading={ busy === 'spam' } onClick={ () => act( 'spam' ) }>
								{ __( 'Spam', 'dev3-ai-comment-moderator' ) }
							</Button>
						) }
						{ ! item.reviewed && (
							<Button variant="ghost" loading={ busy === 'dismiss' } onClick={ () => act( 'dismiss' ) }>
								{ __( 'Mark reviewed', 'dev3-ai-comment-moderator' ) }
							</Button>
						) }
						{ item.status !== 'trash' && (
							<Button variant="ghost" icon="trash" loading={ busy === 'trash' } onClick={ () => act( 'trash' ) }>
								{ __( 'Trash', 'dev3-ai-comment-moderator' ) }
							</Button>
						) }
					</div>
				)
			}
		>
			{ error && <ErrorNotice error={ error } /> }
			{ ! item && ! error && <SkeletonCard lines={ 6 } /> }
			{ item && (
				<Stack gap={ 16 }>
					<div className="dev3cm-chips">
						<Badge tone={ status.tone }>{ status.label }</Badge>
						{ item.decision !== 'none' && <Badge tone={ ACTION_TONE[ item.decision ] }>{ /* translators: %s: policy action */ sprintf( __( 'Policy: %s', 'dev3-ai-comment-moderator' ), ACTION_LABEL[ item.decision ] ) }</Badge> }
						{ item.reviewed && <Badge tone="neutral">{ __( 'Reviewed', 'dev3-ai-comment-moderator' ) }</Badge> }
						<Sentiment value={ item.sentiment } />
						{ item.rating > 0 && <Badge tone="info">{ /* translators: %d: stars */ sprintf( __( '%d/5 stars', 'dev3-ai-comment-moderator' ), item.rating ) }</Badge> }
					</div>
					<blockquote className="dev3cm-content">{ item.content || item.excerpt }</blockquote>
					<dl className="dev3cm-meta-grid">
						<dt>{ __( 'Author', 'dev3-ai-comment-moderator' ) }</dt>
						<dd>
							{ item.author_name || __( 'Anonymous', 'dev3-ai-comment-moderator' ) }
							{ item.author_email ? ` · ${ item.author_email }` : '' }
							{ item.user_id ? ` · ${ __( 'registered user', 'dev3-ai-comment-moderator' ) }` : '' }
						</dd>
						<dt>{ __( 'Posted on', 'dev3-ai-comment-moderator' ) }</dt>
						<dd>
							{ item.post ? (
								<a href={ item.post.url } target="_blank" rel="noreferrer">
									{ item.post.title }
								</a>
							) : (
								'–'
							) }
						</dd>
						<dt>{ __( 'When', 'dev3-ai-comment-moderator' ) }</dt>
						<dd>{ ago( item.created_at ) }</dd>
						{ item.ip_hash && (
							<>
								<dt>{ __( 'IP (hashed)', 'dev3-ai-comment-moderator' ) }</dt>
								<dd>
									<code>{ item.ip_hash }</code>
								</dd>
							</>
						) }
						<dt>{ __( 'Edit', 'dev3-ai-comment-moderator' ) }</dt>
						<dd>
							<a href={ item.edit_url }>{ __( 'Open in WordPress', 'dev3-ai-comment-moderator' ) }</a>
						</dd>
					</dl>
					{ item.parent && (
						<div className="dev3cm-parent">
							<span className="dev3cm-muted">{ /* translators: %s: author */ sprintf( __( 'In reply to %s', 'dev3-ai-comment-moderator' ), item.parent.author || __( 'Anonymous', 'dev3-ai-comment-moderator' ) ) }</span>
							<p>{ item.parent.excerpt }</p>
						</div>
					) }

					<section className="dev3cm-section" aria-labelledby="dev3cm-reasons-h">
						<h3 id="dev3cm-reasons-h">{ __( 'Why it was flagged', 'dev3-ai-comment-moderator' ) }</h3>
						{ item.reasons.length ? (
							<ul className="dev3cm-reasonlist">
								{ item.reasons.map( ( r ) => (
									<li key={ r.code }>
										<Badge tone={ ACTION_TONE[ r.action ] }>{ ACTION_LABEL[ r.action ] }</Badge>
										<span>
											<strong>{ r.label }</strong>
											{ r.score !== null && r.score !== undefined ? ` · ${ Math.round( r.score * 100 ) }%` : '' }
											{ r.detail ? ` · ${ r.detail }` : '' }
										</span>
									</li>
								) ) }
							</ul>
						) : (
							<p className="dev3cm-muted">{ __( 'No rule or DEV3 AI label fired.', 'dev3-ai-comment-moderator' ) }</p>
						) }
					</section>

					<section className="dev3cm-section dev3cm-section--ai" aria-labelledby="dev3cm-ai-h">
						<div className="dev3cm-section__head">
							<h3 id="dev3cm-ai-h">{ __( 'DEV3 AI labels', 'dev3-ai-comment-moderator' ) }</h3>
							<Button size="sm" variant="ai" icon="sparkles" loading={ busy === 'classify' } disabled={ ! aiReady } onClick={ rescan }>
								{ item.ai_state === 'done' ? __( 'Re-scan with DEV3 AI', 'dev3-ai-comment-moderator' ) : __( 'Check with DEV3 AI', 'dev3-ai-comment-moderator' ) }
							</Button>
						</div>
						{ item.ai_state === 'done' ? (
							<>
								<LabelMeters labels={ item.labels } settings={ settings } />
								{ item.reason && <p className="dev3cm-muted">{ item.reason }</p> }
								{ item.model && <p className="dev3cm-muted">{ /* translators: %s: model */ sprintf( __( 'Model: %s', 'dev3-ai-comment-moderator' ), item.model ) }</p> }
							</>
						) : (
							<p className="dev3cm-muted">
								{ item.ai_state === 'queued' && __( 'Waiting for the background DEV3 AI check.', 'dev3-ai-comment-moderator' ) }
								{ item.ai_state === 'failed' && __( 'The DEV3 AI check failed. Rules decided; try again.', 'dev3-ai-comment-moderator' ) }
								{ ( item.ai_state === 'none' || item.ai_state === 'skipped' ) && ( aiReady ? __( 'Not checked by DEV3 AI yet.', 'dev3-ai-comment-moderator' ) : __( 'Add a Gemini API key in API Config to score spam, toxicity, harassment, off-topic and personal data.', 'dev3-ai-comment-moderator' ) ) }
							</p>
						) }
						{ aiError && <ErrorNotice error={ aiError } onDismiss={ () => setAiError( null ) } /> }
					</section>

					<section className="dev3cm-section" aria-labelledby="dev3cm-reply-h">
						<h3 id="dev3cm-reply-h">{ __( 'Reply', 'dev3-ai-comment-moderator' ) }</h3>
						{ item.replies.length > 0 && (
							<ul className="dev3cm-replies">
								{ item.replies.map( ( r, i ) => (
									<li key={ i }>
										<span className="dev3cm-muted">
											{ r.author } · { ago( r.date ) }
										</span>
										<p>{ r.excerpt }</p>
									</li>
								) ) }
							</ul>
						) }
						{ templates.length > 0 && (
							<Select
								label={ __( 'Start from a template', 'dev3-ai-comment-moderator' ) }
								value=""
								onChange={ ( v ) => v !== '' && setReply( templates[ Number( v ) ].text ) }
								placeholder={ __( 'Choose a template…', 'dev3-ai-comment-moderator' ) }
								options={ templates.map( ( t, i ) => ( { value: String( i ), label: t.name } ) ) }
							/>
						) }
						<Textarea label={ __( 'Your reply', 'dev3-ai-comment-moderator' ) } value={ reply } onChange={ setReply } rows={ 4 } maxLength={ 5000 } />
						{ item.status !== 'live' && <Checkbox label={ __( 'Approve the comment when I reply', 'dev3-ai-comment-moderator' ) } checked={ approve } onChange={ setApprove } /> }
						<div className="dev3cm-actions">
							<Button variant="primary" disabled={ ! reply.trim() } loading={ busy === 'reply' } onClick={ () => send( reply ) }>
								{ __( 'Post reply', 'dev3-ai-comment-moderator' ) }
							</Button>
						</div>
						<div className="dev3cm-aibox">
							<TextField
								label={ __( 'Instructions for DEV3 AI (optional)', 'dev3-ai-comment-moderator' ) }
								value={ instructions }
								onChange={ setInstructions }
								maxLength={ 400 }
								placeholder={ __( 'e.g. mention the new tutorial, offer to help by email', 'dev3-ai-comment-moderator' ) }
							/>
							<AiButton loading={ busy === 'draft' } disabled={ ! aiReady } onClick={ generate } loadingText={ __( 'Drafting…', 'dev3-ai-comment-moderator' ) }>
								{ __( 'Draft reply with DEV3 AI', 'dev3-ai-comment-moderator' ) }
							</AiButton>
							{ ! aiReady && <p className="dev3cm-muted">{ __( 'Add a Gemini API key in API Config to draft replies in your brand voice.', 'dev3-ai-comment-moderator' ) }</p> }
							{ draft && (
								<AiDraftReview
									key={ draft.draft }
									draft={ draft.draft }
									format="text"
									acceptLabel={ __( 'Post this reply', 'dev3-ai-comment-moderator' ) }
									accepting={ busy === 'reply' }
									regenerating={ busy === 'draft' }
									onAccept={ ( value ) => send( value ) }
									onReject={ () => setDraft( null ) }
									onRegenerate={ generate }
									meta={ { model: draft.model, tokens: draft.tokens, cached: draft.cached } }
								/>
							) }
						</div>
					</section>

					{ item.history.length > 0 && (
						<section className="dev3cm-section" aria-labelledby="dev3cm-hist-h">
							<h3 id="dev3cm-hist-h">{ __( 'History', 'dev3-ai-comment-moderator' ) }</h3>
							<ol className="dev3cm-timeline">
								{ item.history
									.slice()
									.reverse()
									.map( ( h, i ) => (
										<li key={ i }>
											<strong>{ HISTORY[ h.action ] || h.action }</strong>
											<span className="dev3cm-muted">
												{ ` ${ __( 'by', 'dev3-ai-comment-moderator' ) } ${ byLabel( h.by ) } · ${ ago( h.at ) }` }
											</span>
											{ h.note && <span className="dev3cm-timeline__note">{ h.note }</span> }
										</li>
									) ) }
							</ol>
						</section>
					) }
				</Stack>
			) }
		</Drawer>
	);
}
