<?php

/**
 * Render a template by specifying a filename and context.
 *
 * @param (string) $template -- the filename of the template to render.
 * @param (array) $context -- associative array of values used within the template.
 *
 * @since 0.1
 */
function pmp_render_template($template, $context=false) {
	if (!empty($context))
		extract($context);

	include PMP_TEMPLATE_DIR . '/' . $template;
}

/**
 * Return a hash where keys are creator names and values are their respective GUIDs.
 *
 * @since 0.1
 */
function pmp_get_creators() {
	return array(
		'APM' => '98bf597a-2a6f-446c-9b7e-d8ae60122f0d',
		'NPR' => '6140faf0-fb45-4a95-859a-070037fafa01',
		'PBS' => 'fc53c568-e939-4d9c-86ea-c2a2c70f1a99',
		'PRI' => '7a865268-c9de-4b27-a3c1-983adad90921',
		'PRX' => '609a539c-9177-4aa7-acde-c10b77a6a525'
	);
}

/**
 * Return a has where keys are content type names and values are respective profile aliases.
 *
 * @since 0.1
 */
function pmp_get_profiles() {
	return array(
		'Story' => 'story',
		'Audio' => 'audio',
		'Video' => 'video',
		'Image' => 'image',
		'Series' => 'series',
		'Episode' => 'episode'
	);
}

/**
 * Clean up post_content before sending to the PMP
 *
 * @param string $content the original wp_content
 * @param boolean $tagless whether to also strip tags from the content
 * @return string the sanitized content
 */
function pmp_sanitize_content($content, $tagless = false) {
	global $shortcode_tags;

	// remove audio shortcodes
	$stack = $shortcode_tags;
	$shortcode_tags = array('audio' => 1);
	$content = strip_shortcodes($content);
	$shortcode_tags = $stack;

	// convert remaining shortcodes to html
	$content = apply_filters('the_content', $content);

	// strip newlines (convert to spaces, if non-html)
	if ($tagless) {
		$content = str_replace('&nbsp;', ' ', $content); // these are weird
		$content = strip_tags(html_entity_decode($content));
		$content = trim(preg_replace("/[\n\r]+/", ' ', $content));
	}
	else {
		$content = trim(preg_replace("/[\n\r]/", '', $content));
	}

	return $content;
}

/**
 * Similar to `media_sideload_image` except that it simply returns the attachment's ID on success
 *
 * @param (string) $file the url of the image to download and attach to the post
 * @param (integer) $post_id the post ID to attach the image to
 * @param (string) $desc an optional description for the image
 *
 * @since 0.1
 */
function pmp_media_sideload_image($file, $post_id, $desc=null) {
	if (!empty($file)) {
		pmp_debug("      ** sideloading-image $file for post[$post_id]");
		include_once ABSPATH . 'wp-admin/includes/image.php';
		include_once ABSPATH . 'wp-admin/includes/file.php';
		include_once ABSPATH . 'wp-admin/includes/media.php';

		// Set variables for storage, fix file filename for query strings.
		preg_match('/[^\?]+\.(jpe?g|jpe|gif|png)\b/i', $file, $matches);
		$file_array = array();
		if (empty($matches)) {
			$file_array['name'] = basename($file);
		}
		else {
			$file_array['name'] = basename($matches[0]);
		}

		// Download file to temp location.
		$file_array['tmp_name'] = download_url($file);

		// If error storing temporarily, return the error.
		if (is_wp_error($file_array['tmp_name'])) {
			return $file_array['tmp_name'];
		}

		// Do the validation and storage stuff.
		$id = media_handle_sideload($file_array, $post_id, $desc);

		// If error storing permanently, unlink.
		if (is_wp_error($id)) {
			@unlink($file_array['tmp_name']);
		}

		return $id;
	}
}

/**
 * Query for Post-attachments originating from PMP media
 *
 * @since 0.3
 */
function pmp_get_pmp_attachments($parent_id) {
	if (!$parent_id || $parent_id < 1) return array();

	$query = new WP_Query(array(
		'meta_query' => array(
			'key' => 'pmp_guid',
			'compare' => 'EXISTS'
		),
		'posts_per_page' => -1,
		'post_type' => array('attachment', 'pmp_attachment'),
		'post_status' => 'any',
		'post_parent' => $parent_id,
	));

	return $query->posts;
}

/**
 * Verify that we have all settings required to successfully query the PMP API.
 *
 * @since 0.1
 */
