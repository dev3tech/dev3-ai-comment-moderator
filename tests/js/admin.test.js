/**
 * Admin screens: dashboard, queue + bulk, item drawer (reply draft review), discussions + summary, policy tester, voice, front-end.
 */
import apiFetch from '@wordpress/api-fetch';
import { ToastProvider } from '../../src/core3/ui';
import Dashboard from '../../src/admin/Dashboard';
import Queue from '../../src/admin/Queue';
import ItemDrawer from '../../src/admin/ItemDrawer';
import Discussions from '../../src/admin/Discussions';
import ThreadDrawer from '../../src/admin/ThreadDrawer';
import PolicyTab, { KEYS as POLICY_KEYS } from '../../src/admin/PolicyTab';
import VoiceTab from '../../src/admin/VoiceTab';
import FrontendTab from '../../src/admin/FrontendTab';
import { ago, bucket } from '../../src/admin/helpers';
import { act, change, click, render } from './render';

const wait = ( ms ) => act( () => new Promise( ( r ) => setTimeout( r, ms ) ) );

Object.assign( window.dev3cmConfig, {
	canGenerate: true,
	woo: true,
	bbpress: true,
	akismet: false,
	words: [ 'stupid', 'idiot' ],
	strings: { title: 'Before you post…', message: 'May hurt.', edit: 'Edit my comment', use: 'Use suggested wording', post: 'Post anyway', suggestion: 'Kinder:', reasons: { insult: 'Hurtful words.', shouting: 'Shouting.', pile_on: 'Too many marks.' } },
} );

const SETTINGS = {
	api_key_set: true,
	ai_enabled: true,
	src_comments: true,
	src_reviews: true,
	src_bbpress: true,
	skip_trusted: true,
	rule_links_action: 'hold',
	rule_links_max: 2,
	rule_blocklist_action: 'hold',
	rule_blocklist_words: '',
	rule_rate_action: 'hold',
	rule_rate_max: 5,
	rule_rate_minutes: 10,
	rule_duplicate_action: 'spam',
	rule_duplicate_days: 30,
	rule_pii_action: 'hold',
	rule_profanity_action: 'flag',
	rule_akismet_action: 'spam',
	ai_mode: 'sync',
	ai_timeout: 8,
	ai_spam_action: 'spam',
	ai_spam_threshold: 0.85,
	ai_toxicity_action: 'hold',
	ai_toxicity_threshold: 0.75,
	ai_harassment_action: 'hold',
	ai_harassment_threshold: 0.7,
	ai_off_topic_action: 'flag',
	ai_off_topic_threshold: 0.85,
	ai_pii_action: 'hold',
	ai_pii_threshold: 0.7,
	voice_tone: 'friendly',
	voice_guidelines: '',
	voice_signoff: '',
	voice_avoid: '',
	reply_templates: [],
	nudge_enabled: true,
	nudge_ai: false,
	nudge_suggest: true,
	nudge_title: '',
	nudge_message: '',
	note_enabled: false,
	note_text: '',
	summary_display: 'off',
	summary_ai_note: true,
	accent_color: '#2563eb',
	retention_days: 365,
	delete_on_uninstall: false,
};

const REASON = { code: 'ai_toxicity', label: 'Toxic (AI)', detail: 'Insults the author', action: 'hold', score: 0.91, by: 'ai' };
const ITEM = {
	id: 12,
	source: 'comment',
	object_id: 44,
	post: { id: 3, title: 'Hello world', url: 'http://x/hello', type: 'post' },
	author_name: 'Sam',
	author_email: 's@x.co',
	user_id: 0,
	excerpt: 'You are an idiot and this post is garbage.',
	status: 'held',
	decision: 'hold',
	reasons: [ REASON ],
	labels: { spam: 0.1, toxicity: 0.91, harassment: 0.4, off_topic: 0.05, pii: 0 },
	reason: 'Insults the author',
	sentiment: -0.8,
	sentiment_src: 'ai',
	ai_state: 'done',
	reviewed: false,
	created_at: new Date( Date.now() - 2 * 3600 * 1000 ).toISOString(),
};
const FULL = { ...ITEM, content: ITEM.excerpt, parent: null, history: [ { at: '2026-10-25 08:00:00', action: 'held', by: 'ai', note: 'Toxic (AI)' } ], ip_hash: 'abc123', model: 'gemini-2.5-flash', edit_url: 'http://x/edit', rating: 0, replies: [], templates: [ { name: 'Keep it kind', text: 'Hi Sam, please keep it respectful.' } ] };
const COUNTS = { needs: 3, held: 2, spam: 1, flagged: 1, reviewed: 0, trash: 0, all: 5 };

