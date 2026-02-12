<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Box Media Library Integration
 * 
 * Adds custom tabs to WordPress media uploader for browsing
 * and uploading files to Box.
 */
class EDBX_Media_Library
{
    private $client;
    private $config;

    public function __construct()
    {
        $this->config = new EDBX_Box_Config();
        $this->client = new EDBX_Box_Client();

        // Enqueue styles
        add_action('admin_enqueue_scripts', array($this, 'enqueueStyles'));

        // Add Box button to EDD downloadable files (Server Side)
        add_action('edd_download_file_table_row', array($this, 'renderBrowseButton'), 10, 3);

        // Print scripts for button functionality
        add_action('admin_footer', array($this, 'printAdminScripts'));

        // AJAX Handler for fetching library
        add_action('wp_ajax_edbx_get_library', array($this, 'ajaxGetLibrary'));
    }

    /**
     * AJAX Handler to get library content
     */
    public function ajaxGetLibrary()
    {
        check_ajax_referer('media-form', '_wpnonce');

        $mediaCapability = apply_filters('edbx_media_access_cap', 'edit_products');
        if (!current_user_can($mediaCapability)) {
            wp_send_json_error(esc_html__('You do not have permission to access Box library.', 'storage-for-edd-via-box'));
        }

        // Box uses folder IDs, but we also support path for context-aware navigation
        $folder_id = isset($_REQUEST['folder_id']) ? sanitize_text_field(wp_unslash($_REQUEST['folder_id'])) : '';
        $path = isset($_REQUEST['path']) ? sanitize_text_field(wp_unslash($_REQUEST['path'])) : '';

        // Reject invalid folder IDs (like 'undefined' from JS)
        if ($folder_id === 'undefined' || !preg_match('/^[0-9]*$/', $folder_id)) {
            $folder_id = '';
        }

        // If path is provided (from context-aware navigation), convert to folder ID
        if (empty($folder_id) && !empty($path)) {
            $folder_id = $this->client->getFolderIdByPath($path);
        }

        if (empty($folder_id)) {
            $folder_id = $this->config->getSelectedFolder();
            if ($folder_id === false || $folder_id === '') {
                $folder_id = '0';
            }
        }


        ob_start();
        $this->renderLibraryContent($folder_id);
        $html = ob_get_clean();

        wp_send_json_success(array('html' => $html));
    }

