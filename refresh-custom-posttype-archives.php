<?php
/*
Plugin Name: Refresh Custom Posttype Archives
Description: Select post types and pages to clear cache when a post is updated.
Version: 1.0
Author: Dustin Wight
Namespace: refresh_cpt_archives
*/

if(!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// initialize settings
require_once __DIR__ . '/inc/settings.php';
require_once __DIR__ . '/inc/update-pages.php';