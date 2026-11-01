import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import { Badge, Button, Card, Checkbox, ErrorNotice, FormGrid, JobProgress, NumberField, PageHeader, Select, Stack, TextField, Textarea, Toggle, useToast } from '../core3/ui';
import { LabelMeters } from './ItemDrawer';
import { ACTION_LABEL, ACTION_OPTIONS, ACTION_TONE, LABELS, Sentiment, useSettingsForm } from './helpers';

export const KEYS = [
	'src_comments',
	'src_reviews',
	'src_bbpress',
	'skip_trusted',
	'rule_links_action',
	'rule_links_max',
	'rule_blocklist_action',
	'rule_blocklist_words',
	'rule_rate_action',
	'rule_rate_max',
	'rule_rate_minutes',
	'rule_duplicate_action',
	'rule_duplicate_days',
	'rule_pii_action',
	'rule_profanity_action',
	'rule_akismet_action',
	'ai_mode',
	'ai_timeout',
	'ai_spam_action',
	'ai_spam_threshold',
	'ai_toxicity_action',
	'ai_toxicity_threshold',
	'ai_harassment_action',
	'ai_harassment_threshold',
	'ai_off_topic_action',
	'ai_off_topic_threshold',
	'ai_pii_action',
	'ai_pii_threshold',
	'retention_days',
	'delete_on_uninstall',
];

const RULES = [
	{ id: 'links', title: __( 'Links', 'dev3-ai-comment-moderator' ), help: __( 'Comments with more links than allowed.', 'dev3-ai-comment-moderator' ) },
	{ id: 'blocklist', title: __( 'Blocklist', 'dev3-ai-comment-moderator' ), help: __( 'Words or phrases in the text, name, email or website.', 'dev3-ai-comment-moderator' ) },
	{ id: 'rate', title: __( 'Posting rate', 'dev3-ai-comment-moderator' ), help: __( 'Many posts from one IP address or email in a short time.', 'dev3-ai-comment-moderator' ) },
	{ id: 'duplicate', title: __( 'Duplicates', 'dev3-ai-comment-moderator' ), help: __( 'The same text pasted on other posts (typical spam).', 'dev3-ai-comment-moderator' ) },
	{ id: 'pii', title: __( 'Personal data', 'dev3-ai-comment-moderator' ), help: __( 'Emails, phone numbers, card numbers or IP addresses in the text.', 'dev3-ai-comment-moderator' ) },
	{ id: 'profanity', title: __( 'Profanity', 'dev3-ai-comment-moderator' ), help: __( 'Built-in list of insults and swear words (works without AI).', 'dev3-ai-comment-moderator' ) },
	{ id: 'akismet', title: __( 'Akismet', 'dev3-ai-comment-moderator' ), help: __( 'Records Akismet spam verdicts as a reason when Akismet is active.', 'dev3-ai-comment-moderator' ) },
];

const LABEL_HELP = {
	spam: __( 'Adverts, SEO links, scams and gibberish.', 'dev3-ai-comment-moderator' ),
	toxicity: __( 'Insults, profanity aimed at people, demeaning language. Criticism of ideas is allowed.', 'dev3-ai-comment-moderator' ),
	harassment: __( 'Threats, targeted attacks, doxxing, sexual harassment.', 'dev3-ai-comment-moderator' ),
	off_topic: __( 'Nothing to do with the post it was written under.', 'dev3-ai-comment-moderator' ),
	pii: __( 'The writer shares personal data (address, phone, ID…).', 'dev3-ai-comment-moderator' ),
};

/**
 * Policy & rules: sources, rule screen, AI policy matrix, tester, scanner, data.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.settings Settings.
 * @param {Function} props.save     Save.
 * @param {Function} props.onUsage  AI used.
 * @return {Element} Screen.
 */