    /**
     * Render the inner content of the library (for AJAX)
     * 
     * @param string $folder_id Box folder ID
     */
    private function renderLibraryContent($folder_id)
    {
        // Check if Box is connected
        if (!$this->config->isConnected()) {
?>
            <div class="edbx-library-content">
                <div class="edbx-notice warning">
                    <h4><?php esc_html_e('Box not connected', 'storage-for-edd-via-box'); ?></h4>
                    <p><?php esc_html_e('Please connect to Box in the plugin settings before browsing files.', 'storage-for-edd-via-box'); ?></p>
                    <p>
                        <a href="<?php echo esc_url(admin_url('edit.php?post_type=download&page=edd-settings&tab=extensions&section=edbx-settings')); ?>" class="button-primary">
                            <?php esc_html_e('Configure Box Settings', 'storage-for-edd-via-box'); ?>
                        </a>
                    </p>
                </div>
            <?php
            return;
        }

        // Try to get files
        try {
            $files = $this->client->listFiles($folder_id);
            $connection_error = false;
        } catch (Exception $e) {
            $files = [];
            $connection_error = true;
            $this->config->debug('Box connection error: ' . $e->getMessage());
        }

        // Calculate parent folder for back navigation
        $back_folder_id = '';
        $folderInfo = null;
        if ($folder_id !== '0' && !empty($folder_id)) {
            $folderInfo = $this->client->getFolderDetails($folder_id);
            if ($folderInfo && isset($folderInfo['parent']) && isset($folderInfo['parent']['id'])) {
                $back_folder_id = $folderInfo['parent']['id'];
            }
        }
            ?>
            <div class="edbx-header-row">
                <h3 class="media-title"><?php esc_html_e('Select a file from Box', 'storage-for-edd-via-box'); ?></h3>
                <div class="edbx-header-buttons">
                    <button type="button" class="button button-primary" id="edbx-toggle-upload">
                        <?php esc_html_e('Upload File', 'storage-for-edd-via-box'); ?>
                    </button>
                </div>
            </div>

            <?php if ($connection_error) { ?>
                <div class="edbx-notice warning">
                    <h4><?php esc_html_e('Connection Error', 'storage-for-edd-via-box'); ?></h4>
                    <p><?php esc_html_e('Unable to connect to Box.', 'storage-for-edd-via-box'); ?></p>
                    <p><?php esc_html_e('Please check your Box configuration settings and try again.', 'storage-for-edd-via-box'); ?></p>
                    <p>
                        <a href="<?php echo esc_url(admin_url('edit.php?post_type=download&page=edd-settings&tab=extensions&section=edbx-settings')); ?>" class="button-primary">
                            <?php esc_html_e('Check Settings', 'storage-for-edd-via-box'); ?>
                        </a>
                    </p>
                </div>
            <?php } else { ?>

                <div class="edbx-breadcrumb-nav">
                    <div class="edbx-nav-group">
                        <?php if (!empty($back_folder_id) || $folder_id !== '0') { ?>
                            <a href="#" class="edbx-nav-back" title="<?php esc_attr_e('Go Back', 'storage-for-edd-via-box'); ?>" data-folder-id="<?php echo esc_attr($back_folder_id ?: '0'); ?>">
                                <span class="dashicons dashicons-arrow-left-alt2"></span>
                            </a>
                        <?php } else { ?>
                            <span class="edbx-nav-back disabled">
                                <span class="dashicons dashicons-arrow-left-alt2"></span>
                            </span>
                        <?php } ?>

                        <div class="edbx-breadcrumbs">
                            <?php
                            $breadcrumb_links = array();

                            if ($folder_id !== '0') {
                                if (!isset($folderInfo)) {
                                    $folderInfo = $this->client->getFolderDetails($folder_id);
                                }

                                // Build breadcrumbs from path_collection
                                if (isset($folderInfo['path_collection']) && isset($folderInfo['path_collection']['entries'])) {
                                    foreach ($folderInfo['path_collection']['entries'] as $ancestor) {
                                        if ($ancestor['id'] === '0') {
                                            $breadcrumb_links[] = '<a href="#" data-folder-id="0">' . esc_html__('Home', 'storage-for-edd-via-box') . '</a>';
                                        } else {
                                            $breadcrumb_links[] = '<a href="#" data-folder-id="' . esc_attr($ancestor['id']) . '">' . esc_html($ancestor['name']) . '</a>';
                                        }
                                    }
                                } else {
                                    $breadcrumb_links[] = '<a href="#" data-folder-id="0">' . esc_html__('Home', 'storage-for-edd-via-box') . '</a>';
                                }

                                // Current folder (not a link)
                                if (isset($folderInfo['name'])) {
                                    $breadcrumb_links[] = '<span class="current">' . esc_html($folderInfo['name']) . '</span>';
                                }

                                echo wp_kses(implode(' <span class="sep">/</span> ', $breadcrumb_links), array(
                                    'a' => array('href' => array(), 'data-folder-id' => array()),
                                    'span' => array('class' => array())
                                ));
                            } else {
                                echo '<span class="current">' . esc_html__('Home', 'storage-for-edd-via-box') . '</span>';
                            }
                            ?>
                        </div>
                    </div>

                    <?php if (!empty($files)) { ?>
                        <div class="edbx-search-inline">
                            <input type="search"
                                id="edbx-file-search"
                                class="edbx-search-input"
                                placeholder="<?php esc_attr_e('Search files...', 'storage-for-edd-via-box'); ?>">
                        </div>
                    <?php } ?>
                </div>

                <!-- Upload Form (Hidden by default) -->
                <form enctype="multipart/form-data" method="post" action="#" class="edbx-upload-form" id="edbx-upload-section" style="display: none;">
                    <?php wp_nonce_field('edbx_upload', 'edbx_nonce'); ?>
                    <input type="hidden" name="action" value="edbx_ajax_upload" />
                    <div class="upload-field">
                        <input type="file"
                            name="edbx_file"
                            accept=".zip,.rar,.7z,.pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif" />
                    </div>
                    <p class="description">
                        <?php
                        // translators: %s: Maximum upload file size.
                        printf(esc_html__('Maximum upload file size: %s', 'storage-for-edd-via-box'), esc_html(size_format(wp_max_upload_size())));
                        ?>
                    </p>
                    <input type="submit"
                        class="button-primary"
                        value="<?php esc_attr_e('Upload', 'storage-for-edd-via-box'); ?>" />
                    <input type="hidden" name="edbx_folder" value="<?php echo esc_attr($folder_id); ?>" />
                </form>

                <?php if (is_array($files) && !empty($files)) { ?>
                    <!-- File Display Table -->
                    <table class="wp-list-table widefat fixed edbx-files-table">
                        <thead>
                            <tr>
                                <th class="column-primary" style="width: 40%;"><?php esc_html_e('File Name', 'storage-for-edd-via-box'); ?></th>
                                <th class="column-size" style="width: 20%;"><?php esc_html_e('File Size', 'storage-for-edd-via-box'); ?></th>
                                <th class="column-date" style="width: 25%;"><?php esc_html_e('Last Modified', 'storage-for-edd-via-box'); ?></th>
                                <th class="column-actions" style="width: 15%;"><?php esc_html_e('Actions', 'storage-for-edd-via-box'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Sort: folders first, then files
                            usort($files, function ($a, $b) {
                                if ($a['is_folder'] && !$b['is_folder']) return -1;
                                if (!$a['is_folder'] && $b['is_folder']) return 1;
                                return strcasecmp($a['name'], $b['name']);
                            });

                            foreach ($files as $file) {
                                // Handle folders
                                if ($file['is_folder']) {
                            ?>
                                    <tr class="edbx-folder-row">
                                        <td class="column-primary" data-label="<?php esc_attr_e('Folder Name', 'storage-for-edd-via-box'); ?>">
                                            <a href="#" class="folder-link" data-folder-id="<?php echo esc_attr($file['id']); ?>">
                                                <span class="dashicons dashicons-category"></span>
                                                <span class="file-name"><?php echo esc_html($file['name']); ?></span>
                                            </a>
                                        </td>
                                        <td class="column-size">—</td>
                                        <td class="column-date">—</td>
                                        <td class="column-actions">
                                            <a href="#" class="button-secondary button-small folder-link" data-folder-id="<?php echo esc_attr($file['id']); ?>">
                                                <?php esc_html_e('Open', 'storage-for-edd-via-box'); ?>
                                            </a>
                                        </td>
                                    </tr>
                                <?php
                                    continue;
                                }

                                // Handle files
                                $file_size = $this->formatFileSize($file['size']);
                                $last_modified = !empty($file['modified']) ? $this->formatHumanDate($file['modified']) : '—';
                                ?>
                                <tr>
                                    <td class="column-primary" data-label="<?php esc_attr_e('File Name', 'storage-for-edd-via-box'); ?>">
                                        <div class="edbx-file-display">
                                            <span class="file-name"><?php echo esc_html($file['name']); ?></span>
                                        </div>
                                    </td>
                                    <td class="column-size" data-label="<?php esc_attr_e('File Size', 'storage-for-edd-via-box'); ?>">
                                        <span class="file-size"><?php echo esc_html($file_size); ?></span>
                                    </td>
                                    <td class="column-date" data-label="<?php esc_attr_e('Last Modified', 'storage-for-edd-via-box'); ?>">
                                        <span class="file-date"><?php echo esc_html($last_modified); ?></span>
                                    </td>
                                    <td class="column-actions" data-label="<?php esc_attr_e('Actions', 'storage-for-edd-via-box'); ?>">
                                        <a class="save-edbx-file button-secondary button-small"
                                            href="javascript:void(0)"
                                            data-edbx-filename="<?php echo esc_attr($file['name']); ?>"
                                            data-edbx-link="<?php echo esc_attr($file['full_path']); ?>">
                                            <?php esc_html_e('Select File', 'storage-for-edd-via-box'); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                <?php } else { ?>
                    <div class="edbx-notice info" style="margin-top: 15px;">
                        <p><?php esc_html_e('This folder is empty. Use the upload form above to add files.', 'storage-for-edd-via-box'); ?></p>
                    </div>
                <?php } ?>
            <?php } ?>
            </div>
        <?php
    }

    /**
     * Format file size in human readable format
     * @param int $size
     * @return string
     */
    private function formatFileSize($size)
    {
        if ($size === 0) return '0 B';

        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        $power = floor(log($size, 1024));
        if ($power >= count($units)) {
            $power = count($units) - 1;
        }

        return round($size / pow(1024, $power), 2) . ' ' . $units[$power];
    }

    /**
     * Format date in human readable format
     * @param string $date
     * @return string
     */
    private function formatHumanDate($date)
    {
        try {
            $timestamp = strtotime($date);
            if ($timestamp) {
                return date_i18n('j F Y', $timestamp);
            }
        } catch (Exception $e) {
            // Ignore date formatting errors
        }
        return $date;
    }

    /**
     * Enqueue CSS styles and JS scripts
     */
    public function enqueueStyles()
    {
        // Register styles
        wp_register_style('edbx-media-library', EDBX_PLUGIN_URL . 'assets/css/box-media-library.css', array(), EDBX_VERSION);
        wp_register_style('edbx-upload', EDBX_PLUGIN_URL . 'assets/css/box-upload.css', array(), EDBX_VERSION);
        wp_register_style('edbx-media-container', EDBX_PLUGIN_URL . 'assets/css/box-media-container.css', array(), EDBX_VERSION);
        wp_register_style('edbx-modal', EDBX_PLUGIN_URL . 'assets/css/box-modal.css', array('dashicons'), EDBX_VERSION);
        wp_register_style('edbx-browse-button', EDBX_PLUGIN_URL . 'assets/css/box-browse-button.css', array(), EDBX_VERSION);

        // Register scripts
        wp_register_script('edbx-media-library', EDBX_PLUGIN_URL . 'assets/js/box-media-library.js', array('jquery'), EDBX_VERSION, true);
        wp_register_script('edbx-upload', EDBX_PLUGIN_URL . 'assets/js/box-upload.js', array('jquery'), EDBX_VERSION, true);
        wp_register_script('edbx-modal', EDBX_PLUGIN_URL . 'assets/js/box-modal.js', array('jquery'), EDBX_VERSION, true);
        wp_register_script('edbx-browse-button', EDBX_PLUGIN_URL . 'assets/js/box-browse-button.js', array('jquery', 'edbx-modal'), EDBX_VERSION, true);

        // Localize scripts
        wp_localize_script('edbx-media-library', 'edbx_i18n', array(
            'file_selected_success' => esc_html__('File selected successfully!', 'storage-for-edd-via-box'),
            'file_selected_error' => esc_html__('Error selecting file. Please try again.', 'storage-for-edd-via-box')
        ));

        wp_add_inline_script('edbx-media-library', 'var edbx_url_prefix = "' . esc_js($this->config->getUrlPrefix()) . '";', 'before');

        wp_localize_script('edbx-upload', 'edbx_i18n', array(
            'file_size_too_large' => esc_html__('File size too large. Maximum allowed size is', 'storage-for-edd-via-box')
        ));

        wp_add_inline_script('edbx-upload', 'var edbx_url_prefix = "' . esc_js($this->config->getUrlPrefix()) . '";', 'before');
        wp_add_inline_script('edbx-upload', 'var edbx_max_upload_size = ' . wp_json_encode(wp_max_upload_size()) . ';', 'before');
    }

    /**
     * Render Browse Box button in EDD file row (Server Side)
     */
    public function renderBrowseButton($key, $file, $post_id)
    {
        if (!$this->config->isConnected()) {
            return;
        }
        ?>
            <div class="edd-form-group edd-file-box-browse">
                <label class="edd-form-group__label edd-repeatable-row-setting-label">&nbsp;</label>
                <div class="edd-form-group__control">
                    <button type="button" class="button edbx_browse_button">
                        <?php esc_html_e('Browse Box', 'storage-for-edd-via-box'); ?>
                    </button>
                </div>
            </div>
    <?php
    }

    /**
     * Add Box browse button scripts
     */
    public function printAdminScripts()
    {
        global $pagenow, $typenow;

        // Only on EDD download edit pages
        if (!($pagenow === 'post.php' || $pagenow === 'post-new.php') || $typenow !== 'download') {
            return;
        }

        // Only if connected
        if (!$this->config->isConnected()) {
            return;
        }

        // Enqueue modal assets
        wp_enqueue_style('edbx-modal');
        wp_enqueue_script('edbx-modal');

        // Enqueue media library assets for AJAX
        wp_enqueue_style('edbx-media-library');
        wp_enqueue_script('edbx-media-library');
        wp_enqueue_style('edbx-upload');
        wp_enqueue_script('edbx-upload');

        // Enqueue browse button assets
        wp_enqueue_style('edbx-browse-button');
        wp_enqueue_script('edbx-browse-button');

        // Localize script with dynamic data for browse button
        wp_localize_script('edbx-browse-button', 'edbx_browse_button', array(
            'modal_title' => __('Box Library', 'storage-for-edd-via-box'),
            'nonce'       => wp_create_nonce('media-form'),
            'url_prefix'  => $this->config->getUrlPrefix()
        ));

        // Localize AJAX URL and nonce for media library
        $ajax_url = admin_url('admin-ajax.php');
        wp_add_inline_script('edbx-media-library', 'var edbx_ajax_url = "' . esc_js($ajax_url) . '";', 'before');
        wp_add_inline_script('edbx-media-library', 'var edbx_nonce = "' . esc_js(wp_create_nonce('media-form')) . '";', 'before');
        wp_add_inline_script('edbx-media-library', 'var edbx_url_prefix = "' . esc_js($this->config->getUrlPrefix()) . '";', 'before');
        wp_add_inline_script('edbx-media-library', 'var edbx_max_upload_size = ' . wp_json_encode(wp_max_upload_size()) . ';', 'before');
        wp_localize_script('edbx-media-library', 'edbx_i18n', array(
            'error'              => esc_html__('An error occurred. Please try again.', 'storage-for-edd-via-box'),
            'uploading'          => esc_html__('Uploading...', 'storage-for-edd-via-box'),
            'upload_success'     => esc_html__('File uploaded successfully!', 'storage-for-edd-via-box'),
            'upload_error'       => esc_html__('Upload failed. Please try again.', 'storage-for-edd-via-box'),
            'file_size_too_large' => esc_html__('File size too large. Maximum allowed:', 'storage-for-edd-via-box'),
            'select_file'        => esc_html__('Please select a file.', 'storage-for-edd-via-box'),
        ));
    }
}