function pmp_verify_settings() {
	$options = get_option('pmp_settings');
	return (
		!empty($options['pmp_api_url']) &&
		!empty($options['pmp_client_id']) &&
		!empty($options['pmp_client_secret'])
	);
}

/**
 * Verify that a post's publish date is set according to data retrieved from the PMP API
 * when a draft post transitions to published post.
 *
 * @since 0.2
 */
function pmp_on_post_status_transition($new_status, $old_status, $post) {
	if ($old_status == 'draft' && $new_status == 'publish') {
		$custom_fields = get_post_custom($post->ID);

		if (!empty($custom_fields['pmp_guid'][0]) && !empty($custom_fields['pmp_published'][0])) {
			$post_data = array(
				'ID' => $post->ID,
				'post_date' => date('Y-m-d H:i:s', strtotime($custom_fields['pmp_published'][0]))
			);

			$updated_post = wp_update_post($post_data);
		}
	}
}
add_action('transition_post_status',  'pmp_on_post_status_transition', 10, 3 );

/**
 * Add a "PMP Pushed" date to the meta actions box.
 *
 * @param $post object the WP_Post object to use for rendering the PMP last modified meta info.
 * @since 0.2
 */
function pmp_last_modified_meta($post) {
	// Only show meta if this post came from the PMP
	$pmp_guid = get_post_meta($post->ID, 'pmp_guid', true);
	$pmp_mod = get_post_meta($post->ID, 'pmp_modified', true);
	if (empty($pmp_guid)) return;

	// Format similar to WP's published date
	$pmp_local = get_date_from_gmt(date('Y-m-d H:i:s', strtotime($pmp_mod)), 'M n, Y @ G:i');
?>
  <div id="pmp-publish-meta">
		<div class="misc-pub-section curtime">
			<span id="timestamp">PMP guid: <b><a target="_blank"
				href="<?php echo pmp_get_support_link($pmp_guid); ?>"><?php echo substr($pmp_guid, 0, 8); ?><span class="ext-link dashicons dashicons-external"></span></a></b></span>
		</div>
		<div class="misc-pub-section curtime">
			<span id="timestamp">PMP modified: <b><?php echo $pmp_local; ?></b></span>
		</div>
	</div>
<?php
}

/**
 * Get the base url for linking to the PMP support site
 *
 * @since 0.3
 */
function pmp_get_support_link_base() {
	$options = get_option('pmp_settings');
	if ($options && $options['pmp_api_url'] && strpos($options['pmp_api_url'], 'sandbox')) {
		$pmp_link = 'https://support.pmp.io/sandboxsearch?text=guid%3A';
	}
	else {
		$pmp_link = 'https://support.pmp.io/search?text=guid%3A';
	}
	return $pmp_link;
}

/**
 * Get the support site url for a guid
 *
 * @since 0.3
 */
function pmp_get_support_link($guid) {
	return pmp_get_support_link_base() . $guid;
}

/**
 * Add a "Publish and push to PMP" button the post publish actions meta box.
 *
 * @param $post object the WP_Post object to use for rendering the PMP 'Push to PMP' button.
 * @since 0.2
 */
function pmp_publish_and_push_to_pmp_button($post) {
	// Check if post is in the PMP, and if it's mine
	$pmp_guid = get_post_meta($post->ID, 'pmp_guid', true);
	$pmp_mine = pmp_post_is_mine($post->ID);
	if ($pmp_guid && !$pmp_mine) return;

	// Base display/disabled on post status
	$publishable_status = apply_filters( 'pmp_publishable_status', array( 'publish' ) );
	$is_disabled = ! in_array( $post->post_status, $publishable_status, true );
	if ($is_disabled) {
		$helper_text = 'You must publish first!';
	}
	else if (!$pmp_guid) {
		$helper_text = 'Not in PMP';
	}
	else {
		$helper_text = 'Post will be updated';
	}
?>
	<div id="pmp-publish-actions">
		<p class="helper-text"><?php echo $helper_text; ?></p>
<?php
	$attrs = array('id' => 'pmp-update-push');
	if ($is_disabled)
		$attrs['disabled'] = 'disabled';

	submit_button('Push to PMP', 'large', 'pmp_update_push', false, $attrs);
?>
	</div>
<?php
}

/**
 * Push content to PMP when user clicks "Push to PMP"
 *
 * @since 0.2
 */
function pmp_push_to_pmp($post_id) {
	if (isset($_POST['pmp_update_push']) && !wp_is_post_revision($post_id)) {
		return pmp_handle_push($post_id);
	}
}
add_action('save_post', 'pmp_push_to_pmp', 11);

