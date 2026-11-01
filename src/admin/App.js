import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import ApiTab from '../core3/ApiTab';
import UsageTab from '../core3/UsageTab';
import { AppShell, ErrorNotice, PageHeader, SkeletonCard, UsageMeter, useHashRoute } from '../core3/ui';
import Dashboard from './Dashboard';
import Queue from './Queue';
import Discussions from './Discussions';
import PolicyTab from './PolicyTab';
import VoiceTab from './VoiceTab';
import FrontendTab from './FrontendTab';

const IDS = [ 'dashboard', 'queue', 'discussions', 'policy', 'voice', 'frontend', 'api', 'usage' ];

export default function App() {
	const [ route, navigate ] = useHashRoute( IDS, 'dashboard' );
	const [ settings, setSettings ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ meter, setMeter ] = useState( 0 );
	const [ needs, setNeeds ] = useState( null );

	const load = () => {
		setError( null );
		request( 'settings' ).then( setSettings ).catch( setError );
	};
	useEffect( load, [] );

	const save = ( patch ) =>
		request( 'settings', { method: 'POST', data: patch } ).then( ( next ) => {
			setSettings( next );
			setMeter( ( n ) => n + 1 );
			return next;
		} );

	const onUsage = () => setMeter( ( n ) => n + 1 );
	const onCounts = ( counts ) => counts && setNeeds( counts.needs || 0 );

	const nav = [
		{ id: 'dashboard', label: __( 'Dashboard', 'dev3-ai-comment-moderator' ), icon: 'dashboard' },
		{ id: 'queue', label: __( 'Queue', 'dev3-ai-comment-moderator' ), icon: 'inbox', badge: needs ? String( needs ) : undefined },
		{ id: 'discussions', label: __( 'Discussions', 'dev3-ai-comment-moderator' ), icon: 'users' },
		{ id: 'policy', label: __( 'Policy & rules', 'dev3-ai-comment-moderator' ), icon: 'settings' },
		{ id: 'voice', label: __( 'Replies & voice', 'dev3-ai-comment-moderator' ), icon: 'edit' },
		{ id: 'frontend', label: __( 'Front-end', 'dev3-ai-comment-moderator' ), icon: 'columns' },
		{ id: 'api', label: __( 'API Config', 'dev3-ai-comment-moderator' ), icon: 'key' },
		{ id: 'usage', label: __( 'Usage Logs', 'dev3-ai-comment-moderator' ), icon: 'chart' },
	];

	const screens = {
		dashboard: () => <Dashboard settings={ settings } navigate={ navigate } onCounts={ onCounts } />,
		queue: () => <Queue settings={ settings } navigate={ navigate } openId={ Number( route.params[ 0 ] ) || 0 } onCounts={ onCounts } onUsage={ onUsage } />,
		discussions: () => <Discussions settings={ settings } navigate={ navigate } openId={ Number( route.params[ 0 ] ) || 0 } onUsage={ onUsage } />,
		policy: () => <PolicyTab settings={ settings } save={ save } onUsage={ onUsage } />,
		voice: () => <VoiceTab settings={ settings } save={ save } />,
		frontend: () => <FrontendTab settings={ settings } save={ save } />,
		api: () => (
			<>
				<PageHeader title={ __( 'API Config', 'dev3-ai-comment-moderator' ) } description={ __( 'Connect Google Gemini, pick models and set a monthly AI budget.', 'dev3-ai-comment-moderator' ) } />
				<ApiTab settings={ settings } save={ save } />
			</>
		),
		usage: () => (
			<>
				<PageHeader title={ __( 'Usage Logs', 'dev3-ai-comment-moderator' ) } description={ __( 'Requests, tokens and estimated cost of every DEV3 AI feature.', 'dev3-ai-comment-moderator' ) } />
				<UsageTab />
			</>
		),
	};

	return (
		<AppShell
			name={ config.name || 'DEV3 AI Comment & Community Moderator for WordPress' }
			version={ config.version }
			icon={ config.assetsUrl ? `${ config.assetsUrl }img/icon.svg` : undefined }
			nav={ nav }
			active={ route.id }
			actions={ <UsageMeter compact refreshKey={ meter } /> }
			footer={ __( 'Made by DEV3 Technologies · AI by Google Gemini, using your own API key.', 'dev3-ai-comment-moderator' ) }
		>
			{ error && <ErrorNotice error={ error } onRetry={ load } /> }
			{ ! settings && ! error && <SkeletonCard lines={ 5 } /> }
			{ settings && screens[ route.id ]() }
		</AppShell>
	);
}
