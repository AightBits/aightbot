<?php
/**
 * Installation and Activation Class
 */
class AightBot_Install {
    
    /**
     * Plugin activation
     */
    public static function activate() {
        // Check WordPress version
        if (version_compare(get_bloginfo('version'), '5.6', '<')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(
                sprintf(
                    __('AightBot requires WordPress version 5.6 or higher. You are running version %s. Please upgrade WordPress.', 'aightbot'),
                    get_bloginfo('version')
                ),
                __('Plugin Activation Error', 'aightbot'),
                ['back_link' => true]
            );
        }
        
        // Check PHP version
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(
                sprintf(
                    __('AightBot requires PHP version 7.4 or higher. You are running version %s. Please upgrade PHP.', 'aightbot'),
                    PHP_VERSION
                ),
                __('Plugin Activation Error', 'aightbot'),
                ['back_link' => true]
            );
        }
        
        // Set default options
        self::set_default_options();
        
        // Create database tables
        self::create_tables();
        
        // Schedule cleanup cron (hourly since sessions are browser-session based)
        if (!wp_next_scheduled('aightbot_cleanup_sessions')) {
            wp_schedule_event(time(), 'hourly', 'aightbot_cleanup_sessions');
        }
        
        // Schedule log cleanup cron
        if (!wp_next_scheduled('aightbot_cleanup_logs')) {
            wp_schedule_event(time(), 'daily', 'aightbot_cleanup_logs');
        }
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     */
    public static function deactivate() {
        // Clear scheduled events
        wp_clear_scheduled_hook('aightbot_cleanup_sessions');
        wp_clear_scheduled_hook('aightbot_cleanup_logs');
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Note: We don't delete options or tables on deactivation
        // This preserves user settings if they reactivate
    }
    
    /**
     * Set default options
     */
    private static function set_default_options() {
        $default_settings = [
            'enabled' => 'no',
            'bot_name' => 'AightBot',
            'llm_url' => '',
            'api_key' => '',
            'model_name' => '',
            'system_prompt' => __('You are a helpful AI assistant.', 'aightbot'),
            'starter_message' => '',
            'sampler_overrides' => '',
            'disable_ssl_verify' => 'no',
            'rate_limit_requests' => 20,
            'rate_limit_window' => 300,
            'enable_logging' => 'no',
            'log_retention_days' => 30,
            'max_context_messages' => 40,
            'max_context_words' => 8000
        ];
        
        // Only add if doesn't exist (don't override existing settings)
        if (false === get_option(AIGHTBOT_OPTION_PREFIX . 'settings')) {
            add_option(AIGHTBOT_OPTION_PREFIX . 'settings', $default_settings);
        }
        
        // RAG settings
        $default_rag_settings = [
            'enable_rag' => 'no',
            'index_posts' => 'yes',
            'index_pages' => 'yes',
            'index_custom_types' => [],
            'content_depth' => 'full',
            'enable_chunking' => 'no',
            'chunk_size' => 500,
            'results_count' => 5,
            'min_relevance' => 0.3,
            'cite_sources' => 'yes',
            'only_indexed_content' => 'no',
            'auto_reindex' => 'no',
            'scheduled_reindex' => 'no',
            'reindex_frequency' => 'daily'
        ];
        
        if (false === get_option(AIGHTBOT_OPTION_PREFIX . 'rag_settings')) {
            add_option(AIGHTBOT_OPTION_PREFIX . 'rag_settings', $default_rag_settings);
        }
        
        // Store version
        add_option(AIGHTBOT_OPTION_PREFIX . 'version', AIGHTBOT_VERSION);
    }
    
    /**
     * Create database tables
     */
    private static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        // Sessions table
        $table_name = $wpdb->prefix . 'aightbot_sessions';
        
        // Only create if doesn't exist
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            $sql = "CREATE TABLE $table_name (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                session_id varchar(64) NOT NULL,
                user_id bigint(20) UNSIGNED DEFAULT 0,
                bot_name varchar(100) DEFAULT 'AightBot',
                history longtext,
                created_at datetime NOT NULL,
                last_active datetime NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY session_id (session_id),
                KEY user_id (user_id),
                KEY last_active (last_active)
            ) $charset_collate;";
            
            dbDelta($sql);
        }
        
        // Content index table for RAG
        $content_index_table = $wpdb->prefix . 'aightbot_content_index';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$content_index_table'") != $content_index_table) {
            $sql = "CREATE TABLE $content_index_table (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                post_id bigint(20) UNSIGNED NOT NULL,
                post_type varchar(20) NOT NULL,
                title varchar(255) NOT NULL,
                content longtext NOT NULL,
                url varchar(500) NOT NULL,
                indexed_at datetime NOT NULL,
                PRIMARY KEY (id),
                KEY post_id (post_id),
                KEY post_type (post_type),
                KEY indexed_at (indexed_at),
                FULLTEXT KEY content_search (title, content)
            ) $charset_collate;";
            
            dbDelta($sql);
        }
    }
    
    /**
     * Update routine for version changes
     */
    public static function maybe_update() {
        $current_version = get_option(AIGHTBOT_OPTION_PREFIX . 'version', '0.0.0');
        
        if (version_compare($current_version, AIGHTBOT_VERSION, '<')) {
            // Run update routines based on version
            self::run_updates($current_version);
            
            // Update version number
            update_option(AIGHTBOT_OPTION_PREFIX . 'version', AIGHTBOT_VERSION);
        }
    }
    
    /**
     * Run version-specific updates
     */
    private static function run_updates($from_version) {
        // Example: if updating from pre-1.0 to 1.0+
        // if (version_compare($from_version, '1.0.0', '<')) {
        //     self::update_to_1_0_0();
        // }
    }
    
    /**
     * Uninstall cleanup (called from uninstall.php)
     */
    public static function uninstall() {
        global $wpdb;
        
        // Delete options
        delete_option(AIGHTBOT_OPTION_PREFIX . 'settings');
        delete_option(AIGHTBOT_OPTION_PREFIX . 'rag_settings');
        delete_option(AIGHTBOT_OPTION_PREFIX . 'version');
        delete_option(AIGHTBOT_OPTION_PREFIX . 'last_indexed');
        
        // Drop tables
        $sessions_table = $wpdb->prefix . 'aightbot_sessions';
        $index_table = $wpdb->prefix . 'aightbot_content_index';
        $wpdb->query("DROP TABLE IF EXISTS $sessions_table");
        $wpdb->query("DROP TABLE IF EXISTS $index_table");
        
        // Clear scheduled events
        wp_clear_scheduled_hook('aightbot_cleanup_sessions');
        wp_clear_scheduled_hook('aightbot_cleanup_logs');
        wp_clear_scheduled_hook('aightbot_scheduled_reindex');
        
        // Clear any transients
        delete_transient(AIGHTBOT_OPTION_PREFIX . 'test_connection');
    }
}
