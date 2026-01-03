<?php
/**
 * Content Indexer Class
 * 
 * Indexes public WordPress content for RAG functionality
 */

if (!defined('ABSPATH')) {
    exit;
}

class AightBot_Content_Indexer {
    
    private $settings;
    private $table_name;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'aightbot_content_index';
        $this->settings = get_option(AIGHTBOT_OPTION_PREFIX . 'rag_settings', []);
        
        // Validate table name matches expected pattern
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $this->table_name)) {
            error_log('AightBot Indexer: Invalid table name detected');
            $this->table_name = $wpdb->prefix . 'aightbot_content_index'; // Force correct name
        }
        
        $this->init_hooks();
    }
    
    private function init_hooks() {
        // AJAX handlers for admin
        add_action('wp_ajax_aightbot_index_content', [$this, 'ajax_index_content']);
        add_action('wp_ajax_aightbot_clear_index', [$this, 'ajax_clear_index']);
        add_action('wp_ajax_aightbot_get_index_status', [$this, 'ajax_get_index_status']);
        
        // Use transition_post_status to catch all post types including custom ones
        if (!empty($this->settings['auto_reindex'])) {
            add_action('transition_post_status', [$this, 'handle_post_transition'], 10, 3);
        }
        
        // Scheduled reindex
        add_action('aightbot_scheduled_reindex', [$this, 'index_all_content']);
    }
    
    /**
     * Handle post status transitions for auto-reindexing
     * 
     * @param string $new_status New post status
     * @param string $old_status Old post status
     * @param WP_Post $post Post object
     */
    public function handle_post_transition($new_status, $old_status, $post) {
        // Skip revisions and auto-saves
        if (wp_is_post_revision($post->ID) || wp_is_post_autosave($post->ID)) {
            return;
        }
        
        // Only reindex when post is published (either newly published or updated while published)
        if ($new_status === 'publish') {
            $this->reindex_single_post($post->ID, $post);
        }
        // If unpublished, remove from index
        elseif ($old_status === 'publish' && $new_status !== 'publish') {
            global $wpdb;
            $wpdb->delete($this->table_name, ['post_id' => $post->ID], ['%d']);
        }
    }
    
    /**
     * AJAX handler to index all content
     */
    public function ajax_index_content() {
        check_ajax_referer('aightbot_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'aightbot'));
        }
        
        // Rate limit indexing operations (max once per minute)
        $rate_limit_key = 'aightbot_indexing_in_progress';
        if (get_transient($rate_limit_key)) {
            wp_send_json_error(__('Indexing is already in progress. Please wait before trying again.', 'aightbot'));
        }
        
        // Set lock for 5 minutes (generous timeout for large sites)
        set_transient($rate_limit_key, time(), 300);
        
        // Use try-catch-finally to ensure lock is always cleared
        $result = null;
        $exception = null;
        
        try {
            $result = $this->index_all_content();
        } catch (Throwable $e) {
            // Catch both Exceptions and Errors (PHP 7+)
            $exception = $e;
        } finally {
            // Always clear the lock, even if an error occurs
            delete_transient($rate_limit_key);
        }
        
        // Now send response after lock is cleared
        if ($exception) {
            // Log the actual error but don't expose details to user
            error_log('AightBot Indexing Error: ' . $exception->getMessage());
            wp_send_json_error(__('An error occurred during indexing. Please check the error logs.', 'aightbot'));
        } elseif ($result && $result['success']) {
            wp_send_json_success($result);
        } elseif ($result) {
            wp_send_json_error($result['message']);
        } else {
            wp_send_json_error(__('Unknown error occurred', 'aightbot'));
        }
    }
    
    /**
     * AJAX handler to clear index
     */
    public function ajax_clear_index() {
        check_ajax_referer('aightbot_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'aightbot'));
        }
        
        $result = $this->clear_index();
        
        if ($result) {
            wp_send_json_success(__('Index cleared successfully', 'aightbot'));
        } else {
            wp_send_json_error(__('Failed to clear index', 'aightbot'));
        }
    }
    
    /**
     * AJAX handler to get index status
     */
    public function ajax_get_index_status() {
        check_ajax_referer('aightbot_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'aightbot'));
        }
        
        $status = $this->get_index_status();
        wp_send_json_success($status);
    }
    
    /**
     * Index all public content
     * 
     * @return array Result with success status and message
     */
    public function index_all_content() {
        global $wpdb;
        
        // Reload settings to get any changes made since constructor
        $this->settings = get_option(AIGHTBOT_OPTION_PREFIX . 'rag_settings', []);
        
        // Get settings
        $index_posts = !empty($this->settings['index_posts']);
        $index_pages = !empty($this->settings['index_pages']);
        $index_custom = !empty($this->settings['index_custom_types']) ? $this->settings['index_custom_types'] : [];
        $content_depth = $this->settings['content_depth'] ?? 'full';
        $chunk_size = !empty($this->settings['enable_chunking']) ? absint($this->settings['chunk_size'] ?? 500) : 0;
        
        // Build post types array
        $post_types = [];
        if ($index_posts) $post_types[] = 'post';
        if ($index_pages) $post_types[] = 'page';
        
        // Validate custom post types against registered public types
        if (!empty($index_custom) && is_array($index_custom)) {
            $valid_custom_types = get_post_types(['public' => true], 'names');
            foreach ($index_custom as $custom_type) {
                // Only add if it's a valid, registered, public post type
                if (isset($valid_custom_types[$custom_type]) && !in_array($custom_type, ['post', 'page', 'attachment'])) {
                    $post_types[] = sanitize_key($custom_type);
                }
            }
        }
        
        if (empty($post_types)) {
            return [
                'success' => false,
                'message' => __('No content types selected for indexing', 'aightbot')
            ];
        }
        
        // Use proper prepared statement with placeholders
        $placeholders = implode(', ', array_fill(0, count($post_types), '%s'));
        
        // Allow developers to modify the limit (default 1000 for memory safety)
        // For sites with >1000 posts, this indexes the 1000 most recently modified
        // Developers can increase this or implement batch processing via filter
        $index_limit = apply_filters('aightbot_index_limit', 1000);
        $index_limit = absint($index_limit);
        if ($index_limit < 1) {
            $index_limit = 1000; // Safety fallback
        }
        
        // Use spread operator to pass array elements as separate arguments
        $query = $wpdb->prepare(
            "SELECT ID, post_title, post_content, post_excerpt, post_type 
             FROM {$wpdb->posts} 
             WHERE post_status = %s 
               AND post_password = %s 
               AND post_type IN ($placeholders)
             ORDER BY post_modified DESC
             LIMIT %d",
            ...array_merge(['publish', ''], $post_types, [$index_limit])
        );
        
        $posts = $wpdb->get_results($query);
        
        if ($wpdb->last_error) {
            return [
                'success' => false,
                'message' => __('Database error during indexing', 'aightbot')
            ];
        }
        
        if (empty($posts)) {
            return [
                'success' => false,
                'message' => __('No published content found to index', 'aightbot')
            ];
        }
        
        // Clear existing index
        $this->clear_index();
        
        $indexed_count = 0;
        $chunk_count = 0;
        
        foreach ($posts as $post) {
            // Get content based on depth setting
            $content = $this->get_content_by_depth($post, $content_depth);
            
            if (empty($content)) {
                continue;
            }
            
            $url = get_permalink($post->ID);
            
            // Skip posts that don't have a valid permalink
            if (empty($url) || $url === false) {
                continue;
            }
            
            // Check if chunking is enabled
            if ($chunk_size > 0 && str_word_count($content) > $chunk_size) {
                // Split into chunks
                $chunks = $this->chunk_content($content, $chunk_size);
                
                foreach ($chunks as $index => $chunk) {
                    // Sanitize title to prevent XSS
                    $chunk_title = sanitize_text_field($post->post_title . ' (Part ' . ($index + 1) . ')');
                    $result = $this->insert_index_entry($post->ID, $post->post_type, $chunk_title, $chunk, $url);
                    if ($result !== false) {
                        $chunk_count++;
                    }
                }
            } else {
                // Index as single entry - SECURITY: Sanitize title
                $safe_title = sanitize_text_field($post->post_title);
                $result = $this->insert_index_entry($post->ID, $post->post_type, $safe_title, $content, $url);
                if ($result !== false) {
                    $indexed_count++;
                }
            }
        }
        
        // Update last indexed time
        update_option(AIGHTBOT_OPTION_PREFIX . 'last_indexed', current_time('mysql'));
        
        $total_entries = $indexed_count + $chunk_count;
        
        return [
            'success' => true,
            'message' => sprintf(
                __('Successfully indexed %d items (%d posts, %d chunks)', 'aightbot'),
                $total_entries,
                $indexed_count,
                $chunk_count
            ),
            'count' => $total_entries
        ];
    }
    
    /**
     * Get content based on depth setting
     * 
     * @param object $post WordPress post object
     * @param string $depth Depth setting (full, excerpt, title)
     * @return string Content to index
     */
    private function get_content_by_depth($post, $depth) {
        switch ($depth) {
            case 'title':
                // Sanitize title when returning it as content
                return sanitize_text_field($post->post_title);
                
            case 'excerpt':
                if (!empty($post->post_excerpt)) {
                    // Strip HTML from excerpts too
                    return wp_strip_all_tags(strip_shortcodes($post->post_excerpt));
                }
                // Fall back to auto-generated excerpt
                return wp_trim_words(wp_strip_all_tags(strip_shortcodes($post->post_content)), 55);
                
            case 'full':
            default:
                // Strip shortcodes and HTML tags
                $content = wp_strip_all_tags(strip_shortcodes($post->post_content));
                return $content;
        }
    }
    
    /**
     * Chunk content into smaller pieces
     * 
     * @param string $content Content to chunk
     * @param int $chunk_size Words per chunk
     * @return array Array of content chunks
     */
    private function chunk_content($content, $chunk_size) {
        // OPTIMIZATION: Better word splitting using regex for multiple spaces/newlines
        $words = preg_split('/\s+/', trim($content), -1, PREG_SPLIT_NO_EMPTY);
        $chunks = [];
        
        for ($i = 0; $i < count($words); $i += $chunk_size) {
            $chunk = array_slice($words, $i, $chunk_size);
            $chunks[] = implode(' ', $chunk);
        }
        
        return $chunks;
    }
    
    /**
     * Insert an entry into the index
     * 
     * @param int $post_id Post ID
     * @param string $post_type Post type
     * @param string $title Title
     * @param string $content Content
     * @param string $url URL
     * @return bool Success
     */
    private function insert_index_entry($post_id, $post_type, $title, $content, $url) {
        global $wpdb;
        
        // Truncate title and URL to fit database schema
        $title = substr($title, 0, 255);
        $url = substr($url, 0, 500);
        $post_type = substr($post_type, 0, 20);
        
        $result = $wpdb->insert(
            $this->table_name,
            [
                'post_id' => $post_id,
                'post_type' => $post_type,
                'title' => $title,
                'content' => $content,
                'url' => $url,
                'indexed_at' => current_time('mysql')
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s']
        );
        
        return $result;
    }
    
    /**
     * Reindex a single post
     * 
     * @param int $post_id Post ID
     * @param WP_Post $post Post object
     */
    public function reindex_single_post($post_id, $post) {
        global $wpdb;
        
        // Reload settings to ensure we have latest configuration
        $this->settings = get_option(AIGHTBOT_OPTION_PREFIX . 'rag_settings', []);
        
        // Check if this post type should be indexed
        $index_posts = !empty($this->settings['index_posts']);
        $index_pages = !empty($this->settings['index_pages']);
        $index_custom = !empty($this->settings['index_custom_types']) ? $this->settings['index_custom_types'] : [];
        
        // Check custom post types too, not just posts and pages
        // Validate custom post types even here (defense-in-depth)
        $should_index = false;
        if ($post->post_type === 'post' && $index_posts) {
            $should_index = true;
        } elseif ($post->post_type === 'page' && $index_pages) {
            $should_index = true;
        } elseif (is_array($index_custom) && in_array($post->post_type, $index_custom)) {
            // Verify the post type actually exists and is public
            $valid_types = get_post_types(['public' => true], 'names');
            if (isset($valid_types[$post->post_type])) {
                $should_index = true;
            }
        }
        
        if (!$should_index) {
            return;
        }
        
        // Check if published and not password protected
        if ($post->post_status !== 'publish' || !empty($post->post_password)) {
            // Remove from index if exists
            $wpdb->delete($this->table_name, ['post_id' => $post_id], ['%d']);
            return;
        }
        
        // Delete existing entries for this post
        $wpdb->delete($this->table_name, ['post_id' => $post_id], ['%d']);
        
        // Get content
        $content_depth = $this->settings['content_depth'] ?? 'full';
        $content = $this->get_content_by_depth($post, $content_depth);
        
        if (empty($content)) {
            return;
        }
        
        $url = get_permalink($post_id);
        
        // Don't index posts without valid permalinks
        if (empty($url) || $url === false) {
            return;
        }
        
        // Check chunking
        $chunk_size = !empty($this->settings['enable_chunking']) ? absint($this->settings['chunk_size'] ?? 500) : 0;
        
        if ($chunk_size > 0 && str_word_count($content) > $chunk_size) {
            $chunks = $this->chunk_content($content, $chunk_size);
            foreach ($chunks as $index => $chunk) {
                // Sanitize title
                $chunk_title = sanitize_text_field($post->post_title . ' (Part ' . ($index + 1) . ')');
                $this->insert_index_entry($post_id, $post->post_type, $chunk_title, $chunk, $url);
            }
        } else {
            // Sanitize title
            $safe_title = sanitize_text_field($post->post_title);
            $this->insert_index_entry($post_id, $post->post_type, $safe_title, $content, $url);
        }
    }
    
    /**
     * Clear the entire index
     * 
     * @return bool Success
     */
    public function clear_index() {
        global $wpdb;
        
        // Validate table name
        $expected_table = $wpdb->prefix . 'aightbot_content_index';
        if ($this->table_name !== $expected_table) {
            error_log('AightBot: Table name mismatch in clear_index()');
            return false;
        }
        
        return $wpdb->query("TRUNCATE TABLE {$this->table_name}") !== false;
    }
    
    /**
     * Get index status information
     * 
     * @return array Status information
     */
    public function get_index_status() {
        global $wpdb;
        
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        $last_indexed = get_option(AIGHTBOT_OPTION_PREFIX . 'last_indexed', null);
        
        // Calculate approximate size
        $size_query = "SELECT SUM(LENGTH(content)) as total_size FROM {$this->table_name}";
        $total_size = $wpdb->get_var($size_query);
        
        $size_mb = $total_size ? round($total_size / 1024 / 1024, 2) : 0;
        
        return [
            'count' => (int) $count,
            'last_indexed' => $last_indexed,
            'size_mb' => $size_mb,
            'last_indexed_human' => $last_indexed ? human_time_diff(strtotime($last_indexed), current_time('timestamp')) . ' ago' : 'Never'
        ];
    }
    
    /**
     * Get available custom post types for indexing
     * 
     * @return array Array of post type objects
     */
    public static function get_available_custom_post_types() {
        $args = [
            'public' => true,
            '_builtin' => false
        ];
        
        $post_types = get_post_types($args, 'objects');
        
        return $post_types;
    }
}
