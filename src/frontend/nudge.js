/**
 * "Be kind" nudge for comment, review and bbPress forms. Progressive enhancement: without JS the form posts
 * normally, and the server-side moderation runs either way.
 */
import { signals } from './check';

const FORMS = '#commentform, form.comment-form, #new-post, .bbp-reply-form form, .bbp-topic-form form';
const FIELDS = '#comment, textarea[name="comment"], #bbp_reply_content, #bbp_topic_content, textarea[name="bbp_reply_content"], textarea[name="bbp_topic_content"]';

/**
 * Element helper.
 *
 * @param {string} tag   Tag.
 * @param {Object} attrs Attributes (className, text…).
 * @param {Array}  kids  Children.
 * @return {HTMLElement} Element.
 */
function el( tag, attrs = {}, kids = [] ) {
	const node = document.createElement( tag );
	Object.keys( attrs ).forEach( ( k ) => {
		if ( k === 'text' ) {
			node.textContent = attrs[ k ];
		} else if ( k === 'className' ) {
			node.className = attrs[ k ];
		} else if ( k.startsWith( 'on' ) ) {
			node.addEventListener( k.slice( 2 ), attrs[ k ] );
		} else {
			node.setAttribute( k, attrs[ k ] );
		}
	} );
	kids.filter( Boolean ).forEach( ( kid ) => node.appendChild( kid ) );
	return node;
}

/**
 * Asks the server (AI with a local fallback). Falls back to the local verdict on any error or after 6 s.
 *
 * @param {Object}   cfg   Config.
 * @param {string}   text  Draft.
 * @param {string[]} local Local reasons.
 * @return {Promise<Object>} Verdict.
 */
export function ask( cfg, text, local ) {
	const fallback = { unkind: local.length > 0, reasons: local, suggestion: '', message: '' };
	const ctrl = typeof AbortController !== 'undefined' ? new AbortController() : null;
	const timer = setTimeout( () => ctrl && ctrl.abort(), 6000 );
	return window
		.fetch( cfg.url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { text } ),
			signal: ctrl ? ctrl.signal : undefined,
		} )
		.then( ( r ) => ( r.ok ? r.json() : fallback ) )
		.then( ( v ) => ( v && typeof v.unkind === 'boolean' ? v : fallback ) )
		.catch( () => fallback )
		.finally( () => clearTimeout( timer ) );
}

/**
 * Sends the form for real (skipping the nudge once).
 *
 * @param {HTMLFormElement} form      Form.
 * @param {HTMLElement}     submitter Button that was pressed.
 */
function send( form, submitter ) {
	form.dataset.dev3cmOk = '1';
	if ( typeof form.requestSubmit === 'function' ) {
		try {
			form.requestSubmit( submitter && submitter.form === form ? submitter : undefined );
			return;
		} catch ( e ) {}
	}
	// WordPress names the submit button "submit", which shadows form.submit().
	window.HTMLFormElement.prototype.submit.call( form );
}

/**
 * Removes an open panel.
 *
 * @param {HTMLFormElement} form Form.
 */
function close( form ) {
	const open = form.querySelector( '.dev3cm-nudge' );
	if ( open ) {
		open.remove();
	}
}

/**
 * Shows the nudge panel above the submit button.
 *
 * @param {HTMLFormElement}     form      Form.
 * @param {HTMLTextAreaElement} field     Comment field.
 * @param {Object}              verdict   { reasons, message, suggestion }.
 * @param {HTMLElement}         submitter Submit button.
 * @param {Object}              s         Strings.
 * @return {HTMLElement} Panel.
 */
