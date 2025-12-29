<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Box API Client
 */
class EDBX_Box_Client
{
    private $config;
    private $client;

    public function __construct()
    {
        $this->config = new EDBX_Box_Config();
    }

    /**
     * Get Guzzle Client
     */
    private function getClient()
    {
        if (!$this->client) {
            $this->client = new \GuzzleHttp\Client([
                'base_uri' => 'https://api.box.com/2.0/',
                'timeout'  => 30,
            ]);
        }
        return $this->client;
    }

    /**
     * Generate OAuth Authorization URL
     */
    public function getAuthorizationUrl($redirect_uri)
    {
        $params = array(
            'response_type' => 'code',
            'client_id'     => $this->config->getClientId(),
            'redirect_uri'  => $redirect_uri,
            // Explicitly request read/write access to avoid 403 errors
            'scope' => 'root_readwrite',
        );

        return EDBX_Box_Config::AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Exchange Authorization Code for Tokens
     */
    public function exchangeCodeForTokens($code, $redirect_uri)
    {
        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->post(EDBX_Box_Config::TOKEN_URL, [
                'form_params' => [
                    'grant_type'    => 'authorization_code',
                    'code'          => $code,
                    'client_id'     => $this->config->getClientId(),
                    'client_secret' => $this->config->getClientSecret(),
                    'redirect_uri'  => $redirect_uri,
                ]
            ]);

            $data = json_decode($response->getBody(), true);

            if (isset($data['access_token'])) {
                $this->config->saveTokens(
                    $data['access_token'],
                    $data['refresh_token'],
                    $data['expires_in']
                );
                return $data;
            }
        } catch (Exception $e) {
            $this->config->debug('Token exchange failed: ' . $e->getMessage());
        }

        return false;
    }

    /**
     * Refresh Access Token
     */
    public function refreshToken()
    {
        $refresh_token = $this->config->getRefreshToken();
        if (!$refresh_token) {
            return false;
        }

        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->post(EDBX_Box_Config::TOKEN_URL, [
                'form_params' => [
                    'grant_type'    => 'refresh_token',
                    'refresh_token' => $refresh_token,
                    'client_id'     => $this->config->getClientId(),
                    'client_secret' => $this->config->getClientSecret(),
                ]
            ]);

            $data = json_decode($response->getBody(), true);

            if (isset($data['access_token'])) {
                $this->config->saveTokens(
                    $data['access_token'],
                    $data['refresh_token'],
                    $data['expires_in']
                );
                return $data['access_token'];
            }
        } catch (Exception $e) {
            $this->config->debug('Token refresh failed: ' . $e->getMessage());
            $this->config->clearTokens(); // Tokens are likely invalid
        }

