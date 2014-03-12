<?php

/**
 * Functionality related to Edit Lock
 *
 * @since 1.6.0
 */

/**
 * Handle heartbeat
 *
 * @since 1.6.0
 */
function bp_docs_heartbeat_callback( $response, $data ) {
	if ( empty( $data['doc_id'] ) ) {
		return;
	}

	$doc_id = intval( $data['doc_id'] );
}
add_filter( 'heartbeat_received', 'bp_docs_heartbeat_callback', 10, 2 );

/**
 * Check to see if the post is currently being edited by another user.
 *
 * This is a verbatim copy of wp_check_post_lock(), which is only available
 * in the admin
 *
 * @since 1.2.8
 *
 * @param int $post_id ID of the post to check for editing
 * @return bool|int False: not locked or locked by current user. Int: user ID of user with lock.
 */
function bp_docs_check_post_lock( $post_id ) {
	if ( !$post = get_post( $post_id ) )
		return false;

	if ( !$lock = get_post_meta( $post->ID, '_edit_lock', true ) )
		return false;

	$lock = explode( ':', $lock );
	$time = $lock[0];
	$user = isset( $lock[1] ) ? $lock[1] : get_post_meta( $post->ID, '_edit_last', true );

	$time_window = apply_filters( 'wp_check_post_lock_window', AUTOSAVE_INTERVAL * 2 );

	if ( $time && $time > time() - $time_window && $user != get_current_user_id() )
		return $user;
	return false;
}

/**
 * Get the lock status of a doc
 *
 * The function first tries to get the lock status out of $bp. If it has to look it up, it
 * stores the data in $bp for future use.
 *
 * @package BuddyPress Docs
 * @since 1.0-beta-2
 *
 * @param int $doc_id Optional. Defaults to the doc currently being viewed
 * @return int Returns 0 if there is no lock, otherwise returns the user_id of the locker
 */
function bp_docs_is_doc_edit_locked( $doc_id = false ) {
	global $bp, $post;

	// Try to get the lock out of $bp first
	if ( isset( $bp->bp_docs->current_doc_lock ) ) {
		$is_edit_locked = $bp->bp_docs->current_doc_lock;
	} else {
		$is_edit_locked = 0;

		if ( empty( $doc_id ) )
			$doc_id = !empty( $post->ID ) ? $post->ID : false;

		if ( $doc_id ) {
			// Because we're not using WP autosave at the moment, ensure that
			// the lock interval always returns as in process
			add_filter( 'wp_check_post_lock_window', create_function( false, 'return time();' ) );

			$is_edit_locked = bp_docs_check_post_lock( $doc_id );
		}

		// Put into the $bp global to avoid extra lookups
		$bp->bp_docs->current_doc_lock = $is_edit_locked;
	}

	return apply_filters( 'bp_docs_is_doc_edit_locked', $is_edit_locked, $doc_id );
}

/**
 * Echoes the output of bp_docs_get_current_doc_locker_name()
 *
 * @package BuddyPress Docs
 * @since 1.0-beta-2
 */
function bp_docs_current_doc_locker_name() {
	echo bp_docs_get_current_doc_locker_name();
}
	/**
	 * Get the name of the user locking the current document, if any
	 *
	 * @package BuddyPress Docs
	 * @since 1.0-beta-2
	 *
	 * @return string $locker_name The full name of the locking user
	 */
	function bp_docs_get_current_doc_locker_name() {
		$locker_name = '';

		$locker_id = bp_docs_is_doc_edit_locked();

		if ( $locker_id )
			$locker_name = bp_core_get_user_displayname( $locker_id );

		return apply_filters( 'bp_docs_get_current_doc_locker_name', $locker_name, $locker_id );
	}

/**
 * Echoes the output of bp_docs_get_force_cancel_edit_lock_link()
 *
 * @package BuddyPress Docs
 * @since 1.0-beta-2
 */
function bp_docs_force_cancel_edit_lock_link() {
	echo bp_docs_get_force_cancel_edit_lock_link();
}
	/**
	 * Get the URL for canceling the edit lock on the current doc
	 *
	 * @package BuddyPress Docs
	 * @since 1.0-beta-2
	 *
	 * @return string $cancel_link href for the cancel edit lock link
	 */
	function bp_docs_get_force_cancel_edit_lock_link() {
		global $post;

		$doc_id = !empty( $post->ID ) ? $post->ID : false;

		if ( !$doc_id )
			return false;

		$doc_permalink = bp_docs_get_doc_link( $doc_id );

		$cancel_link = wp_nonce_url( add_query_arg( 'bpd_action', 'cancel_edit_lock', $doc_permalink ), 'bp_docs_cancel_edit_lock' );

		return apply_filters( 'bp_docs_get_force_cancel_edit_lock_link', $cancel_link, $doc_permalink );
	}

/**
 * Echoes the output of bp_docs_get_cancel_edit_link()
 *
 * @package BuddyPress Docs
 * @since 1.0-beta-2
 */
function bp_docs_cancel_edit_link() {
	echo bp_docs_get_cancel_edit_link();
}
	/**
	 * Get the URL for canceling out of Edit mode on a doc
	 *
	 * This used to be a straight link back to non-edit mode, but something fancier is needed
	 * in order to detect the Cancel and to remove the edit lock.
	 *
	 * @package BuddyPress Docs
	 * @since 1.0-beta-2
	 *
	 * @return string $cancel_link href for the cancel edit link
	 */
	function bp_docs_get_cancel_edit_link() {
		global $bp, $post;

		$doc_id = !empty( $bp->bp_docs->current_post->ID ) ? $bp->bp_docs->current_post->ID : false;

		if ( !$doc_id )
			return false;

		$doc_permalink = bp_docs_get_doc_link( $doc_id );

		$cancel_link = add_query_arg( 'bpd_action', 'cancel_edit', $doc_permalink );

		return apply_filters( 'bp_docs_get_cancel_edit_link', $cancel_link, $doc_permalink );
	}

/**
 * AJAX handler for remove_edit_lock option
 *
 * This function is called when a user is editing a Doc and clicks a link to leave the page
 *
 * @package BuddyPress Docs
 * @since 1.1
 */
function bp_docs_remove_edit_lock() {
	$doc_id = isset( $_POST['doc_id'] ) ? $_POST['doc_id'] : false;

	if ( !$doc_id )
		return false;

	delete_post_meta( $doc_id, '_edit_lock' );
}
add_action( 'wp_ajax_remove_edit_lock', 'bp_docs_remove_edit_lock' );


