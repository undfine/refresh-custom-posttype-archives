# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

A small WordPress plugin (PHP 7.4+, WP 5.0+). It targets **static pages that act as archives** (loop queries / dynamic data). When a post of a configured post type changes, it clears the caches of the admin-chosen pages. WordPress already invalidates real archives; these pages aren't tied to a post, so they must be told. WP Engine is the primary host.

There is no build step, package manager, linter, or test suite. Develop by editing the PHP files and testing in the surrounding Local WordPress site (the plugin lives in `wp-content/plugins/`). Use the `local-wp` skill (`wpl eval-file script.php`) to run PHP against the site; the site must be running in Local. The plugin version appears in the header of the main file and in the README changelog; update both on release.

## Architecture

- [refresh-custom-posttype-archives.php](refresh-custom-posttype-archives.php): plugin header, "Settings" link, `require_once` of `inc/`.
- [inc/settings.php](inc/settings.php): Settings → Refresh CPT Archives (slug `refresh-cpt-archives`). Select2 is loaded from the jsDelivr CDN, not bundled.
- [inc/update-pages.php](inc/update-pages.php): all runtime logic (flow below).

## Data model

- Option `refresh_cpt_archives`: `[ post_type => [ 'page' => [page_id, ...] ] ]`, sanitized by `rcpta_sanitize_settings()`.
- Option `refresh_cpt_archives_force_update`: boolean.

## Runtime flow (inc/update-pages.php)

1. Triggers: `wp_after_insert_post` (not `save_post`, which fires before REST/block-editor terms and meta are saved) and `deleted_post`. Only publish-related changes count (new status is publish, or the previous status was publish). Autosaves and revisions are skipped.
2. `rcpta_queue_pages()` reads the settings for the post's type, applies the `rcpta_page_ids_{post_type}` filter, rejects junk IDs, and never queues the triggering post itself. IDs go into a de-duplicated static set (`rcpta_pending_pages()`).
3. `rcpta_process_queue()` runs once on `shutdown`. It drains the set until empty, purging each page at most once per request, and has a re-entry guard.
4. `rcpta_clear_caches($id, $force)` skips non-published pages. It always clears the object cache (`clean_post_cache()` plus the `rcpta_clear_object_cache` action) and every detected layer from `rcpta_cache_layers()`. The built-in layers are Elementor (deletes the page's `_elementor_element_cache` meta), WP Engine, WP Rocket, W3TC, WP Super Cache, LiteSpeed, WP Fastest Cache, Cache Enabler, SiteGround and Cloudflare. Each layer has `detect()` and `purge($id, $urls)`, and a failing layer is isolated by `rcpta_run_layer()`. It returns per-layer results. Hooks for extending it: `rcpta_cache_layers`, `rcpta_purge_urls`, `rcpta_after_clear`.

## Gotchas

- **Force Update must not call `wp_update_post()`.** It expects slashed data; `get_post()` output is unslashed, so backslashes in content and meta get stripped. It also creates revisions and re-fires save hooks, which can recurse. Force update only writes `post_modified` and `post_modified_gmt` directly via `$wpdb`.
- Real WP Engine, WP Rocket, etc. can't be exercised locally. Test layers with mocks defined at runtime in an `eval-file` script. Verify on staging with `curl -I` (`X-Cache`, `Age`) before and after an edit.
- In tests, inject settings in memory with `add_filter('pre_option_refresh_cpt_archives', ...)` instead of writing the real options. Purging happens at `shutdown`, so call `rcpta_process_queue()` explicitly or log from `rcpta_after_clear`.
