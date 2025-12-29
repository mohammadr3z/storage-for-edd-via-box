<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Box Admin Settings
 * 
 * Integrates Box configuration with EDD settings panel
 * and handles OAuth2 authorization flow.
 */
class EDBX_Admin_Settings
{
    private $client;
    private $config;

    public function __construct()
    {
        $this->config = new EDBX_Box_Config();
        $this->client = new EDBX_Box_Client();

        // Register EDD settings
        add_filter('edd_settings_extensions', array($this, 'addSettings'));
        add_filter('edd_settings_sections_extensions', array($this, 'registerSection'));

        // Enqueue admin scripts/styles
        add_action('admin_enqueue_scripts', array($this, 'enqueueAdminScripts'));

        // OAuth callback handler
        add_action('admin_post_edbx_oauth_start', array($this, 'startOAuthFlow'));
        add_action('admin_post_edbx_disconnect', array($this, 'handleDisconnect'));

        // Register clean OAuth callback endpoint
        add_action('init', array($this, 'registerOAuthEndpoint'));
        add_action('template_redirect', array($this, 'handleOAuthEndpoint'));

        // Register query vars
        add_filter('query_vars', array($this, 'addQueryVars'));

        // Auto-flush rewrite rules if version changed
        add_action('init', array($this, 'maybeFlushRewriteRules'), 99);

        // Admin notices
        add_action('admin_notices', array($this, 'showAdminNotices'));

        // Clear tokens when Client ID or Client Secret changes
        add_filter('pre_update_option_edd_settings', array($this, 'checkCredentialsChange'), 10, 2);
    }

    /**
     * Add query variables
     * 
     * @param array $vars
     * @return array
     */
    public function addQueryVars($vars)
    {
        $vars[] = 'edbx_oauth_callback';
        return $vars;
    }

    /**
     * Flush rewrite rules if plugin version changed
     */
    public function maybeFlushRewriteRules()
    {
        $current_version = EDBX_VERSION;
        $saved_version = get_option('edbx_rewrite_version');

        if ($saved_version !== $current_version) {
            $this->registerOAuthEndpoint(); // Ensure rules are added before flushing
            flush_rewrite_rules();
            update_option('edbx_rewrite_version', $current_version);
        }
    }

    /**
     * Register OAuth callback endpoint rewrite rule
     */
    public function registerOAuthEndpoint()
    {
        add_rewrite_rule(
            '^edbx-oauth-callback/?$',
            'index.php?edbx_oauth_callback=1',
            'top'
        );
        add_rewrite_tag('%edbx_oauth_callback%', '1');
    }

    /**
     * Handle OAuth callback at custom endpoint
     */
    public function handleOAuthEndpoint()
    {
        if (get_query_var('edbx_oauth_callback')) {
            $this->handleOAuthCallback();
            exit;
        }
    }

    /**
     * Flush rewrite rules on plugin activation
     */
    public static function activatePlugin()
    {
        // Register the endpoint first
        add_rewrite_rule(
            '^edbx-oauth-callback/?$',
            'index.php?edbx_oauth_callback=1',
            'top'
        );
        add_rewrite_tag('%edbx_oauth_callback%', '1');

        // Flush to apply the new rule
        flush_rewrite_rules();
    }

    /**
     * Flush rewrite rules on plugin deactivation
     */
    public static function deactivatePlugin()
    {
        flush_rewrite_rules();
    }

    /**
     * Check if Client ID or Client Secret has changed and clear tokens if so
     * 
     * @param array $new_value New settings value
     * @param array $old_value Old settings value
     * @return array
     */
    public function checkCredentialsChange($new_value, $old_value)
    {
        $client_id_field = EDBX_Box_Config::KEY_CLIENT_ID;
        $client_secret_field = EDBX_Box_Config::KEY_CLIENT_SECRET;

        $old_key = isset($old_value[$client_id_field]) ? $old_value[$client_id_field] : '';
        $new_key = isset($new_value[$client_id_field]) ? $new_value[$client_id_field] : '';

        $old_secret = isset($old_value[$client_secret_field]) ? $old_value[$client_secret_field] : '';
        $new_secret = isset($new_value[$client_secret_field]) ? $new_value[$client_secret_field] : '';

        // If Client ID or Client Secret changed, clear tokens
        if ($old_key !== $new_key || $old_secret !== $new_secret) {
            $this->config->clearTokens();
        }

        return $new_value;
    }

