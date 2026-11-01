import { __, sprintf } from '@wordpress/i18n';
import { Button, Card, EmptyState, FormGrid, IconButton, PageHeader, Select, Stack, TextField, Textarea } from '../core3/ui';
import { useSettingsForm } from './helpers';

export const KEYS = [ 'voice_tone', 'voice_guidelines', 'voice_signoff', 'voice_avoid', 'reply_templates' ];

export const TONES = [
	{ value: 'friendly', label: __( 'Friendly', 'dev3-ai-comment-moderator' ) },
	{ value: 'professional', label: __( 'Professional', 'dev3-ai-comment-moderator' ) },
	{ value: 'warm', label: __( 'Warm and personal', 'dev3-ai-comment-moderator' ) },
	{ value: 'playful', label: __( 'Playful', 'dev3-ai-comment-moderator' ) },
	{ value: 'concise', label: __( 'Short and direct', 'dev3-ai-comment-moderator' ) },
	{ value: 'empathetic', label: __( 'Empathetic', 'dev3-ai-comment-moderator' ) },
];

const DEFAULTS = [
	{ name: __( 'Thanks for the feedback', 'dev3-ai-comment-moderator' ), text: __( 'Thanks for sharing your thoughts, {author}! We really appreciate you taking the time to comment on "{post}".', 'dev3-ai-comment-moderator' ) },
	{ name: __( 'Keep it kind', 'dev3-ai-comment-moderator' ), text: __( 'Hi {author}, thanks for joining the discussion. Please keep comments respectful so everyone feels welcome here.', 'dev3-ai-comment-moderator' ) },
	{ name: __( 'We will look into it', 'dev3-ai-comment-moderator' ), text: __( 'Thanks {author}, good question. We are checking this and will update this thread soon. The {site} team', 'dev3-ai-comment-moderator' ) },
];

/**
 * Brand voice for DEV3 AI reply drafts + reply templates (work without AI).
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.settings Settings.
 * @param {Function} props.save     Save.
 * @return {Element} Screen.
 */
export default function VoiceTab( { settings, save } ) {
	const { form, set, dirty, busy, submit, reset } = useSettingsForm( settings, save, KEYS );
	const templates = form.reply_templates && form.reply_templates.length ? form.reply_templates : DEFAULTS;
	const setTemplates = ( next ) => set( 'reply_templates' )( next );
	const update = ( i, key ) => ( value ) => setTemplates( templates.map( ( t, j ) => ( j === i ? { ...t, [ key ]: value } : t ) ) );

	return (
		<>
			<PageHeader title={ __( 'Replies & voice', 'dev3-ai-comment-moderator' ) } description={ __( 'How replies sound. DEV3 AI drafts follow this voice; templates work without AI.', 'dev3-ai-comment-moderator' ) } />
			<form onSubmit={ submit }>
				<Stack gap={ 16 }>
					<Card tone="ai" title={ __( 'Brand voice', 'dev3-ai-comment-moderator' ) } description={ __( 'Used for every "Draft reply with DEV3 AI". Drafts are never posted until you accept them.', 'dev3-ai-comment-moderator' ) }>
						<Stack gap={ 12 }>
							<FormGrid>
								<Select label={ __( 'Tone', 'dev3-ai-comment-moderator' ) } value={ form.voice_tone } onChange={ set( 'voice_tone' ) } options={ TONES } />
								<TextField label={ __( 'Sign-off', 'dev3-ai-comment-moderator' ) } value={ form.voice_signoff } onChange={ set( 'voice_signoff' ) } maxLength={ 120 } placeholder={ __( 'e.g. – Maya, community team', 'dev3-ai-comment-moderator' ) } />
							</FormGrid>
							<Textarea
								label={ __( 'Guidelines', 'dev3-ai-comment-moderator' ) }
								help={ __( 'Facts and rules the replies may use: support email, return policy, how you talk about competitors…', 'dev3-ai-comment-moderator' ) }
								rows={ 5 }
								maxLength={ 1500 }
								value={ form.voice_guidelines }
								onChange={ set( 'voice_guidelines' ) }
							/>
							<Textarea label={ __( 'Never say', 'dev3-ai-comment-moderator' ) } help={ __( 'Words or phrases replies must avoid.', 'dev3-ai-comment-moderator' ) } rows={ 2 } maxLength={ 600 } value={ form.voice_avoid } onChange={ set( 'voice_avoid' ) } />
						</Stack>
					</Card>
					<Card
						title={ __( 'Reply templates', 'dev3-ai-comment-moderator' ) }
						description={ __( 'Placeholders: {author} (first name), {post} (post title), {site} (site name).', 'dev3-ai-comment-moderator' ) }
						actions={
							<Button size="sm" variant="secondary" icon="plus" onClick={ () => setTemplates( [ ...templates, { name: '', text: '' } ] ) } disabled={ templates.length >= 30 }>
								{ __( 'Add template', 'dev3-ai-comment-moderator' ) }
							</Button>
						}
						footer={
							<>
								<Button variant="ghost" disabled={ ! dirty || busy } onClick={ reset }>
									{ __( 'Discard', 'dev3-ai-comment-moderator' ) }
								</Button>
								<Button variant="primary" type="submit" loading={ busy } disabled={ ! dirty }>
									{ __( 'Save changes', 'dev3-ai-comment-moderator' ) }
								</Button>
							</>
						}
					>
						{ templates.length ? (
							<ul className="dev3cm-templates">
								{ templates.map( ( t, i ) => (
									<li key={ i } className="dev3cm-template">
										<div className="dev3cm-template__head">
											<TextField label={ sprintf( /* translators: %d: number */ __( 'Template %d name', 'dev3-ai-comment-moderator' ), i + 1 ) } value={ t.name } onChange={ update( i, 'name' ) } maxLength={ 80 } />
											<IconButton icon="trash" variant="ghost" label={ __( 'Remove template', 'dev3-ai-comment-moderator' ) } onClick={ () => setTemplates( templates.filter( ( x, j ) => j !== i ) ) } />
										</div>
										<Textarea label={ __( 'Text', 'dev3-ai-comment-moderator' ) } rows={ 3 } maxLength={ 1500 } value={ t.text } onChange={ update( i, 'text' ) } />
									</li>
								) ) }
							</ul>
						) : (
							<EmptyState compact icon="edit" title={ __( 'No templates', 'dev3-ai-comment-moderator' ) } description={ __( 'Add canned replies you use often.', 'dev3-ai-comment-moderator' ) } />
						) }
					</Card>
				</Stack>
			</form>
		</>
	);
}
