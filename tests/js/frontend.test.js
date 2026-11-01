/**
 * Front-end "be kind" nudge: local signals, AI path with fallback, panel actions.
 */
import { normalize, signals } from '../../src/frontend/check';
import { ask, boot, onSubmit, panel } from '../../src/frontend/nudge';

const STRINGS = { title: 'Before you post…', message: 'May hurt.', edit: 'Edit my comment', use: 'Use suggested wording', post: 'Post anyway', checking: 'Checking…', suggestion: 'Kinder:', reasons: { insult: 'Hurtful words.', shouting: 'Shouting.', pile_on: 'Too many marks.', ai: 'AI' } };

const form = () => {
	document.body.innerHTML = '<form id="commentform" class="comment-form" action="/wp-comments-post.php" method="post"><textarea id="comment" name="comment"></textarea><p class="form-submit"><input type="submit" name="submit" id="submit" value="Post Comment"></p></form>';
	return document.getElementById( 'commentform' );
};

const submitEvent = ( f ) => {
	const e = new window.Event( 'submit', { bubbles: true, cancelable: true } );
	e.submitter = f.querySelector( '[type="submit"]' );
	Object.defineProperty( e, 'target', { value: f } );
	return e;
};

beforeEach( () => {
	window.dev3cmNudge = { url: '/wp-json/dev3cm/v1/public/nudge', ai: false, words: [ 'idiot', 'shut up' ], strings: STRINGS };
	window.HTMLFormElement.prototype.requestSubmit = jest.fn();
} );

afterEach( () => {
	document.body.innerHTML = '';
	delete window.fetch;
} );

test( 'normalize and signals mirror the PHP rules', () => {
	expect( normalize( "Don't SHOUT, ok?" ) ).toBe( 'don t shout ok' );
	expect( signals( 'You idiot.', [ 'idiot' ] ) ).toEqual( [ 'insult' ] );
	expect( signals( 'Please SHUT UP already', [ 'shut up' ] ) ).toEqual( [ 'insult' ] );
	expect( signals( 'THIS IS THE WORST THING EVER', [] ) ).toEqual( [ 'shouting' ] );
	expect( signals( 'Really????', [] ) ).toEqual( [ 'pile_on' ] );
	expect( signals( 'Idiotic is not the word idiot2', [ 'idiot' ] ) ).toEqual( [] );
	expect( signals( 'Lovely post, thanks!', [ 'idiot' ] ) ).toEqual( [] );
} );

test( 'kind comments submit normally without AI (no preventDefault)', () => {
	const f = form();
	f.querySelector( '#comment' ).value = 'Great write-up, thank you.';
	const e = submitEvent( f );
	onSubmit( e );
	expect( e.defaultPrevented ).toBe( false );
	expect( f.querySelector( '.dev3cm-nudge' ) ).toBeNull();
} );

test( 'unkind comments get the local nudge; Edit focuses the field, Post anyway submits', () => {
	const f = form();
	const field = f.querySelector( '#comment' );
	field.value = 'You are an idiot';
	const e = submitEvent( f );
	onSubmit( e );
	expect( e.defaultPrevented ).toBe( true );
	const box = f.querySelector( '.dev3cm-nudge' );
	expect( box ).not.toBeNull();
	expect( box.textContent ).toContain( 'Hurtful words.' );
	expect( document.activeElement.className ).toBe( 'dev3cm-nudge__title' );
	// Panel sits before the submit row.
	expect( box.nextElementSibling.className ).toBe( 'form-submit' );
	Array.from( box.querySelectorAll( 'button' ) ).find( ( b ) => b.textContent === 'Edit my comment' ).click();
	expect( f.querySelector( '.dev3cm-nudge' ) ).toBeNull();
	expect( document.activeElement ).toBe( field );

	onSubmit( submitEvent( f ) );
	Array.from( f.querySelectorAll( '.dev3cm-nudge button' ) ).find( ( b ) => b.textContent === 'Post anyway' ).click();
	expect( window.HTMLFormElement.prototype.requestSubmit ).toHaveBeenCalled();
	// The resubmit is let through once.
	const again = submitEvent( f );
	onSubmit( again );
	expect( again.defaultPrevented ).toBe( false );
} );

