<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Trigger: a post was saved (after terms and meta are written).
 *
 * wp_after_insert_post is used instead of save_post because on REST/block-editor
 * saves, terms and meta are written after save_post, so caches could be purged
 * and re-warmed with stale data.
 */
add_action('wp_after_insert_post', function($post_id, $post, $update, $post_before = null) {
    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) return;

    // Only publish-related transitions matter: publish->publish, publish->other, other->publish.
    $was_published = $post_before && $post_before->post_status === 'publish';
    if ($post->post_status !== 'publish' && !$was_published) return;

    rcpta_queue_pages($post);
}, 10, 4);

/**
 * Trigger: a published post was permanently deleted (does not fire save hooks).
 */
add_action('deleted_post', function($post_id, $post = null) {
    if (!$post instanceof WP_Post) {
        $post = get_post($post_id);
    }
    if (!$post || $post->post_status !== 'publish') return;

    rcpta_queue_pages($post);
}, 10, 2);

/**
 * Queue the configured pages for a post's type. Pages are de-duplicated and
 * purged once, on shutdown, no matter how many posts were saved in the request.
 *
 * @param WP_Post $post The post that changed.
 */
function rcpta_queue_pages($post) {
    static $hooked = false;

    $settings = get_option('refresh_cpt_archives', []);
    $pt = $post->post_type;

    $page_ids = isset($settings[$pt]['page']) ? array_filter((array) $settings[$pt]['page']) : [];

    // Allow filtering of page IDs before processing
    $page_ids = apply_filters('rcpta_page_ids_' . $pt, $page_ids, $post);

    foreach ((array) $page_ids as $page_id) {
        // Reject junk up front: absint() would turn '-5', 1.5 or [3] into valid-looking IDs.
        if (!is_scalar($page_id) || !ctype_digit(trim((string) $page_id))) continue;
        $page_id = (int) $page_id;
        // Never refresh the post that triggered the change.
        if ($page_id && $page_id !== (int) $post->ID) {
            rcpta_pending_pages($page_id);
        }
    }

    if (!$hooked && rcpta_pending_pages()) {
        $hooked = true;
        add_action('shutdown', 'rcpta_process_queue');
    }
}

/**
 * Add a page ID to (or read) the pending set for this request.
 *
 * @param int|null $add   Page ID to add, or null to just read the set.
 * @param bool     $drain Empty the set after reading it.
 * @return int[] Pending page IDs.
 */
function rcpta_pending_pages($add = null, $drain = false) {
    static $pending = [];
    if ($add !== null) {
        $pending[$add] = $add;
    }
    $ids = array_values($pending);
    if ($drain) {
        $pending = [];
    }
    return $ids;
}

/**
 * Purge every queued page once. Runs on shutdown.
 */
function rcpta_process_queue() {
    static $running = false;
    if ($running) return; // Re-entry guard
    $running = true;

    $force_update = (bool) get_option('refresh_cpt_archives_force_update', false);

    // Drain until empty so pages queued during processing aren't lost; $done
    // guarantees each page is purged at most once per request.
    static $done = [];
    while ($batch = rcpta_pending_pages(null, true)) {
        foreach ($batch as $page_id) {
            if (isset($done[$page_id])) continue;
            $done[$page_id] = true;
            rcpta_clear_caches($page_id, $force_update);
        }
    }

    $running = false;
}

/**
 * Clear all caches (object, page and network) for a specific page.
 *
 * Object cache and every detected page/network cache layer are always cleared.
 * "Force update" additionally bumps the page's modified date, directly in the
 * database, so that anything keyed on post_modified (ETags, sitemaps, Last-Modified)
 * changes. It deliberately does not call wp_update_post(): that rewrites content
 * (unslashed data corrupts backslashes), creates a revision and re-fires save hooks.
 *
 * @param int  $post_id      The page ID to clear.
 * @param bool $force_update Also bump post_modified / post_modified_gmt.
 * @return array|false Per-layer results (layer => true|false|error message), or false for an invalid page.
 */
