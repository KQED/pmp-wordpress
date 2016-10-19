<?php

/**
 * A class for syncing PMP-documents to WP-posts
 *
 * @since 0.4
 */
abstract class PmpSyncer {

  public $doc;
  public $post;
  public $post_meta;

  // keep track of content with some itags
  public static $ITAGS = array('pmp-wordpress');

  /**
   * Generic initializer for syncing PMP docs
   *
   * @param $pmp_doc - a CollectionDocJson instance (or null)
   * @param $wp_post - a WP_Post instance (or null)
   */
  public function __construct(\Pmp\Sdk\CollectionDocJson $pmp_doc = null, WP_Post $wp_post = null) {
    $this->doc = $pmp_doc;
    $this->post = $wp_post;
    if (!$pmp_doc && !$wp_post) {
      throw new RuntimeException('The PMP doc or WP post must exist first!');
    }
    $this->load_pmp_post_meta();
  }

  /**
   * Load PMP-related metadata from the wordpress database
   */
  protected function load_pmp_post_meta() {
    $this->post_meta = array();

    // look for any meta fields starting with "pmp_"
    if ($this->post) {
      $all_meta = get_post_meta($this->post->ID);
      foreach ($all_meta as $key => $value) {
        if (preg_match('/^pmp_/', $key)) {
          $this->post_meta[$key] = (is_array($value) && count($value) == 1) ? $value[0] : $value;
        }
      }
    }

    // BACKWARDS COMPATIBILITY: set pmp_last_pushed from pmp_owner
    if (!empty($this->post_meta['pmp_owner'])) {
      if ($this->post_meta['pmp_owner'] == pmp_get_my_guid()) {
        if (isset($this->post_meta['pmp_modified'])) {
          $this->post_meta['pmp_last_pushed'] = $this->post_meta['pmp_modified'];
        }
        else {
          $this->post_meta['pmp_last_pushed'] = date('c', time());
        }
        update_post_meta($this->post->ID, 'pmp_last_pushed', $this->post_meta['pmp_last_pushed']);
      }
      unset($this->post_meta['pmp_owner']);
      delete_post_meta($this->post->ID, 'pmp_owner');
    }
  }

  /**
   * Does this Post look like the upstream Doc?
   *
   * @return boolean post or doc is modified
   */
  public function is_modified() {
    $doc_modified = $this->doc ? $this->doc->attributes->modified : null;
    $post_modified = isset($this->post_meta['pmp_modified']) ? $this->post_meta['pmp_modified'] : null;
    return ($doc_modified != $post_modified);
  }

  /**
   * Does this Post have a local origin?
   */
  public function is_local() {
    return !empty($this->post_meta['pmp_last_pushed']);
  }

  /**
   * Is this Post subscribed to updates?
   */
  public function is_subscribed_to_updates() {
    if (!$this->post || empty($this->post_meta['pmp_subscribe_to_updates'])) {
      return true;
    }
    else {
      return ($this->post_meta['pmp_subscribe_to_updates'] != 'off');
    }
  }

  /**
   * Does it seem like I can write to the upstream Doc?
   *
   * @return boolean writeable
   */
  public function is_writeable() {
    if (!$this->doc) {
      return true;
    }
    else if ($this->doc->scope == 'write') {
      return true;
    }
    else {
      return false;
    }
  }

  /**
   * Get upstream changes to this doc
   *
   * @param $force force updates, ignoring local/modified/subscribed flags
   * @return boolean success
   */
  public function pull($force = false) {
    if (!$force) {
      if ($this->is_local()) {
        $this->pmp_debug('-- pull skipping local');
        return false;
      }
      if (!$this->is_modified()) {
        $this->pmp_debug('-- pull skipping not modified');
        return true;
      }
      if (!$this->is_subscribed_to_updates()) {
        $this->pmp_debug('-- pull skipping updates off');
        return false;
      }
    }
    $this->pmp_debug('-- pulling');

    // remove post, if upstream doc is gone
    if (!$this->doc) {
      if ($this->delete_post()) {
        $this->post = null;
        $this->post_meta = array();
        return true;
      }
      else {
        return false;
      }
    }

    // create the post/attachment, if it doesn't exist yet
    if (!$this->post) {
      if (!$this->insert_post()) {
        return false; // failed to create
      }
    }

    // now sync pmp data (don't short-circuit, to make sure pmp_guid is set!)
    $data_success = $this->pull_post_data();
    $meta_success = $this->pull_post_metadata();
    return ($data_success && $meta_success);
  }

