<?php
/**
 * API Handler for LLM Communication
 */
class AightBot_API_Handler {
    
    private $encryption;
    private $settings;
    private $logger;
    private $rag_handler;
    
    public function __construct($encryption) {
        $this->encryption = $encryption;
        $this->settings = get_option(AIGHTBOT_OPTION_PREFIX . 'settings', []);
        
        // Initialize logger with error handling
        try {
            $this->logger = new AightBot_Logger();
        } catch (Exception $e) {
            error_log('AightBot: Failed to initialize logger: ' . $e->getMessage());
            $this->logger = null;
        }
        
        // Initialize RAG handler
        try {
            $this->rag_handler = new AightBot_RAG_Handler();
        } catch (Exception $e) {
            error_log('AightBot: Failed to initialize RAG handler: ' . $e->getMessage());
            $this->rag_handler = null;
        }
        
        $this->init_hooks();
    }
    
    private function init_hooks() {
        add_action('wp_ajax_aightbot_send_message', [$this, 'ajax_send_message']);
        add_action('wp_ajax_nopriv_aightbot_send_message', [$this, 'ajax_send_message']);
        add_action('wp_ajax_aightbot_create_session', [$this, 'ajax_create_session']);
        add_action('wp_ajax_nopriv_aightbot_create_session', [$this, 'ajax_create_session']);
    }
    