/**
 * Handle pushing post content to PMP. Works with posts and attachments (images).
 *
 * @since 0.2
 */
function pmp_handle_push($post_id) {
	$post = get_post($post_id);
	$syncer = PmpPost::fromPost($post);
	if ($syncer->push()) {
		return $syncer->doc->attributes->guid;
	}
	else {
		return null;
	}
}

/**
 * Build an array of enclosures for a given "media"/attachment post. Currently works with
 * image attachments only.
 *
 * @since 0.2
 */
function pmp_enclosures_for_media($media_id) {
	$allowed_sizes = array(
		'thumbnail' => 'square',
		'small' => 'small',
		'medium' => 'medium',
		'large' => 'large'
	);

	$media_metadata = wp_get_attachment_metadata($media_id);
	$enclosures = array();
	foreach ($media_metadata['sizes'] as $name => $meta) {
		if (in_array($name, array_keys($allowed_sizes))) {
			$src = wp_get_attachment_image_src($media_id, $name);
			$enclosures[] = (object) array(
				'href' => $src[0],
				'meta' => (object) array(
					'crop' => $allowed_sizes[$name],
					'height' => $meta['height'],
					'width' => $meta['width']
				),
				'type' => $meta['mime-type']
			);
		}
	}

	$enclosures[] = (object) array(
		'href' => wp_get_attachment_url($media_id),
		'meta' => (object) array(
			'crop' => 'primary',
			'height' => $media_metadata['height'],
			'width' => $media_metadata['width'],
		),
		'type' => get_post_mime_type($media_id)
	);

	return $enclosures;
}

/**
 * Build an array of enclosures for a given "audio" attachment post.
 *
 * @since 0.4
 */
function pmp_enclosures_for_audio($audio_id) {
	$audio_metadata = wp_get_attachment_metadata($audio_id);
	$enclosure = array(
		'href' => wp_get_attachment_url($audio_id),
		'type' => $audio_metadata['mime_type'],
		'meta' => array(
			'duration' => $audio_metadata['length'],
		),
	);
	return array($enclosure);
}

/**
 * Find out if your PMP API user is the owner of a given post/PMP Doc
 *
 * @since 0.2
 */
function pmp_post_is_mine($post_id) {
	$pmp_guid = get_post_meta($post_id, 'pmp_guid', true);
	if (empty($pmp_guid))
		return true;

	// check for the new "last_pushed" timestamp
	$pmp_last_pushed = get_post_meta($post_id, 'pmp_last_pushed', true);
	if (!empty($pmp_last_pushed))
		return true;

	// BACKWARDS COMPATIBILITY: set pmp_last_pushed from pmp_owner
	$pmp_owner = get_post_meta($post_id, 'pmp_owner', true);
  if (!empty($pmp_owner)) {
  	delete_post_meta($post_id, 'pmp_owner');
    if ($pmp_owner == pmp_get_my_guid()) {
    	$date = get_post_meta($post_id, 'pmp_modified', true);
    	$date = $date ? $date : date('c', time());
      update_post_meta($post_id, 'pmp_last_pushed', $date);
      return true;
    }
  }

  return false;
}

/**
 * When querying for attachments, only show those items that belong to the current PMP user,
 * or items that have not been pushed to the PMP.
 *
 * @since 0.2
 */
function pmp_filter_media_library($wp_query) {
	if (isset($_POST['action']) && $_POST['action'] == 'query-attachments') {
		$pmp_guid = get_post_meta($_POST['post_id'], 'pmp_guid', true);
		$pmp_last_pushed = get_post_meta($_POST['post_id'], 'pmp_last_pushed', true);

		// filter PMP-sourced docs separately from local/owned stuff
		if ($pmp_guid && empty($pmp_last_pushed)) {
			$wp_query->set('post_parent', $_POST['post_id']);
		}
		else {
			$wp_query->set('meta_query', array(
				'relation' => 'OR',
				array(
					'key' => 'pmp_guid',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key' => 'pmp_last_pushed',
					'compare' => 'EXISTS',
				),
			));
		}
	}
}
add_action('pre_get_posts', 'pmp_filter_media_library');

/**
 * Delete PMP-attachments along with their parent Post
 *
 * @since 0.3
 */
