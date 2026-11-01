import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, Placeholder, TextControl, ToggleControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { __ } from '@wordpress/i18n';

const title = __( 'Discussion summary', 'dev3-ai-comment-moderator' );

const Empty = () => (
	<Placeholder
		icon="format-chat"
		label={ title }
		instructions={ __( 'Shows the accepted discussion summary of this post (or of the post ID set in the block settings). Create and approve summaries in DEV3 AI Moderator → Discussions and switch on "Show on site".', 'dev3-ai-comment-moderator' ) }
	/>
);

registerBlockType( 'dev3cm/discussion-summary', {
	apiVersion: 2,
	title,
	description: __( 'What readers are saying: a short, moderator-approved summary of the comments.', 'dev3-ai-comment-moderator' ),
	category: 'widgets',
	icon: 'format-chat',
	keywords: [ 'comments', 'summary', 'sentiment', 'discussion' ],
	usesContext: [ 'postId', 'postType' ],
	supports: { html: false, spacing: { margin: true } },
	attributes: {
		postId: { type: 'number', default: 0 },
		showTitle: { type: 'boolean', default: true },
	},
	edit: function Edit( { attributes, setAttributes, context } ) {
		const blockProps = useBlockProps();
		const id = attributes.postId || ( context && context.postId ) || 0;
		return (
			<div { ...blockProps }>
				<InspectorControls>
					<PanelBody title={ __( 'Settings', 'dev3-ai-comment-moderator' ) }>
						<TextControl
							label={ __( 'Post ID', 'dev3-ai-comment-moderator' ) }
							help={ __( 'Leave 0 to use the current post.', 'dev3-ai-comment-moderator' ) }
							type="number"
							min={ 0 }
							value={ attributes.postId || 0 }
							onChange={ ( v ) => setAttributes( { postId: Math.max( 0, parseInt( v, 10 ) || 0 ) } ) }
						/>
						<ToggleControl label={ __( 'Show title', 'dev3-ai-comment-moderator' ) } checked={ attributes.showTitle } onChange={ ( v ) => setAttributes( { showTitle: v } ) } />
					</PanelBody>
				</InspectorControls>
				{ id ? <ServerSideRender block="dev3cm/discussion-summary" attributes={ { ...attributes, postId: id } } EmptyResponsePlaceholder={ Empty } /> : <Empty /> }
			</div>
		);
	},
	save: () => null,
} );