test( 'AI verdict with a kinder suggestion can be applied', async () => {
	window.dev3cmNudge.ai = true;
	window.fetch = jest.fn( () => Promise.resolve( { ok: true, json: () => Promise.resolve( { unkind: true, reasons: [ 'ai' ], message: 'This may sound harsh.', suggestion: 'I disagree with this point.', ai: true } ) } ) );
	const f = form();
	const field = f.querySelector( '#comment' );
	field.value = 'What a dumb take, typical.';
	const e = submitEvent( f );
	onSubmit( e );
	expect( e.defaultPrevented ).toBe( true );
	expect( f.querySelector( '.dev3cm-checking' ).textContent ).toBe( 'Checking…' );
	await new Promise( ( r ) => setTimeout( r, 0 ) );
	await new Promise( ( r ) => setTimeout( r, 0 ) );
	const box = f.querySelector( '.dev3cm-nudge' );
	expect( box.textContent ).toContain( 'This may sound harsh.' );
	expect( box.querySelector( 'blockquote' ).textContent ).toBe( 'I disagree with this point.' );
	Array.from( box.querySelectorAll( 'button' ) ).find( ( b ) => b.textContent === 'Use suggested wording' ).click();
	expect( field.value ).toBe( 'I disagree with this point.' );
	expect( f.querySelector( '.dev3cm-checking' ) ).toBeNull();
	const body = JSON.parse( window.fetch.mock.calls[ 0 ][ 1 ].body );
	expect( body ).toEqual( { text: 'What a dumb take, typical.' } );
} );

test( 'AI says kind → form is sent', async () => {
	window.dev3cmNudge.ai = true;
	window.fetch = jest.fn( () => Promise.resolve( { ok: true, json: () => Promise.resolve( { unkind: false, reasons: [], suggestion: '' } ) } ) );
	const f = form();
	f.querySelector( '#comment' ).value = 'Nice explanation.';
	onSubmit( submitEvent( f ) );
	await new Promise( ( r ) => setTimeout( r, 0 ) );
	await new Promise( ( r ) => setTimeout( r, 0 ) );
	expect( window.HTMLFormElement.prototype.requestSubmit ).toHaveBeenCalled();
} );

test( 'network errors fall back to the local verdict', async () => {
	window.fetch = jest.fn( () => Promise.reject( new Error( 'offline' ) ) );
	const v = await ask( window.dev3cmNudge, 'you idiot', [ 'insult' ] );
	expect( v ).toEqual( { unkind: true, reasons: [ 'insult' ], suggestion: '', message: '' } );
	window.fetch = jest.fn( () => Promise.resolve( { ok: false, json: () => Promise.resolve( {} ) } ) );
	const v2 = await ask( window.dev3cmNudge, 'fine', [] );
	expect( v2.unkind ).toBe( false );
} );

test( 'other forms are ignored and boot registers once', () => {
	document.body.innerHTML = '<form id="search"><textarea id="comment">idiot</textarea></form>';
	const e = new window.Event( 'submit', { bubbles: true, cancelable: true } );
	Object.defineProperty( e, 'target', { value: document.getElementById( 'search' ) } );
	onSubmit( e );
	expect( e.defaultPrevented ).toBe( false );
	const spy = jest.spyOn( document, 'addEventListener' );
	boot();
	boot();
	expect( spy.mock.calls.filter( ( c ) => c[ 0 ] === 'submit' ) ).toHaveLength( 1 );
} );

test( 'Escape in the panel returns to the field', () => {
	const f = form();
	const field = f.querySelector( '#comment' );
	const box = panel( f, field, { reasons: [ 'shouting' ] }, f.querySelector( '#submit' ), STRINGS );
	box.dispatchEvent( new window.KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } ) );
	expect( f.querySelector( '.dev3cm-nudge' ) ).toBeNull();
	expect( document.activeElement ).toBe( field );
} );
