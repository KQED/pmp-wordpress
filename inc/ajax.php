<?php

include_once __DIR__ . '/class-sdkwrapper.php';

/**
 * Ajax search functionality
 *
 * @since 0.1
 */
function pmp_search() {
	check_ajax_referer('pmp_ajax_nonce', 'security');

	$sdk = new SDKWrapper();
	$opts = array(
		'profile' => 'story',
		'limit' => 10
	);

	if (isset($_POST['query'])) {
		$query = json_decode(stripslashes($_POST['query']), true);
		$opts = array_merge($opts, $query);
	}

	if (isset($opts['guid'])) {
		$guid = $opts['guid'];
		unset($opts['guid']);
		$result = $sdk->query2json('fetchDoc', $guid, $opts);
	} else
		$result = $sdk->query2json('queryDocs', $opts);

	if (!$result) {
		header("HTTP/1.0 404 Not Found");
		print json_encode(array(
			"message" => "No results found.",
			"success" => false
		));
	} else {
		print json_encode(array(
			"data" => $result,
			"success" => true
		));
	}
	wp_die();
}
add_action('wp_ajax_pmp_search', 'pmp_search');

/**
 * Ajax function to create a draft post based on PMP story
 *
 * @since 0.1
 */
function pmp_draft_post() {
	check_ajax_referer('pmp_ajax_nonce', 'security');
	_pmp_ajax_create_post(true);
}
add_action('wp_ajax_pmp_draft_post', 'pmp_draft_post');

/**
 * Ajax function to publish a post based on PMP story
 *
 * @since 0.1
 */
function pmp_publish_post() {
	check_ajax_referer('pmp_ajax_nonce', 'security');
	_pmp_ajax_create_post();
}
add_action('wp_ajax_pmp_publish_post', 'pmp_publish_post');

/**
 * Ajax function to create new group
 *
 * @since 0.2
 */
function pmp_create_group() {
	check_ajax_referer('pmp_ajax_nonce', 'security');

	$group = json_decode(stripslashes($_POST['group']));
	$doc = _pmp_create_doc('group', $group);

	print json_encode(array(
		"success" => true,
		"data" => SDKWrapper::prepFetchData($doc)
	));
	wp_die();
}
add_action('wp_ajax_pmp_create_group', 'pmp_create_group');

/**
 * Ajax function to modify an existing group
 *
 * @since 0.2
 */
function pmp_modify_group() {
	check_ajax_referer('pmp_ajax_nonce', 'security');

	$group = json_decode(stripslashes($_POST['group']));
	$doc = _pmp_modify_doc($group);

	print json_encode(array(
		"success" => true,
		"data" => SDKWrapper::prepFetchData($doc)
	));
	wp_die();
}
add_action('wp_ajax_pmp_modify_group', 'pmp_modify_group');

/**
 * Ajax function to the default PMP group
 *
 * @since 0.2
 */
function pmp_default_group() {
	check_ajax_referer('pmp_ajax_nonce', 'security');

	$group = json_decode(stripslashes($_POST['group']));

	update_option('pmp_default_group', $group->attributes->guid);

	print json_encode(array("success" => true));
	wp_die();
}
add_action('wp_ajax_pmp_default_group', 'pmp_default_group');

/**
 * Ajax function to save a group's users
 *
 * @since 0.2
 */
function pmp_save_users() {
	check_ajax_referer('pmp_ajax_nonce', 'security');

	$group_data = json_decode(stripslashes($_POST['data']));

	$sdk = new SDKWrapper();
	$group = $sdk->fetchDoc($group_data->group_guid);

	if (!empty($group_data->user_guids)) {
		$group->links->item = array();

		foreach ($group_data->user_guids as $user_guid) {
			$link_item = new \stdClass();
			$link_item->href = $sdk->href4guid($user_guid);
			$group->links->item[] = $link_item;
		}
	} else
		unset($group->links->item);

	$group->save();

	print json_encode(array(
		"success" => true,
		"data" => SDKWrapper::prepFetchData($group)
	));
	wp_die();
}
add_action('wp_ajax_pmp_save_users', 'pmp_save_users');

/**
 * Ajax functions to create a new series or property
 *
 * @since 0.2
 */
function pmp_create_collection() {
	check_ajax_referer('pmp_ajax_nonce', 'security');

	$collection = json_decode(stripslashes($_POST['collection']));
	$doc = _pmp_create_doc($_POST['profile'], $collection);

	print json_encode(array(
		"success" => true,
		"data" => SDKWrapper::prepFetchData($doc)
	));
	wp_die();
}
add_action('wp_ajax_pmp_create_collection', 'pmp_create_collection');

