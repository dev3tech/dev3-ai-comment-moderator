<?php
/**
 * Front-end: accepted discussion summaries (block dev3cm/discussion-summary, shortcode [dev3cm_summary], auto-insert).
 * Only summaries an admin accepted AND marked "show on site" are ever rendered.
 *
 * @package Dev3\AiCommentModerator
 */

namespace Dev3\AiCommentModerator\Frontend;

use Dev3\AiCommentModerator\Data\Text;
use Dev3\AiCommentModerator\Data\Threads;
use Dev3\AiCommentModerator\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Summary output.
 */
class Summary {

	const BLOCK = 'dev3cm/discussion-summary';

	/**
	 * Registers hooks.
	 */
	public function register() {
		add_action( 'init', array( self::class, 'register_block' ) );
		add_shortcode( 'dev3cm_summary', array( self::class, 'shortcode' ) );
		add_filter( 'the_content', array( self::class, 'auto_insert' ), 20 );
	}

	/**
	 * Block (server-rendered).
	 */
	public static function register_block() {
		if ( ! function_exists( 'register_block_type' ) || \WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK ) ) {
			return;
		}
		$asset_file = DEV3CM_PATH . 'build/blocks.asset.php';
		if ( is_readable( $asset_file ) && ! wp_script_is( 'dev3cm-blocks', 'registered' ) ) {
			$asset = include $asset_file;
			wp_register_script( 'dev3cm-blocks', DEV3CM_URL . 'build/blocks.js', $asset['dependencies'], $asset['version'], true );
			wp_set_script_translations( 'dev3cm-blocks', 'dev3-ai-comment-moderator', DEV3CM_PATH . 'languages' );
		}
		Nudge::register_assets();
		register_block_type(
			self::BLOCK,
			array(
				'api_version'     => 2,
				'title'           => __( 'Discussion summary', 'dev3-ai-comment-moderator' ),
				'category'        => 'widgets',
				'editor_script'   => 'dev3cm-blocks',
				'style'           => Nudge::HANDLE,
				'uses_context'    => array( 'postId', 'postType' ),
				'attributes'      => array(
					'postId'    => array(
						'type'    => 'number',
						'default' => 0,
					),
					'showTitle' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
				'supports'        => array(
					'html'    => false,
					'spacing' => array( 'margin' => true ),
				),
				'render_callback' => array( self::class, 'render_block' ),
			)
		);
	}

	/**
	 * Block render.
	 *
	 * @param array          $attributes Attributes.
	 * @param string         $content    Inner content.
	 * @param \WP_Block|null $block      Block.
	 * @return string
	 */
	public static function render_block( $attributes, $content = '', $block = null ) {
		unset( $content );
		$post_id = (int) ( $attributes['postId'] ?? 0 );
		if ( ! $post_id && $block && isset( $block->context['postId'] ) ) {
			$post_id = (int) $block->context['postId'];
		}
		if ( ! $post_id ) {
			$post_id = (int) get_the_ID();
		}
		return self::render( $post_id, ! isset( $attributes['showTitle'] ) || ! empty( $attributes['showTitle'] ) );
	}

	/**
	 * Shortcode [dev3cm_summary post_id="" title="yes"].
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'post_id' => 0,
				'title'   => 'yes',
			),
			(array) $atts,
			'dev3cm_summary'
		);
		$id   = (int) $atts['post_id'] ? (int) $atts['post_id'] : (int) get_the_ID();
		return self::render( $id, 'no' !== $atts['title'] );
	}

	/**
	 * Appends the summary after the post content when "After the post content" is chosen.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public static function auto_insert( $content ) {
		if ( 'after_content' !== Settings::get( 'summary_display', 'off' ) || ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		if ( has_block( self::BLOCK ) || has_shortcode( (string) $content, 'dev3cm_summary' ) ) {
			return $content;
		}
		return $content . self::render( (int) get_the_ID(), true );
	}

	/**
	 * Mood label.
	 *
	 * @param float $score Sentiment.
	 * @return array [ class, label ]
	 */
	public static function mood( $score ) {
		$bucket = Text::bucket( (float) $score );
		$labels = array(
			'positive' => __( 'Mostly positive', 'dev3-ai-comment-moderator' ),
			'neutral'  => __( 'Mixed', 'dev3-ai-comment-moderator' ),
			'negative' => __( 'Mostly critical', 'dev3-ai-comment-moderator' ),
		);
		return array( $bucket, $labels[ $bucket ] );
	}

	/**
	 * Summary HTML ('' when there is no public summary).
	 *
	 * @param int  $post_id    Post.
	 * @param bool $show_title Title.
	 * @return string
	 */
	public static function render( $post_id, $show_title = true ) {
		$row = $post_id ? Threads::get( $post_id ) : null;
		if ( ! $row || empty( $row['show_public'] ) || '' === trim( (string) $row['summary'] ) || 'publish' !== get_post_status( $post_id ) ) {
			return '';
		}
		Nudge::register_assets();
		wp_enqueue_style( Nudge::HANDLE );
		$type  = get_post_type( $post_id );
		$title = 'product' === $type ? __( 'What customers are saying', 'dev3-ai-comment-moderator' ) : __( 'What readers are saying', 'dev3-ai-comment-moderator' );
		if ( function_exists( 'bbp_get_topic_post_type' ) && bbp_get_topic_post_type() === $type ) {
			$title = __( 'Discussion summary', 'dev3-ai-comment-moderator' );
		}
		list( $bucket, $mood ) = self::mood( $row['sentiment'] );
		$uid                   = 'dev3cm-summary-' . (int) $post_id;
		$html                  = '<section class="dev3cm-summary" aria-labelledby="' . esc_attr( $uid ) . '">';
		$html                 .= $show_title ? '<h2 class="dev3cm-summary__title" id="' . esc_attr( $uid ) . '">' . esc_html( $title ) . '</h2>' : '<h2 class="dev3cm-sr" id="' . esc_attr( $uid ) . '">' . esc_html( $title ) . '</h2>';
		$html                 .= '<p class="dev3cm-summary__meta"><span class="dev3cm-mood dev3cm-mood--' . esc_attr( $bucket ) . '">' . esc_html( $mood ) . '</span>';
		/* translators: %d: number of comments */
		$html .= '<span>' . esc_html( sprintf( _n( 'Based on %d comment', 'Based on %d comments', (int) $row['item_count'], 'dev3-ai-comment-moderator' ), (int) $row['item_count'] ) ) . '</span></p>';
		$html .= '<p class="dev3cm-summary__text">' . esc_html( $row['summary'] ) . '</p>';
		if ( $row['themes'] ) {
			$html .= '<ul class="dev3cm-summary__themes">';
			foreach ( $row['themes'] as $theme ) {
				list( $tb ) = self::mood( (float) $theme['sentiment'] );
				$html      .= '<li class="dev3cm-theme dev3cm-theme--' . esc_attr( $tb ) . '">' . esc_html( $theme['label'] ) . '</li>';
			}
			$html .= '</ul>';
		}
		if ( Settings::get( 'summary_ai_note', true ) ) {
			$html .= '<p class="dev3cm-summary__note">' . esc_html__( 'Summary written with AI and reviewed by the site team.', 'dev3-ai-comment-moderator' ) . '</p>';
		}
		return $html . '</section>';
	}
}
