<?php
/**
 * Plugin Name: ElasticPress Comments
 * Description: Include post comments in ElasticPress indexes, and enables searching in comments
 * Version:     2.4
 * Author:      Shady Sharaf, Human Made <hmn.md>
 * Author URI:  http://hmn.md
 * License:     GPLv2 or later
 * Text Domain: elasticpress-comments
 * Domain Path: /lang/
 *
 * Copyright (C) 2017 Human Made Ltd
 */

namespace ElasticPress\Comments;

/**
 * Register the comment indexing as an EP feature
 *
 * @action plugins_loaded
 */
function add_feature() {

	if ( ! function_exists( 'ep_register_feature' ) ) {
		return;
	}

	ep_register_feature( 'related_posts', [
		'title'                    => __( 'Index comments', 'elasticpress-comments' ),
		'setup_cb'                 => __NAMESPACE__ . '\\setup_actions',
		'feature_box_summary_cb'   => __NAMESPACE__ . '\\feature_box_summary',
		'requires_install_reindex' => true,
	] );
}

add_action( 'plugins_loaded', __NAMESPACE__ . '\\add_feature' );

/**
 * Setup the comment indexing feature actions
 *
 * @action plugins_loaded
 */
function setup_actions() {
	add_filter( 'ep_post_sync_args', __NAMESPACE__ . '\\include_comments_in_post_args', 10, 2 );

	add_action( 'wp_insert_comment', __NAMESPACE__ . '\\sync_post_on_comment_update', 10, 2 );
	add_action( 'edit_comment', __NAMESPACE__ . '\\sync_post_on_comment_update' );
	add_action( 'wp_set_comment_status', __NAMESPACE__ . '\\sync_post_on_comment_update', 10, 2 );

	add_filter( 'ep_search_fields', __NAMESPACE__ . '\\include_comments_in_search_fields' );
}

/**
 * Output feature box summary
 *
 * @since 2.1
 */
function feature_box_summary() {
	?>
    <p><?php esc_html_e( 'Include comments in indexed posts, and search them as well as part of the integrated WP_Query search.', 'elasticpress-comments' ); ?></p>
	<?php
}

/**
 * Bundle comments content/author in indexed post in EP
 *
 * @param $post_args
 * @param $post_id
 *
 * @filter ep_post_sync_args
 *
 * @return mixed
 */
function include_comments_in_post_args( $post_args, $post_id ) {
	if ( ! should_index_post_comments( $post_id ) ) {
		return $post_args;
	}

	$comment_query_args = apply_filters( 'ep_comment_query_args', [
		'post_id' => $post_id,
		'status'  => 'approve',
	] );

	$comments = new \WP_Comment_Query( $comment_query_args );

	$parsed_comments = [];
	if ( ! empty( $comments ) ) {
		/** @var \WP_Comment $comment */
		foreach ( $comments->get_comments() as $comment ) {
			$parsed_comment = [
				'content' => $comment->comment_content,
				'author'  => $comment->comment_author,
			];

			$parsed_comments[] = $parsed_comment;
		}
	}

	$post_args['comments'] = apply_filters( 'ep_comments_data', $parsed_comments, $post_id, $post_args );

	return $post_args;
}

/**
 * Sync post using EP when comments are added/updated
 *
 * @param $comment_id
 */
function sync_post_on_comment_update( $comment_id ) {
	$comment = get_comment( $comment_id );

	if ( empty( $comment ) ) { // No checking of comment status, to catch status changes of comments
		return;
	}

	$post_id = $comment->comment_post_ID;

	if ( should_index_post_comments( $post_id ) ) {
		schedule_post_sync( $post_id );
	}
}

/**
 * Include comments content and author in search fields
 *
 * @param array $search_fields
 *
 * @filter ep_search_fields
 *
 * @return array
 */
function include_comments_in_search_fields( array $search_fields ) {
	$search_fields[] = 'comments.content';
	$search_fields[] = 'comments.author';

	return $search_fields;
}

/**
 * Should we index comments of this post ?
 *
 * @param $post_id
 *
 * @return bool
 */
function should_index_post_comments( $post_id ) {
	// Opt to blacklist post types instead of whitelisting them, EP should handle the whitelisting instead
	$blacklisted_post_types = apply_filters( 'ep_comments_post_type_blacklist', [] );

	if ( ! comments_open( $post_id ) ) {
		$should = false;
	} elseif ( in_array( get_post_type( $post_id ), $blacklisted_post_types, true ) ) {
		$should = false;
	} else {
		$should = true;
	}

	return apply_filters( 'ep_should_index_post_comments', $should );
}

/**
 * Enqueue a post to be Synced on shutdown
 *
 * @param $post_id
 */
function schedule_post_sync( $post_id ) {
	add_filter( 'ep_scheduled_posts_for_sync', function ( $post_ids ) use ( $post_id ) {
		$post_ids[] = $post_id;

		return $post_ids;
	} );

	add_action( 'shutdown', __NAMESPACE__ . '\\sync_scheduled_posts' );
}

/**
 * Sync scheduled EP posts on shutdown
 *
 * @action shutdown
 */
function sync_scheduled_posts() {
	$post_ids = apply_filters( 'ep_scheduled_posts_for_sync', [] );

	array_map( 'ep_sync_post', array_map( 'absint', $post_ids ) );
}
