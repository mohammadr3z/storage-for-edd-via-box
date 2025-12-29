<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Box Configuration Handler
 */
class EDBX_Box_Config
{
    const KEY_CLIENT_ID = 'edbx_client_id';
    const KEY_CLIENT_SECRET = 'edbx_client_secret';
    const KEY_ACCESS_TOKEN = 'edbx_access_token';
    const KEY_REFRESH_TOKEN = 'edbx_refresh_token';
    const KEY_TOKEN_EXPIRES = 'edbx_token_expires';
    const KEY_FOLDER = 'edbx_default_folder';

    // OAuth Endpoints
    const AUTH_URL = 'https://account.box.com/api/oauth2/authorize';
    const TOKEN_URL = 'https://api.box.com/oauth2/token';
    const URL_PREFIX = 'edd-box://';

    /**
     * Get URL Prefix
     */
    public function getUrlPrefix()
    {
        /**
         * Filter the URL prefix for Box file URLs
         * 
         * @param string $prefix The default URL prefix
         * @return string The filtered URL prefix
         */
        return apply_filters('edbx_url_prefix', self::URL_PREFIX);
    }

    /**
     * Get Client ID
     */
    public function getClientId()
    {
        return edd_get_option(self::KEY_CLIENT_ID, '');
    }

    /**
     * Get Client Secret
     */
    public function getClientSecret()
    {
        return edd_get_option(self::KEY_CLIENT_SECRET, '');
    }

    /**
     * Get Access Token
     */
    public function getAccessToken()
    {
        return get_option(self::KEY_ACCESS_TOKEN, '');
    }

    /**
     * Get Refresh Token
     */
    public function getRefreshToken()
    {
        return get_option(self::KEY_REFRESH_TOKEN, '');
    }

    /**
     * Get Token Expiry Timestamp
     */
    public function getTokenExpires()
    {
        return get_option(self::KEY_TOKEN_EXPIRES, 0);
    }

    /**
     * Check if plugin has App credentials
     */
    public function hasAppCredentials()
    {
        return !empty($this->getClientId()) && !empty($this->getClientSecret());
    }

    /**
     * Check if connected (has valid tokens)
     */
    public function isConnected()
    {
        // Simple check if we have tokens. Validation happens in Client.
        return !empty($this->getAccessToken());
    }

    /**
     * Save OAuth Tokens
     */
    public function saveTokens($access_token, $refresh_token, $expires_in)
    {
        update_option(self::KEY_ACCESS_TOKEN, $access_token);
        update_option(self::KEY_REFRESH_TOKEN, $refresh_token);
        // Box tokens expire in 60 minutes. Buffer it slightly.
        update_option(self::KEY_TOKEN_EXPIRES, time() + intval($expires_in) - 60);
    }

    /**
     * Clear all tokens (Disconnect)
     */
    public function clearTokens()
    {
        delete_option(self::KEY_ACCESS_TOKEN);
        delete_option(self::KEY_REFRESH_TOKEN);
        delete_option(self::KEY_TOKEN_EXPIRES);
    }

    /**
     * Get Default Upload Folder
     */
    public function getSelectedFolder()
    {
        return edd_get_option(self::KEY_FOLDER, '0'); // '0' is Box root
    }



    /**
     * Debug Logging
     */
    public function debug($log)
    {
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            if (is_array($log) || is_object($log)) {
                $message = wp_json_encode($log, JSON_UNESCAPED_UNICODE);
                if ($message !== false) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    error_log('[EDBX] ' . $message);
                }
            } else {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[EDBX] ' . sanitize_text_field($log));
            }
        }
    }
}
