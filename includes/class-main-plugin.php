<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main Class for Box Storage
 */
class EDBX_BoxStorage
{
    private $settings;
    private $media_library;
    private $downloader;
    private $uploader;

    public function __construct()
    {
        $this->init();
    }

    /**
     * Initialize plugin components
     */
    private function init()
    {
        // Admin Notices
        add_action('admin_notices', array($this, 'showConfigurationNotice'));

        // Initialize components
        $this->settings      = new EDBX_Admin_Settings();
        $this->media_library = new EDBX_Media_Library();
        $this->uploader      = new EDBX_Box_Uploader();
        $this->downloader    = new EDBX_Box_Downloader();

        // Register EDD download filter
        add_filter('edd_requested_file', array($this->downloader, 'generateUrl'), 11, 3);
    }

    /**
     * Show admin notice if Box is not configured
     */
    public function showConfigurationNotice()
    {
        // Only show on admin pages
        if (!is_admin()) {
            return;
        }

        // Don't show on Box settings page itself
        $current_screen = get_current_screen();
        if ($current_screen && strpos($current_screen->id, 'edd-settings') !== false) {
            return;
        }

        // Show notice if not connected
        $config = new EDBX_Box_Config();
        if (!$config->isConnected()) {
            $settings_url = admin_url('edit.php?post_type=download&page=edd-settings&tab=extensions&section=edbx-settings');
?>
            <div class="notice notice-error">
                <p>
                    <strong><?php esc_html_e('Storage for EDD via Box:', 'storage-for-edd-via-box'); ?></strong>
                    <?php esc_html_e('Please connect to Box to start using cloud storage for your digital products.', 'storage-for-edd-via-box'); ?>
                    <a href="<?php echo esc_url($settings_url); ?>" class="button button-secondary" style="margin-left: 10px;">
                        <?php esc_html_e('Configure Box', 'storage-for-edd-via-box'); ?>
                    </a>
                </p>
            </div>
<?php
        }
    }

    /**
     * Get plugin version
     * @return string
     */
    public function getVersion()
    {
        return EDBX_VERSION;
    }
}
