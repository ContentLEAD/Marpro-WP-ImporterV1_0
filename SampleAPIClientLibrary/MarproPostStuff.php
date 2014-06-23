<?php
/**
 * Post functions and post utility function.
 *
 * @package WordPress
 * @subpackage Post
 * @since 1.5.0
 */

//*******************************************************************************************
//*******************************************************************************************
//*******************************************************************************************
//*****************************BEGIN MARPRO**************************************************
//*******************************************************************************************
//*******************************************************************************************
//*******************************************************************************************
/**
 * Update a post with new post data.
 *
 * The date does not have to be set for drafts. You can set the date and it will
 * not be overridden.
 *
 * @since 1.0.0
 *
 * @param array|object $postarr Post data. Arrays are expected to be escaped, objects are not.
 * @param bool $wp_error Optional. Allow return of WP_Error on failure.
 * @return int|WP_Error The value 0 or WP_Error on failure. The post ID on success.
 */
function m_wp_update_post( $postarr = array(), $wp_error = false ) {
	if ( is_object($postarr) ) {
		// non-escaped post was passed
		$postarr = get_object_vars($postarr);
		$postarr = wp_slash($postarr);
	}

	// First, get all of the original fields
	$post = get_post($postarr['ID'], ARRAY_A);

	if ( is_null( $post ) ) {
		if ( $wp_error )
			return new WP_Error( 'invalid_post', __( 'Invalid post ID.' ) );
		return 0;
	}

	// Escape data pulled from DB.
	$post = wp_slash($post);

	// Passed post category list overwrites existing category list if not empty.
	if ( isset($postarr['post_category']) && is_array($postarr['post_category'])
			 && 0 != count($postarr['post_category']) )
		$post_cats = $postarr['post_category'];
	else
		$post_cats = $post['post_category'];

	// Drafts shouldn't be assigned a date unless explicitly done so by the user
	if ( isset( $post['post_status'] ) && in_array($post['post_status'], array('draft', 'pending', 'auto-draft')) && empty($postarr['edit_date']) &&
			 ('0000-00-00 00:00:00' == $post['post_date_gmt']) )
		$clear_date = true;
	else
		$clear_date = false;

	// Merge old and new fields with new fields overwriting old ones.
	$postarr = array_merge($post, $postarr);
	$postarr['post_category'] = $post_cats;
	if ( $clear_date ) {
		$postarr['post_date'] = current_time('mysql');
		$postarr['post_date_gmt'] = '';
	}

	if ($postarr['post_type'] == 'attachment')
		return wp_insert_attachment($postarr);

	return m_wp_insert_post( $postarr, $wp_error);
}


/**
 * Insert or update a post.
 *
 * If the $postarr parameter has 'ID' set to a value, then post will be updated.
 *
 * You can set the post date manually, by setting the values for 'post_date'
 * and 'post_date_gmt' keys. You can close the comments or open the comments by
 * setting the value for 'comment_status' key.
 *
 * @global wpdb $wpdb    WordPress database abstraction object.
 *
 * @since 1.0.0
 *
 * @param array $postarr {
 *     An array of elements that make up a post to update or insert.
 *
 *     @type int    $ID                    The post ID. If equal to something other than 0, the post with that ID will
 *                                         be updated. Default 0.
 *     @type string $post_status           The post status. Default 'draft'.
 *     @type string $post_type             The post type. Default 'post'.
 *     @type int    $post_author           The ID of the user who added the post. Default the current user ID.
 *     @type bool   $ping_status           Whether the post can accept pings. Default value of 'default_ping_status' option.
 *     @type int    $post_parent           Set this for the post it belongs to, if any. Default 0.
 *     @type int    $menu_order            The order it is displayed. Default 0.
 *     @type string $to_ping               Space or carriage return-separated list of URLs to ping. Default empty string.
 *     @type string $pinged                Space or carriage return-separated list of URLs that have been pinged.
 *                                         Default empty string.
 *     @type string $post_password         The password to access the post. Default empty string.
 *     @type string $guid'                 Global Unique ID for referencing the post.
 *     @type string $post_content_filtered The filtered post content. Default empty string.
 *     @type string $post_excerpt          The post excerpt. Default empty string.
 * }
 * @param bool  $wp_error Optional. Allow return of WP_Error on failure.
 * @return int|WP_Error The post ID on success. The value 0 or WP_Error on failure.
 */