        return false;
    }

    /**
     * Make Authenticated Request
     */
    private function request($method, $endpoint, $options = [])
    {
        $accessToken = $this->config->getAccessToken();

        // Check expiry
        if ($this->config->getTokenExpires() < time()) {
            $accessToken = $this->refreshToken();
            if (!$accessToken) {
                throw new Exception('Unable to refresh access token');
            }
        }

        $defaultOptions = [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
            ]
        ];

        // Merge headers if provided
        if (isset($options['headers'])) {
            $defaultOptions['headers'] = array_merge($defaultOptions['headers'], $options['headers']);
            unset($options['headers']);
        }

        $options = array_merge_recursive($defaultOptions, $options);

        try {
            return $this->getClient()->request($method, $endpoint, $options);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            // Handle 401 Unauthorized - Retry once with refresh
            if ($e->getResponse()->getStatusCode() === 401) {
                $accessToken = $this->refreshToken();
                if ($accessToken) {
                    $options['headers']['Authorization'] = 'Bearer ' . $accessToken;
                    return $this->getClient()->request($method, $endpoint, $options);
                }
            }
            throw $e;
        }
    }

    /**
     * List Files in Folder
     * Box root folder ID is '0'
     */
    public function listFiles($folderId = '0')
    {
        if (empty($folderId)) {
            $folderId = '0';
        }

        try {
            // Request path_collection to build full paths
            $response = $this->request('GET', "folders/{$folderId}/items", [
                'query' => [
                    'limit' => 1000, // Increased limit to find files more reliably
                    'fields' => 'id,type,name,size,modified_at,path_collection',
                    'sort' => 'name',
                    'direction' => 'ASC'
                ]
            ]);

            $data = json_decode($response->getBody(), true);
            $items = array();

            if (isset($data['entries'])) {
                foreach ($data['entries'] as $entry) {
                    // Build path string
                    $pathString = '';
                    if (isset($entry['path_collection']) && isset($entry['path_collection']['entries'])) {
                        foreach ($entry['path_collection']['entries'] as $ancestor) {
                            if ($ancestor['id'] !== '0') { // Skip root "All Files"
                                $pathString .= $ancestor['name'] . '/';
                            }
                        }
                    }
                    $pathString .= $entry['name'];

                    $items[] = array(
                        'id' => $entry['id'],
                        'name' => $entry['name'],
                        'is_folder' => ($entry['type'] === 'folder'),
                        'size' => isset($entry['size']) ? $entry['size'] : 0,
                        'modified' => isset($entry['modified_at']) ? $entry['modified_at'] : '',
                        'path' => $entry['id'], // Keep ID for navigation param
                        'full_path' => $pathString // Human readable path for display/storage
                    );
                }
            }

            return $items;
        } catch (Exception $e) {
            $this->config->debug('List files failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Resolve a path string to a File ID
     * 
     * @param string $path Full path e.g. "Folder/Subfolder/File.txt"
     * @return string|false File ID or false
     */
    public function getFileIdByPath($path)
    {
        $path = trim($path, '/');
        if (empty($path)) {
            return false;
        }

        // Check cache first
        $cacheKey = 'edbx_path_' . md5($path);
        $cachedId = get_transient($cacheKey);
        if ($cachedId) {
            return $cachedId;
        }

        $segments = explode('/', $path);
        $currentId = '0'; // Start at root
        $foundId = false;

        foreach ($segments as $index => $segment) {
            $isLast = ($index === count($segments) - 1);

            // We need to find $segment in $currentId
            // We can't easily "search" inside a folder specifically by name without potential ambiguity using search API.
            // But we can list items. listing is expensive for deep paths.
            // Let's try listing. Cached per folder? 
            // We'll trust listFiles caching isn't implemented yet, but we should probably cache folder contents transiently?
            // For now, raw requests.

            try {
                $response = $this->request('GET', "folders/{$currentId}/items", [
                    'query' => [
                        'limit' => 1000,
                        'fields' => 'id,name,type'
                    ]
                ]);
                $data = json_decode($response->getBody(), true);

                $foundInLevel = false;
                if (isset($data['entries'])) {
                    foreach ($data['entries'] as $entry) {
                        if ($entry['name'] === $segment) {
                            // Validate type if it's the last segment (should be file usually, but could be folder if we supported folder linking)
                            // The user links files.
                            $currentId = $entry['id'];
                            $foundInLevel = true;
                            if ($isLast) {
                                $foundId = $entry['id'];
                            }
                            break;
                        }
                    }
                }

                if (!$foundInLevel) {
                    return false; // Path broken
                }
            } catch (Exception $e) {
                $this->config->debug('Path resolution failed at segment ' . $segment . ': ' . $e->getMessage());
                return false;
            }
        }

        if ($foundId) {
            // Cache for 24 hours? 1 hour? Paths change if renamed.
            // 12 hours seems reasonable.
            set_transient($cacheKey, $foundId, 12 * HOUR_IN_SECONDS);
            return $foundId;
        }

        return false;
    }

    /**
     * Get Download URL for File
     * Uses Box API to get a direct download link (valid for 15 mins)
     */
    public function getDownloadUrl($fileId)
    {
        try {
            // Box provides a dedicated endpoint for content download redirects
            // or we can use 'files/{id}/content' which redirects to the actual content

            // For EDD, we need a URL we can redirect the user to.
            // Getting the download URL (Read-only session or similar) might be needed if simple link isn't enough.
            // Standard way: GET https://api.box.com/2.0/files/:file_id/content
            // This returns a 302 Redirect. We can capture that location or let EDD fetch it.
            // EDD usually redirects the user. So we should get the literal download URL.

            // NOTE: The standard /content endpoint requires Auth header. We can't give that to the user's browser easily WITHOUT proxying.
            // Box has "Shared Links" but they might be public.
            // Better approach for private files: Use the 'GET /files/:id/content' but we need to pass the Location header.

            // Let's check Guzzle "allow_redirects" => false to capture the Location header.

            $response = $this->request('GET', "files/{$fileId}/content", [
                'allow_redirects' => false
            ]);

            if ($response->getStatusCode() === 302 && $response->hasHeader('Location')) {
                return $response->getHeaderLine('Location');
            }
        } catch (Exception $e) {
            $this->config->debug('Get download URL failed: ' . $e->getMessage());
        }

        return false;
    }

    /**
     * Get details of a folder (for breadcrumbs mostly)
     */
    public function getFolderDetails($folderId)
    {
        if ($folderId == '0') {
            return ['id' => '0', 'name' => 'All Files', 'path_collection' => []];
        }

        try {
            $response = $this->request('GET', "folders/{$folderId}", [
                'query' => ['fields' => 'id,name,parent,path_collection']
            ]);
            return json_decode($response->getBody(), true);
        } catch (Exception $e) {
            return ['id' => $folderId, 'name' => 'Unknown'];
        }
    }

    /**
     * Get full path string for a folder ID
     * 
     * @param string $folderId
     * @return string
     */
    public function getFolderPath($folderId)
    {
        if ($folderId == '0' || empty($folderId)) {
            return '';
        }

        $details = $this->getFolderDetails($folderId);
        $pathString = '';

        if (isset($details['path_collection']) && isset($details['path_collection']['entries'])) {
            foreach ($details['path_collection']['entries'] as $ancestor) {
                if ($ancestor['id'] !== '0') {
                    $pathString .= $ancestor['name'] . '/';
                }
            }
        }

        if (isset($details['name'])) {
            $pathString .= $details['name'];
        }

        return $pathString;
    }
}
