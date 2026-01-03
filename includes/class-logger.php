<?php
/**
 * Session Logger
 * 
 * Handles file-based logging of conversation sessions
 */

if (!defined('ABSPATH')) {
    exit;
}

class AightBot_Logger {
    
    private $log_dir;
    private $settings;
    
    public function __construct() {
        $upload_dir = wp_upload_dir();
        $this->log_dir = $upload_dir['basedir'] . '/aightbot-logs';
        $this->settings = get_option(AIGHTBOT_OPTION_PREFIX . 'settings', []);
        
        // Initialize logging
        $this->init_log_directory();
        
        // Schedule cleanup cron
        add_action('aightbot_cleanup_logs', [$this, 'cleanup_old_logs']);
    }
    
    /**
     * Initialize log directory with security
     */
    private function init_log_directory() {
        if (!file_exists($this->log_dir)) {
            wp_mkdir_p($this->log_dir);
        }
        
        // Create .htaccess to deny access
        $htaccess_file = $this->log_dir . '/.htaccess';
        if (!file_exists($htaccess_file)) {
            $htaccess_content = "# Deny all access to log files\n";
            $htaccess_content .= "Order deny,allow\n";
            $htaccess_content .= "Deny from all\n";
            file_put_contents($htaccess_file, $htaccess_content);
        }
        
        // Create index.php to prevent directory listing
        $index_file = $this->log_dir . '/index.php';
        if (!file_exists($index_file)) {
            file_put_contents($index_file, '<?php // Silence is golden');
        }
    }
    
    /**
     * Get client IP address
     * 
     * @return string IP address
     */
    private function get_client_ip() {
        $ip = '';
        
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // Get first IP if multiple are present
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($ips[0]);
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
        // Validate IP
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
        
        return 'unknown';
    }
    
    /**
     * Log a message to session file
     * 
     * @param string $session_id Session ID
     * @param string $role Message role (user/assistant/system)
     * @param string $content Message content
     * @param string $ip_address Optional IP address (will auto-detect if not provided)
     */
    public function log_message($session_id, $role, $content, $ip_address = null) {
        // Check if logging is enabled
        if (!isset($this->settings['enable_logging']) || $this->settings['enable_logging'] !== 'yes') {
            return;
        }
        
        // Validate session ID
        if (empty($session_id)) {
            error_log('AightBot Logger: Empty session ID provided');
            return;
        }
        
        // Ensure log directory exists
        if (!is_dir($this->log_dir)) {
            error_log('AightBot Logger: Log directory missing, recreating');
            $this->init_log_directory();
            if (!is_dir($this->log_dir)) {
                error_log('AightBot Logger: Failed to create log directory');
                return;
            }
        }
        
        $log_file = $this->log_dir . '/session_' . sanitize_file_name($session_id) . '.log';
        
        // CRITICAL SECURITY FIX: Check log file size before writing
        // Prevent disk exhaustion from unbounded log growth
        $max_log_size = 10 * 1024 * 1024; // 10MB per session log
        
        if (file_exists($log_file) && filesize($log_file) >= $max_log_size) {
            // Log file too large, rotate it
            $rotated_file = $log_file . '.old';
            if (file_exists($rotated_file)) {
                unlink($rotated_file); // Delete old rotation
            }
            rename($log_file, $rotated_file);
        }
        
        try {
            // Get current user info
            $user_info = is_user_logged_in() ? wp_get_current_user()->user_email : 'guest';
            
            // Get IP address
            if ($ip_address === null) {
                $ip_address = $this->get_client_ip();
            }
            
            // Get timestamp
            $timestamp = current_time('Y-m-d H:i:s');
            
            // Build log entry with ALL formatting
            $log_entry = '';
            
            // If file doesn't exist, add session header
            if (!file_exists($log_file)) {
                $log_entry .= str_repeat('=', 80) . "\n";
                $log_entry .= "SESSION: {$session_id}\n";
                $log_entry .= "STARTED: {$timestamp}\n";
                $log_entry .= "USER: {$user_info}\n";
                $log_entry .= "IP: {$ip_address}\n";
                $log_entry .= "BOT: " . (isset($this->settings['bot_name']) ? $this->settings['bot_name'] : 'AightBot') . "\n";
                $log_entry .= str_repeat('=', 80) . "\n\n";
            }
            
            // Add message with complete formatting
            $log_entry .= "[" . $timestamp . "] " . strtoupper($role) . ":\n";
            $log_entry .= $content . "\n";
            $log_entry .= str_repeat('-', 80) . "\n\n";
            
            // Write to file
            $bytes_written = file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
            
            if ($bytes_written === false) {
                error_log("AightBot Logger: Failed to write to {$log_file}");
            } else {
                error_log("AightBot Logger: Wrote {$bytes_written} bytes to {$log_file} for {$role} message");
            }
            
        } catch (Exception $e) {
            error_log('AightBot Logger: Exception - ' . $e->getMessage());
        }
    }
    
    /**
     * Log context truncation event
     * 
     * @param string $session_id Session ID
     * @param int $original_count Original message count
     * @param int $new_count New message count after truncation
     * @param int $original_words Original word count
     * @param int $new_words New word count
     * @param int $original_tokens Estimated original tokens
     * @param int $new_tokens Estimated new tokens
     */
    public function log_truncation($session_id, $original_count, $new_count, $original_words, $new_words, $original_tokens, $new_tokens) {
        if (!isset($this->settings['enable_logging']) || $this->settings['enable_logging'] !== 'yes') {
            return;
        }
        
        $log_file = $this->log_dir . '/session_' . sanitize_file_name($session_id) . '.log';
        $timestamp = current_time('Y-m-d H:i:s');
        
        $log_entry = "[{$timestamp}] CONTEXT TRUNCATED:\n";
        $log_entry .= "  Messages: {$original_count} → {$new_count}\n";
        $log_entry .= "  Words: {$original_words} → {$new_words}\n";
        $log_entry .= "  Estimated Tokens: {$original_tokens} → {$new_tokens} (words × 1.3)\n";
        $log_entry .= str_repeat('-', 80) . "\n\n";
        
        file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Cleanup old log files
     */
    public function cleanup_old_logs() {
        $retention_days = isset($this->settings['log_retention_days']) ? absint($this->settings['log_retention_days']) : 30;
        
        // If set to 0, never delete
        if ($retention_days === 0) {
            return;
        }
        
        $cutoff_time = time() - ($retention_days * DAY_IN_SECONDS);
        
        $log_files = glob($this->log_dir . '/session_*.log');
        
        if (!$log_files) {
            return;
        }
        
        $deleted_count = 0;
        foreach ($log_files as $log_file) {
            if (filemtime($log_file) < $cutoff_time) {
                unlink($log_file);
                $deleted_count++;
            }
        }
        
        // Log cleanup event
        if ($deleted_count > 0) {
            $cleanup_log = $this->log_dir . '/cleanup.log';
            $timestamp = current_time('Y-m-d H:i:s');
            $entry = "[{$timestamp}] Deleted {$deleted_count} log file(s) older than {$retention_days} days\n";
            file_put_contents($cleanup_log, $entry, FILE_APPEND | LOCK_EX);
        }
    }
    
    /**
     * Get log file path for a session
     * 
     * @param string $session_id Session ID
     * @return string Log file path
     */
    public function get_log_file($session_id) {
        return $this->log_dir . '/session_' . sanitize_file_name($session_id) . '.log';
    }
}
