import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import config from '../core3/config';
import { Button, Card, ColorField, Columns, FormGrid, PageHeader, Select, Stack, TextField, Textarea, Toggle } from '../core3/ui';
import { signals } from '../frontend/check';
import { useSettingsForm } from './helpers';

export const KEYS = [ 'nudge_enabled', 'nudge_ai', 'nudge_suggest', 'nudge_title', 'nudge_message', 'note_enabled', 'note_text', 'summary_display', 'summary_ai_note', 'accent_color' ];

/**
 * Live preview of the nudge with the local (non-AI) check.
 *
 * @param {Object} props      Props.
 * @param {Object} props.form Form values.
 * @return {Element} Preview.
 */
function NudgePreview( { form } ) {
	const [ text, setText ] = useState( __( 'THIS IS SO STUPID, WHAT AN IDIOT!!!!', 'dev3-ai-comment-moderator' ) );
	const s = config.strings || { reasons: {} };
	const reasons = signals( text, config.words || [] );
	return (
		<div className="dev3cm-preview" style={ { '--dev3cm-accent': form.accent_color } }>
			<Textarea label={ __( 'Try a comment', 'dev3-ai-comment-moderator' ) } rows={ 3 } value={ text } onChange={ setText } />
			{ form.note_enabled && <p className="dev3cm-note">{ form.note_text || __( 'Please keep it kind and on topic. Comments are moderated.', 'dev3-ai-comment-moderator' ) }</p> }
			{ form.nudge_enabled && reasons.length > 0 ? (
				<div className="dev3cm-nudge">
					<p className="dev3cm-nudge__title">{ form.nudge_title || s.title }</p>
					<p className="dev3cm-nudge__msg">{ form.nudge_message || s.message }</p>
					<ul className="dev3cm-nudge__why">
						{ reasons.map( ( r ) => (
							<li key={ r }>{ s.reasons[ r ] }</li>
						) ) }
					</ul>
					<div className="dev3cm-nudge__actions">
						<button type="button" className="dev3cm-btn dev3cm-btn--primary">
							{ s.edit }
						</button>
						<button type="button" className="dev3cm-btn dev3cm-btn--ghost">
							{ s.post }
						</button>
					</div>
				</div>
			) : (
				<p className="dev3cm-muted">{ form.nudge_enabled ? __( 'No nudge: this comment would post straight away.', 'dev3-ai-comment-moderator' ) : __( 'The nudge is off.', 'dev3-ai-comment-moderator' ) }</p>
			) }
		</div>
	);
}

/**
 * Front-end settings: nudge, community note, discussion summary display.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.settings Settings.
 * @param {Function} props.save     Save.
 * @return {Element} Screen.
 */
export default function FrontendTab( { settings, save } ) {
	const { form, set, dirty, busy, submit, reset } = useSettingsForm( settings, save, KEYS );
	const s = config.strings || {};
	return (
		<>
			<PageHeader title={ __( 'Front-end', 'dev3-ai-comment-moderator' ) } description={ __( 'What visitors see on comment, review and forum forms. Text shown to visitors carries no DEV3 branding.', 'dev3-ai-comment-moderator' ) } />
			<form onSubmit={ submit }>
				<Columns min={ 340 }>
					<Stack gap={ 16 }>
						<Card title={ __( '"Be kind" nudge', 'dev3-ai-comment-moderator' ) } description={ __( 'Before a comment is sent, the writer gets a gentle prompt if it may come across as hurtful. They can edit or post anyway.', 'dev3-ai-comment-moderator' ) }>
							<Stack gap={ 12 }>
								<Toggle label={ __( 'Show the nudge', 'dev3-ai-comment-moderator' ) } help={ __( 'Uses a local word check in the browser, no network.', 'dev3-ai-comment-moderator' ) } checked={ !! form.nudge_enabled } onChange={ set( 'nudge_enabled' ) } />
								<Toggle
									label={ __( 'Ask DEV3 AI before posting', 'dev3-ai-comment-moderator' ) }
									help={ settings.api_key_set ? __( 'Catches subtle hostility the word list misses. Falls back to the local check if AI is slow or off (6 s).', 'dev3-ai-comment-moderator' ) : __( 'Needs a Gemini API key (API Config).', 'dev3-ai-comment-moderator' ) }
									checked={ !! form.nudge_ai }
									onChange={ set( 'nudge_ai' ) }
									disabled={ ! form.nudge_enabled }
								/>
								<Toggle label={ __( 'Offer kinder wording', 'dev3-ai-comment-moderator' ) } help={ __( 'DEV3 AI suggests a rewrite the writer may use.', 'dev3-ai-comment-moderator' ) } checked={ !! form.nudge_suggest } onChange={ set( 'nudge_suggest' ) } disabled={ ! form.nudge_ai } />
								<TextField label={ __( 'Title', 'dev3-ai-comment-moderator' ) } value={ form.nudge_title } onChange={ set( 'nudge_title' ) } placeholder={ s.title } maxLength={ 120 } />
								<Textarea label={ __( 'Message', 'dev3-ai-comment-moderator' ) } rows={ 2 } value={ form.nudge_message } onChange={ set( 'nudge_message' ) } placeholder={ s.message } maxLength={ 300 } />
							</Stack>
						</Card>
						<Card title={ __( 'Community note', 'dev3-ai-comment-moderator' ) }>
							<Stack gap={ 12 }>
								<Toggle label={ __( 'Show a note under the comment box', 'dev3-ai-comment-moderator' ) } checked={ !! form.note_enabled } onChange={ set( 'note_enabled' ) } />
								<TextField label={ __( 'Note', 'dev3-ai-comment-moderator' ) } value={ form.note_text } onChange={ set( 'note_text' ) } placeholder={ __( 'Please keep it kind and on topic. Comments are moderated.', 'dev3-ai-comment-moderator' ) } maxLength={ 300 } disabled={ ! form.note_enabled } />
							</Stack>
						</Card>
						<Card
							title={ __( 'Discussion summaries on the site', 'dev3-ai-comment-moderator' ) }
							description={ __( 'Only summaries you saved and marked "Show on site" in Discussions appear.', 'dev3-ai-comment-moderator' ) }
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
							<Stack gap={ 12 }>
								<Select
									label={ __( 'Placement', 'dev3-ai-comment-moderator' ) }
									value={ form.summary_display }
									onChange={ set( 'summary_display' ) }
									options={ [
										{ value: 'off', label: __( 'Only where I add the block or shortcode', 'dev3-ai-comment-moderator' ) },
										{ value: 'after_content', label: __( 'After the post content (automatic)', 'dev3-ai-comment-moderator' ) },
									] }
									help={ __( 'Block: "Discussion summary". Shortcode: [dev3cm_summary] or [dev3cm_summary post_id="123"].', 'dev3-ai-comment-moderator' ) }
								/>
								<Toggle label={ __( 'Add "written with AI and reviewed" note', 'dev3-ai-comment-moderator' ) } checked={ !! form.summary_ai_note } onChange={ set( 'summary_ai_note' ) } />
								<FormGrid>
									<ColorField label={ __( 'Accent colour', 'dev3-ai-comment-moderator' ) } value={ form.accent_color } onChange={ set( 'accent_color' ) } />
								</FormGrid>
							</Stack>
						</Card>
					</Stack>
					<Card title={ __( 'Preview', 'dev3-ai-comment-moderator' ) } description={ __( 'Local check only. Colours and fonts follow your theme on the site.', 'dev3-ai-comment-moderator' ) }>
						<NudgePreview form={ form } />
					</Card>
				</Columns>
			</form>
		</>
	);
}
