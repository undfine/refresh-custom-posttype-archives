<?php
/**
 * Plugin Name: Refresh Custom Post Type Archives
 * Description: Select post types and pages to clear cache when a post is updated.
 * Version: 1.0.3
 * Author: Dustin Wight
 * Author URI: https://github.com/undfine
 * Text Domain: refresh-cpt-archives
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 *
 * @package refresh-custom-posttype-archives
 */

if(!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Add settings link to plugin page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    $settings_link = '<a href="' . admin_url('options-general.php?page=refresh-cpt-archives') . '">' . __('Settings', 'refresh-cpt-archives') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
});

// initialize settings
require_once __DIR__ . '/inc/settings.php';
require_once __DIR__ . '/inc/update-pages.php';