    /**
     * AJAX handler for creating new session
     */
    public function ajax_create_session() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'aightbot_chat_nonce')) {
            wp_send_json_error(__('Security check failed', 'aightbot'));
        }
        
        // Generate unique session ID
        $session_id = 'sess_' . wp_generate_password(32, false);
        
        wp_send_json_success([
            'session_id' => $session_id
        ]);
    }
    
    /**
     * AJAX handler for sending messages
     */
    public function ajax_send_message() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'aightbot_chat_nonce')) {
            wp_send_json_error(__('Security check failed', 'aightbot'));
        }
        
        // Get message - SECURITY FIX: Use sanitize_textarea_field to preserve newlines
        $message = isset($_POST['message']) ? sanitize_textarea_field($_POST['message']) : '';
        if (empty($message)) {
            wp_send_json_error(__('Message is required', 'aightbot'));
        }
        
        // Get session ID
        $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';
        
        // Check rate limit
        if (!$this->check_rate_limit($session_id)) {
            wp_send_json_error(__('You\'re sending messages too quickly. Please wait a moment before trying again.', 'aightbot'));
        }
        
        try {
            // Get conversation history
            $history = $this->get_conversation_history($session_id);
            
            // If this is the first message and we have a starter message, inject it
            if (empty($history) && !empty($this->settings['starter_message'])) {
                $history[] = [
                    'role' => 'assistant',
                    'content' => $this->settings['starter_message']
                ];
            }
            
            // Add user message
            $history[] = [
                'role' => 'user',
                'content' => $message
            ];
            
            // Get user IP for logging
            $user_ip = $this->get_client_ip();
            
            // Log user message with IP
            if ($this->logger) {
                $this->logger->log_message($session_id, 'user', $message, $user_ip);
            }
            
            // Truncate context if needed (before sending to API)
            $history = $this->truncate_context($session_id, $history);
            
            // Send to API
            $response = $this->send_to_llm($history);
            
            // Log assistant response
            if ($this->logger) {
                $this->logger->log_message($session_id, 'assistant', $response);
            }
            
            // Add assistant response to history
            $history[] = [
                'role' => 'assistant',
                'content' => $response
            ];
            
            // CRITICAL SECURITY FIX: Limit stored history to prevent storage exhaustion
            // Keep last 100 messages in database (vs unlimited before)
            if (count($history) > 100) {
                $history = array_slice($history, -100);
            }
            
            // Save conversation history (now with size limit)
            $this->save_conversation_history($session_id, $history);
            
            // Record this request timestamp
            $this->record_request($session_id);
            
            wp_send_json_success([
                'message' => $response,
                'session_id' => $session_id
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * Check if session has exceeded rate limit
     * 
     * @param string $session_id Session ID
     * @return bool True if within limit, false if exceeded
     */
    private function check_rate_limit($session_id) {
        // Allow empty session for first message
        if (empty($session_id)) {
            return true;
        }
        
        $max_requests = isset($this->settings['rate_limit_requests']) ? (int)$this->settings['rate_limit_requests'] : 20;
        $time_window = isset($this->settings['rate_limit_window']) ? (int)$this->settings['rate_limit_window'] : 300;
        
        // Get request timestamps for this session
        $timestamps = get_transient('aightbot_ratelimit_' . $session_id);
        
        if (!$timestamps || !is_array($timestamps)) {
            return true; // No history, allow
        }
        
        // Remove timestamps outside the window
        $current_time = time();
        $cutoff_time = $current_time - $time_window;
        $timestamps = array_filter($timestamps, function($ts) use ($cutoff_time) {
            return $ts > $cutoff_time;
        });
        
        // Check if limit exceeded
        return count($timestamps) < $max_requests;
    }
    
    /**
     * Record a request timestamp for rate limiting
     * 
     * @param string $session_id Session ID
     */
    private function record_request($session_id) {
        if (empty($session_id)) {
            return;
        }
        
        $time_window = isset($this->settings['rate_limit_window']) ? (int)$this->settings['rate_limit_window'] : 300;
        
        // Get existing timestamps
        $timestamps = get_transient('aightbot_ratelimit_' . $session_id);
        
        if (!$timestamps || !is_array($timestamps)) {
            $timestamps = [];
        }
        
        // Add current timestamp
        $timestamps[] = time();
        
        // Store with expiration = time window + buffer
        set_transient('aightbot_ratelimit_' . $session_id, $timestamps, $time_window + 60);
    }
    
    /**
     * Send request to LLM API
     * 
     * @param array $messages Conversation history
     * @return string Response from LLM
     * @throws Exception
     */
    private function send_to_llm($messages) {
        // Validate settings
        if (empty($this->settings['llm_url'])) {
            throw new Exception(__('LLM API is not configured', 'aightbot'));
        }
        
        // Prepare payload
        $payload = [
            'messages' => $messages
        ];
        
        // Add model if specified
        if (!empty($this->settings['model_name'])) {
            $payload['model'] = $this->settings['model_name'];
        }
        
        // Add system prompt if specified
        if (!empty($this->settings['system_prompt'])) {
            $system_prompt = $this->settings['system_prompt'];
            
            // Enhance system prompt with RAG context if enabled
            if ($this->rag_handler && $this->rag_handler->is_enabled()) {
                // Get the user's last message for context search
                $user_message = '';
                foreach (array_reverse($messages) as $msg) {
                    if ($msg['role'] === 'user') {
                        $user_message = $msg['content'];
                        break;
                    }
                }
                
                if (!empty($user_message)) {
                    $system_prompt = $this->rag_handler->get_enhanced_system_prompt($user_message, $system_prompt);
                }
            }
            
            array_unshift($payload['messages'], [
                'role' => 'system',
                'content' => $system_prompt
            ]);
        }
        
        // Merge sampler overrides
        if (!empty($this->settings['sampler_overrides'])) {
            $overrides = json_decode($this->settings['sampler_overrides'], true);
            if (is_array($overrides)) {
                $payload = array_merge($payload, $overrides);
            }
        }
        
        // Ensure max_tokens is valid integer before sending
        // This must happen AFTER merge so we check the final value
        if (isset($payload['max_tokens'])) {
            // Convert to int (handles strings, floats, etc)
            $payload['max_tokens'] = intval($payload['max_tokens']);
            
            // Validate range
            if ($payload['max_tokens'] < 1) {
                unset($payload['max_tokens']); // Let API use its default
            } elseif ($payload['max_tokens'] > 100000) {
                $payload['max_tokens'] = 100000;
            }
        }
        
        // Prepare request headers
        $headers = [
            'Content-Type' => 'application/json',
        ];
        
        // Add API key if present
        if (!empty($this->settings['api_key'])) {
            try {
                $api_key = $this->encryption->decrypt($this->settings['api_key']);
                if (!empty($api_key)) {
                    $headers['Authorization'] = 'Bearer ' . $api_key;
                }
            } catch (Exception $e) {
                error_log('AightBot: Failed to decrypt API key');
            }
        }
        
        // Prepare request arguments
        $args = [
            'timeout' => 60,
            'headers' => $headers,
            'body' => wp_json_encode($payload),
            'redirection' => 0, // Don't follow redirects on POST
        ];
        
        // Only verify SSL for HTTPS URLs
        // If URL starts with http:// there's no SSL to verify
        if (strpos($this->settings['llm_url'], 'https://') === 0) {
            // HTTPS - verify SSL unless explicitly disabled
            $args['sslverify'] = !isset($this->settings['disable_ssl_verify']) || $this->settings['disable_ssl_verify'] !== 'yes';
        } else {
            // HTTP - no SSL verification needed
            $args['sslverify'] = false;
        }
        
        // Make request
        $response = wp_remote_post($this->settings['llm_url'], $args);
        
        // Handle errors
        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            $body = wp_remote_retrieve_body($response);
            $error_data = json_decode($body, true);
            
            if (!empty($error_data['error']['message'])) {
                throw new Exception($error_data['error']['message']);
            }
            
            throw new Exception(sprintf(__('API error (HTTP %d)', 'aightbot'), $status_code));
        }
        
        // Parse response
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception(__('Invalid JSON response from API', 'aightbot'));
        }
        
        // Extract response content
        // Priority: content (final answer) > reasoning fields (fallback) > alternatives
        if (isset($data['choices'][0]['message'])) {
            $message = $data['choices'][0]['message'];
            
            // Standard: content field (final answer for both standard and reasoning models)
            if (isset($message['content']) && !empty($message['content'])) {
                return $message['content'];
            }
            // Fallback: reasoning fields (only if no final answer in content)
            if (isset($message['reasoning_content']) && !empty($message['reasoning_content'])) {
                return $message['reasoning_content'];
            }
            if (isset($message['reasoning']) && !empty($message['reasoning'])) {
                return $message['reasoning'];
            }
        }
        
        // Alternative formats
        if (isset($data['choices'][0]['text'])) {
            return $data['choices'][0]['text'];
        }
        if (isset($data['choices'][0]['delta']['content'])) {
            return $data['choices'][0]['delta']['content'];
        }
        
        throw new Exception(__('No response content found in API response', 'aightbot'));
    }
    
    /**
     * Get conversation history from session
     * 
     * @param string $session_id Session ID
     * @return array Conversation history
     */
    private function get_conversation_history($session_id) {
        if (empty($session_id)) {
            return [];
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'aightbot_sessions';
        
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT history FROM $table WHERE session_id = %s",
            $session_id
        ));
        
        if ($session && !empty($session->history)) {
            $history = json_decode($session->history, true);
            return is_array($history) ? $history : [];
        }
        
        return [];
    }
    
    /**
     * Save conversation history to session
     * 
     * @param string $session_id Session ID
     * @param array $history Conversation history
     */
    private function save_conversation_history($session_id, $history) {
        global $wpdb;
        $table = $wpdb->prefix . 'aightbot_sessions';
        
        $user_id = get_current_user_id();
        $bot_name = $this->settings['bot_name'] ?? 'AightBot';
        $now = current_time('mysql');
        
        // Check if session exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE session_id = %s",
            $session_id
        ));
        
        if ($exists) {
            // Update existing session
            $wpdb->update(
                $table,
                [
                    'history' => wp_json_encode($history),
                    'last_active' => $now
                ],
                ['session_id' => $session_id],
                ['%s', '%s'],
                ['%s']
            );
        } else {
            // Create new session
            $wpdb->insert(
                $table,
                [
                    'session_id' => $session_id,
                    'user_id' => $user_id,
                    'bot_name' => $bot_name,
                    'history' => wp_json_encode($history),
                    'created_at' => $now,
                    'last_active' => $now
                ],
                ['%s', '%d', '%s', '%s', '%s', '%s']
            );
        }
    }
    
    /**
     * Truncate context based on message count and/or word count limits
     * 
     * @param string $session_id Session ID for logging
     * @param array $history Conversation history
     * @return array Truncated history
     */
    private function truncate_context($session_id, $history) {
        $max_messages = isset($this->settings['max_context_messages']) ? absint($this->settings['max_context_messages']) : 40;
        $max_words = isset($this->settings['max_context_words']) ? absint($this->settings['max_context_words']) : 8000;
        
        // Build context that will be sent to API
        // Always include system prompt
        $context = [];
        $context[] = [
            'role' => 'system',
            'content' => $this->settings['system_prompt'] ?? 'You are a helpful AI assistant.'
        ];
        
        // Keep starter message if it exists (don't count towards limits)
        $starter_included = false;
        if (!empty($this->settings['starter_message'])) {
            foreach ($history as $msg) {
                if ($msg['role'] === 'assistant' && $msg['content'] === $this->settings['starter_message']) {
                    $context[] = $msg;
                    $starter_included = true;
                    break;
                }
            }
        }
        
        // Get conversation messages (excluding starter if already included)
        $conversation = [];
        foreach ($history as $msg) {
            if ($starter_included && $msg['role'] === 'assistant' && $msg['content'] === $this->settings['starter_message']) {
                continue; // Skip starter, already added
            }
            $conversation[] = $msg;
        }
        
        // Track original counts for logging
        $original_count = count($conversation);
        $original_words = 0;
        foreach ($conversation as $msg) {
            $original_words += str_word_count($msg['content']);
        }
        
        // Truncate based on BOTH limits
        $truncated = false;
        
        // Check message count limit
        if (count($conversation) > $max_messages) {
            // Keep most recent messages
            $conversation = array_slice($conversation, -$max_messages);
            $truncated = true;
        }
        
        // Check word count limit (approximate token limit)
        $current_words = 0;
        foreach ($conversation as $msg) {
            $current_words += str_word_count($msg['content']);
        }
        
        while ($current_words > $max_words && count($conversation) > 1) {
            // Remove oldest message
            array_shift($conversation);
            
            // Recalculate word count
            $current_words = 0;
            foreach ($conversation as $msg) {
                $current_words += str_word_count($msg['content']);
            }
            $truncated = true;
        }
        
        // Log truncation if it occurred
        if ($truncated) {
            $new_count = count($conversation);
            $new_words = 0;
            foreach ($conversation as $msg) {
                $new_words += str_word_count($msg['content']);
            }
            
            // Calculate approximate tokens (words × 1.3)
            $original_tokens = (int)($original_words * 1.3);
            $new_tokens = (int)($new_words * 1.3);
            
            if ($this->logger) {
                $this->logger->log_truncation($session_id, $original_count, $new_count, $original_words, $new_words, $original_tokens, $new_tokens);
            }
        }
        
        // Merge: system prompt + starter + truncated conversation
        $final_context = array_merge($context, $conversation);
        
        return $final_context;
    }
    
    /**
     * Get client IP address
     * 
     * SECURITY NOTE: This function checks common IP headers but they can be spoofed
     * in environments without proper proxy configuration. For production use behind
     * a reverse proxy (nginx, CloudFlare, etc.), ensure your proxy is configured to
     * set trusted headers and that your firewall blocks direct access.
     * 
     * Rate limiting by IP alone is not foolproof - session-based rate limiting
     * (already implemented) provides stronger protection.
     * 
     * @return string IP address
     */
    private function get_client_ip() {
        $ip = '';
        
        // These headers can be spoofed if server is directly accessible
        // Only trust X-Forwarded-For if behind a properly configured reverse proxy
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // Get first IP if multiple are present (rightmost is most reliable)
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($ips[0]);
        } elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
        // Validate IP
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
        
        return 'unknown';
    }
}
