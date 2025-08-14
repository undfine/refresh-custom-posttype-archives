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
    $page_ids = apply_filters('rcpta_page_ids_'.$pt, $page_ids);

    if (!empty($page_ids)) {
        foreach ($page_ids as $page_id) {
            if ($page_id) {
                rcpta_clear_caches($page_id, $force_update);
            }
        }
    }
}, 10, 3);

/**
 * Clear all caches for a specific post/page.
 *
 * @param int $post_id The post ID to clear.
 */
function rcpta_clear_caches($post_id, $force_update = false) {
    if (!$post_id || !is_numeric($post_id)) {
        error_log('Refresh CPT Archives: Invalid post ID provided');
        return false;
    }

    $post = get_post($post_id, ARRAY_A);
    if (empty($post) || !is_array($post)) {
        error_log('Refresh CPT Archives: Post not found - ' . $post_id);
        return false;
    }

    try {
        if ($force_update) {
            wp_update_post($post);
        }
        
        if (function_exists('clean_post_cache')) {
            clean_post_cache($post_id);
        }
        
        if (function_exists('wpe_clear_post_cache')) {
            wpe_clear_post_cache($post_id);
        }
        
        return true;
    } catch (Exception $e) {
        error_log('Refresh CPT Archives: Error clearing cache - ' . $e->getMessage());
        return false;
    }
}