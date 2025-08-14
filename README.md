# Refresh Custom Post-type Archives

A WordPress plugin that allows you to automatically clear cache and refresh specific pages when certain post types are updated.

## Description

This plugin provides a settings interface where you can:
- Select which post types to monitor
- Choose specific pages to refresh when posts are updated
- Option to force update pages instead of just clearing cache

## Features

- Supports all public post types
- Multiple page selection per post type
- Cache-aware page list for better performance
- Compatible with popular caching plugins
- Force update option for stubborn caches

## Installation

1. Upload the plugin files to `/wp-content/plugins/refresh-custom-posttype-archives`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Use the Settings->Refresh CPT Archives screen to configure the plugin

## Usage

1. Go to Settings -> Refresh CPT Archives
2. For each post type, select the pages that should be refreshed when posts of that type are updated
3. Optionally enable "Force Update" if simple cache clearing isn't sufficient
4. Save your settings

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher

## Changelog

### 1.0
- Initial release

## Credits

Developed by Dustin Wight