  /**
   * Send this post to the PMP
   *
   * @return boolean success
   */
  public function push() {
    $this->pmp_debug('-- pushing');
    if (!$this->post) {
      throw new RuntimeException('A post object is required');
    }

    // create the PMP doc, if it doesn't exist
    if (!$this->doc) {
      $this->pmp_debug('   ** newing a doc');
      if (!$this->new_doc()) {
        return false;
      }
    }

    // set the data, save, and refresh local metadata
    try {
      $this->set_doc_data();
      $this->doc = apply_filters('pmp_before_push', $this->doc, $this->post->ID);
      $this->doc->save();
      do_action('pmp_after_push', $this->doc, $this->post->ID);
      update_post_meta($this->post->ID, 'pmp_last_pushed', $this->doc->attributes->modified);
    }
    catch (\Pmp\Sdk\Exception\ValidationException $e) {
      var_log("ERROR: pmp invalid pushing post[{$this->post->ID}]: {$e->getValidationMessage()}");
      return false;
    }
    catch (\Pmp\Sdk\Exception\RemoteException $e) {
      var_log("ERROR: pmp exception pushing post[{$this->post->ID}]: $e");
      return false;
    }
    return $this->pull_post_metadata();
  }

  /**
   * Handle inserting a new wp-post
   *
   * @return boolean success
   */
  protected function insert_post() {
    $data = array(
      'post_title'   => "draft pmp-pulled content: {$this->doc->attributes->guid}",
      'post_content' => "draft pmp-pulled content: {$this->doc->attributes->guid}",
      'post_author'  => get_current_user_id(), // TODO: often null
      'post_type'    => 'post',
      'post_status'  => 'auto-draft',
    );
    $id_or_error = wp_insert_post($data, true);
    if (is_wp_error($id_or_error)) {
      var_log("insert_post ERROR for [{$this->doc->attributes->guid}] - {$id_or_error->get_error_message()}");
      return false;
    }
    else {
      $this->post = get_post($id_or_error);
      $this->pmp_debug('   ** created new post');
      return true;
    }
  }

  /**
   * Handle deleting a wp-post
   *
   * @return boolean success
   */
  protected function delete_post() {
    $this->pmp_debug('   ** deleting stale post');
    wp_delete_post($this->post->ID, true);
    return true;
  }

  /**
   * Handle new-ing a pmp doc (without saving it)
   *
   * @return boolean success
   */
  protected function new_doc() {
    $sdk = new SDKWrapper();
    $this->doc = $sdk->newDoc('base', array(
      'attributes' => array(
        'title'     => $this->post->post_title,
        'published' => date('c', strtotime($this->post->post_date)),
        'itags'     => array_merge(self::$ITAGS, array("post-id-{$this->post->ID}")),
      ),
    ));
    return true;
  }

  /**
   * Generic logic for pulling PMP data into the post
   *
   * @return boolean success
   */
  protected function pull_post_data() {
    $data = array('ID' => $this->post->ID);
    $data['post_title'] = $this->doc->attributes->title;
    if (isset($this->doc->attributes->teaser)) {
      $data['post_excerpt'] = $this->doc->attributes->teaser;
    }
    if (isset($this->doc->attributes->contentencoded)) {
      $data['post_content'] = $this->doc->attributes->contentencoded;
    }
    else if (isset($this->doc->attributes->description)) {
      $data['post_content'] = $this->doc->attributes->description;
    }
    else {
      $data['post_content'] = '';
    }

    // default to draft posts
    if ($this->post->post_status == 'auto-draft') {
      $data['post_status'] = 'draft';
    }

    // set published date, even for draft/pending posts
    $data['post_date']     = date('Y-m-d H:i:s', strtotime($this->doc->attributes->published));
    $data['post_date_gmt'] = gmdate('Y-m-d H:i:s', strtotime($this->doc->attributes->published));
    if (in_array($this->post->post_status, array('pending', 'draft', 'auto-draft'))) {
      $data['edit_date'] = true;
    }

    // save changes
    $id_or_error = wp_update_post($data, true);
    if (is_wp_error($id_or_error)) {
      var_log("pull_post_data ERROR for [{$this->doc->attributes->guid}] - {$id_or_error->get_error_message()}");
      return false;
    }
    $this->post = get_post($id_or_error);
    return true;
  }

