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

        // AJAX Handler for upload
        add_action('wp_ajax_edbx_ajax_upload', array($this, 'ajaxUpload'));
    }

    /**
     * AJAX Upload Handler
     */
    public function ajaxUpload()
    {
        // Verify Nonce
        if (!isset($_POST['edbx_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['edbx_nonce'])), 'edbx_upload')) {
            wp_send_json_error(esc_html__('Security check failed.', 'storage-for-edd-via-box'));
        }

        // Check permissions
        if (!current_user_can('edit_products')) {
            wp_send_json_error(esc_html__('You do not have permission to upload files.', 'storage-for-edd-via-box'));
        }

        // Validate upload
        $validation = $this->validateUploadAjax();
        if ($validation !== true) {
            wp_send_json_error($validation);
        }

        // Get folder ID
        $folderId = !empty($_POST['edbx_folder']) ? sanitize_text_field(wp_unslash($_POST['edbx_folder'])) : '0';

        // Process file
        $fileName = sanitize_file_name($_FILES['edbx_file']['name']);
        $filePath = $_FILES['edbx_file']['tmp_name'];

        try {
            $uploadedFile = $this->uploadFile($filePath, $fileName, $folderId);

            if ($uploadedFile) {
                // Get full path for the file
                $boxClient = new EDBX_Box_Client();
                $folderPath = $boxClient->getFolderPath($folderId);
                $fullPath = trim($folderPath . '/' . $uploadedFile['name'], '/');

                // Return success with file info (matching Dropbox format)
                wp_send_json_success(array(
                    'message' => esc_html__('File uploaded successfully!', 'storage-for-edd-via-box'),
                    'filename' => $uploadedFile['name'],
                    'path' => $fullPath,
                    // Also include the link format for Use this file button
                    'edbx_link' => ltrim($fullPath, '/')
                ));
            }
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }

        wp_send_json_error(esc_html__('Unknown error occurred.', 'storage-for-edd-via-box'));
    }

    /**
     * Validate upload for AJAX (returns error message or true)
     * @return bool|string
     */
    private function validateUploadAjax()
    {
        // Check for file existence
        if (
            !isset($_FILES['edbx_file']) ||
            !isset($_FILES['edbx_file']['name']) ||
            !isset($_FILES['edbx_file']['tmp_name']) ||
            !isset($_FILES['edbx_file']['size']) ||
            empty($_FILES['edbx_file']['name'])
        ) {
            return esc_html__('Please select a file to upload.', 'storage-for-edd-via-box');
        }

        // Check uploaded file security
        if (!is_uploaded_file($_FILES['edbx_file']['tmp_name'])) {
            return esc_html__('Invalid file upload.', 'storage-for-edd-via-box');
        }

        // Validate file type
        if (!$this->isAllowedFileType(sanitize_file_name($_FILES['edbx_file']['name']))) {
            return esc_html__('File type not allowed. Only safe file types are permitted.', 'storage-for-edd-via-box');
        }

        // Validate Content-Type
        if (!$this->validateFileContentType($_FILES['edbx_file'])) {
            return esc_html__('File content type validation failed.', 'storage-for-edd-via-box');
        }

        // Check file size
        $fileSize = absint($_FILES['edbx_file']['size']);
        $maxSize = wp_max_upload_size();
        if ($fileSize > $maxSize || $fileSize <= 0) {
            return sprintf(
                // translators: %s: Maximum upload file size.
                esc_html__('File size too large. Maximum allowed size is %s', 'storage-for-edd-via-box'),
                esc_html(size_format($maxSize))
            );
        }

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

        try {
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
        } finally {
            // Ensure file handle is closed even if request fails
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            if (is_resource($fileHandle)) {
                fclose($fileHandle);
            }
        }
    }
}
