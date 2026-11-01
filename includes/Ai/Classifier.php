<?php
/**
 * AI classification (spam, toxicity, harassment, off-topic, PII + sentiment), the async queue and the scan job.
 *
 * @package Dev3\AiCommentModerator
 */

namespace Dev3\AiCommentModerator\Ai;

use Dev3\AiCommentModerator\Core3\Api\Ai;
use Dev3\AiCommentModerator\Core3\Api\Prompts;
use Dev3\AiCommentModerator\Core3\Core\Jobs;
use Dev3\AiCommentModerator\Data\Items;
use Dev3\AiCommentModerator\Data\Text;
use Dev3\AiCommentModerator\Moderation\Pipeline;
use Dev3\AiCommentModerator\Moderation\Policy;
use Dev3\AiCommentModerator\Moderation\Sources;
use Dev3\AiCommentModerator\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Classifier.
 */
class Classifier {

	const BATCH      = 8;
	const QUEUE_HOOK = 'dev3cm_process_queue';
	const SCAN_JOB   = 'dev3cm_scan';

	/**
	 * Is AI classification available in this context?
	 *
	 * @param string $context Context.
	 * @return bool
	 */
	public static function available( $context = 'admin' ) {
		return 'off' !== Settings::get( 'ai_mode', 'sync' ) && Ai::enabled( $context );
	}

	/**
	 * Registers hooks + the scan job (every request).
	 */
	public static function register() {
		add_action( self::QUEUE_HOOK, array( self::class, 'process_queue' ) );
		Jobs::register( self::SCAN_JOB, array( self::class, 'run_scan_chunk' ) );
	}