function pmp_cleanup_attachments($post_id) {
	$pmp_guid = get_post_meta($post_id, 'pmp_guid', true);
	if ($pmp_guid) {
		$attachments = pmp_get_pmp_attachments($post_id);
		foreach ($attachments as $attach) {
			wp_delete_post($attach->ID, true);
		}
	}
}
add_action('before_delete_post', 'pmp_cleanup_attachments');

/**
 * Get the current user's PMP GUID
 *
 * @since 0.2
 */
function pmp_get_my_guid() {
	$pmp_my_guid_transient_key = 'pmp_my_guid';
	$pmp_my_guid_transient = get_transient($pmp_my_guid_transient_key);

	if (!empty($pmp_my_guid_transient))
		return $pmp_my_guid_transient;

	$sdk = new SDKWrapper();
	$me = $sdk->fetchUser('me');

	$pmp_my_guid_transient = $me->attributes->guid;
	set_transient($pmp_my_guid_transient_key, $pmp_my_guid_transient, 0);
	return $pmp_my_guid_transient;
}

/**
 * Update the transient that stores the current user's PMP GUID
 *
 * @since 0.2
 */
function pmp_update_my_guid_transient() {
	pmp_get_my_guid();
}

/**
 * Retrieve saved search queries
 *
 * @since 0.3
 */
function pmp_get_saved_search_queries() {
	$search_queries = get_option('pmp_saved_search_queries');

	if (empty($search_queries))
		return array();

	return $search_queries;
}

/**
 * Save a search query for later use
 *
 * @param $query_data (array) Should have two keys: `options` and `query`.
 *
 * `options` should include `title` and `query_auto_create`.
 * `query` should describe the query parameters as pertains to the search form on the search page itself.
 *
 * @return (mixed) $search_id if the query was saved successsfully, false if it was not.
 * @since 0.3
 */
function pmp_save_search_query($search_id=false, $query_data) {
	$search_queries = get_option('pmp_saved_search_queries', array());

	if (is_numeric($search_id))
		$search_queries[$search_id] = $query_data;
	else
		$search_queries[] = $query_data;

	$ret = update_option('pmp_saved_search_queries', $search_queries);

	if (empty($ret)) {
		return $search_id;
	} else {
		if (!empty($search_id))
			return $search_id;
		else {
			end($search_queries);
			return key($search_queries);
		}
	}
}

/**
 * Get details about a saved search
 *
 * @param $search_id (string) the id of the saved search query to fetch.
 * @return (mixed) the saved search query if it exists or false.
 * @since 0.3
 */
function pmp_get_saved_search_query($search_id) {
	$search_queries = pmp_get_saved_search_queries();

	if (!empty($search_queries[$search_id]))
		return $search_queries[$search_id];
	else
		return false;
}

/**
 * Delete a saved search query by id
 *
 * @since 0.3
 */
function pmp_delete_saved_query_by_id($search_id) {
	$search_queries = pmp_get_saved_search_queries();

	if (!isset($search_queries[$search_id]))
		return false;

	delete_option('pmp_last_saved_search_cron_' . sanitize_title($search_queries[$search_id]->options->title));

	unset($search_queries[$search_id]);
	return update_option('pmp_saved_search_queries', $search_queries);
}

/**
 * Get the override value for property, series, group for a post
 *
 * @param $post (integer|object) the post id or post object to check for override values.
 * @param $type (string) the collection type override to check for (e.g., property, series or group)
 * @since 0.3
 */
function pmp_get_collection_override_value($post, $type) {
	$post = get_post($post);
	$pmp_guid = get_post_meta($post->ID, 'pmp_guid', true);
	$override = get_post_meta($post->ID, 'pmp_' . $type . '_override', true);

	if (empty($override)) {
		if (empty($pmp_guid))
			$value = get_option('pmp_default_' . $type, false);
		else
			$value = false;
	} else
		$value = $override;

	return maybe_unserialize($value);
}

if (!function_exists('var_log')) {
	/**
	 * Log anything in a human-friendly format.
	 *
	 * @param mixed $stuff the data structure to send to the error log.
	 * @since 0.2
	 */
	function var_log($stuff) {
		if (!is_string($stuff)) {
			$stuff = var_export($stuff, true);
		}
		error_log($stuff);
	}
}

/**
 * Debug logger
 *
 * @param mixed $stuff the data structure to send to the error log.
 * @since 0.3
 */
function pmp_debug($stuff) {
	if (PMP_DEBUG) {
		if (!is_string($stuff)) {
			$stuff = var_export($stuff, true);
		}
		error_log("[PMP_DEBUG] $stuff");
	}
}
