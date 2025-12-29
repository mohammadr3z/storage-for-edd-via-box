<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Box Downloader
 * 
 * Generates download links for EDD downloads stored in Box.
 */
class EDBX_Box_Downloader
{
    private $client;
    private $config;

    public function __construct()
    {
        $this->config = new EDBX_Box_Config();
        $this->client = new EDBX_Box_Client();

        // Hook into EDD file download
        add_filter('edd_requested_file', array($this, 'generateUrl'), 10, 3);
    }

    /**
     * Generate a Box download URL.
     * 
     * @param string $file The original file URL
     * @param array $downloadFiles Array of download files
     * @param string $fileKey The key of the current file
     * @return string The download URL or original file
     */
    public function generateUrl($file, $downloadFiles, $fileKey)
    {
        if (empty($downloadFiles[$fileKey])) {
            return $file;
        }

        $fileData = $downloadFiles[$fileKey];
        $filename = $fileData['file'];

        // Check if this is a Box file
        $urlPrefix = $this->config->getUrlPrefix(); // 'edd-box://' (updated in config)
        if (strpos($filename, $urlPrefix) !== 0) {
            return $file;
        }

        // Extract the Box file Path from the URL
        // Format: edd-box://Folder/File.ext
        $filePath = substr($filename, strlen($urlPrefix));

        if (!$this->config->isConnected()) {
            $this->config->debug('Box not connected for download: ' . $filePath);
            return $file;
        }

        try {
            // Resolve Path to ID
            // $filePath is the full string path
            $fileId = $this->client->getFileIdByPath($filePath);

            if (!$fileId) {
                $this->config->debug('Could not resolve path to ID: ' . $filePath);
                return $file;
            }

            // Get download URL from Box
            $downloadUrl = $this->client->getDownloadUrl($fileId);

            if ($downloadUrl) {
                return $downloadUrl;
            }

            $this->config->debug('Failed to get download URL for: ' . $fileId);
            return $file;
        } catch (Exception $e) {
            $this->config->debug('Error generating download URL: ' . $e->getMessage());
            return $file;
        }
    }
}
