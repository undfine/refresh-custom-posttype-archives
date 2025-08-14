<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}


add_action('save_post', function($post_id, $post, $update) {
    // Avoid running on autosaves or revisions
    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) return;
   
    $settings = get_option('refresh_cpt_archives', []);
    $force_update = get_option('refresh_cpt_archives_force_update', false);

    $pt = $post->post_type;
    
    // Only run if there are selected pages for this post type
    $page_ids = isset($settings[$pt]['page']) ? array_filter((array)$settings[$pt]['page']) : [];
    
    // Allow filtering of page IDs before processing
    $page_ids = apply_filters('refresh_cpt_archives__page_ids_'.$pt, $page_ids);

    if (!empty($page_ids)) {
        foreach ($page_ids as $page_id) {
            if ($page_id) {
                refresh_cpt_archives__clear_caches($page_id, $force_update);
            }
        }
    }
}, 10, 3);

/**
 * Clear all caches for a specific post/page.
 *
 * @param int $post_id The post ID to clear.
 */
function refresh_cpt_archives__clear_caches( $post_id, $force_update = false ) {

    $post = get_post($post_id, ARRAY_A);
    if (empty($post) || !is_array($post)) {
        return;
    }

    // Only force update if option is enabled
    if ($force_update) {
        wp_update_post($post);
    } else {
        // clear the cache using WordPress core function
        if ( function_exists( 'clean_post_cache' )) {
            clean_post_cache( $post_id );
        } 
        // Optionally check for WP Engine cache clearing functions
        if( function_exists( 'wpe_clear_post_cache' )) {
            wpe_clear_post_cache( $post_id );
        } 
    }
}