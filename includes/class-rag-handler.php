<?php
/**
 * RAG Handler Class
 * 
 * Handles Retrieval-Augmented Generation using indexed content
 */

if (!defined('ABSPATH')) {
    exit;
}

class AightBot_RAG_Handler {
    
    private $settings;
    private $table_name;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'aightbot_content_index';
        $this->settings = get_option(AIGHTBOT_OPTION_PREFIX . 'rag_settings', []);
        
        // Validate table name matches expected pattern
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $this->table_name)) {
            error_log('AightBot RAG: Invalid table name detected');
            $this->table_name = $wpdb->prefix . 'aightbot_content_index'; // Force correct name
        }
    }
    
    /**
     * Check if RAG is enabled
     * 
     * @return bool
     */
    public function is_enabled() {
        return !empty($this->settings['enable_rag']);
    }
    
    /**
     * Search indexed content for relevant information
     * 
     * @param string $query User's query
     * @param int $limit Number of results to return
     * @return array Array of relevant content entries
     */
    public function search_content($query, $limit = null) {
        global $wpdb;
        
        // Reload settings to ensure we have latest configuration
        $this->settings = get_option(AIGHTBOT_OPTION_PREFIX . 'rag_settings', []);
        
        if (!$this->is_enabled()) {
            return [];
        }
        
        if ($limit === null) {
            $limit = isset($this->settings['results_count']) ? absint($this->settings['results_count']) : 5;
        }
        
        // Sanitize query for FULLTEXT search
        $search_query = $this->prepare_search_query($query);
        
        if (empty($search_query)) {
            return [];
        }
        
        // Perform FULLTEXT search with relevance scoring
        $sql = $wpdb->prepare(
            "SELECT 
                id,
                post_id,
                post_type,
                title,
                content,
                url,
                MATCH(title, content) AGAINST(%s IN NATURAL LANGUAGE MODE) as relevance
            FROM {$this->table_name}
            WHERE MATCH(title, content) AGAINST(%s IN NATURAL LANGUAGE MODE)
            ORDER BY relevance DESC
            LIMIT %d",
            $search_query,
            $search_query,
            $limit
        );
        
        $results = $wpdb->get_results($sql);
        
        // Filter by minimum relevance score if set
        $min_relevance = isset($this->settings['min_relevance']) ? floatval($this->settings['min_relevance']) : 0.0;
        
        if ($min_relevance > 0) {
            $results = array_filter($results, function($result) use ($min_relevance) {
                return $result->relevance >= $min_relevance;
            });
        }
        
        return $results;
    }
    
    /**
     * Prepare search query for FULLTEXT search
     * 
     * @param string $query Raw query
     * @return string Prepared query
     */
    private function prepare_search_query($query) {
        // Remove extra whitespace
        $query = trim(preg_replace('/\s+/', ' ', $query));
        
        // Remove special characters that might break FULLTEXT
        $query = preg_replace('/[^\w\s\-]/', '', $query);
        
        return $query;
    }
    
    /**
     * Build context from search results
     * 
     * @param array $results Search results
     * @param bool $include_citations Whether to include source URLs
     * @return string Formatted context
     */
    public function build_context($results, $include_citations = true) {
        if (empty($results)) {
            return '';
        }
        
        $context = "Relevant information from the website:\n\n";
        
        foreach ($results as $index => $result) {
            $context .= "Source " . ($index + 1) . ": {$result->title}\n";
            
            // Truncate content if too long
            $content = $result->content;
            $max_length = 500; // Characters per result
            
            if (strlen($content) > $max_length) {
                $content = substr($content, 0, $max_length) . '...';
            }
            
            $context .= $content . "\n";
            
            if ($include_citations) {
                $context .= "URL: {$result->url}\n";
            }
            
            $context .= "\n";
        }
        
        return $context;
    }
    
    /**
     * Get RAG-enhanced system prompt
     * 
     * @param string $user_message User's message
     * @param string $original_system_prompt Original system prompt
     * @return string Enhanced system prompt with context
     */
    public function get_enhanced_system_prompt($user_message, $original_system_prompt) {
        // Reload settings to check if RAG is currently enabled
        $this->settings = get_option(AIGHTBOT_OPTION_PREFIX . 'rag_settings', []);
        
        if (!$this->is_enabled()) {
            return $original_system_prompt;
        }
        
        // Search for relevant content
        $results = $this->search_content($user_message);
        
        if (empty($results)) {
            return $original_system_prompt;
        }
        
        // Build context
        $cite_sources = !empty($this->settings['cite_sources']);
        $context = $this->build_context($results, $cite_sources);
        
        // Create enhanced prompt
        $enhanced_prompt = $original_system_prompt . "\n\n";
        $enhanced_prompt .= "You have access to the following information from this website:\n\n";
        $enhanced_prompt .= $context;
        
        if ($cite_sources) {
            $enhanced_prompt .= "\nWhen using information from these sources, please cite them with their URLs.";
        }
        
        $only_use_indexed = !empty($this->settings['only_indexed_content']);
        if ($only_use_indexed) {
            $enhanced_prompt .= "\nIMPORTANT: Only use information from the sources provided above. Do not use general knowledge outside of what's provided.";
        }
        
        return $enhanced_prompt;
    }
    
    /**
     * Get simple context injection (for adding to conversation history)
     * 
     * @param string $user_message User's message
     * @return string|null Context message or null if no relevant content
     */
    public function get_context_injection($user_message) {
        // Reload settings to check if RAG is currently enabled
        $this->settings = get_option(AIGHTBOT_OPTION_PREFIX . 'rag_settings', []);
        
        if (!$this->is_enabled()) {
            return null;
        }
        
        $results = $this->search_content($user_message);
        
        if (empty($results)) {
            return null;
        }
        
        $cite_sources = !empty($this->settings['cite_sources']);
        return $this->build_context($results, $cite_sources);
    }
    
    /**
     * Format results for display (admin preview)
     * 
     * @param array $results Search results
     * @return array Formatted results
     */
    public function format_results_for_display($results) {
        $formatted = [];
        
        foreach ($results as $result) {
            $formatted[] = [
                'title' => $result->title,
                'excerpt' => wp_trim_words($result->content, 50),
                'url' => $result->url,
                'relevance' => round($result->relevance, 3),
                'post_type' => $result->post_type
            ];
        }
        
        return $formatted;
    }
}