    /**
     * Add settings to EDD Extensions tab
     * 
     * @param array $settings
     * @return array
     */
    public function addSettings($settings)
    {
        $is_connected = $this->config->isConnected();

        // Check if we are on the EDD extensions settings page
        // This prevents API calls on every admin page load
        // Load folders if: tab=extensions AND (no section OR section is ours)
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only comparison against hardcoded strings for page detection, no data processing.
        $is_settings_page = is_admin() &&
            isset($_GET['page']) && $_GET['page'] === 'edd-settings' &&
            isset($_GET['tab']) && $_GET['tab'] === 'extensions' &&
            (!isset($_GET['section']) || $_GET['section'] === 'edbx-settings');
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        // Build folder options
        // Only fetch from API if we are effectively on the settings page and connected
        $folder_options = array('0' => __('Root folder', 'storage-for-edd-via-box'));
        $has_permission_error = false;

        if ($is_connected && $is_settings_page) {
            try {
                // Fetch folders from Box (using listFiles but filtering folders)
                // Note: listFiles in Client returns items with 'is_folder'
                $folders = $this->client->listFiles('0');
                if (empty($folders) && is_array($folders)) {
                    // Empty root is fine
                } else {
                    foreach ($folders as $item) {
                        if ($item['is_folder']) {
                            $folder_options[$item['id']] = $item['name'];
                        }
                    }
                }
            } catch (Exception $e) {
                // Box errors might differ from Dropbox permissions
                // basic logic:
                $this->config->debug('Error loading folders: ' . $e->getMessage());
                // We don't mark permission error by default unless we know specific API code
            }
        } elseif ($is_connected && !$is_settings_page) {
            // If connected but not on settings page, preserve the currently saved value
            $saved_folder = $this->config->getSelectedFolder();
            if (!empty($saved_folder) && $saved_folder !== '0') {
                $folder_options[$saved_folder] = $saved_folder; // We don't have name easily here without API
            }
        }

        // Build connect/disconnect button HTML
        $connect_button = '';
        if ($is_connected) {
            $disconnect_url = wp_nonce_url(
                admin_url('admin-post.php?action=edbx_disconnect'),
                'edbx_disconnect'
            );

            // Different status display based on permissions
            if ($has_permission_error) {
                // Logic for permission error if needed
                $connect_button = sprintf(
                    '<span class="edbx-warning-status">%s</span><br><br><span class="edbx-permission-warning">%s</span><br><br><a href="%s" class="button button-secondary">%s</a>',
                    esc_html__('Permissions Not Active', 'storage-for-edd-via-box'),
                    esc_html__('Required permissions are not enabled. Please disconnect, check your Box app settings, then reconnect.', 'storage-for-edd-via-box'),
                    esc_url($disconnect_url),
                    esc_html__('Disconnect from Box', 'storage-for-edd-via-box')
                );
            } else {
                // Green status when fully connected
                $connect_button = sprintf(
                    '<span class="edbx-connected-status">%s</span><br><br><a href="%s" class="button button-secondary">%s</a>',
                    esc_html__('Connected', 'storage-for-edd-via-box'),
                    esc_url($disconnect_url),
                    esc_html__('Disconnect from Box', 'storage-for-edd-via-box')
                );
            }
        } elseif ($this->config->hasAppCredentials()) {
            $connect_url = wp_nonce_url(
                admin_url('admin-post.php?action=edbx_oauth_start'),
                'edbx_oauth_start'
            );
            $connect_button = sprintf(
                '<a href="%s" class="button button-primary">%s</a>',
                esc_url($connect_url),
                esc_html__('Connect to Box', 'storage-for-edd-via-box')
            );
        } else {
            $connect_button = '<span class="edbx-notice">' . esc_html__('Please enter your Client ID and Client Secret first, then save settings.', 'storage-for-edd-via-box') . '</span>';
        }

        $box_settings = array(
            array(
                'id' => 'edbx_settings',
                'name' => '<strong>' . __('Box Storage Settings', 'storage-for-edd-via-box') . '</strong>',
                'type' => 'header'
            ),
            array(
                'id' => EDBX_Box_Config::KEY_CLIENT_ID,
                'name' => __('Client ID', 'storage-for-edd-via-box'),
                'desc' => __('Enter your Box App Client ID from the Box Developer Console.', 'storage-for-edd-via-box'),
                'type' => 'text',
                'size' => 'regular',
                'class' => 'edbx-credential'
            ),
            array(
                'id' => EDBX_Box_Config::KEY_CLIENT_SECRET,
                'name' => __('Client Secret', 'storage-for-edd-via-box'),
                'desc' => __('Enter your Box App Client Secret from the Box Developer Console.', 'storage-for-edd-via-box'),
                'type' => 'password', // Changed to password for security like Dropbox
                'size' => 'regular',
                'class' => 'edbx-credential'
            ),
            array(
                'id' => 'edbx_connection',
                'name' => __('Connection Status', 'storage-for-edd-via-box'),
                'desc' => $connect_button,
                'type' => 'descriptive_text'
            ),
            array(
                'id' => EDBX_Box_Config::KEY_FOLDER,
                'name' => __('Default Folder', 'storage-for-edd-via-box'),
                'desc' => $is_connected
                    ? __('Select the default folder for uploads.', 'storage-for-edd-via-box')
                    : __('Connect to Box first to select a folder.', 'storage-for-edd-via-box'),
                'type' => 'select',
                'options' => $folder_options,
                'std' => '0',
                'class' => $is_connected ? '' : 'edbx-disabled'
            ),
            array(
                'id' => 'edbx_help',
                'name' => __('Setup Instructions', 'storage-for-edd-via-box'),
                'desc' => sprintf(
                    '<ol>
                        <li>%s <a href="https://app.box.com/developers/console" target="_blank">%s</a></li>
                        <li>%s</li>
                        <li><strong>%s</strong>
                            <ul style="margin-top:5px;margin-left:20px;">
                                <li><code>Read all files and folders stored in Box</code></li>
                                <li><code>Write all files and folders stored in Box</code></li>
                            </ul>
                        </li>
                        <li>%s <code>%s</code></li>
                        <li>%s</li>
                        <li>%s</li>
                    </ol>',
                    __('Go to', 'storage-for-edd-via-box'),
                    __('Box Developer Console', 'storage-for-edd-via-box'),
                    __('Create a new Custom App with "User Authentication" (Standard OAuth 2.0).', 'storage-for-edd-via-box'),
                    __('Ensure these permissions/scopes are enabled:', 'storage-for-edd-via-box'),
                    __('Add this OAuth Redirect URI:', 'storage-for-edd-via-box'),
                    esc_html($this->getRedirectUri()),
                    __('Copy the Client ID and Client Secret and paste them above.', 'storage-for-edd-via-box'),
                    __('Save settings, then click "Connect to Box".', 'storage-for-edd-via-box')
                ),
                'type' => 'descriptive_text'
            ),
        );

        return array_merge($settings, array('edbx-settings' => $box_settings));
    }