/**
 * Ajax function to modify a series or property
 *
 * @since 0.2
 */
function pmp_modify_collection() {
	check_ajax_referer('pmp_ajax_nonce', 'security');

	$collection = json_decode(stripslashes($_POST['collection']));
	$doc = _pmp_modify_doc($collection);

	print json_encode(array(
		"success" => true,
		"data" => SDKWrapper::prepFetchData($doc)
	));
	wp_die();
}
add_action('wp_ajax_pmp_modify_collection', 'pmp_modify_collection');

/**
 * Ajax function to set the default PMP series or property
 *
 * @since 0.2
 */
function pmp_default_collection() {
	check_ajax_referer('pmp_ajax_nonce', 'security');

	$collection = json_decode(stripslashes($_POST['collection']));

	update_option('pmp_default_' . $_POST['profile'], $collection->attributes->guid);

	print json_encode(array("success" => true));
	wp_die();
}
add_action('wp_ajax_pmp_default_collection', 'pmp_default_collection');

/* Helper functions */
function _pmp_create_doc($type, $data) {
	$sdk = new SDKWrapper();

	if (!empty($data->attributes->tags))
		$data->attributes->tags = SDKWrapper::commas2array($data->attributes->tags);

	$doc = $sdk->newDoc($type, $data);
	$doc->save();

	return $doc;
}

function _pmp_modify_doc($data) {
	$sdk = new SDKWrapper();
	$doc = $sdk->fetchDoc($data->attributes->guid);

	if (!empty($data->attributes->tags))
		$data->attributes->tags = SDKWrapper::commas2array($data->attributes->tags);

	$doc->attributes = (object) array_merge((array) $doc->attributes, (array) $data->attributes);
	$doc->save();

	return $doc;
}

function _pmp_ajax_create_post($draft=false) {
	print json_encode(_pmp_create_post($draft));
	wp_die();
}

function _pmp_create_post($draft=false) {
	if (!current_user_can('edit_posts'))
		wp_die( __( 'You do not have sufficient permissions to access this page.' ) );

	$data = json_decode(stripslashes($_POST['post_data']), true);

	$post_data = array_merge(pmp_get_post_data_from_pmp_doc($data), array(
		'post_author' => get_current_user_id(),
		'post_status' => (!empty($draft))? 'draft' : 'publish'
	));
	$new_post = wp_insert_post($post_data);

	if (is_wp_error($new_post)) {
		return array(
			"success" => false,
			"message" => $new_post->get_error_message()
		);
	}

	if (!empty($data['attachment'])) {
		$attachment = $data['attachment'];

		$standard = null;

		// Try really hard to find the 'standard' image crop
		foreach ($attachment['links']['enclosure'] as $enc) {
			if ($enc['meta']['crop'] == 'standard') {
				$standard = $enc;
				break;
			}
		}

		// If we couldn't get the 'standard' crop, fallback to the first enclosure
		if (empty($standard) && !empty($attachment['links']['enclosure'][0]))
			$standard = $attachment['links']['enclosure'][0];

		// If we were able to get an enclosure proceed with attaching it to the post
		if (!empty($standard)) {
			$img_attrs = $attachment['attributes'];

			// Import the image
			$new_image = pmp_media_sideload_image(
				$standard['href'], $new_post, $attachment['attributes']['description']);

			if (!is_wp_error($new_image)) {
				// If import was successful, set basic attachment attributes
				$image_update = array(
					'ID' => $new_image,
					'post_excerpt' => $attachment['attributes']['description'], // caption
					'post_title' => $attachment['attributes']['title']
				);
				wp_update_post($image_update);

				// Also set the alt text and various PMP-related attachment meta
				$image_meta= array_merge(pmp_get_post_meta_from_pmp_doc($attachment), array(
					'_wp_attachment_image_alt' => $attachment['attributes']['title'], // alt text
				));

				foreach ($image_meta as $image_meta_key => $image_meta_value)
					update_post_meta($new_image, $image_meta_key, $image_meta_value);

				// Actually attach the image to the new post
				update_post_meta($new_post, '_thumbnail_id', $new_image);
			}
		}
	}

	$post_meta = pmp_get_post_meta_from_pmp_doc($data);
	foreach ($post_meta as $key => $value)
		update_post_meta($new_post, $key, $value);

	return array(
		"success" => true,
		"data" => array(
			"edit_url" => html_entity_decode(get_edit_post_link($new_post))
		)
	);
}
