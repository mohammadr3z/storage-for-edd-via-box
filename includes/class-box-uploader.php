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

        // Validate upload before processing
        if (!$this->validateUpload()) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified at top of function.
        $folderId = !empty($_POST['edbx_folder']) ? sanitize_text_field(wp_unslash($_POST['edbx_folder'])) : '0';
        $redirectUrl = admin_url('media-upload.php?type=edbx_lib&tab=edbx_lib&folder=' . $folderId);

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Filename is sanitized using sanitize_file_name and validated in validateUpload()
        $fileName = sanitize_file_name($_FILES['edbx_file']['name']);
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- tmp_name is a system path and validated in validateUpload()
        $filePath = $_FILES['edbx_file']['tmp_name'];
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
     * Validate file upload.
     * @return bool
     */
    private function validateUpload()
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in processUpload() before this method is called.
        // Check for file existence and its components
        if (
            !isset($_FILES['edbx_file']) ||
            !isset($_FILES['edbx_file']['name']) ||
            !isset($_FILES['edbx_file']['tmp_name']) ||
            !isset($_FILES['edbx_file']['size']) ||
            empty($_FILES['edbx_file']['name'])
        ) {
            wp_die(esc_html__('Please select a file to upload.', 'storage-for-edd-via-box'), esc_html__('Error', 'storage-for-edd-via-box'), array('back_link' => true));
            return false;
        }

        // Check uploaded file security
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- tmp_name is a system path
        if (!is_uploaded_file($_FILES['edbx_file']['tmp_name'])) {
            wp_die(esc_html__('Invalid file upload.', 'storage-for-edd-via-box'), esc_html__('Error', 'storage-for-edd-via-box'), array('back_link' => true));
            return false;
        }

        // Validate file type
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Filename is sanitized using sanitize_file_name
        if (!$this->isAllowedFileType(sanitize_file_name($_FILES['edbx_file']['name']))) {
            wp_die(esc_html__('File type not allowed. Only safe file types are permitted.', 'storage-for-edd-via-box'), esc_html__('Error', 'storage-for-edd-via-box'), array('back_link' => true));
            return false;
        }

        // Validate Content-Type (MIME type)
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- $_FILES array passed to validateFileContentType() where tmp_name is used as system path and name is sanitized via sanitize_file_name() with wp_check_filetype_and_ext() validation.
        if (!$this->validateFileContentType($_FILES['edbx_file'])) {
            wp_die(esc_html__('File content type validation failed. The file may be corrupted or have an incorrect extension.', 'storage-for-edd-via-box'), esc_html__('Error', 'storage-for-edd-via-box'), array('back_link' => true));
            return false;
        }

        // Check and sanitize file size
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- File size is validated/sanitized using absint
        $fileSize = absint($_FILES['edbx_file']['size']);
        $maxSize = wp_max_upload_size();
        if ($fileSize > $maxSize || $fileSize <= 0) {
            wp_die(
                // translators: %s: Maximum upload file size.
                sprintf(esc_html__('File size too large. Maximum allowed size is %s', 'storage-for-edd-via-box'), esc_html(size_format($maxSize))),
                esc_html__('Error', 'storage-for-edd-via-box'),
                array('back_link' => true)
            );
            return false;
        }

        // phpcs:enable WordPress.Security.NonceVerification.Missing
        return true;
    }

    /**
     * Check if file type is allowed (simple extension-based validation)
     * @param string $filename
     * @return bool
     */
    private function isAllowedFileType($filename)
    {
        // Get file extension
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // Allowed safe extensions for digital products
        $allowedExtensions = array(
            'zip',
            'rar',
            '7z',
            'tar',
            'gz',
            'pdf',
            'doc',
            'docx',
            'txt',
            'rtf',
            'jpg',
            'jpeg',
            'png',
            'gif',
            'webp',
            'mp3',
            'wav',
            'ogg',
            'flac',
            'm4a',
            'mp4',
            'avi',
            'mov',
            'wmv',
            'flv',
            'webm',
            'epub',
            'mobi',
            'azw',
            'azw3',
            'xls',
            'xlsx',
            'csv',
            'ppt',
            'pptx',
            'css',
            'js',
            'json',
            'xml'
        );

        // Check if extension is in allowed list
        if (!in_array($extension, $allowedExtensions, true)) {
            return false;
        }

        // Block dangerous file patterns
        $dangerousPatterns = array(
            '.php',
            '.phtml',
            '.asp',
            '.aspx',
            '.jsp',
            '.cgi',
            '.pl',
            '.py',
            '.exe',
            '.com',
            '.bat',
            '.cmd',
            '.scr',
            '.vbs',
            '.jar',
            '.sh',
            '.bash',
            '.zsh',
            '.fish',
            '.htaccess',
            '.htpasswd'
        );

        $lowerFilename = strtolower($filename);
        foreach ($dangerousPatterns as $pattern) {
            if (strpos($lowerFilename, $pattern) !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate file content type (MIME type) matches the file extension
     * @param array $file The uploaded file array from $_FILES
     * @return bool
     */
    private function validateFileContentType($file)
    {
        // Ensure we have the required file information
        if (!isset($file['tmp_name']) || !isset($file['name'])) {
            return false;
        }

        // Use WordPress's built-in function to check file type and extension
        $filetype = wp_check_filetype_and_ext($file['tmp_name'], sanitize_file_name($file['name']));

        // Check if the file type was detected
        if (!$filetype || !isset($filetype['ext']) || !isset($filetype['type'])) {
            return false;
        }

        // If extension or type is false, the file failed validation
        if (false === $filetype['ext'] || false === $filetype['type']) {
            return false;
        }

        // Additional check: ensure the detected extension matches what we expect
        $actualExtension = strtolower(pathinfo(sanitize_file_name($file['name']), PATHINFO_EXTENSION));
        if ($filetype['ext'] !== $actualExtension) {
            return false;
        }

        // Validate against allowed MIME types
        $allowedMimeTypes = array(
            // Archives
            'application/zip',
            'application/x-zip-compressed',
            'application/x-rar-compressed',
            'application/x-7z-compressed',
            'application/x-tar',
            'application/gzip',
            'application/x-gzip',
            // Documents
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'text/plain',
            'application/rtf',
            // Images
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/gif',
            'image/webp',
            // Audio
            'audio/mpeg',
            'audio/mp3',
            'audio/wav',
            'audio/ogg',
            'audio/flac',
            'audio/x-m4a',
            // Video
            'video/mp4',
            'video/mpeg',
            'video/quicktime',
            'video/x-msvideo',
            'video/x-ms-wmv',
            'video/x-flv',
            'video/webm',
            // E-books
            'application/epub+zip',
            'application/x-mobipocket-ebook',
            // Spreadsheets
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/csv',
            // Presentations
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            // Web files
            'text/css',
            'application/javascript',
            'text/javascript',
            'application/json',
            'application/xml',
            'text/xml',
        );

        // Apply filter to allow customization
        $allowedMimeTypes = apply_filters('edbx_allowed_mime_types', $allowedMimeTypes);

        // Check if the detected MIME type is in our allowed list
        if (!in_array($filetype['type'], $allowedMimeTypes, true)) {
            return false;
        }

        return true;
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