  /**
   * Generic logic for pulling PMP data into the post_meta
   *
   * @return boolean success
   */
  protected function pull_post_metadata() {
    $post_meta                  = array();
    $post_meta['pmp_guid']      = $this->doc->attributes->guid;
    $post_meta['pmp_created']   = $this->doc->attributes->created;
    $post_meta['pmp_modified']  = $this->doc->attributes->modified;
    $post_meta['pmp_published'] = $this->doc->attributes->published;
    $post_meta['pmp_writeable'] = ($this->doc->scope == 'write') ? 'yes' : 'no';
    $post_meta['pmp_byline']    = isset($this->doc->attributes->byline) ? $this->doc->attributes->byline : null;
    $post_meta['pmp_subscribe_to_updates'] = 'on'; // default
    $this->post_meta = apply_filters( 'pmp_pull_post_meta', $post_meta );
    foreach ($this->post_meta as $key => $val) {
      update_post_meta($this->post->ID, $key, $val);
    }
    return true;
  }

  /**
   * Generic logic for setting PMP doc data from a post
   */
  protected function set_doc_data() {
    $this->doc->attributes->title = $this->post->post_title;
    $this->doc->attributes->published = date('c', strtotime($this->post->post_date_gmt));
    $this->doc->attributes->itags = array_merge(self::$ITAGS, array("post-id-{$this->post->ID}"));

    // pull collection/group settings based on the TOP-LEVEL post id
    $top_level_id = ($this->post->post_parent > 0) ? $this->post->post_parent : $this->post->ID;

    // collections
    if (!empty($this->doc->links->collection)) {
        $previous_collection = $this->doc->links->collection;
    } else {
        $previous_collection = array();
    }
    $this->doc->links->collection = array();

    $series = pmp_get_collection_override_value($top_level_id, 'series');
    if (!empty($series)) {
        if (is_array($series)) {
            foreach ($series as $ser) {
                $this->doc->links->collection[] = $this->get_collection_link($ser, 'series');
            }
        } else {
            $this->doc->links->collection[] = $this->get_collection_link($series, 'series');
        }
    }

    $property = pmp_get_collection_override_value($top_level_id, 'property');
    if (!empty($property)) {
        if (is_array($property)) {
            foreach ($property as $prop) {
                $this->doc->links->collection[] = $this->get_collection_link($prop, 'property');
            }
        } else {
            $this->doc->links->collection[] = $this->get_collection_link($property, 'property');
        }
    }

    $this->doc = apply_filters('pmp_set_doc_collection', $this->doc, $previous_collection, $this->post);

    // permissions
    $this->doc->links->permission = array();
    $group = pmp_get_collection_override_value($top_level_id, 'group');
    if (!empty($group)) {
        if (is_array($group)) {
            foreach ($group as $grp) {
                $this->doc->links->permission[] = $this->get_group_link($grp);
            }
        } else {
            $this->doc->links->permission[] = $this->get_group_link($group);
        }
    }
  }

  /**
   * Helper for getting profile links
   *
   * @param $alias the profile alias
   * @return a profile link object
   */
  protected function get_profile_links($alias) {
    if (!$this->doc) {
      return null;
    }
    $fetch_profile = $this->doc->link(\Pmp\Sdk::FETCH_PROFILE);
    if (empty($fetch_profile)) {
      var_log('WOH: unable to get the fetch-profile link from this document');
      return null;
    }
    return array((object) array(
      'href' => $fetch_profile->expand(array('guid' => $alias)),
    ));
  }

  /**
   * Helper for getting collection links
   *
   * @param $guid the collection guid
   * @param $type the type of collection (property/series/etc)
   * @return a link object
   */
  protected function get_collection_link($guid, $type) {
    if (!$this->doc) {
      return null;
    }
    $fetch_doc = $this->doc->link(\Pmp\Sdk::FETCH_DOC);
    if (empty($fetch_doc)) {
      var_log('WOH: unable to get the fetch-doc link from this document');
      return null;
    }
    return (object) array(
      'href' => $fetch_doc->expand(array('guid' => $guid)),
      'rels' => array("urn:collectiondoc:collection:$type"),
    );
  }

  /**
   * Helper for getting group links
   *
   * @param $guid the group guid
   * @return a link object
   */
  protected function get_group_link($guid) {
    if (!$this->doc) {
      return null;
    }
    $fetch_doc = $this->doc->link(\Pmp\Sdk::FETCH_DOC);
    if (empty($fetch_doc)) {
      var_log('WOH: unable to get the fetch-doc link from this document');
      return null;
    }
    return (object) array(
      'href' => $fetch_doc->expand(array('guid' => $guid)),
    );
  }

  /**
   * Debug helper to attach post/doc information
   */
  protected function pmp_debug($msg) {
    if ($this->post) {
      $msg = "$msg wp[{$this->post->ID}]";
    }
    if ($this->doc) {
      $msg = "$msg pmp[{$this->doc->attributes->guid}]";
    }
    pmp_debug($msg);
  }

}
