<?php

if(!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}


add_action('admin_init', function() {
    register_setting('refresh_cpt_archives_group', 'refresh_cpt_archives');
    register_setting('refresh_cpt_archives_group', 'refresh_cpt_archives_force_update');
});

add_action('admin_menu', function() {
    add_options_page(
        'Refresh CPT Archives',
        'Refresh CPT Archives',
        'manage_options',
        'refresh-cpt-archives',
        'refresh_cpt_archives_settings_page'
    );
});

add_action('admin_enqueue_scripts', function($hook) {
    if ($hook !== 'settings_page_refresh-cpt-archives') return;
    wp_enqueue_style(
        'select2',
        plugins_url('assets/css/select2.min.css', dirname(__DIR__) . '/refresh-custom-posttype-archives.php')
    );
    wp_enqueue_script(
        'select2',
        plugins_url('assets/js/select2.min.js', dirname(__DIR__) . '/refresh-custom-posttype-archives.php'),
        ['jquery'],
        null,
        true
    );
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

function refresh_cpt_archives_settings_page() {
    $post_types = get_post_types(['public' => true], 'objects');
    unset($post_types['attachment']);
    $pages = get_pages();
    $selected = get_option('refresh_cpt_archives', []);
    $force_update = get_option('refresh_cpt_archives_force_update', false);

    ob_start();
    ?>
    <div class="wrap">
        <h1>Refresh Custom Post-type Archives</h1>
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

