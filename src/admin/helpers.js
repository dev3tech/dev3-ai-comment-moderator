import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Badge, useToast } from '../core3/ui';

export const SOURCES = {
	comment: __( 'Comment', 'dev3-ai-comment-moderator' ),
	review: __( 'Product review', 'dev3-ai-comment-moderator' ),
	bbp_topic: __( 'Forum topic', 'dev3-ai-comment-moderator' ),
	bbp_reply: __( 'Forum reply', 'dev3-ai-comment-moderator' ),
};

export const STATUS = {
	live: { label: __( 'Live', 'dev3-ai-comment-moderator' ), tone: 'success' },
	held: { label: __( 'Held', 'dev3-ai-comment-moderator' ), tone: 'warning' },
	spam: { label: __( 'Spam', 'dev3-ai-comment-moderator' ), tone: 'danger' },
	trash: { label: __( 'Trash', 'dev3-ai-comment-moderator' ), tone: 'neutral' },
};

export const ACTION_LABEL = {
	off: __( 'Off', 'dev3-ai-comment-moderator' ),
	flag: __( 'Flag only', 'dev3-ai-comment-moderator' ),
	hold: __( 'Hold for review', 'dev3-ai-comment-moderator' ),
	spam: __( 'Move to spam', 'dev3-ai-comment-moderator' ),
};

export const ACTION_TONE = { flag: 'info', hold: 'warning', spam: 'danger', off: 'neutral' };

export const ACTION_OPTIONS = Object.keys( ACTION_LABEL ).map( ( value ) => ( { value, label: ACTION_LABEL[ value ] } ) );

export const LABELS = {
	spam: __( 'Spam', 'dev3-ai-comment-moderator' ),
	toxicity: __( 'Toxicity', 'dev3-ai-comment-moderator' ),
	harassment: __( 'Harassment', 'dev3-ai-comment-moderator' ),
	off_topic: __( 'Off-topic', 'dev3-ai-comment-moderator' ),
	pii: __( 'Personal data', 'dev3-ai-comment-moderator' ),
};

export const CODES = {
	links: __( 'Too many links', 'dev3-ai-comment-moderator' ),
	blocklist: __( 'Blocklist', 'dev3-ai-comment-moderator' ),
	rate: __( 'Posting too fast', 'dev3-ai-comment-moderator' ),
	duplicate: __( 'Duplicate', 'dev3-ai-comment-moderator' ),
	pii: __( 'Personal data', 'dev3-ai-comment-moderator' ),
	profanity: __( 'Profanity', 'dev3-ai-comment-moderator' ),
	akismet: __( 'Akismet', 'dev3-ai-comment-moderator' ),
	ai_spam: __( 'Spam (AI)', 'dev3-ai-comment-moderator' ),
	ai_toxicity: __( 'Toxic (AI)', 'dev3-ai-comment-moderator' ),
	ai_harassment: __( 'Harassment (AI)', 'dev3-ai-comment-moderator' ),
	ai_off_topic: __( 'Off-topic (AI)', 'dev3-ai-comment-moderator' ),
	ai_pii: __( 'Personal data (AI)', 'dev3-ai-comment-moderator' ),
};

export const HISTORY = {
	screened: __( 'Screened', 'dev3-ai-comment-moderator' ),
	scanned: __( 'Scanned', 'dev3-ai-comment-moderator' ),
	classified: __( 'Classified by DEV3 AI', 'dev3-ai-comment-moderator' ),
	held: __( 'Held', 'dev3-ai-comment-moderator' ),
	spammed: __( 'Moved to spam', 'dev3-ai-comment-moderator' ),
	approved: __( 'Approved', 'dev3-ai-comment-moderator' ),
	trashed: __( 'Moved to trash', 'dev3-ai-comment-moderator' ),
	reviewed: __( 'Marked reviewed', 'dev3-ai-comment-moderator' ),
	replied: __( 'Replied', 'dev3-ai-comment-moderator' ),
};

/**
 * "Who" of a history row.
 *
 * @param {string} by rules|ai|user:<id>|system.
 * @return {string} Label.
 */
export function byLabel( by ) {
	if ( by === 'rules' ) {
		return __( 'rules', 'dev3-ai-comment-moderator' );
	}
	if ( by === 'ai' ) {
		return __( 'DEV3 AI', 'dev3-ai-comment-moderator' );
	}
	if ( String( by ).startsWith( 'user:' ) ) {
		return __( 'a moderator', 'dev3-ai-comment-moderator' );
	}
	return by;
}

/**
 * Relative time ("3h ago").
 *
 * @param {string} iso RFC 3339 date.
 * @return {string} Label.
 */
export function ago( iso ) {
	if ( ! iso ) {
		return '';
	}
	const d = new Date( iso.replace( ' ', 'T' ) + ( /Z|[+-]\d\d:?\d\d$/.test( iso ) ? '' : 'Z' ) );
	const s = Math.max( 0, ( Date.now() - d.getTime() ) / 1000 );
	if ( isNaN( s ) ) {
		return '';
	}
	if ( s < 60 ) {
		return __( 'just now', 'dev3-ai-comment-moderator' );
	}
	if ( s < 3600 ) {
		/* translators: %d: minutes */
		return sprintf( __( '%dm ago', 'dev3-ai-comment-moderator' ), Math.floor( s / 60 ) );
	}
	if ( s < 86400 ) {
		/* translators: %d: hours */
		return sprintf( __( '%dh ago', 'dev3-ai-comment-moderator' ), Math.floor( s / 3600 ) );
	}
	if ( s < 86400 * 30 ) {
		/* translators: %d: days */
		return sprintf( __( '%dd ago', 'dev3-ai-comment-moderator' ), Math.floor( s / 86400 ) );
	}
	return d.toLocaleDateString( undefined, { year: 'numeric', month: 'short', day: 'numeric' } );
}

