<?php
/**
 * Session Management Class
 */
class AightBot_Session_Manager {
    
    public function __construct() {
        $this->init_hooks();
    }
    
    private function init_hooks() {
        add_action('aightbot_cleanup_sessions', [$this, 'cleanup_old_sessions']);
        add_action('wp_ajax_aightbot_new_session', [$this, 'ajax_new_session']);
        add_action('wp_ajax_nopriv_aightbot_new_session', [$this, 'ajax_new_session']);
    }
    
    /**
     * Generate new session ID
     * 
     * @return string Session ID
     */
    public function generate_session_id() {
        return bin2hex(random_bytes(32));
    }
    
    /**
     * AJAX handler for creating new session
     */
    public function ajax_new_session() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'aightbot_chat_nonce')) {
            wp_send_json_error(__('Security check failed', 'aightbot'));
        }
        
        $session_id = $this->generate_session_id();
        
        wp_send_json_success([
            'session_id' => $session_id
        ]);
    }
    
    /**
     * Get session by ID
     * 
     * @param string $session_id Session ID
     * @return object|null Session data
     */
    public function get_session($session_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'aightbot_sessions';
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE session_id = %s",
            $session_id
        ));
    }
    
    /**
     * Delete session
     * 
     * @param string $session_id Session ID
     * @return bool Success
     */
    public function delete_session($session_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'aightbot_sessions';
        
        return $wpdb->delete(
            $table,
            ['session_id' => $session_id],
            ['%s']
        ) !== false;
    }
    
    /**
     * Clean up old sessions (older than 1 hour)
     * Sessions are browser-session based and should be cleaned up aggressively
     */
    public function cleanup_old_sessions() {
        global $wpdb;
        $table = $wpdb->prefix . 'aightbot_sessions';
        
        $hours_to_keep = apply_filters('aightbot_session_retention_hours', 1);
        
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $table WHERE last_active < DATE_SUB(NOW(), INTERVAL %d HOUR)",
            $hours_to_keep
        ));
    }
    
    /**
     * Get user's sessions
     * 
     * @param int $user_id User ID (0 for current user)
     * @param int $limit Number of sessions to retrieve
     * @return array Sessions
     */
    public function get_user_sessions($user_id = 0, $limit = 10) {
        global $wpdb;
        $table = $wpdb->prefix . 'aightbot_sessions';
        
        if ($user_id === 0) {
            $user_id = get_current_user_id();
        }
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d ORDER BY last_active DESC LIMIT %d",
            $user_id,
            $limit
        ));
    }
    
    /**
     * Get session statistics
     * 
     * @return array Statistics
     */
    public function get_statistics() {
        global $wpdb;
        $table = $wpdb->prefix . 'aightbot_sessions';
        
        $stats = [
            'total_sessions' => 0,
            'active_today' => 0,
            'active_this_week' => 0,
            'total_messages' => 0
        ];
        
        $stats['total_sessions'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
        
        $stats['active_today'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM $table WHERE DATE(last_active) = CURDATE()"
        );
        
        $stats['active_this_week'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM $table WHERE last_active >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
        
        // Count total messages from all sessions
        $sessions = $wpdb->get_col("SELECT history FROM $table");
        foreach ($sessions as $history_json) {
            $history = json_decode($history_json, true);
            if (is_array($history)) {
                $stats['total_messages'] += count($history);
            }
        }
        
        return $stats;
    }
}