export function panel( form, field, verdict, submitter, s ) {
	close( form );
	const uid = `dev3cm-nudge-${ Date.now() }`;
	const reasons = ( verdict.reasons || [] ).map( ( r ) => s.reasons[ r ] ).filter( Boolean );
	const items = verdict.message ? [ verdict.message ] : reasons;
	const edit = () => {
		close( form );
		field.focus();
	};
	const box = el( 'div', { className: 'dev3cm-nudge', role: 'region', 'aria-labelledby': uid }, [
		el( 'p', { className: 'dev3cm-nudge__title', id: uid, tabindex: '-1', text: s.title } ),
		el( 'p', { className: 'dev3cm-nudge__msg', text: s.message } ),
		items.length ? el( 'ul', { className: 'dev3cm-nudge__why' }, items.map( ( t ) => el( 'li', { text: t } ) ) ) : null,
		verdict.suggestion
			? el( 'div', { className: 'dev3cm-nudge__alt' }, [ el( 'p', { className: 'dev3cm-nudge__alt-label', text: s.suggestion } ), el( 'blockquote', { className: 'dev3cm-nudge__alt-text', text: verdict.suggestion } ) ] )
			: null,
		el( 'div', { className: 'dev3cm-nudge__actions' }, [
			el( 'button', { type: 'button', className: 'dev3cm-btn dev3cm-btn--primary', onclick: edit, text: s.edit } ),
			verdict.suggestion
				? el( 'button', {
					type: 'button',
					className: 'dev3cm-btn',
					onclick: () => {
						field.value = verdict.suggestion;
						field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
						edit();
					},
					text: s.use,
				} )
				: null,
			el( 'button', { type: 'button', className: 'dev3cm-btn dev3cm-btn--ghost', onclick: () => send( form, submitter ), text: s.post } ),
		] ),
	] );
	box.addEventListener( 'keydown', ( e ) => {
		if ( e.key === 'Escape' ) {
			edit();
		}
	} );
	const anchor = ( submitter && submitter.closest( '.form-submit, .bbp-submit-wrapper, p, div' ) ) || null;
	if ( anchor && form.contains( anchor ) && anchor !== form ) {
		anchor.parentNode.insertBefore( box, anchor );
	} else {
		form.appendChild( box );
	}
	box.querySelector( '.dev3cm-nudge__title' ).focus();
	return box;
}

/**
 * Submit handler (capture phase, so it runs before theme scripts).
 *
 * @param {SubmitEvent} e Event.
 */
export function onSubmit( e ) {
	const cfg = window.dev3cmNudge;
	const form = e.target;
	if ( ! cfg || ! form || ! form.matches || ! form.matches( FORMS ) ) {
		return;
	}
	if ( form.dataset.dev3cmOk === '1' ) {
		delete form.dataset.dev3cmOk;
		return;
	}
	const field = form.querySelector( FIELDS );
	const text = field ? field.value.trim() : '';
	if ( ! text ) {
		return;
	}
	const submitter = e.submitter || form.querySelector( '[type="submit"]' );
	const local = signals( text, cfg.words || [] );
	if ( ! cfg.ai && ! local.length ) {
		return;
	}
	e.preventDefault();
	if ( ! cfg.ai ) {
		panel( form, field, { reasons: local }, submitter, cfg.strings );
		return;
	}
	if ( form.dataset.dev3cmBusy === '1' ) {
		return;
	}
	form.dataset.dev3cmBusy = '1';
	if ( submitter ) {
		submitter.setAttribute( 'aria-busy', 'true' );
	}
	const status = el( 'p', { className: 'dev3cm-checking', role: 'status', text: cfg.strings.checking } );
	( submitter && submitter.parentNode ? submitter.parentNode : form ).appendChild( status );
	ask( cfg, text, local ).then( ( verdict ) => {
		delete form.dataset.dev3cmBusy;
		status.remove();
		if ( submitter ) {
			submitter.removeAttribute( 'aria-busy' );
		}
		if ( verdict.unkind ) {
			panel( form, field, verdict, submitter, cfg.strings );
		} else {
			send( form, submitter );
		}
	} );
}

/**
 * Boots once.
 */
export function boot() {
	if ( window.dev3cmNudgeBooted ) {
		return;
	}
	window.dev3cmNudgeBooted = true;
	document.addEventListener( 'submit', onSubmit, true );
}
