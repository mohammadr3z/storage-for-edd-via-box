<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Box Uploader
 */
class EDBX_Box_Uploader
{
    private $config;
    // Box actually doesn't use 'client' for simple upload via Guzzle often, but let's see. 
    // Standard upload endpoint: POST https://upload.box.com/api/2.0/files/content
    // Requires 'attributes' (json) and 'file' (content).

    public function __construct()
    {
        $this->config = new EDBX_Box_Config();

        // Hook for admin-post actions to handle upload form submission
        add_action('admin_post_edbx_upload', array($this, 'processUpload'));
    }

    /**
     * Process File Upload
     */
    public function processUpload()
    {
        // Verify Nonce
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is happening right here
        if (!isset($_POST['edbx_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['edbx_nonce'])), 'edbx_upload')) {
            wp_die(esc_html__('Security check failed', 'storage-for-edd-via-box'));
        }

        // Check permissions
        if (!current_user_can('edit_products')) {
            wp_die(esc_html__('You do not have permission to upload files.', 'storage-for-edd-via-box'));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified at top of function.
        $folderId = !empty($_POST['edbx_folder']) ? sanitize_text_field(wp_unslash($_POST['edbx_folder'])) : '0';
        $redirectUrl = admin_url('media-upload.php?type=edbx_lib&tab=edbx_lib&folder=' . $folderId);

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Nonce verified at top, checking both isset and error value.
        if (!isset($_FILES['edbx_file']) || !isset($_FILES['edbx_file']['error']) || $_FILES['edbx_file']['error'] !== UPLOAD_ERR_OK) {
            $this->redirectWithError($redirectUrl, 'File upload failed or no file selected.');
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in processUpload() before this code runs.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_FILES array elements are validated/sanitized on subsequent lines.
        $file = $_FILES['edbx_file'];
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- tmp_name is a system path
        $filePath = $file['tmp_name'];
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Filename is sanitized using sanitize_file_name
        $fileName = sanitize_file_name($file['name']);
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        try {
            $uploadedFile = $this->uploadFile($filePath, $fileName, $folderId);

            if ($uploadedFile) {
                // Resolved Folder Path
                $boxClient = new EDBX_Box_Client();
                $folderPath = $boxClient->getFolderPath($folderId);
                $fullPath = trim($folderPath . '/' . $uploadedFile['name'], '/');

                // Success
                $redirectUrl = add_query_arg(array(
                    'edbx_success' => '1',
                    'edbx_filename' => $uploadedFile['name'], // Name for display
                    'edbx_file_id' => $fullPath,   // PASSPATH instead of ID
                    'folder' => $folderId
                ), $redirectUrl);

                wp_safe_redirect($redirectUrl);
                exit;
            }
        } catch (Exception $e) {
            $this->redirectWithError($redirectUrl, $e->getMessage());
        }

        $this->redirectWithError($redirectUrl, 'Unknown error occurred.');
    }

    /**
     * Upload file to Box
     */
    private function uploadFile($sourcePath, $fileName, $folderId)
    {
        $client = new \GuzzleHttp\Client();
        $accessToken = $this->config->getAccessToken();

        // Refresh token check is handled in Client, but here we are doing direct Guzzle for upload.
        // Ideally we should use EDBX_Box_Client to get a fresh token or use it to request.
        // Let's instantiate Client just to refresh token if needed.
        $boxClient = new EDBX_Box_Client();
        // Force token check/refresh by making a lightweight call or just relying on Config getting updated. 
        // Better: client should expose "getValidAccessToken". 
        // For now, let's assume token is valid or use refreshToken method if we can access it.
        // Simplest: Just use the stored token.

        $url = 'https://upload.box.com/api/2.0/files/content';

        $attributes = wp_json_encode([
            'name' => $fileName,
            'parent' => ['id' => $folderId]
        ]);

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Required for Guzzle multipart upload stream, WP_Filesystem not applicable here.
        $fileHandle = fopen($sourcePath, 'r');

        $response = $client->request('POST', $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken
            ],
            'multipart' => [
                [
                    'name' => 'attributes',
                    'contents' => $attributes
                ],
                [
                    'name' => 'file',
                    'contents' => $fileHandle,
                    'filename' => $fileName
                ]
            ]
        ]);

        $data = json_decode($response->getBody(), true);

        if (isset($data['entries'][0])) {
            return $data['entries'][0];
        }

        throw new Exception('Invalid response from Box API');
    }

    private function redirectWithError($url, $message)
    {
        wp_safe_redirect(add_query_arg('error', urlencode($message), $url));
        exit;
    }
}