/**
 * Sentiment bucket.
 *
 * @param {number} v -1..1.
 * @return {string} positive|neutral|negative.
 */
export const bucket = ( v ) => {
	if ( v >= 0.25 ) {
		return 'positive';
	}
	return v <= -0.25 ? 'negative' : 'neutral';
};

export const MOOD = {
	positive: { label: __( 'Positive', 'dev3-ai-comment-moderator' ), tone: 'success' },
	neutral: { label: __( 'Neutral', 'dev3-ai-comment-moderator' ), tone: 'neutral' },
	negative: { label: __( 'Negative', 'dev3-ai-comment-moderator' ), tone: 'danger' },
};

/**
 * Sentiment chip.
 *
 * @param {Object} props       Props.
 * @param {number} props.value -1..1.
 * @return {Element} Badge.
 */
export function Sentiment( { value } ) {
	const b = bucket( Number( value ) || 0 );
	const sign = value > 0 ? '+' : '';
	return (
		<Badge tone={ MOOD[ b ].tone } dot>
			{ MOOD[ b ].label } { sign }
			{ ( Number( value ) || 0 ).toFixed( 2 ) }
		</Badge>
	);
}

/**
 * Reason badges.
 *
 * @param {Object}  props         Props.
 * @param {Array}   props.reasons Reasons.
 * @param {number}  props.max     Max shown.
 * @return {Element|null} Badges.
 */
export function Reasons( { reasons = [], max = 3 } ) {
	if ( ! reasons.length ) {
		return null;
	}
	const shown = reasons.slice( 0, max );
	return (
		<span className="dev3cm-reasons">
			{ shown.map( ( r ) => (
				<Badge key={ r.code } tone={ ACTION_TONE[ r.action ] || 'neutral' }>
					{ CODES[ r.code ] || r.label }
					{ r.score !== null && r.score !== undefined ? ` ${ Math.round( r.score * 100 ) }%` : '' }
				</Badge>
			) ) }
			{ reasons.length > max && <Badge tone="neutral">{ `+${ reasons.length - max }` }</Badge> }
		</span>
	);
}

/**
 * Positive/neutral/negative bar.
 *
 * @param {Object} props          Props.
 * @param {number} props.positive Count.
 * @param {number} props.neutral  Count.
 * @param {number} props.negative Count.
 * @return {Element} Bar.
 */
export function MoodBar( { positive = 0, neutral = 0, negative = 0 } ) {
	const total = positive + neutral + negative;
	const pct = ( n ) => ( total ? ( n / total ) * 100 : 0 );
	/* translators: 1: positive, 2: neutral, 3: negative */
	const label = sprintf( __( '%1$d positive, %2$d neutral, %3$d negative', 'dev3-ai-comment-moderator' ), positive, neutral, negative );
	return (
		<span className="dev3cm-moodbar" role="img" aria-label={ label } title={ label }>
			{ total === 0 && <span className="dev3cm-moodbar__empty" /> }
			<span className="dev3cm-moodbar__seg dev3cm-moodbar__seg--pos" style={ { width: `${ pct( positive ) }%` } } />
			<span className="dev3cm-moodbar__seg dev3cm-moodbar__seg--neu" style={ { width: `${ pct( neutral ) }%` } } />
			<span className="dev3cm-moodbar__seg dev3cm-moodbar__seg--neg" style={ { width: `${ pct( negative ) }%` } } />
		</span>
	);
}

/**
 * Settings form state shared by the settings screens.
 *
 * @param {Object}   settings Settings.
 * @param {Function} save     Save(patch) → Promise.
 * @param {string[]} keys     Keys this screen edits.
 * @return {Object} { form, set, dirty, busy, submit, reset }.
 */
export function useSettingsForm( settings, save, keys ) {
	const toast = useToast();
	const [ form, setForm ] = useState( settings );
	const [ busy, setBusy ] = useState( false );
	const set = ( key ) => ( value ) => setForm( ( f ) => ( { ...f, [ key ]: value } ) );
	const dirty = keys.some( ( k ) => JSON.stringify( form[ k ] ) !== JSON.stringify( settings[ k ] ) );
	const submit = ( e ) => {
		if ( e && e.preventDefault ) {
			e.preventDefault();
		}
		const patch = {};
		keys.forEach( ( k ) => {
			patch[ k ] = form[ k ] === '' && typeof settings[ k ] === 'number' ? settings[ k ] : form[ k ];
		} );
		setBusy( true );
		return save( patch )
			.then( ( next ) => {
				setForm( next );
				toast.success( __( 'Settings saved.', 'dev3-ai-comment-moderator' ) );
				return next;
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( false ) );
	};
	return { form, set, dirty, busy, submit, reset: () => setForm( settings ) };
}
