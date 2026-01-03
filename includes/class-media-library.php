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

        // Media library integration
        add_filter('media_upload_tabs', array($this, 'addBoxTabs'));
        add_action('media_upload_edbx_lib', array($this, 'registerLibraryTab'));
        add_action('admin_head', array($this, 'setupAdminJS'));

        // Enqueue styles
        add_action('admin_enqueue_scripts', array($this, 'enqueueStyles'));
    }

    /**
     * Add Box tabs to media uploader
     * 
     * @param array $default_tabs
     * @return array
     */
    public function addBoxTabs($default_tabs)
    {
        if ($this->config->isConnected()) {
            $default_tabs['edbx_lib'] = esc_html__('Box Library', 'storage-for-edd-via-box');
        }
        return $default_tabs;
    }

    /**
     * Register Box Library tab
     */
    public function registerLibraryTab()
    {
        // Using same capability filter/default as Dropbox for consistency
        $mediaCapability = apply_filters('edbx_media_access_cap', 'edit_products');
        if (!current_user_can($mediaCapability)) {
            wp_die(esc_html__('You do not have permission to access Box library.', 'storage-for-edd-via-box'));
        }

        // Check nonce for GET requests with parameters
        if (!empty($_GET) && (isset($_GET['path']) || isset($_GET['_wpnonce']))) {
            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'media-form')) {
                wp_die(esc_html__('Security check failed.', 'storage-for-edd-via-box'));
            }
        }

        if (!empty($_POST)) {
            if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'media-form')) {
                wp_die(esc_html__('Security check failed.', 'storage-for-edd-via-box'));
            }

            $error = media_upload_form_handler();
            if (is_string($error)) {
                return $error;
            }
        }
        wp_iframe(array($this, 'renderLibraryTab'));
    }

    /**
     * Render Box Library tab content
     */
    public function renderLibraryTab()
    {
        media_upload_header();
        wp_enqueue_style('media');
        wp_enqueue_style('edbx-media-library');
        wp_enqueue_style('edbx-media-container');
        wp_enqueue_style('edbx-upload');
        wp_enqueue_script('edbx-media-library');
        wp_enqueue_script('edbx-upload');

        $path = $this->getPath();

        // Check if Box is connected
        if (!$this->config->isConnected()) {
?>
            <div id="media-items" class="edbx-media-container">
                <h3 class="media-title"><?php esc_html_e('Box File Browser', 'storage-for-edd-via-box'); ?></h3>

                <div class="edbx-notice warning">
                    <h4><?php esc_html_e('Box not connected', 'storage-for-edd-via-box'); ?></h4>
                    <p><?php esc_html_e('Please connect to Box in the plugin settings before browsing files.', 'storage-for-edd-via-box'); ?></p>
                    <p>
                        <a href="<?php echo esc_url(admin_url('edit.php?post_type=download&page=edd-settings&tab=extensions&section=edbx-settings')); ?>" class="button-primary">
                            <?php esc_html_e('Configure Box Settings', 'storage-for-edd-via-box'); ?>
                        </a>
                    </p>
                </div>
            </div>
        <?php
            return;
        }

        // Use default folder from settings if no path specified in URL
        if (empty($path)) {
            $path = $this->config->getSelectedFolder();
            // Box uses '0' for root, which might evaluate to empty in some checks but let's be safe
            if ($path === false || $path === '') {
                $path = '0';
            }
        }

        // Try to get files
        try {
            $files = $this->client->listFiles($path);
            $connection_error = false;
        } catch (Exception $e) {
            $files = [];
            $connection_error = true;
            $this->config->debug('Box connection error: ' . $e->getMessage());
        }
        ?>

        <?php
        // Calculate back URL for header if in subfolder
        $back_url = '';
        if ($path !== '0' && !empty($path)) {
            // Box doesn't have "paths" like /foo/bar natively easily accessible from ID.
            // But we need to navigate UP.
            // The listFiles response usually includes 'parent' info or we can fetch folder details.
            // For now, let's see if we can get parent from folder details.
            // Client::listFiles returns items.
            // To get parent ID, we might need an extra call or rely on Client storing structure.
            // Dropbox uses paths strings so dirname() works. Box uses IDs.
            // We need to fetch folder info for the current ID to know its parent.

            // Let's verify if Client has getFolderDetails. Yes it does.
            $folderInfo = $this->client->getFolderDetails($path);
            if ($folderInfo && isset($folderInfo['parent']) && isset($folderInfo['parent']['id'])) {
                $parent_path = $folderInfo['parent']['id'];
                $back_url = remove_query_arg(array('edbx_success', 'edbx_filename', 'error'));
                $back_url = add_query_arg(array(
                    'path' => $parent_path,
                    '_wpnonce' => wp_create_nonce('media-form')
                ), $back_url);
            }
        }
        ?>
        <div style="width: inherit;" id="media-items">
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
            <?php } elseif (!$connection_error) { ?>

                <div class="edbx-breadcrumb-nav">
                    <div class="edbx-nav-group">
                        <?php if (!empty($back_url)) { ?>
                            <a href="<?php echo esc_url($back_url); ?>" class="edbx-nav-back" title="<?php esc_attr_e('Go Back', 'storage-for-edd-via-box'); ?>">
                                <span class="dashicons dashicons-arrow-left-alt2"></span>
                            </a>
                        <?php } else { ?>
                            <span class="edbx-nav-back disabled">
                                <span class="dashicons dashicons-arrow-left-alt2"></span>
                            </span>
                        <?php } ?>

                        <div class="edbx-breadcrumbs">
                            <?php
                            // Box uses IDs, so we need to get folder details
                            $browsingName = 'Home';
                            $breadcrumb_links = array();

                            if ($path !== '0') {
                                if (!isset($folderInfo)) {
                                    $folderInfo = $this->client->getFolderDetails($path);
                                }

                                // Check if we have path_collection from Box API (contains full path)
                                if (isset($folderInfo['path_collection']) && isset($folderInfo['path_collection']['entries'])) {
                                    foreach ($folderInfo['path_collection']['entries'] as $ancestor) {
                                        if ($ancestor['id'] === '0') {
                                            // Root folder
                                            $root_url = remove_query_arg(array('path', 'edbx_success', 'edbx_filename', 'error'));
                                            $root_url = add_query_arg(array(
                                                'path' => '0',
                                                '_wpnonce' => wp_create_nonce('media-form')
                                            ), $root_url);
                                            $breadcrumb_links[] = '<a href="' . esc_url($root_url) . '">' . esc_html__('Home', 'storage-for-edd-via-box') . '</a>';
                                        } else {
                                            // Parent folder
                                            $folder_url = remove_query_arg(array('edbx_success', 'edbx_filename', 'error'));
                                            $folder_url = add_query_arg(array(
                                                'path' => $ancestor['id'],
                                                '_wpnonce' => wp_create_nonce('media-form')
                                            ), $folder_url);
                                            $breadcrumb_links[] = '<a href="' . esc_url($folder_url) . '">' . esc_html($ancestor['name']) . '</a>';
                                        }
                                    }
                                } else {
                                    // Fallback - at least show root
                                    $root_url = remove_query_arg(array('path', 'edbx_success', 'edbx_filename', 'error'));
                                    $root_url = add_query_arg(array(
                                        'path' => '0',
                                        '_wpnonce' => wp_create_nonce('media-form')
                                    ), $root_url);
                                    $breadcrumb_links[] = '<a href="' . esc_url($root_url) . '">' . esc_html__('Home', 'storage-for-edd-via-box') . '</a>';
                                }

                                // Current folder (not a link)
                                if (isset($folderInfo['name'])) {
                                    $breadcrumb_links[] = '<span class="current">' . esc_html($folderInfo['name']) . '</span>';
                                } else {
                                    $breadcrumb_links[] = '<span class="current">Folder ID: ' . esc_html($path) . '</span>';
                                }

                                echo wp_kses(implode(' <span class="sep">/</span> ', $breadcrumb_links), array(
                                    'a' => array('href' => array()),
                                    'span' => array('class' => array()),
                                    'strong' => array()
                                ));
                            } else {
                                // Bucket (root) link
                                // Just show bucket name 
                                echo '<span class="current">' . esc_html__('Home', 'storage-for-edd-via-box') . '</span>';
                            }
                            ?>
                        </div>
                    </div>

                    <!-- Moved Search Input -->
                    <?php if (is_array($files) && !empty($files)) { ?>
                        <div class="edbx-search-inline">
                            <input type="search"
                                id="edbx-file-search"
                                class="edbx-search-input"
                                placeholder="<?php esc_attr_e('Search files...', 'storage-for-edd-via-box'); ?>">
                        </div>
                    <?php } ?>
                </div>



                <?php
                // Upload form integrated into Library
                $successFlag = filter_input(INPUT_GET, 'edbx_success', FILTER_SANITIZE_NUMBER_INT);
                $errorMsg = filter_input(INPUT_GET, 'error', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

                if ($errorMsg) {
                    $this->config->debug('Upload error: ' . $errorMsg);
                ?>
                    <div class="edd_errors edbx-notice warning">
                        <h4><?php esc_html_e('Error', 'storage-for-edd-via-box'); ?></h4>
                        <p class="edd_error"><?php esc_html_e('An error occurred during the upload process. Please try again.', 'storage-for-edd-via-box'); ?></p>
                    </div>
                <?php
                }

                if (!empty($successFlag) && '1' == $successFlag) {
                    $savedPathAndFilename = filter_input(INPUT_GET, 'edbx_filename', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
                    $savedFileId = filter_input(INPUT_GET, 'edbx_file_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

                    // Box uses ID for the link usually in our system (edd-box:ID)
                    // But here we might want to show name
                    $savedFilename = sanitize_text_field($savedPathAndFilename);
                ?>
                    <div class="edd_errors edbx-notice success">
                        <h4><?php esc_html_e('Upload Successful', 'storage-for-edd-via-box'); ?></h4>
                        <p class="edd_success">
                            <?php
                            // translators: %s: File name.
                            printf(esc_html__('File %s uploaded successfully!', 'storage-for-edd-via-box'), '<strong>' . esc_html($savedFilename) . '</strong>');
                            ?>
                        </p>
                        <p>
                            <a href="javascript:void(0)"
                                id="edbx_save_link"
                                class="button-primary"
                                data-edbx-fn="<?php echo esc_attr($savedFilename); ?>"
                                data-edbx-path="<?php echo esc_attr($savedFileId); ?>">
                                <?php esc_html_e('Use this file in your Download', 'storage-for-edd-via-box'); ?>
                            </a>
                        </p>
                    </div>
                <?php
                }
                ?>
                <!-- Upload Form (Hidden by default) -->
                <form enctype="multipart/form-data" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="edbx-upload-form" id="edbx-upload-section" style="display: none;">
                    <?php wp_nonce_field('edbx_upload', 'edbx_nonce'); ?>
                    <input type="hidden" name="action" value="edbx_upload" />
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
                    <input type="hidden" name="edbx_folder" value="<?php echo esc_attr($path); ?>" />
                </form>

                <?php if (!empty($files)) { ?>


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
                                    $folder_url = add_query_arg(array(
                                        'path' => $file['id'], // Box uses ID
                                        '_wpnonce' => wp_create_nonce('media-form')
                                    ));
                            ?>
                                    <tr class="edbx-folder-row">
                                        <td class="column-primary" data-label="<?php esc_attr_e('Folder Name', 'storage-for-edd-via-box'); ?>">
                                            <a href="<?php echo esc_url($folder_url); ?>" class="folder-link">
                                                <span class="dashicons dashicons-category"></span>
                                                <span class="file-name"><?php echo esc_html($file['name']); ?></span>
                                            </a>
                                        </td>
                                        <td class="column-size">—</td>
                                        <td class="column-date">—</td>
                                        <td class="column-actions">
                                            <a href="<?php echo esc_url($folder_url); ?>" class="button-secondary button-small">
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
                                            data-edbx-link="<?php echo esc_attr($file['full_path']); // Use full path for display logic 
                                                            ?>">
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
     * Setup admin JavaScript
     */
    public function setupAdminJS()
    {
        wp_enqueue_script('edbx-admin-upload-buttons');
    }

    /**
     * Get current path (Folder ID for Box) from GET param
     * @return string
     */
    private function getPath()
    {
        $mediaCapability = apply_filters('edbx_media_access_cap', 'edit_products');
        if (!current_user_can($mediaCapability)) {
            return '';
        }

        if (!empty($_GET['path'])) {
            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'media-form')) {
                wp_die(esc_html__('Security check failed.', 'storage-for-edd-via-box'));
            }
        }
        return !empty($_GET['path']) ? sanitize_text_field(wp_unslash($_GET['path'])) : '';
    }

    /**
     * Format file size in human readable format
     * @param int $size
     * @return string
     */
    private function formatFileSize($size)
    {
        if ($size == 0) return '0 B';

        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        $power = floor(log($size, 1024));

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

        // Register scripts
        wp_register_script('edbx-media-library', EDBX_PLUGIN_URL . 'assets/js/box-media-library.js', array('jquery'), EDBX_VERSION, true);
        wp_register_script('edbx-upload', EDBX_PLUGIN_URL . 'assets/js/box-upload.js', array('jquery'), EDBX_VERSION, true);
        wp_register_script('edbx-admin-upload-buttons', EDBX_PLUGIN_URL . 'assets/js/admin-upload-buttons.js', array('jquery'), EDBX_VERSION, true);

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

        wp_add_inline_script('edbx-admin-upload-buttons', 'var edbx_url_prefix = "' . esc_js($this->config->getUrlPrefix()) . '";', 'before');
    }
}
