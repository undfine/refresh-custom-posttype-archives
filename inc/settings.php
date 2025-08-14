<?php
/**
 * Settings page functionality for Refresh Custom Post-type Archives plugin
 *
 * @package refresh-custom-posttype-archives
 * @since 1.0.0
 */

if(!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Register plugin settings
 */
add_action('admin_init', function() {
    register_setting(
        'refresh_cpt_archives_group',
        'refresh_cpt_archives',
        array(
            'type' => 'array',
            'sanitize_callback' => 'rcpta_sanitize_settings'
        )
    );
    register_setting('refresh_cpt_archives_group', 'refresh_cpt_archives_force_update', array(
        'type' => 'boolean',
        'default' => false
    ));
});

add_action('admin_menu', function() {
    add_options_page(
        'Refresh CPT Archives',
        'Refresh CPT Archives',
        'manage_options',
        'refresh-cpt-archives',
        'rcpta_settings_page'
    );
});


add_action('admin_enqueue_scripts', function($hook) {
    if ($hook !== 'settings_page_refresh-cpt-archives') return;

    //Add the Select2 CSS file
    wp_enqueue_style( 'select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', array(), '4.1.0-rc.0');

    //Add the Select2 JavaScript file
    wp_enqueue_script( 'select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', 'jquery', '4.1.0-rc.0');
    
    // Your custom initialization
    wp_add_inline_script('select2', "
        jQuery(document).ready(function($){
            $('.refresh-cpt-archives-pages').select2({
                width: '50%',
                placeholder: 'Select pages',
                allowClear: true
            });
        });
    ");
});

/**
 * Render the plugin settings page
 *
 * Displays a form with all public post types and allows selection of pages
 * to be refreshed when posts of each type are updated.
 *
 * @since 1.0.0
 * @return void
 */
function rcpta_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }
    
    $post_types = get_post_types(['public' => true], 'objects');
    unset($post_types['attachment']);
    unset($post_types['page']);
    $pages = rcpta_get_pages();
    $selected = get_option('refresh_cpt_archives', []);
    $force_update = get_option('refresh_cpt_archives_force_update', false);

    ob_start();
    ?>
    <div class="wrap">
        
        <h1>Refresh Custom Post-type Archives</h1>
        <div class="rcpta-description" style="margin: 20px 0; padding: 15px; background: #fff; ">
            <h2>About This Plugin</h2>
            <p>This plugin automatically refreshes specified pages when posts of a selected custom post type is updated. This is particularly useful for:</p>
            <ul style="list-style-type: disc; margin-left: 20px;">
                <li>Clearing cache on archive pages when new posts are published</li>
                <li>Updating landing pages that display custom post type content</li>
                <li>Ensuring dynamic content stays fresh across your site</li>
            </ul>
            <p><strong>How to use:</strong></p>
            <ol style="list-style-type: decimal; margin-left: 20px;">
                <li>Select the page or pages that should be refreshed for each post type</li>
                <li>Enable "Force Update" if regular cache clearing isn't sufficient</li>
                <li>Save your changes</li>
            </ol>
        </div>
        <form method="post" action="options.php">
            <?php 
            settings_fields('refresh_cpt_archives_group');
            do_settings_sections('refresh-cpt-archives');
            ?>
            <table class="form-table">
                <tr>
                    <th scope="row">Force Update Page</th>
                    <td>
                        <input type="checkbox" name="refresh_cpt_archives_force_update" value="1" <?php checked($force_update, 1); ?>>
                        If checked, the selected pages will be force updated, instead of just clearing the object cache.
                    </td>
                </tr>
                <?php foreach ($post_types as $pt): ?>
                    <tr>
                        <th scope="row"><?php echo esc_html($pt->labels->name); ?></th>
                        <td>
                            <br>Associated Pages:
                            <select class="refresh-cpt-archives-pages" name="refresh_cpt_archives[<?php echo esc_attr($pt->name); ?>][page][]" multiple="multiple" style="width: 400px;">
                                <?php
                                $selected_pages = isset($selected[$pt->name]['page']) ? (array)$selected[$pt->name]['page'] : [];
                                foreach ($pages as $page):
                                    $sel = in_array($page->ID, $selected_pages) ? 'selected' : '';
                                    ?>
                                    <option value="<?php echo esc_attr($page->ID); ?>" <?php echo $sel; ?>>
                                        <?php echo esc_html($page->post_title); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
    echo ob_get_clean();
}

/**
 * Get all pages for the settings dropdown
 * 
 * Implements caching to improve performance when retrieving pages
 * Cache is stored for one hour to balance freshness and performance
 *
 * @since 1.0.0
 * @return array Array of WP_Post objects representing pages
 */
function rcpta_get_pages() {
    $cache_key = 'rcpta_pages';
    $pages = wp_cache_get($cache_key);
    
    if (false === $pages) {
        $pages = get_pages();
        wp_cache_set($cache_key, $pages, '', HOUR_IN_SECONDS);
    }
    
    return $pages;
}

/**
 * Sanitize and validate the plugin settings
 *
 * @param mixed $value The new option value
 * @return array Sanitized option value
 */
function rcpta_sanitize_settings($value) {
    if (!current_user_can('manage_options')) {
        return array();
    }
    
    // If empty, return empty array instead of null
    if (empty($value)) {
        return array();
    }

    // Ensure value is array
    $value = (array)$value;
    
    // Sanitize each post type's settings
    foreach ($value as $post_type => &$settings) {
        // Sanitize the post type key
        $post_type = sanitize_key($post_type);
        
        // Ensure page IDs are numeric
        if (isset($settings['page'])) {
            $settings['page'] = array_map('absint', (array)$settings['page']);
            
            // Remove any zero or invalid values
            $settings['page'] = array_filter($settings['page']);
        }
    }
    
    return $value;
}