export default function PolicyTab( { settings, save, onUsage } ) {
	const toast = useToast();
	const { form, set, dirty, busy, submit, reset } = useSettingsForm( settings, save, KEYS );
	const [ sample, setSample ] = useState( '' );
	const [ sampleAi, setSampleAi ] = useState( false );
	const [ testing, setTesting ] = useState( false );
	const [ result, setResult ] = useState( null );
	const [ testError, setTestError ] = useState( null );
	const [ days, setDays ] = useState( 30 );
	const [ scanAi, setScanAi ] = useState( false );
	const [ move, setMove ] = useState( false );
	const [ job, setJob ] = useState( null );
	const [ scanning, setScanning ] = useState( false );
	const aiReady = settings.api_key_set && config.canGenerate;

	const test = () => {
		setTesting( true );
		setTestError( null );
		request( 'test', { method: 'POST', data: { text: sample, ai: sampleAi && aiReady } } )
			.then( setResult )
			.catch( setTestError )
			.finally( () => {
				setTesting( false );
				if ( sampleAi ) {
					onUsage();
				}
			} );
	};

	const scan = () => {
		setScanning( true );
		request( 'scan', { method: 'POST', data: { days, ai: scanAi && aiReady, move } } )
			.then( ( res ) => setJob( res.job ) )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setScanning( false ) );
	};

	const extra = ( id ) => {
		switch ( id ) {
			case 'links':
				return <NumberField label={ __( 'Links allowed', 'dev3-ai-comment-moderator' ) } min={ 0 } max={ 50 } value={ form.rule_links_max } onChange={ set( 'rule_links_max' ) } />;
			case 'blocklist':
				return (
					<Textarea
						label={ __( 'Blocked words and phrases', 'dev3-ai-comment-moderator' ) }
						help={ __( 'One per line (or comma separated). Whole words, not case sensitive.', 'dev3-ai-comment-moderator' ) }
						rows={ 4 }
						value={ form.rule_blocklist_words }
						onChange={ set( 'rule_blocklist_words' ) }
					/>
				);
			case 'rate':
				return (
					<FormGrid>
						<NumberField label={ __( 'Posts allowed', 'dev3-ai-comment-moderator' ) } min={ 1 } max={ 500 } value={ form.rule_rate_max } onChange={ set( 'rule_rate_max' ) } />
						<NumberField label={ __( 'Within minutes', 'dev3-ai-comment-moderator' ) } min={ 1 } max={ 1440 } value={ form.rule_rate_minutes } onChange={ set( 'rule_rate_minutes' ) } />
					</FormGrid>
				);
			case 'duplicate':
				return <NumberField label={ __( 'Look back (days)', 'dev3-ai-comment-moderator' ) } min={ 1 } max={ 365 } value={ form.rule_duplicate_days } onChange={ set( 'rule_duplicate_days' ) } />;
			default:
				return null;
		}
	};

	const footer = (
		<>
			<Button variant="ghost" disabled={ ! dirty || busy } onClick={ reset }>
				{ __( 'Discard', 'dev3-ai-comment-moderator' ) }
			</Button>
			<Button variant="primary" type="submit" loading={ busy } disabled={ ! dirty }>
				{ __( 'Save changes', 'dev3-ai-comment-moderator' ) }
			</Button>
		</>
	);

	return (
		<>
			<PageHeader title={ __( 'Policy & rules', 'dev3-ai-comment-moderator' ) } description={ __( 'Decide what is screened and what happens when a rule or DEV3 AI label fires. The moderator only holds or spams: it never approves or deletes.', 'dev3-ai-comment-moderator' ) } />
			<form onSubmit={ submit }>
				<Stack gap={ 16 }>
					<Card title={ __( 'Sources', 'dev3-ai-comment-moderator' ) } description={ __( 'Where the moderator screens new posts.', 'dev3-ai-comment-moderator' ) }>
						<Stack gap={ 12 }>
							<Toggle label={ __( 'WordPress comments', 'dev3-ai-comment-moderator' ) } checked={ !! form.src_comments } onChange={ set( 'src_comments' ) } />
							<Toggle
								label={ __( 'WooCommerce product reviews', 'dev3-ai-comment-moderator' ) }
								help={ config.woo ? '' : __( 'WooCommerce is not active.', 'dev3-ai-comment-moderator' ) }
								checked={ !! form.src_reviews }
								onChange={ set( 'src_reviews' ) }
							/>
							<Toggle
								label={ __( 'bbPress topics and replies', 'dev3-ai-comment-moderator' ) }
								help={ config.bbpress ? '' : __( 'bbPress is not active.', 'dev3-ai-comment-moderator' ) }
								checked={ !! form.src_bbpress }
								onChange={ set( 'src_bbpress' ) }
							/>
							<Toggle label={ __( 'Skip moderators and admins', 'dev3-ai-comment-moderator' ) } help={ __( 'Users who can moderate comments are trusted.', 'dev3-ai-comment-moderator' ) } checked={ !! form.skip_trusted } onChange={ set( 'skip_trusted' ) } />
						</Stack>
					</Card>

					<Card title={ __( 'Rule screen', 'dev3-ai-comment-moderator' ) } description={ __( 'Fast checks that run before anything else, with no AI and no network. The strictest action wins.', 'dev3-ai-comment-moderator' ) }>
						<ul className="dev3cm-rules">
							{ RULES.map( ( rule ) => (
								<li key={ rule.id } className="dev3cm-rule">
									<div className="dev3cm-rule__head">
										<div>
											<h3 className="dev3cm-rule__title">{ rule.title }</h3>
											<p className="dev3cm-muted">{ rule.help }</p>
										</div>
										<Select label={ sprintf( /* translators: %s: rule */ __( 'Action for %s', 'dev3-ai-comment-moderator' ), rule.title ) } hideLabel value={ form[ `rule_${ rule.id }_action` ] } onChange={ set( `rule_${ rule.id }_action` ) } options={ ACTION_OPTIONS } />
									</div>
									{ form[ `rule_${ rule.id }_action` ] !== 'off' && extra( rule.id ) }
								</li>
							) ) }
						</ul>
					</Card>

					<Card tone="ai" title={ __( 'DEV3 AI classification', 'dev3-ai-comment-moderator' ) } description={ __( 'Scores each post 0–100% for five labels. Set the action and the score that triggers it.', 'dev3-ai-comment-moderator' ) }>
						<Stack gap={ 16 }>
							{ ! settings.api_key_set && <p className="dev3cm-muted">{ __( 'Add a Gemini API key in API Config to turn this on. Until then only the rule screen runs.', 'dev3-ai-comment-moderator' ) }</p> }
							<FormGrid>
								<Select
									label={ __( 'When to run', 'dev3-ai-comment-moderator' ) }
									value={ form.ai_mode }
									onChange={ set( 'ai_mode' ) }
									options={ [
										{ value: 'sync', label: __( 'Before publishing (recommended)', 'dev3-ai-comment-moderator' ) },
										{ value: 'async', label: __( 'In the background after posting', 'dev3-ai-comment-moderator' ) },
										{ value: 'off', label: __( 'Off (rules only)', 'dev3-ai-comment-moderator' ) },
									] }
									help={ __( 'Before publishing waits for DEV3 AI (up to the timeout) and falls back to rules, then retries in the background.', 'dev3-ai-comment-moderator' ) }
								/>
								<NumberField label={ __( 'Timeout (seconds)', 'dev3-ai-comment-moderator' ) } min={ 3 } max={ 30 } value={ form.ai_timeout } onChange={ set( 'ai_timeout' ) } />
							</FormGrid>
							<div className="dev3cm-matrix" role="table" aria-label={ __( 'AI policy', 'dev3-ai-comment-moderator' ) }>
								<div className="dev3cm-matrix__row dev3cm-matrix__row--head" role="row">
									<span role="columnheader">{ __( 'Label', 'dev3-ai-comment-moderator' ) }</span>
									<span role="columnheader">{ __( 'Action', 'dev3-ai-comment-moderator' ) }</span>
									<span role="columnheader">{ __( 'Trigger at score', 'dev3-ai-comment-moderator' ) }</span>
								</div>
								{ Object.keys( LABELS ).map( ( key ) => (
									<div className="dev3cm-matrix__row" role="row" key={ key }>
										<span role="cell" className="dev3cm-matrix__label">
											<strong>{ LABELS[ key ] }</strong>
											<span className="dev3cm-muted">{ LABEL_HELP[ key ] }</span>
										</span>
										<span role="cell">
											<Select label={ sprintf( /* translators: %s: label */ __( 'Action for %s', 'dev3-ai-comment-moderator' ), LABELS[ key ] ) } hideLabel value={ form[ `ai_${ key }_action` ] } onChange={ set( `ai_${ key }_action` ) } options={ ACTION_OPTIONS } />
										</span>
										<span role="cell" className="dev3cm-matrix__score">
											<NumberField
												label={ sprintf( /* translators: %s: label */ __( 'Score for %s (0.05–1)', 'dev3-ai-comment-moderator' ), LABELS[ key ] ) }
												hideLabel
												min={ 0.05 }
												max={ 1 }
												step={ 0.05 }
												value={ form[ `ai_${ key }_threshold` ] }
												onChange={ set( `ai_${ key }_threshold` ) }
												disabled={ form[ `ai_${ key }_action` ] === 'off' }
											/>
											<span className="dev3cm-muted">{ `≥ ${ Math.round( ( Number( form[ `ai_${ key }_threshold` ] ) || 0 ) * 100 ) }%` }</span>
										</span>
									</div>
								) ) }
							</div>
						</Stack>
					</Card>

					<Card title={ __( 'Data', 'dev3-ai-comment-moderator' ) } footer={ footer }>
						<FormGrid>
							<NumberField label={ __( 'Keep moderation records (days)', 'dev3-ai-comment-moderator' ) } help={ __( '0 keeps them forever. Comments themselves are never deleted.', 'dev3-ai-comment-moderator' ) } min={ 0 } max={ 3650 } value={ form.retention_days } onChange={ set( 'retention_days' ) } />
						</FormGrid>
						<Toggle label={ __( 'Delete plugin data when uninstalling', 'dev3-ai-comment-moderator' ) } checked={ !! form.delete_on_uninstall } onChange={ set( 'delete_on_uninstall' ) } />
					</Card>
				</Stack>
			</form>

			<Card title={ __( 'Policy tester', 'dev3-ai-comment-moderator' ) } description={ __( 'Paste a sample comment to see what the saved rules (and DEV3 AI) would do. Nothing is stored.', 'dev3-ai-comment-moderator' ) }>
				<Stack gap={ 12 }>
					<Textarea label={ __( 'Sample comment', 'dev3-ai-comment-moderator' ) } value={ sample } onChange={ setSample } rows={ 3 } maxLength={ 3000 } />
					<Checkbox label={ __( 'Include DEV3 AI scores', 'dev3-ai-comment-moderator' ) } checked={ sampleAi } onChange={ setSampleAi } disabled={ ! aiReady } />
					<div className="dev3cm-actions">
						<Button variant="secondary" loading={ testing } disabled={ ! sample.trim() } onClick={ test }>
							{ __( 'Test policy', 'dev3-ai-comment-moderator' ) }
						</Button>
					</div>
					{ testError && <ErrorNotice error={ testError } /> }
					{ result && (
						<div className="dev3cm-testresult" role="status">
							<div className="dev3cm-chips">
								<strong>{ __( 'Outcome:', 'dev3-ai-comment-moderator' ) }</strong>
								<Badge tone={ result.decision === 'none' ? 'success' : ACTION_TONE[ result.decision ] }>{ result.decision === 'none' ? __( 'Publish normally', 'dev3-ai-comment-moderator' ) : ACTION_LABEL[ result.decision ] }</Badge>
								<Sentiment value={ result.sentiment } />
							</div>
							{ result.reasons.length > 0 && (
								<ul className="dev3cm-reasonlist">
									{ result.reasons.map( ( r ) => (
										<li key={ r.code }>
											<Badge tone={ ACTION_TONE[ r.action ] }>{ ACTION_LABEL[ r.action ] }</Badge>
											<span>
												<strong>{ r.label }</strong>
												{ r.detail ? ` · ${ r.detail }` : '' }
											</span>
										</li>
									) ) }
								</ul>
							) }
							{ result.ai && <LabelMeters labels={ result.labels } settings={ settings } /> }
							{ result.ai_error && <ErrorNotice error={ result.ai_error } /> }
						</div>
					) }
				</Stack>
			</Card>

			<Card title={ __( 'Scan existing content', 'dev3-ai-comment-moderator' ) } description={ __( 'Screens comments, reviews and forum posts that were posted before the plugin was active, and fills Discussions with sentiment.', 'dev3-ai-comment-moderator' ) }>
				<Stack gap={ 12 }>
					<FormGrid>
						<NumberField label={ __( 'Posted in the last (days)', 'dev3-ai-comment-moderator' ) } min={ 1 } max={ 3650 } value={ days } onChange={ ( v ) => setDays( v || 30 ) } />
					</FormGrid>
					<Checkbox label={ __( 'Also check with DEV3 AI (uses tokens)', 'dev3-ai-comment-moderator' ) } checked={ scanAi } onChange={ setScanAi } disabled={ ! aiReady } />
					<Checkbox label={ __( 'Move matches per policy (hold/spam)', 'dev3-ai-comment-moderator' ) } help={ __( 'Off: matches are only flagged in the queue, nothing already live is hidden.', 'dev3-ai-comment-moderator' ) } checked={ move } onChange={ setMove } />
					<div className="dev3cm-actions">
						<Button variant="secondary" icon="refresh" loading={ scanning } onClick={ scan }>
							{ __( 'Start scan', 'dev3-ai-comment-moderator' ) }
						</Button>
					</div>
					{ job && <JobProgress jobId={ job.id } job={ job } title={ __( 'Scanning existing content', 'dev3-ai-comment-moderator' ) } onDone={ () => onUsage() } /> }
				</Stack>
			</Card>
		</>
	);
}