const wrap = ( el ) => <ToastProvider>{ el }</ToastProvider>;
const route = ( map, calls = [] ) =>
	apiFetch.mockImplementation( ( { url, method = 'GET', data } ) => {
		const path = url.replace( /^.*dev3cm\/v1\//, '' );
		calls.push( { method, path, data } );
		const bare = path.split( '?' )[ 0 ];
		const key = Object.keys( map ).find( ( k ) => ( k.includes( ' ' ) ? k === `${ method } ${ bare }` : method === 'GET' && bare === k ) );
		if ( ! key ) {
			return new Promise( () => {} );
		}
		const v = map[ key ];
		return Promise.resolve( typeof v === 'function' ? v( data, path ) : v );
	} );

afterEach( () => {
	apiFetch.mockReset();
	document.body.innerHTML = '';
	window.location.hash = '';
} );

test( 'helpers: sentiment buckets and relative time', () => {
	expect( bucket( 0.5 ) ).toBe( 'positive' );
	expect( bucket( -0.3 ) ).toBe( 'negative' );
	expect( bucket( 0.1 ) ).toBe( 'neutral' );
	expect( ago( new Date( Date.now() - 3 * 3600 * 1000 ).toISOString() ) ).toBe( '3h ago' );
	expect( ago( '' ) ).toBe( '' );
} );

test( 'dashboard shows KPIs, reasons, held items and negative discussions', async () => {
	const stats = {
		needs: 3,
		screened: 40,
		held: 6,
		spam: 9,
		classified: 30,
		sentiment: 0.31,
		series: [
			{ date: '2026-10-24', live: 3, held: 1, spam: 2 },
			{ date: '2026-10-25', live: 5, held: 0, spam: 1 },
		],
		codes: { ai_spam: 7, links: 3 },
		counts: COUNTS,
		negative: [ { post_id: 3, title: 'Hello world', total: 8, sentiment: -0.42, positive: 1, neutral: 2, negative: 5 } ],
		latest: [ ITEM ],
		sources: { woo: true, bbpress: true, akismet: false },
		ai: { available: true, mode: 'sync', queued: 0 },
	};
	route( { stats } );
	const navigate = jest.fn();
	const onCounts = jest.fn();
	const view = await render( wrap( <Dashboard settings={ SETTINGS } navigate={ navigate } onCounts={ onCounts } /> ) );
	await view.flush();
	expect( view.getByText( '40' ) ).toBeTruthy();
	expect( view.getByText( '+0.31' ) ).toBeTruthy();
	expect( view.$$( '.dev3cm-chart__col' ) ).toHaveLength( 2 );
	expect( view.getByText( 'Spam (AI)' ) ).toBeTruthy();
	expect( onCounts ).toHaveBeenCalledWith( COUNTS );
	await click( view.getByText( ITEM.excerpt ).closest( 'button' ) );
	expect( navigate ).toHaveBeenCalledWith( 'queue/12' );
	await click( view.getByText( 'Hello world' ).closest( 'button' ) );
	expect( navigate ).toHaveBeenCalledWith( 'discussions/3' );
} );

test( 'dashboard nudges to connect DEV3 AI without a key', async () => {
	route( { stats: { needs: 0, screened: 0, held: 0, spam: 0, ai: 0, sentiment: null, series: [], codes: {}, counts: COUNTS, negative: [], latest: [], ai: { available: false, mode: 'sync', queued: 0 } } } );
	const view = await render( wrap( <Dashboard settings={ { ...SETTINGS, api_key_set: false } } navigate={ jest.fn() } onCounts={ jest.fn() } /> ) );
	await view.flush();
	expect( view.getByText( 'Connect DEV3 AI for toxicity, harassment and off-topic detection' ) ).toBeTruthy();
	expect( view.getByText( 'All caught up' ) ).toBeTruthy();
} );

test( 'queue lists items with reasons and runs a bulk action', async () => {
	const calls = [];
	route( { items: { items: [ ITEM ], total: 1, counts: COUNTS, queued: 0 }, 'POST items/bulk': { done: 1, failed: 0 } }, calls );
	const onCounts = jest.fn();
	const view = await render( wrap( <Queue settings={ SETTINGS } navigate={ jest.fn() } openId={ 0 } onCounts={ onCounts } onUsage={ jest.fn() } /> ) );
	await view.flush();
	expect( view.getByText( ITEM.excerpt ) ).toBeTruthy();
	expect( view.getByText( 'Toxic (AI)' ) ).toBeTruthy();
	expect( onCounts ).toHaveBeenCalledWith( COUNTS );
	const box = view.$( 'input[aria-label="Select item"]' );
	await click( box );
	const select = view.$$( 'select' ).find( ( s ) => Array.from( s.options ).some( ( o ) => o.value === 'spam' && o.text === 'Mark as spam' ) );
	await change( select, 'spam' );
	await click( view.getByText( 'Apply' ) );
	await view.flush();
	const bulk = calls.find( ( c ) => c.path === 'items/bulk' );
	expect( bulk.data ).toEqual( { ids: [ 12 ], action: 'spam' } );
} );

test( 'queue quick-approves from the row without opening the drawer', async () => {
	const calls = [];
	const navigate = jest.fn();
	route( { items: { items: [ ITEM ], total: 1, counts: COUNTS, queued: 2 }, 'POST items/12/action': FULL }, calls );
	const view = await render( wrap( <Queue settings={ SETTINGS } navigate={ navigate } openId={ 0 } onCounts={ jest.fn() } onUsage={ jest.fn() } /> ) );
	await view.flush();
	expect( view.getByText( /2 items are waiting for the DEV3 AI check/ ) ).toBeTruthy();
	await click( view.$( 'button[aria-label="Approve"]' ) );
	await view.flush();
	expect( calls.find( ( c ) => c.path === 'items/12/action' ).data ).toEqual( { action: 'approve' } );
	expect( navigate ).not.toHaveBeenCalled();
} );

test( 'item drawer shows labels and posts an accepted DEV3 AI reply draft', async () => {
	const calls = [];
	route(
		{
			'items/12': FULL,
			'POST items/12/reply-draft': { draft: 'Thanks Sam, let us keep it kind here.', model: 'gemini-2.5-flash', tokens: 120, cached: false },
			'POST items/12/reply': { ...FULL, status: 'live', reply_id: 99 },
		},
		calls
	);
	const onChanged = jest.fn();
	const view = await render( wrap( <ItemDrawer id={ 12 } settings={ SETTINGS } onClose={ jest.fn() } onChanged={ onChanged } onUsage={ jest.fn() } /> ) );
	await view.flush();
	expect( view.getByText( 'Why it was flagged' ) ).toBeTruthy();
	expect( view.$$( '.dev3cm-meters li.is-over' ) ).toHaveLength( 1 );
	expect( view.$( '.dev3cm-timeline' ).textContent ).toContain( 'Held' );
	await click( view.getByText( 'Draft reply with DEV3 AI' ).closest( 'button' ) );
	await view.flush();
	expect( view.getByText( 'Not saved yet' ) ).toBeTruthy();
	await click( view.getByText( 'Post this reply' ).closest( 'button' ) );
	await view.flush();
	const posted = calls.find( ( c ) => c.method === 'POST' && c.path === 'items/12/reply' );
	expect( posted.data ).toEqual( { content: 'Thanks Sam, let us keep it kind here.', approve: true } );
	expect( onChanged ).toHaveBeenCalled();
} );

test( 'item drawer fills a reply template without AI', async () => {
	const calls = [];
	route( { 'items/12': FULL, 'POST items/12/reply': { ...FULL, reply_id: 5 } }, calls );
	const view = await render( wrap( <ItemDrawer id={ 12 } settings={ { ...SETTINGS, api_key_set: false } } onClose={ jest.fn() } onChanged={ jest.fn() } onUsage={ jest.fn() } /> ) );
	await view.flush();
	const tpl = view.$$( 'select' ).find( ( s ) => Array.from( s.options ).some( ( o ) => o.text === 'Keep it kind' ) );
	await change( tpl, '0' );
	expect( view.$( 'textarea' ).value ).toBe( 'Hi Sam, please keep it respectful.' );
	expect( view.getByText( 'Draft reply with DEV3 AI' ).closest( 'button' ).disabled ).toBe( true );
	await click( view.getByText( 'Post reply' ).closest( 'button' ) );
	await view.flush();
	expect( calls.find( ( c ) => c.path === 'items/12/reply' ).data.content ).toBe( 'Hi Sam, please keep it respectful.' );
} );

test( 'discussions list sentiment and the drawer saves an accepted summary', async () => {
	const calls = [];
	const thread = { post_id: 3, title: 'Hello world', url: 'http://x', type: 'post', source: 'comment', total: 6, live: 5, held: 1, spam: 0, sentiment: 0.4, positive: 4, neutral: 1, negative: 1, last_at: ITEM.created_at, summary: null };
	route(
		{
			threads: { items: [ thread ], total: 1 },
			'threads/3': { post: { id: 3, title: 'Hello world', url: 'http://x', type: 'post' }, stats: thread, summary: null, insights: { keywords: [ { word: 'pricing', count: 3 } ], count: 5, positive: 4, negative: 1, neutral: 0 }, recent: [ ITEM ] },
			'POST threads/3/summarize': { draft: { summary: 'Readers like the guide but want pricing details.', sentiment: 0.4, themes: [ { label: 'Pricing', sentiment: -0.2, share: 0.5 } ], questions: [ 'Is there a free plan?' ], actions: [] }, item_count: 5, model: 'gemini-2.5-flash', tokens: 300 },
			'POST threads/3/summary': { summary: 'Readers like the guide but want pricing details.', themes: [], questions: [], actions: [], sentiment: 0.4, item_count: 5, show_public: false, model: 'x', updated_at: ITEM.created_at },
		},
		calls
	);
	const list = await render( wrap( <Discussions settings={ SETTINGS } navigate={ jest.fn() } openId={ 0 } onUsage={ jest.fn() } /> ) );
	await list.flush();
	expect( list.getByText( 'Hello world' ) ).toBeTruthy();
	expect( list.getByText( 'None' ) ).toBeTruthy();
	list.unmount();

	const view = await render( wrap( <ThreadDrawer postId={ 3 } settings={ SETTINGS } onClose={ jest.fn() } onChanged={ jest.fn() } onUsage={ jest.fn() } navigate={ jest.fn() } /> ) );
	await view.flush();
	expect( view.getByText( 'pricing' ) ).toBeTruthy();
	await click( view.getByText( 'Summarize with DEV3 AI' ).closest( 'button' ) );
	await view.flush();
	expect( view.getByText( 'Is there a free plan?' ) ).toBeTruthy();
	await click( view.getByText( 'Save summary' ).closest( 'button' ) );
	await view.flush();
	const saved = calls.find( ( c ) => c.method === 'POST' && c.path === 'threads/3/summary' );
	expect( saved.data.summary ).toBe( 'Readers like the guide but want pricing details.' );
	expect( saved.data.item_count ).toBe( 5 );
	expect( saved.data.show_public ).toBe( false );
} );

test( 'policy saves the AI matrix and runs the tester', async () => {
	const calls = [];
	route( { 'POST test': { decision: 'hold', reasons: [ { code: 'links', label: 'Too many links', detail: '3 links (max 2)', action: 'hold', score: null, by: 'rules' } ], labels: {}, sentiment: 0, ai_error: null, ai: false } }, calls );
	const save = jest.fn( ( patch ) => Promise.resolve( { ...SETTINGS, ...patch } ) );
	const view = await render( wrap( <PolicyTab settings={ SETTINGS } save={ save } onUsage={ jest.fn() } /> ) );
	const tox = view.$$( 'select' ).find( ( s ) => s.getAttribute( 'aria-label' ) === 'Action for Toxicity' || ( s.id && view.$( `label[for="${ s.id }"]` ) && view.$( `label[for="${ s.id }"]` ).textContent === 'Action for Toxicity' ) );
	await change( tox, 'spam' );
	await click( view.getByText( 'Save changes' ).closest( 'button' ) );
	await view.flush();
	expect( save ).toHaveBeenCalled();
	const patch = save.mock.calls[ 0 ][ 0 ];
	expect( patch.ai_toxicity_action ).toBe( 'spam' );
	expect( Object.keys( patch ).sort() ).toEqual( [ ...POLICY_KEYS ].sort() );

	const sample = view.$$( 'textarea' ).find( ( t ) => t.closest( '.dev3cm-ui-field' ) && t.closest( '.dev3cm-ui-field' ).textContent.includes( 'Sample comment' ) );
	await change( sample, 'Buy now http://a.co http://b.co http://c.co' );
	await click( view.getByText( 'Test policy' ).closest( 'button' ) );
	await view.flush();
	expect( calls.find( ( c ) => c.path === 'test' ).data ).toEqual( { text: 'Buy now http://a.co http://b.co http://c.co', ai: false } );
	expect( view.getByText( 'Too many links' ) ).toBeTruthy();
} );

test( 'policy starts a scan job', async () => {
	const calls = [];
	route( { 'POST scan': { job: { id: 4, status: 'queued', total: 10, done: 0, failed: 0, percent: 0, errors: [] } }, 'jobs/4': { id: 4, status: 'done', total: 10, done: 10, failed: 0, percent: 100, errors: [] } }, calls );
	const view = await render( wrap( <PolicyTab settings={ SETTINGS } save={ jest.fn() } onUsage={ jest.fn() } /> ) );
	await click( view.getByText( 'Start scan' ).closest( 'button' ) );
	await view.flush();
	expect( calls.find( ( c ) => c.path === 'scan' ).data ).toEqual( { days: 30, ai: false, move: false } );
	expect( view.getByText( 'Scanning existing content' ) ).toBeTruthy();
} );

test( 'voice tab saves templates and tone', async () => {
	const save = jest.fn( ( patch ) => Promise.resolve( { ...SETTINGS, ...patch } ) );
	const view = await render( wrap( <VoiceTab settings={ SETTINGS } save={ save } /> ) );
	const tone = view.$$( 'select' ).find( ( s ) => Array.from( s.options ).some( ( o ) => o.value === 'empathetic' ) );
	await change( tone, 'empathetic' );
	await click( view.getByText( 'Add template' ).closest( 'button' ) );
	const names = view.$$( 'input[type="text"]' ).filter( ( i ) => i.closest( '.dev3cm-template' ) );
	expect( names ).toHaveLength( 4 );
	await change( names[ 3 ], 'Welcome' );
	const texts = view.$$( '.dev3cm-template textarea' );
	await change( texts[ 3 ], 'Welcome {author}!' );
	await click( view.getByText( 'Save changes' ).closest( 'button' ) );
	await view.flush();
	const patch = save.mock.calls[ 0 ][ 0 ];
	expect( patch.voice_tone ).toBe( 'empathetic' );
	expect( patch.reply_templates[ 3 ] ).toEqual( { name: 'Welcome', text: 'Welcome {author}!' } );
} );

test( 'front-end tab previews the local nudge', async () => {
	const view = await render( wrap( <FrontendTab settings={ SETTINGS } save={ jest.fn() } /> ) );
	expect( view.getByText( 'Hurtful words.' ) ).toBeTruthy();
	expect( view.getByText( 'Shouting.' ) ).toBeTruthy();
	const field = view.$( '.dev3cm-preview textarea' );
	await change( field, 'Thanks, this helped a lot.' );
	await wait( 0 );
	expect( view.getByText( 'No nudge: this comment would post straight away.' ) ).toBeTruthy();
} );