    /**
     * Register settings section
     * 
     * @param array $sections
     * @return array
     */
    public function registerSection($sections)
    {
        $sections['edbx-settings'] = __('Box Storage', 'storage-for-edd-via-box');
        return $sections;
    }

    /**
     * Enqueue admin scripts and styles
     * 
     * @param string $hook
     */
    public function enqueueAdminScripts($hook)
    {
        if ($hook !== 'download_page_edd-settings') {
            return;
        }

        wp_enqueue_script('jquery');

        wp_register_style('edbx-admin-settings', EDBX_PLUGIN_URL . 'assets/css/admin-settings.css', array(), EDBX_VERSION);
        wp_enqueue_style('edbx-admin-settings');

        wp_register_script('edbx-admin-settings', EDBX_PLUGIN_URL . 'assets/js/admin-settings.js', array('jquery'), EDBX_VERSION, true);
        wp_enqueue_script('edbx-admin-settings');
    }

    /**
     * Get OAuth redirect URI
     * 
     * @return string
     */
    private function getRedirectUri()
    {
        return home_url('/edbx-oauth-callback/');
    }

    /**
     * Start OAuth authorization flow
     */
    public function startOAuthFlow()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'storage-for-edd-via-box'));
        }

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'edbx_oauth_start')) {
            wp_die(esc_html__('Security check failed.', 'storage-for-edd-via-box'));
        }

        if (!$this->config->hasAppCredentials()) {
            wp_safe_redirect(add_query_arg('edbx_error', 'no_credentials', wp_get_referer()));
            exit;
        }

        // Store state for security
        $state = wp_create_nonce('edbx_oauth_state');
        set_transient('edbx_oauth_state_' . get_current_user_id(), $state, 600);

        $auth_url = $this->client->getAuthorizationUrl($this->getRedirectUri());
        // Box state parameter is 'state'
        if (strpos($auth_url, 'state=') === false) {
            $auth_url .= '&state=' . $state;
        }

        add_filter('allowed_redirect_hosts', function ($hosts) {
            $hosts[] = 'account.box.com';
            return $hosts;
        });

        wp_safe_redirect($auth_url);
        exit;
    }

    /**
     * Handle OAuth callback from Box
     */
    public function handleOAuthCallback()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'storage-for-edd-via-box'));
        }

        // Verify state
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- OAuth uses state parameter as CSRF protection; properly sanitized.
        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
        $stored_state = get_transient('edbx_oauth_state_' . get_current_user_id());
        delete_transient('edbx_oauth_state_' . get_current_user_id());
        delete_transient('edbx_oauth_state');

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- State is compared with stored transient value, this is OAuth CSRF protection.
        if (empty($state) || $state !== $stored_state) {
            $this->redirectWithError('invalid_state');
            return;
        }

        // Check for error from Box
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback uses state parameter verification above instead of nonces.
        if (isset($_GET['error'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- OAuth callback uses state parameter verification instead of nonces.
            $this->redirectWithError(sanitize_text_field(wp_unslash($_GET['error'])));
            return;
        }

        // Get authorization code
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- OAuth callback uses state parameter verification instead of nonces.
        $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
        if (empty($code)) {
            $this->redirectWithError('no_code');
            return;
        }

        // Exchange code for token
        $tokens = $this->client->exchangeCodeForTokens($code, $this->getRedirectUri());
        if (!$tokens) {
            $this->redirectWithError('token_exchange_failed');
            return;
        }

        // Client::exchangeCodeForTokens calls config->saveTokens internally regarding tokens check
        // So we are good.

        // Redirect back to settings with success message
        $redirect = admin_url('edit.php?post_type=download&page=edd-settings&tab=extensions&section=edbx-settings&edbx_connected=1');
        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Handle disconnect request
     */
    public function handleDisconnect()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'storage-for-edd-via-box'));
        }

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'edbx_disconnect')) {
            wp_die(esc_html__('Security check failed.', 'storage-for-edd-via-box'));
        }

        $this->config->clearTokens();

        $redirect = admin_url('edit.php?post_type=download&page=edd-settings&tab=extensions&section=edbx-settings&edbx_disconnected=1');
        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Redirect to settings with error
     * 
     * @param string $error
     */
    private function redirectWithError($error)
    {
        $redirect = admin_url('edit.php?post_type=download&page=edd-settings&tab=extensions&section=edbx-settings&edbx_error=' . urlencode($error));
        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Show admin notices
     */
    public function showAdminNotices()
    {
        // Success: Connected
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only check for admin notice, set by OAuth redirect with proper nonce verification in handleOAuthCallback().
        if (isset($_GET['edbx_connected'])) {
?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e('Successfully connected to Box!', 'storage-for-edd-via-box'); ?></p>
            </div>
        <?php
        }

        // Success: Disconnected
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only check for admin notice, set by handleDisconnect() with proper nonce verification.
        if (isset($_GET['edbx_disconnected'])) {
        ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e('Disconnected from Box.', 'storage-for-edd-via-box'); ?></p>
            </div>
        <?php
        }

        // Error messages
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only check, error codes are validated against hardcoded array and never echoed directly.
        if (isset($_GET['edbx_error'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Sanitized with sanitize_text_field, used as lookup key only.
            $error = sanitize_text_field(wp_unslash($_GET['edbx_error']));
            $messages = array(
                'no_credentials' => __('Please enter your Client ID and Client Secret first.', 'storage-for-edd-via-box'),
                'invalid_state' => __('OAuth security check failed. Please try again.', 'storage-for-edd-via-box'),
                'no_code' => __('No authorization code received from Box.', 'storage-for-edd-via-box'),
                'token_exchange_failed' => __('Failed to exchange authorization code for access token.', 'storage-for-edd-via-box'),
                'access_denied' => __('Access was denied by the user.', 'storage-for-edd-via-box'),
            );
            $message = isset($messages[$error]) ? $messages[$error] : __('An error occurred during authorization.', 'storage-for-edd-via-box');
        ?>
            <div class="notice notice-error is-dismissible">
                <p><?php echo esc_html($message); ?></p>
            </div>
<?php
        }
    }
}
