<?php

/**
 * Plugin Name: Storage for EDD via Box
 * Description: Enable secure cloud storage and delivery of your digital products through Box for Easy Digital Downloads.
 * Version: 1.1.1
 * Author: mohammadr3z
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: storage-for-edd-via-box
 */

if (!defined('ABSPATH')) {
    exit;
}

// Check for Composer autoload (required for Guzzle)
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Define plugin constants
if (!defined('EDBX_PLUGIN_DIR')) {
    define('EDBX_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if (!defined('EDBX_PLUGIN_URL')) {
    define('EDBX_PLUGIN_URL', plugin_dir_url(__FILE__));
}
if (!defined('EDBX_VERSION')) {
    define('EDBX_VERSION', '1.1.1');
}

// Load plugin classes
require_once EDBX_PLUGIN_DIR . 'includes/class-box-config.php';
require_once EDBX_PLUGIN_DIR . 'includes/class-box-client.php';
require_once EDBX_PLUGIN_DIR . 'includes/class-box-uploader.php';
require_once EDBX_PLUGIN_DIR . 'includes/class-box-downloader.php';
require_once EDBX_PLUGIN_DIR . 'includes/class-admin-settings.php';
require_once EDBX_PLUGIN_DIR . 'includes/class-media-library.php';
require_once EDBX_PLUGIN_DIR . 'includes/class-main-plugin.php';

// Initialize plugin on plugins_loaded
add_action('plugins_loaded', function () {
    if (!class_exists('Easy_Digital_Downloads')) {
        add_action('admin_notices', function () {
            ?>
            <div class="notice notice-error">
                <p>
                    <strong><?php esc_html_e('Storage for EDD via Box:', 'storage-for-edd-via-box'); ?></strong>
                    <?php esc_html_e('Easy Digital Downloads (Free or Pro) is required but not active. Please install and activate it first.', 'storage-for-edd-via-box'); ?>
                </p>
            </div>
            <?php
        });
        return;
    }

    new EDBX_BoxStorage();
});

// Register activation/deactivation hooks for rewrite rules
register_activation_hook(__FILE__, array('EDBX_Admin_Settings', 'activatePlugin'));
register_deactivation_hook(__FILE__, array('EDBX_Admin_Settings', 'deactivatePlugin'));