function m_wp_insert_post( $postarr, $wp_error = false ) {
	global $wpdb;

	$user_id = get_current_user_id();

	$defaults = array('post_status' => 'draft', 'post_type' => 'post', 'post_author' => $user_id,
		'ping_status' => get_option('default_ping_status'), 'post_parent' => 0,
		'menu_order' => 0, 'to_ping' =>  '', 'pinged' => '', 'post_password' => '',
		'guid' => '', 'post_content_filtered' => '', 'post_excerpt' => '', 'import_id' => 0,
		'post_content' => '', 'post_title' => '');

	$postarr = wp_parse_args($postarr, $defaults);

	unset( $postarr[ 'filter' ] );

	//$postarr = sanitize_post($postarr, 'db');

	// export array as variables
	extract($postarr, EXTR_SKIP);

	// Are we updating or creating?
	$post_ID = 0;
	$update = false;
	if ( ! empty( $ID ) ) {
		$update = true;

		// Get the post ID and GUID
		$post_ID = $ID;
		$post_before = get_post( $post_ID );
		if ( is_null( $post_before ) ) {
			if ( $wp_error )
				return new WP_Error( 'invalid_post', __( 'Invalid post ID.' ) );
			return 0;
		}

		$guid = get_post_field( 'guid', $post_ID );
		$previous_status = get_post_field('post_status', $ID);
	} else {
		$previous_status = 'new';
	}

	$maybe_empty = ! $post_content && ! $post_title && ! $post_excerpt && post_type_supports( $post_type, 'editor' )
		&& post_type_supports( $post_type, 'title' ) && post_type_supports( $post_type, 'excerpt' );

	/**
	 * Filter whether the post should be considered "empty".
	 *
	 * The post is considered "empty" if both:
	 * 1. The post type supports the title, editor, and excerpt fields
	 * 2. The title, editor, and excerpt fields are all empty
	 *
	 * Returning a truthy value to the filter will effectively short-circuit
	 * the new post being inserted, returning 0. If $wp_error is true, a WP_Error
	 * will be returned instead.
	 *
	 * @since 3.3.0
	 *
	 * @param bool  $maybe_empty Whether the post should be considered "empty".
	 * @param array $postarr     Array of post data.
	 */
	if ( apply_filters( 'wp_insert_post_empty_content', $maybe_empty, $postarr ) ) {
		if ( $wp_error )
			return new WP_Error( 'empty_content', __( 'Content, title, and excerpt are empty.' ) );
		else
			return 0;
	}

	if ( empty($post_type) )
		$post_type = 'post';

	if ( empty($post_status) )
		$post_status = 'draft';

	if ( !empty($post_category) )
		$post_category = array_filter($post_category); // Filter out empty terms

	// Make sure we set a valid category.
	if ( empty($post_category) || 0 == count($post_category) || !is_array($post_category) ) {
		// 'post' requires at least one category.
		if ( 'post' == $post_type && 'auto-draft' != $post_status )
			$post_category = array( get_option('default_category') );
		else
			$post_category = array();
	}

	if ( empty($post_author) )
		$post_author = $user_id;

	// Don't allow contributors to set the post slug for pending review posts
	if ( 'pending' == $post_status && !current_user_can( 'publish_posts' ) )
		$post_name = '';

	// Create a valid post name. Drafts and pending posts are allowed to have an empty
	// post name.
	if ( empty($post_name) ) {
		if ( !in_array( $post_status, array( 'draft', 'pending', 'auto-draft' ) ) )
			$post_name = sanitize_title($post_title);
		else
			$post_name = '';
	} else {
		// On updates, we need to check to see if it's using the old, fixed sanitization context.
		$check_name = sanitize_title( $post_name, '', 'old-save' );
		if ( $update && strtolower( urlencode( $post_name ) ) == $check_name && get_post_field( 'post_name', $ID ) == $check_name )
			$post_name = $check_name;
		else // new post, or slug has changed.
			$post_name = sanitize_title($post_name);
	}

	// If the post date is empty (due to having been new or a draft) and status is not 'draft' or 'pending', set date to now
	if ( empty($post_date) || '0000-00-00 00:00:00' == $post_date )
		$post_date = current_time('mysql');

		// validate the date
		$mm = substr( $post_date, 5, 2 );
		$jj = substr( $post_date, 8, 2 );
		$aa = substr( $post_date, 0, 4 );
		$valid_date = wp_checkdate( $mm, $jj, $aa, $post_date );
		if ( !$valid_date ) {
			if ( $wp_error )
				return new WP_Error( 'invalid_date', __( 'Whoops, the provided date is invalid.' ) );
			else
				return 0;
		}

	if ( empty($post_date_gmt) || '0000-00-00 00:00:00' == $post_date_gmt ) {
		if ( !in_array( $post_status, array( 'draft', 'pending', 'auto-draft' ) ) )
			$post_date_gmt = get_gmt_from_date($post_date);
		else
			$post_date_gmt = '0000-00-00 00:00:00';
	}

	if ( $update || '0000-00-00 00:00:00' == $post_date ) {
		$post_modified     = current_time( 'mysql' );
		$post_modified_gmt = current_time( 'mysql', 1 );
	} else {
		$post_modified     = $post_date;
		$post_modified_gmt = $post_date_gmt;
	}

	if ( 'publish' == $post_status ) {
		$now = gmdate('Y-m-d H:i:59');
		if ( mysql2date('U', $post_date_gmt, false) > mysql2date('U', $now, false) )
			$post_status = 'future';
	} elseif( 'future' == $post_status ) {
		$now = gmdate('Y-m-d H:i:59');
		if ( mysql2date('U', $post_date_gmt, false) <= mysql2date('U', $now, false) )
			$post_status = 'publish';
	}

	if ( empty($comment_status) ) {
		if ( $update )
			$comment_status = 'closed';
		else
			$comment_status = get_option('default_comment_status');
	}
	if ( empty($ping_status) )
		$ping_status = get_option('default_ping_status');

	if ( isset($to_ping) )
		$to_ping = sanitize_trackback_urls( $to_ping );
	else
		$to_ping = '';

	if ( ! isset($pinged) )
		$pinged = '';

	if ( isset($post_parent) )
		$post_parent = (int) $post_parent;
	else
		$post_parent = 0;

	/**
	 * Filter the post parent -- used to check for and prevent hierarchy loops.
	 *
	 * @since 3.1.0
	 *
	 * @param int   $post_parent Post parent ID.
	 * @param int   $post_ID     Post ID.
	 * @param array $new_postarr Array of parsed post data.
	 * @param array $postarr     Array of sanitized, but otherwise unmodified post data.
	 */
	$post_parent = apply_filters( 'wp_insert_post_parent', $post_parent, $post_ID, compact( array_keys( $postarr ) ), $postarr );

	if ( isset($menu_order) )
		$menu_order = (int) $menu_order;
	else
		$menu_order = 0;

	if ( !isset($post_password) || 'private' == $post_status )
		$post_password = '';

	$post_name = wp_unique_post_slug($post_name, $post_ID, $post_status, $post_type, $post_parent);

	// expected_slashed (everything!)
	$data = compact( array( 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_content_filtered', 'post_title', 'post_excerpt', 'post_status', 'post_type', 'comment_status', 'ping_status', 'post_password', 'post_name', 'to_ping', 'pinged', 'post_modified', 'post_modified_gmt', 'post_parent', 'menu_order', 'guid' ) );

	/**
	 * Filter slashed post data just before it is inserted into the database.
	 *
	 * @since 2.7.0
	 *
	 * @param array $data    Array of slashed post data.
	 * @param array $postarr Array of sanitized, but otherwise unmodified post data.
	 */
	$data = apply_filters( 'wp_insert_post_data', $data, $postarr );
	$data = wp_unslash( $data );
	$where = array( 'ID' => $post_ID );

	if ( $update ) {
		/**
		 * Fires immediately before an existing post is updated in the database.
		 *
		 * @since 2.5.0
		 *
		 * @param int   $post_ID Post ID.
		 * @param array $data    Array of unslashed post data.
		 */
		do_action( 'pre_post_update', $post_ID, $data );
		if ( false === $wpdb->update( $wpdb->posts, $data, $where ) ) {
			if ( $wp_error )
				return new WP_Error('db_update_error', __('Could not update post in the database'), $wpdb->last_error);
			else
				return 0;
		}
	} else {
		if ( isset($post_mime_type) )
			$data['post_mime_type'] = wp_unslash( $post_mime_type ); // This isn't in the update
		// If there is a suggested ID, use it if not already present
		if ( !empty($import_id) ) {
			$import_id = (int) $import_id;
			if ( ! $wpdb->get_var( $wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE ID = %d", $import_id) ) ) {
				$data['ID'] = $import_id;
			}
		}
		if ( false === $wpdb->insert( $wpdb->posts, $data ) ) {
			if ( $wp_error )
				return new WP_Error('db_insert_error', __('Could not insert post into the database'), $wpdb->last_error);
			else
				return 0;
		}
		$post_ID = (int) $wpdb->insert_id;

		// use the newly generated $post_ID
		$where = array( 'ID' => $post_ID );
	}

	if ( empty($data['post_name']) && !in_array( $data['post_status'], array( 'draft', 'pending', 'auto-draft' ) ) ) {
		$data['post_name'] = sanitize_title($data['post_title'], $post_ID);
		$wpdb->update( $wpdb->posts, array( 'post_name' => $data['post_name'] ), $where );
	}

	if ( is_object_in_taxonomy($post_type, 'category') )
		wp_set_post_categories( $post_ID, $post_category );

	if ( isset( $tags_input ) && is_object_in_taxonomy($post_type, 'post_tag') )
		wp_set_post_tags( $post_ID, $tags_input );

	// new-style support for all custom taxonomies
	if ( !empty($tax_input) ) {
		foreach ( $tax_input as $taxonomy => $tags ) {
			$taxonomy_obj = get_taxonomy($taxonomy);
			if ( is_array($tags) ) // array = hierarchical, string = non-hierarchical.
				$tags = array_filter($tags);
			if ( current_user_can($taxonomy_obj->cap->assign_terms) )
				wp_set_post_terms( $post_ID, $tags, $taxonomy );
		}
	}

	$current_guid = get_post_field( 'guid', $post_ID );

	// Set GUID
	if ( !$update && '' == $current_guid )
		$wpdb->update( $wpdb->posts, array( 'guid' => get_permalink( $post_ID ) ), $where );

	clean_post_cache( $post_ID );

	$post = get_post($post_ID);

	if ( !empty($page_template) && 'page' == $data['post_type'] ) {
		$post->page_template = $page_template;
		$page_templates = wp_get_theme()->get_page_templates( $post );
		if ( 'default' != $page_template && ! isset( $page_templates[ $page_template ] ) ) {
			if ( $wp_error )
				return new WP_Error('invalid_page_template', __('The page template is invalid.'));
			else
				return 0;
		}
		update_post_meta($post_ID, '_wp_page_template',  $page_template);
	}

	wp_transition_post_status($data['post_status'], $previous_status, $post);

	if ( $update ) {
		/**
		 * Fires once an existing post has been updated.
		 *
		 * @since 1.2.0
		 *
		 * @param int     $post_ID Post ID.
		 * @param WP_Post $post    Post object.
		 */
		do_action( 'edit_post', $post_ID, $post );
		$post_after = get_post($post_ID);

		/**
		 * Fires once an existing post has been updated.
		 *
		 * @since 3.0.0
		 *
		 * @param int     $post_ID      Post ID.
		 * @param WP_Post $post_after   Post object following the update.
		 * @param WP_Post $post_before  Post object before the update.
		 */
		do_action( 'post_updated', $post_ID, $post_after, $post_before);
	}

	/**
	 * Fires once a post has been saved.
	 *
	 * The dynamic portion of the hook name, $post->post_type, refers to
	 * the post type slug.
	 *
	 * @since 3.7.0
	 *
	 * @param int     $post_ID Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an existing post being updated or not.
	 */
	do_action( "save_post_{$post->post_type}", $post_ID, $post, $update );

	/**
	 * Fires once a post has been saved.
	 *
	 * @since 1.5.0
	 *
	 * @param int     $post_ID Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an existing post being updated or not.
	 */
	do_action( 'save_post', $post_ID, $post, $update );

	/**
	 * Fires once a post has been saved.
	 *
	 * @since 2.0.0
	 *
	 * @param int     $post_ID Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an existing post being updated or not.
	 */
	do_action( 'wp_insert_post', $post_ID, $post, $update );

	return $post_ID;
}

//*******************************************************************************************
//*******************************************************************************************
//*******************************************************************************************
//*******************************END MARPRO**************************************************
//*******************************************************************************************
//*******************************************************************************************
//*******************************************************************************************

