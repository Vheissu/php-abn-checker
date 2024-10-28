<?php

/**
 * ABN Checker API
 * 
 * @package ABNChecker
 * @version 1.0.0
 */

class ABNCheckerAPI
{
    private const ABR_URL = 'https://abr.business.gov.au/ABN/View';
    private $config;

    /**
     * Constructor with configuration options
     * 
     * @param array $config Configuration options for the checker
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'referer' => 'https://abr.business.gov.au/',
            'proxy' => null,
            'proxy_type' => CURLPROXY_HTTP,
            'proxy_auth' => null,
            'timeout' => 30,
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'verify_ssl' => true,
            'cache_duration' => 86400, // 24 hours in seconds
        ], $config);
    }

    /**
     * Check ABN against ABR
     * 
     * @param string $abn The ABN to check
     * @return array|false Returns array of company details if valid, false if invalid
     */
    public function checkABN($abn)
    {
        // Remove any spaces or special characters
        $abn = preg_replace('/[^0-9]/', '', $abn);

        // Validate ABN format
        if (!$this->isValidABNFormat($abn)) {
            return false;
        }

        // Check cache first
        $cachedResult = $this->getCachedResult($abn);
        if ($cachedResult !== null) {
            return $cachedResult;
        }

        // Get the page content
        $content = $this->fetchABNPage($abn);
        if (!$content) {
            return false;
        }

        // Check if ABN is invalid (error message exists)
        if (strpos($content, 'Invalid ABN/ACN') !== false) {
            return false;
        }

        // Parse and return the company details
        return $this->parseCompanyDetails($content, $abn);
    }

    /**
     * Validates ABN format
     */
    private function isValidABNFormat($abn)
    {
        return strlen($abn) === 11 && is_numeric($abn);
    }

    /**
     * Fetches the ABN lookup page
     */
    private function fetchABNPage($abn)
    {
        $url = self::ABR_URL . '?abn=' . urlencode($abn);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $this->config['timeout'],
            CURLOPT_SSL_VERIFYPEER => $this->config['verify_ssl'],
            CURLOPT_HTTPHEADER => [
                'Referer: ' . $this->config['referer'],
                'User-Agent: ' . $this->config['user_agent'],
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9'
            ]
        ]);

        // Configure proxy if set
        if (!empty($this->config['proxy'])) {
            curl_setopt($ch, CURLOPT_PROXY, $this->config['proxy']);
            curl_setopt($ch, CURLOPT_PROXYTYPE, $this->config['proxy_type']);
            if (!empty($this->config['proxy_auth'])) {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $this->config['proxy_auth']);
            }
        }

        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log("ABN Checker cURL Error: $error");
            return false;
        }

        return ($httpCode === 200) ? $content : false;
    }

    /**
     * Parses company details from the HTML content
     */
    private function parseCompanyDetails($content, $abn)
    {
        // Create a DOM parser
        $dom = new \DOMDocument();
        @$dom->loadHTML($content);
        $xpath = new \DOMXPath($dom);

        // Build structured response
        $details = [
            'abn' => $abn,
            'entity_name' => $this->extractContent($xpath, "//span[@itemprop='legalName']"),
            'abn_status' => [
                'status' => null,
                'date' => null
            ],
            'entity_type' => [
                'type' => null,
                'code' => null
            ],
            'gst_status' => [
                'registered' => false,
                'date' => null
            ],
            'location' => [
                'state' => null,
                'postcode' => null
            ],
            'last_updated' => date('c')
        ];

        // Parse ABN status
        $abnStatus = $this->extractContent($xpath, "//tr[th[contains(text(),'ABN status')]]/td");
        if ($abnStatus) {
            // Clean up the status text - decode HTML entities and aggressively clean whitespace
            $abnStatus = html_entity_decode($abnStatus, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $abnStatus = preg_replace('/[\s\x{00A0}]+/u', ' ', $abnStatus); // Handle non-breaking spaces and all whitespace
            $abnStatus = trim($abnStatus);
            
            // Get status (Active, Cancelled etc)
            preg_match('/^([A-Za-z]+)/', $abnStatus, $statusMatch);
            $status = $statusMatch[1] ?? null;
            
            // Get date - looking for format like "Active from 23 Oct 2013"
            if (preg_match('/(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})/', $abnStatus, $dateMatch)) {
                // Clean each component
                $day = trim($dateMatch[1]);
                $month = trim($dateMatch[2]);
                $year = trim($dateMatch[3]);
                
                // Ensure day is padded
                $day = str_pad($day, 2, '0', STR_PAD_LEFT);
                
                // Construct clean date string
                $dateString = "{$day} {$month} {$year}";
                
                $details['abn_status'] = [
                    'status' => $status,
                    'date' => date('Y-m-d', strtotime($dateString))
                ];
            } else {
                $details['abn_status'] = [
                    'status' => $status,
                    'date' => null
                ];
            }
        }

        // Parse Entity Type
        $entityType = $this->extractContent($xpath, "//tr[th[contains(text(),'Entity type')]]/td/a");
        if (preg_match('/EntityTypeDescription\?Id=(\d+)/', $this->extractHref($xpath, "//tr[th[contains(text(),'Entity type')]]/td/a"), $matches)) {
            $details['entity_type'] = [
                'type' => trim($entityType),
                'code' => $matches[1]
            ];
        }

        // Parse GST Status
        $gstStatus = $this->extractContent($xpath, "//tr[th[contains(text(),'Goods & Services Tax')]]/td");
        if (preg_match('/Registered\s+from\s+(.+)/', $gstStatus, $matches)) {
            $details['gst_status'] = [
                'registered' => true,
                'date' => date('Y-m-d', strtotime($matches[1]))
            ];
        }

        // Parse Location
        $location = $this->extractContent($xpath, "//span[@itemprop='addressLocality']");
        if (preg_match('/([A-Z]{2,3})\s+(\d{4})/', $location, $matches)) {
            $details['location'] = [
                'state' => $matches[1],
                'postcode' => $matches[2]
            ];
        }

        return array_filter($details, function ($value) {
            return $value !== null && $value !== '';
        });
    }

    /**
     * Extracts content using XPath
     */
    private function extractContent($xpath, $query)
    {
        $nodes = $xpath->query($query);
        if ($nodes->length > 0) {
            return trim($nodes->item(0)->textContent);
        }
        return null;
    }

    /**
     * Extracts href attribute using XPath
     */
    private function extractHref($xpath, $query)
    {
        $nodes = $xpath->query($query);
        if ($nodes->length > 0) {
            return $nodes->item(0)->getAttribute('href');
        }
        return null;
    }

    /**
     * Gets cached result
     */
    private function getCachedResult($abn)
    {
        $cacheFile = $this->getCacheFilePath($abn);
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $this->config['cache_duration']) {
            return json_decode(file_get_contents($cacheFile), true);
        }
        return null;
    }

    /**
     * Caches result
     */
    private function cacheResult($abn, $data)
    {
        $cacheFile = $this->getCacheFilePath($abn);
        $cacheDir = dirname($cacheFile);

        if (!is_dir($cacheDir)) {
            wp_mkdir_p($cacheDir);
        }

        file_put_contents($cacheFile, json_encode($data));
    }

    /**
     * Gets cache file path
     */
    private function getCacheFilePath($abn)
    {
        $upload_dir = wp_upload_dir();
        return $upload_dir['basedir'] . '/abn-cache/abn_' . $abn . '.json';
    }
}