function rcpta_clear_caches($post_id, $force_update = false) {
    if (!$post_id || !is_numeric($post_id)) {
        error_log('Refresh CPT Archives: Invalid post ID provided');
        return false;
    }

    $post_id = (int) $post_id;
    $post = get_post($post_id);
    if (!$post) {
        error_log('Refresh CPT Archives: Post not found - ' . $post_id);
        return false;
    }

    // Nothing is cached for unpublished pages.
    if ($post->post_status !== 'publish') {
        return false;
    }

    $results = [];

    if ($force_update) {
        $results['force_update'] = rcpta_run_layer(function() use ($post_id) {
            global $wpdb;
            $updated = $wpdb->update(
                $wpdb->posts,
                [
                    'post_modified'     => current_time('mysql'),
                    'post_modified_gmt' => current_time('mysql', true),
                ],
                ['ID' => $post_id]
            );
            return $updated !== false;
        });
    }

    // Object cache
    $results['object_cache'] = rcpta_run_layer(function() use ($post_id) {
        clean_post_cache($post_id);
        do_action('rcpta_clear_object_cache', $post_id);
        return true;
    });

    // Page / network caches
    $urls = array_values(array_filter((array) apply_filters('rcpta_purge_urls', [get_permalink($post_id)], $post_id)));

    foreach (rcpta_cache_layers() as $name => $layer) {
        if (!$layer['detect']()) continue;
        $results[$name] = rcpta_run_layer(function() use ($layer, $post_id, $urls) {
            $layer['purge']($post_id, $urls);
            return true;
        });
    }

    do_action('rcpta_after_clear', $post_id, $urls, $results);

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('Refresh CPT Archives: page ' . $post_id . ' ' . wp_json_encode($results));
    }

    return $results;
}

/**
 * Run one cache layer, isolating failures so the remaining layers still run.
 *
 * @param callable $fn Returns true on success.
 * @return bool|string True on success, false on failure, or the error message.
 */
function rcpta_run_layer($fn) {
    try {
        return (bool) $fn();
    } catch (\Throwable $e) {
        error_log('Refresh CPT Archives: Error clearing cache - ' . $e->getMessage());
        return $e->getMessage();
    }
}

/**
 * Supported page / network cache layers. Each has a detect() and purge($post_id, $urls).
 * Filter `rcpta_cache_layers` lets other code add or remove layers.
 *
 * @return array
 */
function rcpta_cache_layers() {
    $layers = [
        'wp_engine' => [
            'detect' => function() {
                return class_exists('WpeCommon');
            },
            'purge' => function($id) {
                // Each method is isolated so one failure doesn't skip the other,
                // and a no-op (nothing callable) is reported as a failure, not success.
                $did = false;
                $error = null;
                foreach (['purge_varnish_cache' => [$id], 'purge_memcached' => []] as $method => $args) {
                    if (!is_callable(['WpeCommon', $method])) continue;
                    try {
                        call_user_func_array(['WpeCommon', $method], $args);
                        $did = true;
                    } catch (\Throwable $e) {
                        $error = $e->getMessage();
                    }
                }
                if ($error !== null) {
                    throw new \RuntimeException($error);
                }
                if (!$did) {
                    throw new \RuntimeException('WpeCommon purge methods are not callable');
                }
            },
        ],
        'wp_rocket' => [
            'detect' => function() { return function_exists('rocket_clean_post'); },
            'purge'  => function($id) { rocket_clean_post($id); },
        ],
        'w3_total_cache' => [
            'detect' => function() { return function_exists('w3tc_flush_post'); },
            'purge'  => function($id) { w3tc_flush_post($id); },
        ],
        'wp_super_cache' => [
            'detect' => function() { return function_exists('wpsc_delete_post_cache'); },
            'purge'  => function($id) { wpsc_delete_post_cache($id); },
        ],
        'litespeed' => [
            'detect' => function() { return has_action('litespeed_purge_post'); },
            'purge'  => function($id) { do_action('litespeed_purge_post', $id); },
        ],
        'wp_fastest_cache' => [
            'detect' => function() {
                return !empty($GLOBALS['wp_fastest_cache']) && method_exists($GLOBALS['wp_fastest_cache'], 'singleDeleteCache');
            },
            'purge' => function($id) { $GLOBALS['wp_fastest_cache']->singleDeleteCache(false, $id); },
        ],
        'cache_enabler' => [
            'detect' => function() { return has_action('cache_enabler_clear_page_cache_by_url'); },
            'purge'  => function($id, $urls) {
                foreach ($urls as $url) {
                    do_action('cache_enabler_clear_page_cache_by_url', $url);
                }
            },
        ],
        'siteground' => [
            'detect' => function() { return function_exists('sg_cachepress_purge_cache'); },
            'purge'  => function($id, $urls) {
                foreach ($urls as $url) {
                    sg_cachepress_purge_cache($url);
                }
            },
        ],
        'cloudflare' => [
            'detect' => function() { return has_action('cloudflare_purge_by_url'); },
            'purge'  => function($id, $urls) {
                if ($urls) {
                    do_action('cloudflare_purge_by_url', $urls, $id);
                }
            },
        ],
    ];

    return apply_filters('rcpta_cache_layers', $layers);
}