	/**
	 * Classifies up to 10 entries in one call.
	 *
	 * @param array  $entries [ key => { text, author, post, excerpt, parent, source } ].
	 * @param string $context Context.
	 * @param array  $extra   Extra Ai::run args.
	 * @return array|\WP_Error { labels: [ key => labels ], model, tokens, cached }
	 */
	public static function classify( array $entries, $context = 'admin', array $extra = array() ) {
		$list = array();
		$keys = array();
		$n    = 0;
		foreach ( $entries as $key => $entry ) {
			$id          = (string) ( ++$n );
			$keys[ $id ] = $key;
			$list[]      = array_filter(
				array(
					'id'     => $id,
					'where'  => (string) ( $entry['source'] ?? 'comment' ),
					'post'   => mb_substr( (string) ( $entry['post'] ?? '' ), 0, 160 ),
					'about'  => mb_substr( (string) ( $entry['excerpt'] ?? '' ), 0, 300 ),
					'reply'  => mb_substr( (string) ( $entry['parent'] ?? '' ), 0, 300 ),
					'author' => mb_substr( (string) ( $entry['author'] ?? '' ), 0, 60 ),
					'text'   => mb_substr( (string) ( $entry['text'] ?? '' ), 0, 2500 ),
				)
			);
		}
		$result = Prompts::run(
			'classify',
			array(
				'site'  => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
				'items' => wp_json_encode( $list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			),
			$context,
			$extra + array( 'cache' => WEEK_IN_SECONDS )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$labels = array();
		foreach ( (array) ( $result['data']['items'] ?? array() ) as $row ) {
			$id = (string) ( $row['id'] ?? '' );
			if ( isset( $keys[ $id ] ) ) {
				$labels[ $keys[ $id ] ] = self::normalize( (array) $row );
			}
		}
		// A single entry answered under another id is still that entry.
		if ( ! $labels && 1 === count( $keys ) && ! empty( $result['data']['items'][0] ) ) {
			$labels[ reset( $keys ) ] = self::normalize( (array) $result['data']['items'][0] );
		}
		return array(
			'labels' => $labels,
			'model'  => (string) ( $result['model'] ?? '' ),
			'tokens' => (int) ( $result['usage']['total'] ?? 0 ),
			'cached' => ! empty( $result['cached'] ),
		);
	}

	/**
	 * Clamped labels.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	public static function normalize( array $row ) {
		$out = array();
		foreach ( Settings::LABELS as $label ) {
			$out[ $label ] = round( max( 0, min( 1, (float) ( $row[ $label ] ?? 0 ) ) ), 3 );
		}
		$out['sentiment'] = round( max( -1, min( 1, (float) ( $row['sentiment'] ?? 0 ) ) ), 3 );
		$types            = array_intersect( (array) ( $row['pii_types'] ?? array() ), array( 'email', 'phone', 'address', 'card', 'id', 'name', 'ip' ) );
		$out['pii_types'] = array_values( array_unique( array_map( 'strval', $types ) ) );
		$out['language']  = substr( preg_replace( '/[^a-zA-Z-]/', '', (string) ( $row['language'] ?? '' ) ), 0, 12 );
		$out['reason']    = mb_substr( sanitize_text_field( (string) ( $row['reason'] ?? '' ) ), 0, 200 );
		return $out;
	}

	/**
	 * Context entry for a stored record.
	 *
	 * @param array $item Item.
	 * @return array
	 */
	public static function entry_for( array $item ) {
		$post   = get_post( $item['post_id'] );
		$parent = $item['parent_id'] ? Sources::parent_excerpt( $item['source'], $item['parent_id'] ) : null;
		return array(
			'source'  => $item['source'],
			'text'    => Sources::content( $item['source'], $item['object_id'] ),
			'author'  => $item['author_name'],
			'post'    => $post ? get_the_title( $post ) : '',
			'excerpt' => $post ? Text::excerpt( has_excerpt( $post ) ? $post->post_excerpt : $post->post_content, 300 ) : '',
			'parent'  => $parent ? $parent['excerpt'] : '',
		);
	}

	/**
	 * Stores labels on a record and applies the policy.
	 *
	 * @param int    $item_id Item.
	 * @param array  $labels  Labels.
	 * @param string $model   Model.
	 * @param bool   $move    Move per policy.
	 * @return array|null Item.
	 */
	public static function store( $item_id, array $labels, $model = '', $move = true ) {
		Items::update(
			$item_id,
			array(
				'labels'        => array_diff_key( $labels, array( 'reason' => 1 ) ),
				'reason'        => (string) ( $labels['reason'] ?? '' ),
				'sentiment'     => (float) ( $labels['sentiment'] ?? 0 ),
				'sentiment_src' => 'ai',
				'ai_state'      => 'done',
				'model'         => substr( (string) $model, 0, 60 ),
			)
		);
		$top = '';
		$max = 0;
		foreach ( Settings::LABELS as $label ) {
			if ( ( $labels[ $label ] ?? 0 ) > $max ) {
				$max = $labels[ $label ];
				$top = $label;
			}
		}
		/* translators: 1: label, 2: score percent */
		Items::log( $item_id, 'classified', 'ai', $top ? sprintf( __( 'Highest: %1$s %2$d%%', 'dev3-ai-comment-moderator' ), $top, (int) round( $max * 100 ) ) : '' );
		return Policy::apply( $item_id, 'ai', $move );
	}

	/**
	 * Classifies stored records (batches of 8) and applies the policy.
	 *
	 * @param int[]  $ids     Item ids.
	 * @param string $context Context.
	 * @param bool   $move    Move per policy.
	 * @return array [ id => true|WP_Error ]
	 */
	public static function classify_items( array $ids, $context = 'admin', $move = true ) {
		$out   = array();
		$items = array();
		foreach ( $ids as $id ) {
			$item = Items::get( (int) $id );
			if ( $item ) {
				$items[ $item['id'] ] = $item;
			}
		}
		foreach ( array_chunk( $items, self::BATCH, true ) as $chunk ) {
			$entries = array();
			foreach ( $chunk as $id => $item ) {
				$entries[ $id ] = self::entry_for( $item );
				if ( '' === trim( $entries[ $id ]['text'] ) ) {
					unset( $entries[ $id ] );
					Items::update( $id, array( 'ai_state' => 'skipped' ) );
					$out[ $id ] = true;
				}
			}
			if ( ! $entries ) {
				continue;
			}
			$result = self::classify( $entries, $context );
			foreach ( array_keys( $entries ) as $id ) {
				if ( is_wp_error( $result ) ) {
					Items::update( $id, array( 'ai_state' => self::state_for_error( $result ) ) );
					$out[ $id ] = $result;
				} elseif ( isset( $result['labels'][ $id ] ) ) {
					self::store( $id, $result['labels'][ $id ], $result['model'], $move );
					$out[ $id ] = true;
				} else {
					Items::update( $id, array( 'ai_state' => 'failed' ) );
					$out[ $id ] = new \WP_Error( 'dev3cm_missing_label', __( 'DEV3 AI returned no result for this item.', 'dev3-ai-comment-moderator' ) );
				}
			}
		}
		return $out;
	}

	/**
	 * AI state after an error (disabled/cap → skipped, so the queue does not loop).
	 *
	 * @param \WP_Error $error Error.
	 * @return string
	 */
	private static function state_for_error( \WP_Error $error ) {
		$code = (string) $error->get_error_code();
		return preg_match( '/(ai_disabled|public_ai_disabled|cap_reached|no_key|missing_key)$/', $code ) ? 'skipped' : 'failed';
	}

	/**
	 * Marks a record for async classification and makes sure the queue runs.
	 *
	 * @param int $item_id Item.
	 */
	public static function enqueue( $item_id ) {
		Items::update( $item_id, array( 'ai_state' => 'queued' ) );
		if ( ! wp_next_scheduled( self::QUEUE_HOOK ) ) {
			wp_schedule_single_event( time(), self::QUEUE_HOOK );
		}
	}

	/**
	 * Works through queued records (cron, or inline when the admin opens the queue).
	 *
	 * @param int $budget Seconds.
	 * @return int Processed.
	 */
	public static function process_queue( $budget = 20 ) {
		$budget = max( 1, (int) $budget );
		$start  = microtime( true );
		$done   = 0;
		$lock   = 'dev3cm_queue_lock';
		if ( get_transient( $lock ) ) {
			return 0;
		}
		set_transient( $lock, 1, 60 );
		try {
			while ( microtime( true ) - $start < $budget ) {
				$batch = Items::queued( self::BATCH );
				if ( ! $batch ) {
					break;
				}
				if ( ! Ai::enabled( 'cron' ) ) {
					foreach ( $batch as $item ) {
						Items::update( $item['id'], array( 'ai_state' => 'skipped' ) );
					}
					break;
				}
				self::classify_items( wp_list_pluck( $batch, 'id' ), 'cron' );
				$done += count( $batch );
			}
		} finally {
			delete_transient( $lock );
		}
		if ( Items::queued( 1 ) && ! wp_next_scheduled( self::QUEUE_HOOK ) ) {
			wp_schedule_single_event( time() + 30, self::QUEUE_HOOK );
		}
		return $done;
	}

	/**
	 * Scan job chunk: (re)builds records for existing content, then classifies them when asked.
	 *
	 * @param array $targets "source:id" strings.
	 * @param array $job     Job.
	 * @return array
	 */
	public static function run_scan_chunk( array $targets, array $job ) {
		$use_ai = ! empty( $job['args']['ai'] ) && self::available( 'admin' );
		$move   = ! isset( $job['args']['move'] ) || ! empty( $job['args']['move'] );
		$out    = array();
		$ids    = array();
		foreach ( $targets as $target ) {
			list( $source, $object_id ) = array_pad( explode( ':', (string) $target, 2 ), 2, 0 );
			$item_id                    = Pipeline::record_existing( (string) $source, (int) $object_id, $move );
			if ( ! $item_id ) {
				$out[ $target ] = __( 'Not found or source switched off.', 'dev3-ai-comment-moderator' );
				continue;
			}
			$out[ $target ] = true;
			$ids[ $target ] = $item_id;
		}
		if ( $use_ai && $ids ) {
			$results = self::classify_items( array_values( $ids ), 'admin', $move );
			foreach ( $ids as $target => $item_id ) {
				if ( isset( $results[ $item_id ] ) && is_wp_error( $results[ $item_id ] ) ) {
					$out[ $target ] = $results[ $item_id ]->get_error_message();
				}
			}
		}
		return $out;
	}
}
