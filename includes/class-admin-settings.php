<?php
/**
 * Admin Settings Class
 */
class AightBot_Admin_Settings {
    
    private $page_slug = 'aightbot-settings';
    private $settings_group = 'aightbot_settings_group';
    private $encryption;
    
    public function __construct($encryption) {
        $this->encryption = $encryption;
        $this->init_hooks();
    }
    
    private function init_hooks() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_ajax_aightbot_test_connection', [$this, 'ajax_test_connection']);
    }
    
    public function add_admin_menu() {
        add_menu_page(
            __('AightBot Settings', 'aightbot'),
            __('AightBot', 'aightbot'),
            'manage_options',
            $this->page_slug,
            [$this, 'render_settings_page'],
            'dashicons-format-chat',
            30
        );
    }
    
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, $this->page_slug) === false) {
            return;
        }
        
        wp_enqueue_style(
            'aightbot-admin',
            AIGHTBOT_PLUGIN_URL . 'admin/css/admin-style.css',
            [],
            AIGHTBOT_VERSION
        );
        
        wp_enqueue_script(
            'aightbot-admin',
            AIGHTBOT_PLUGIN_URL . 'admin/js/admin-script.js',
            ['jquery'],
            AIGHTBOT_VERSION,
            true
        );
        
        wp_localize_script('aightbot-admin', 'aightbotAdmin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('aightbot_admin_nonce'),
            'strings' => [
                'testing_connection' => __('Testing connection...', 'aightbot'),
                'test_success' => __('Connection successful!', 'aightbot'),
                'test_error' => __('Connection failed', 'aightbot'),
                'invalid_json' => __('Invalid JSON format', 'aightbot'),
            ]
        ]);
    }
    
    public function register_settings() {
        register_setting(
            $this->settings_group,
            AIGHTBOT_OPTION_PREFIX . 'settings',
            [$this, 'validate_settings']
        );
        
        // Register RAG settings
        register_setting(
            'aightbot_rag_settings_group',
            AIGHTBOT_OPTION_PREFIX . 'rag_settings',
            [$this, 'validate_rag_settings']
        );
        
        // Connection Section
        add_settings_section(
            'aightbot_connection',
            __('Connection Settings', 'aightbot'),
            [$this, 'render_connection_section'],
            $this->page_slug
        );
        
        add_settings_field(
            'llm_url',
            __('LLM API URL', 'aightbot') . ' <span class="required">*</span>',
            [$this, 'render_llm_url_field'],
            $this->page_slug,
            'aightbot_connection'
        );
        
        add_settings_field(
            'api_key',
            __('API Key', 'aightbot'),
            [$this, 'render_api_key_field'],
            $this->page_slug,
            'aightbot_connection'
        );
        
        // Bot Settings Section
        add_settings_section(
            'aightbot_bot',
            __('Bot Configuration', 'aightbot'),
            [$this, 'render_bot_section'],
            $this->page_slug
        );
        
        add_settings_field(
            'enabled',
            __('Enable Chatbot', 'aightbot'),
            [$this, 'render_enabled_field'],
            $this->page_slug,
            'aightbot_bot'
        );
        
        add_settings_field(
            'bot_name',
            __('Bot Name', 'aightbot'),
            [$this, 'render_bot_name_field'],
            $this->page_slug,
            'aightbot_bot'
        );
        
        add_settings_field(
            'model_name',
            __('Model Name', 'aightbot'),
            [$this, 'render_model_name_field'],
            $this->page_slug,
            'aightbot_bot'
        );
        
        add_settings_field(
            'system_prompt',
            __('System Prompt', 'aightbot'),
            [$this, 'render_system_prompt_field'],
            $this->page_slug,
            'aightbot_bot'
        );
        
        add_settings_field(
            'starter_message',
            __('Starter Message', 'aightbot'),
            [$this, 'render_starter_message_field'],
            $this->page_slug,
            'aightbot_bot'
        );
        
        // Advanced Section
        add_settings_section(
            'aightbot_advanced',
            __('Advanced Settings', 'aightbot'),
            [$this, 'render_advanced_section'],
            $this->page_slug
        );
        
        add_settings_field(
            'sampler_overrides',
            __('Sampler Parameters (JSON)', 'aightbot'),
            [$this, 'render_sampler_overrides_field'],
            $this->page_slug,
            'aightbot_advanced'
        );
        
        add_settings_field(
            'disable_ssl_verify',
            __('Disable SSL Verification', 'aightbot'),
            [$this, 'render_disable_ssl_verify_field'],
            $this->page_slug,
            'aightbot_advanced'
        );
        
        add_settings_field(
            'rate_limit_requests',
            __('Rate Limit - Maximum Requests', 'aightbot'),
            [$this, 'render_rate_limit_requests_field'],
            $this->page_slug,
            'aightbot_advanced'
        );
        
        add_settings_field(
            'rate_limit_window',
            __('Rate Limit - Time Window (seconds)', 'aightbot'),
            [$this, 'render_rate_limit_window_field'],
            $this->page_slug,
            'aightbot_advanced'
        );
        
        add_settings_field(
            'enable_logging',
            __('Enable Session Logging', 'aightbot'),
            [$this, 'render_enable_logging_field'],
            $this->page_slug,
            'aightbot_advanced'
        );
        
        add_settings_field(
            'log_retention_days',
            __('Auto-Delete Logs Older Than (days)', 'aightbot'),
            [$this, 'render_log_retention_days_field'],
            $this->page_slug,
            'aightbot_advanced'
        );
        
        add_settings_field(
            'max_context_messages',
            __('Max Context Messages', 'aightbot'),
            [$this, 'render_max_context_messages_field'],
            $this->page_slug,
            'aightbot_advanced'
        );
        
        add_settings_field(
            'max_context_words',
            __('Max Context Words', 'aightbot'),
            [$this, 'render_max_context_words_field'],
            $this->page_slug,
            'aightbot_advanced'
        );
        
        // RAG Settings Sections (separate page/tab)
        add_settings_section(
            'aightbot_rag_enable',
            __('RAG (Retrieval-Augmented Generation)', 'aightbot'),
            [$this, 'render_rag_enable_section'],
            $this->page_slug . '_rag'
        );
        
        add_settings_field(
            'enable_rag',
            __('Enable RAG', 'aightbot'),
            [$this, 'render_enable_rag_field'],
            $this->page_slug . '_rag',
            'aightbot_rag_enable'
        );
        
        // Content Selection Section
        add_settings_section(
            'aightbot_rag_content',
            __('Content Selection', 'aightbot'),
            [$this, 'render_rag_content_section'],
            $this->page_slug . '_rag'
        );
        
        add_settings_field(
            'index_posts',
            __('Index Posts', 'aightbot'),
            [$this, 'render_index_posts_field'],
            $this->page_slug . '_rag',
            'aightbot_rag_content'
        );
        
        add_settings_field(
            'index_pages',
            __('Index Pages', 'aightbot'),
            [$this, 'render_index_pages_field'],
            $this->page_slug . '_rag',
            'aightbot_rag_content'
        );
        
        add_settings_field(
            'content_depth',
            __('Content Depth', 'aightbot'),
            [$this, 'render_content_depth_field'],
            $this->page_slug . '_rag',
            'aightbot_rag_content'
        );
        
        add_settings_field(
            'enable_chunking',
            __('Enable Chunking', 'aightbot'),
            [$this, 'render_enable_chunking_field'],
            $this->page_slug . '_rag',
            'aightbot_rag_content'
        );
        
        // RAG Behavior Section
        add_settings_section(
            'aightbot_rag_behavior',
            __('RAG Behavior', 'aightbot'),
            [$this, 'render_rag_behavior_section'],
            $this->page_slug . '_rag'
        );
        
        add_settings_field(
            'results_count',
            __('Results to Retrieve', 'aightbot'),
            [$this, 'render_results_count_field'],
            $this->page_slug . '_rag',
            'aightbot_rag_behavior'
        );
        
        add_settings_field(
            'min_relevance',
            __('Minimum Relevance Score', 'aightbot'),
            [$this, 'render_min_relevance_field'],
            $this->page_slug . '_rag',
            'aightbot_rag_behavior'
        );
        
        add_settings_field(
            'cite_sources',
            __('Cite Sources', 'aightbot'),
            [$this, 'render_cite_sources_field'],
            $this->page_slug . '_rag',
            'aightbot_rag_behavior'
        );
        
        add_settings_field(
            'only_indexed_content',
            __('Only Use Indexed Content', 'aightbot'),
            [$this, 'render_only_indexed_content_field'],
            $this->page_slug . '_rag',
            'aightbot_rag_behavior'
        );
        
        // Index Management Section
        add_settings_section(
            'aightbot_rag_management',
            __('Index Management', 'aightbot'),
            [$this, 'render_rag_management_section'],
            $this->page_slug . '_rag'
        );
    }
    
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'aightbot'));
        }
        
        include AIGHTBOT_PLUGIN_DIR . 'admin/partials/settings-page.php';
    }
    
    public function validate_settings($input) {
        $current_settings = get_option(AIGHTBOT_OPTION_PREFIX . 'settings', []);
        $validated = [];
        
        // LLM URL - Required
        $llm_url = isset($input['llm_url']) ? trim($input['llm_url']) : '';
        
        if (empty($llm_url)) {
            add_settings_error(
                'llm_url',
                'required_field',
                __('LLM API URL is required.', 'aightbot'),
                'error'
            );
        } else {
            $url = esc_url_raw($llm_url, ['http', 'https']);
            if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
                add_settings_error(
                    'llm_url',
                    'invalid_url',
                    __('Please enter a valid URL (http:// or https://).', 'aightbot'),
                    'error'
                );
            } else {
                $validated['llm_url'] = $url;
            }
        }
        
        // API Key - Optional, encrypt if changed
        $api_key = isset($input['api_key']) ? trim($input['api_key']) : '';
        
        if (empty($api_key)) {
            $validated['api_key'] = '';
        } elseif ($api_key === '••••••••') {
            // Placeholder - keep existing key
            $validated['api_key'] = $current_settings['api_key'] ?? '';
        } else {
            // New key - encrypt it
            try {
                $validated['api_key'] = $this->encryption->encrypt($api_key);
            } catch (Exception $e) {
                add_settings_error(
                    'api_key',
                    'encryption_failed',
                    __('Failed to encrypt API key. Please try again.', 'aightbot'),
                    'error'
                );
                $validated['api_key'] = '';
            }
        }
        
        // Bot Name
        $validated['bot_name'] = !empty(trim($input['bot_name'])) 
            ? sanitize_text_field(trim($input['bot_name'])) 
            : 'AightBot';
        
        // Model Name
        $validated['model_name'] = !empty(trim($input['model_name'])) 
            ? sanitize_text_field(trim($input['model_name'])) 
            : '';
        
        // System Prompt - SECURITY FIX: Strip all HTML tags for plain text
        $validated['system_prompt'] = !empty(trim($input['system_prompt'])) 
            ? sanitize_textarea_field(trim($input['system_prompt'])) 
            : '';
        
        // Starter Message - SECURITY FIX: Strip all HTML tags
        $validated['starter_message'] = !empty(trim($input['starter_message'])) 
            ? sanitize_textarea_field(trim($input['starter_message'])) 
            : '';
        
        // Sampler Overrides - Validate JSON
        $sampler_overrides = isset($input['sampler_overrides']) ? trim($input['sampler_overrides']) : '';
        
        if (!empty($sampler_overrides)) {
            $decoded = json_decode($sampler_overrides, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                add_settings_error(
                    'sampler_overrides',
                    'invalid_json',
                    sprintf(
                        __('Invalid JSON in sampler parameters: %s', 'aightbot'),
                        json_last_error_msg()
                    ),
                    'error'
                );
                $validated['sampler_overrides'] = '';
            } else {
                // Store as formatted JSON
                $validated['sampler_overrides'] = wp_json_encode($decoded, JSON_PRETTY_PRINT);
            }
        } else {
            $validated['sampler_overrides'] = '';
        }
        
        // Enabled checkbox
        $validated['enabled'] = isset($input['enabled']) && $input['enabled'] === 'yes' ? 'yes' : 'no';
        
        // Disable SSL verification checkbox
        $validated['disable_ssl_verify'] = isset($input['disable_ssl_verify']) && $input['disable_ssl_verify'] === 'yes' ? 'yes' : 'no';
        
        // Rate limit - maximum requests (default: 20)
        $rate_limit_requests = isset($input['rate_limit_requests']) ? absint($input['rate_limit_requests']) : 20;
        if ($rate_limit_requests < 1) {
            $rate_limit_requests = 20;
        }
        $validated['rate_limit_requests'] = $rate_limit_requests;
        
        // Rate limit - time window in seconds (default: 300 = 5 minutes)
        $rate_limit_window = isset($input['rate_limit_window']) ? absint($input['rate_limit_window']) : 300;
        if ($rate_limit_window < 60) {
            $rate_limit_window = 300;
        }
        $validated['rate_limit_window'] = $rate_limit_window;
        
        // Enable logging checkbox
        $validated['enable_logging'] = isset($input['enable_logging']) && $input['enable_logging'] === 'yes' ? 'yes' : 'no';
        
        // Log retention days (0 = never delete)
        $log_retention_days = isset($input['log_retention_days']) ? absint($input['log_retention_days']) : 30;
        $validated['log_retention_days'] = $log_retention_days;
        
        // Max context messages
        $max_context_messages = isset($input['max_context_messages']) ? absint($input['max_context_messages']) : 40;
        if ($max_context_messages < 1) {
            $max_context_messages = 40;
        }
        $validated['max_context_messages'] = $max_context_messages;
        
        // Max context words (approximate token limit)
        $max_context_words = isset($input['max_context_words']) ? absint($input['max_context_words']) : 8000;
        if ($max_context_words < 10) {
            $max_context_words = 8000;
        }
        $validated['max_context_words'] = $max_context_words;
        
        return $validated;
    }
    
    // Section callbacks
    public function render_connection_section() {
        echo '<p>' . __('Configure the connection to your LLM API endpoint.', 'aightbot') . '</p>';
    }
    
    public function render_bot_section() {
        echo '<p>' . __('Configure your chatbot\'s behavior and appearance.', 'aightbot') . '</p>';
    }
    
    public function render_advanced_section() {
        echo '<p>' . __('Fine-tune your chatbot with advanced parameters.', 'aightbot') . '</p>';
    }
    
    // Field renderers
    public function render_llm_url_field() {
        $options = get_option(AIGHTBOT_OPTION_PREFIX . 'settings', []);
        $value = $options['llm_url'] ?? '';
        ?>
        <input type="url" 
               name="aightbot_settings[llm_url]" 
               id="llm_url"
               value="<?php echo esc_attr($value); ?>" 
               class="regular-text code" 
               required
               placeholder="https://api.example.com/v1/chat/completions">
        <p class="description">
            <?php _e('The OpenAI-compatible API endpoint URL.', 'aightbot'); ?>
            <br><?php _e('Example: https://api.openai.com/v1/chat/completions', 'aightbot'); ?>
        </p>
        <?php
    }
    
    public function render_api_key_field() {
        $options = get_option(AIGHTBOT_OPTION_PREFIX . 'settings', []);
        $has_key = !empty($options['api_key']);
        $display_value = $has_key ? '••••••••' : '';
        ?>
        <input type="password" 
               name="aightbot_settings[api_key]" 
               id="api_key"
               value="<?php echo esc_attr($display_value); ?>" 
               class="regular-text"
               placeholder="<?php esc_attr_e('Enter API key', 'aightbot'); ?>"
               autocomplete="new-password">
        <p class="description">
            <?php 
            if ($has_key) {
                _e('API key is stored encrypted. Leave as ••••••••  to keep existing key, or enter a new one to update.', 'aightbot');
            } else {
                _e('Optional: API key for authentication (stored encrypted).', 'aightbot');
            }
            ?>
        </p>
        <?php
    }
    
    public function render_enabled_field() {
        $options = get_option(AIGHTBOT_OPTION_PREFIX . 'settings', []);
        $checked = isset($options['enabled']) && $options['enabled'] === 'yes';
        ?>
        <label>
            <input type="checkbox" 
                   name="aightbot_settings[enabled]" 
                   id="enabled"
                   value="yes" 
                   <?php checked($checked); ?>>
            <?php _e('Enable the chatbot on your website', 'aightbot'); ?>
        </label>
        <p class="description">
            <?php _e('When disabled, the chatbot will not appear on your site.', 'aightbot'); ?>
        </p>
        <?php
    }
    
    public function render_bot_name_field() {
        $options = get_option(AIGHTBOT_OPTION_PREFIX . 'settings', []);
        $value = $options['bot_name'] ?? 'AightBot';
        ?>
        <input type="text" 
               name="aightbot_settings[bot_name]" 
               id="bot_name"
               value="<?php echo esc_attr($value); ?>" 
               class="regular-text">
        <p class="description"><?php _e('Display name for the chatbot.', 'aightbot'); ?></p>
        <?php
    }
    
    public function render_model_name_field() {
        $options = get_option(AIGHTBOT_OPTION_PREFIX . 'settings', []);
        $value = $options['model_name'] ?? '';
        ?>
        <input type="text" 
               name="aightbot_settings[model_name]" 
               id="model_name"
               value="<?php echo esc_attr($value); ?>" 
               class="regular-text"
               placeholder="gpt-3.5-turbo">
        <p class="description">
            <?php _e('Optional: Specific model to use. If left empty, the API will use its default model.', 'aightbot'); ?>
        </p>
        <?php
    }
    
    public function render_system_prompt_field() {
        $options = get_option(AIGHTBOT_OPTION_PREFIX . 'settings', []);
        $value = $options['system_prompt'] ?? '';
        $placeholder = __('You are a helpful AI assistant.', 'aightbot');
        ?>
        <textarea name="aightbot_settings[system_prompt]" 
                  id="system_prompt"
                  rows="5" 
                  class="large-text"
                  placeholder="<?php echo esc_attr($placeholder); ?>"><?php echo esc_textarea($value); ?></textarea>
        <p class="description">
            <?php _e('Optional: Instructions that guide the chatbot\'s personality and behavior.', 'aightbot'); ?>
        </p>
        <?php
    }
    
    public function render_starter_message_field() {
        $options = get_option(AIGHTBOT_OPTION_PREFIX . 'settings', []);
        $value = $options['starter_message'] ?? '';
        ?>
        <textarea name="aightbot_settings[starter_message]" 
                  id="starter_message"
                  rows="4" 
                  class="large-text"
                  placeholder="<?php esc_attr_e('Hello! How can I help you today?', 'aightbot'); ?>"><?php echo esc_textarea($value); ?></textarea>
        <p class="description">
            <?php _e('Optional: Initial assistant message to set context. This is sent to the API as the first message in the conversation.', 'aightbot'); ?>
            <br>
            <?php _e('Unlike the system prompt, this appears as an assistant message in the conversation history.', 'aightbot'); ?>
        </p>
        <?php
    }
    
    public function render_sampler_overrides_field() {
        $options = get_option(AIGHTBOT_OPTION_PREFIX . 'settings', []);
        $value = $options['sampler_overrides'] ?? '';
        
        // Default example
        $example = wp_json_encode([
            'temperature' => 0.7,
            'max_tokens' => 500,
            'top_p' => 1.0,
            'frequency_penalty' => 0,
            'presence_penalty' => 0
        ], JSON_PRETTY_PRINT);
        ?>
        <textarea name="aightbot_settings[sampler_overrides]" 
                  id="sampler_overrides"
                  rows="8" 
                  class="large-text code"
                  placeholder='<?php echo esc_attr($example); ?>'><?php echo esc_textarea($value); ?></textarea>
        <p class="description">
            <?php _e('Optional: JSON object with API parameters like temperature, max_tokens, etc.', 'aightbot'); ?>
            <br>
            <button type="button" class="button button-small" id="validate-json-btn">
                <?php _e('Validate JSON', 'aightbot'); ?>
            </button>
            <span id="json-validation-result"></span>
        </p>
        <?php
    }
    
    public function render_disable_ssl_verify_field() {
        $options = get_option(AIGHTBOT_OPTION_PREFIX . 'settings', []);
        $checked = isset($options['disable_ssl_verify']) && $options['disable_ssl_verify'] === 'yes';
        ?>
        <label>
            <input type="checkbox" 
                   name="aightbot_settings[disable_ssl_verify]" 
                   id="disable_ssl_verify"
                   value="yes" 
                   <?php checked($checked); ?>>
            <?php _e('Disable SSL certificate verification for HTTPS URLs', 'aightbot'); ?>
        </label>
        <p class="description">
            <?php _e('Note: HTTP URLs automatically skip SSL verification. This option only affects HTTPS URLs.', 'aightbot'); ?>
        </p>
        <p class="description" style="color: #d63638;">
            <strong><?php _e('⚠️ Security Warning:', 'aightbot'); ?></strong>
            <?php _e('Only enable for HTTPS URLs with self-signed certificates or expired certificates (development/testing). Never use in production with external APIs.', 'aightbot'); ?>
        </p>
        <?php
    }
    
    public function render_rate_limit_requests_field() {
        $options = get_option(AIGHTBOT_OPTION_PREFIX . 'settings', []);
        $value = $options['rate_limit_requests'] ?? 20;
        ?>
        <input type="number" 
               name="aightbot_settings[rate_limit_requests]" 
               id="rate_limit_requests"
               value="<?php echo esc_attr($value); ?>" 
               class="small-text"
               min="1"
               step="1">
        <p class="description">
            <?php _e('Maximum number of requests allowed per time window. Default: 20', 'aightbot'); ?>
        </p>
        <?php
    }
    
    public function render_rate_limit_window_field() {
        $options = get_option(AIGHTBOT_OPTION_PREFIX . 'settings', []);
        $value = $options['rate_limit_window'] ?? 300;
        ?>
        <input type="number" 
               name="aightbot_settings[rate_limit_window]" 
               id="rate_limit_window"
               value="<?php echo esc_attr($value); ?>" 
               class="small-text"
               min="60"
               step="60">
        <p class="description">
            <?php _e('Time window in seconds for rate limiting. Default: 300 (5 minutes)', 'aightbot'); ?>
            <br>
            <?php _e('Users who exceed the limit will see a generic "please try again later" message without specific details.', 'aightbot'); ?>
        </p>
        <?php
    }
    
    public function render_enable_logging_field() {
        $options = get_option(AIGHTBOT_OPTION_PREFIX . 'settings', []);
        $checked = isset($options['enable_logging']) && $options['enable_logging'] === 'yes' ? 'checked' : '';
        ?>
        <label>
            <input type="checkbox" 
                   name="aightbot_settings[enable_logging]" 
                   value="yes" 
                   <?php echo $checked; ?>>
            <?php _e('Enable logging of all session conversations to files', 'aightbot'); ?>
        </label>
        <p class="description">
            <?php _e('Logs stored in /wp-content/uploads/aightbot-logs/ (one file per session)', 'aightbot'); ?>
        </p>
        <?php
    }
    
    public function render_log_retention_days_field() {
        $options = get_option(AIGHTBOT_OPTION_PREFIX . 'settings', []);
        $value = $options['log_retention_days'] ?? 30;
        ?>
        <input type="number" 
               name="aightbot_settings[log_retention_days]" 
               id="log_retention_days"
               value="<?php echo esc_attr($value); ?>" 
               class="small-text"
               min="0"
               step="1">
        <p class="description">
            <?php _e('Automatically delete session logs older than this many days. Set to 0 to never auto-delete.', 'aightbot'); ?>
        </p>
        <?php
    }
    
    public function render_max_context_messages_field() {
        $options = get_option(AIGHTBOT_OPTION_PREFIX . 'settings', []);
        $value = $options['max_context_messages'] ?? 40;
        ?>
        <input type="number" 
               name="aightbot_settings[max_context_messages]" 
               id="max_context_messages"
               value="<?php echo esc_attr($value); ?>" 
               class="small-text"
               min="1"
               step="1">
        <p class="description">
            <?php _e('Maximum number of messages to keep in context. Older messages are silently truncated.', 'aightbot'); ?>
        </p>
        <?php
    }
    
    public function render_max_context_words_field() {
        $options = get_option(AIGHTBOT_OPTION_PREFIX . 'settings', []);
        $value = $options['max_context_words'] ?? 8000;
        ?>
        <input type="number" 
               name="aightbot_settings[max_context_words]" 
               id="max_context_words"
               value="<?php echo esc_attr($value); ?>" 
               class="regular-text"
               min="10">
        <p class="description">
            <?php _e('Maximum words in context. Approximate token limit (words × 1.3 ≈ tokens). Typical: 4000-8000 words ≈ 5200-10400 tokens.', 'aightbot'); ?>
        </p>
        <?php
    }
    
    /**
     * AJAX: Test connection to LLM API
     */
    public function ajax_test_connection() {
        // Security checks
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'aightbot_admin_nonce')) {
            wp_send_json_error(__('Security check failed. Please refresh the page.', 'aightbot'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to perform this action.', 'aightbot'));
        }
        
        $settings = get_option(AIGHTBOT_OPTION_PREFIX . 'settings', []);
        
        // Validate settings
        if (empty($settings['llm_url'])) {
            wp_send_json_error(__('LLM URL is not configured. Please save your settings first.', 'aightbot'));
        }
        
        // Decrypt API key if present
        $api_key = '';
        if (!empty($settings['api_key'])) {
            try {
                $api_key = $this->encryption->decrypt($settings['api_key']);
            } catch (Exception $e) {
                wp_send_json_error(__('Failed to decrypt API key. Please re-enter your API key.', 'aightbot'));
            }
        }
        
        // Prepare test payload
        $test_message = 'Test connection - please respond with OK';
        $payload = [
            'messages' => [
                ['role' => 'user', 'content' => $test_message]
            ],
            'max_tokens' => 10,
            'temperature' => 0
        ];
        
        // Add model if specified
        if (!empty($settings['model_name'])) {
            $payload['model'] = $settings['model_name'];
        }
        
        // Merge sampler overrides (but keep our test values)
        if (!empty($settings['sampler_overrides'])) {
            $overrides = json_decode($settings['sampler_overrides'], true);
            if (is_array($overrides)) {
                $payload = array_merge($overrides, $payload);
                // Ensure test values aren't overridden
                $payload['max_tokens'] = 10;
                $payload['temperature'] = 0;
            }
        }
        
        // Prepare request
        $args = [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($payload),
            'redirection' => 0, // Don't follow redirects on POST
        ];
        
        // Only verify SSL for HTTPS URLs
        // If URL starts with http:// there's no SSL to verify
        if (strpos($settings['llm_url'], 'https://') === 0) {
            // HTTPS - verify SSL unless explicitly disabled
            $args['sslverify'] = !isset($settings['disable_ssl_verify']) || $settings['disable_ssl_verify'] !== 'yes';
        } else {
            // HTTP - no SSL verification needed
            $args['sslverify'] = false;
        }
        
        // Add API key if present
        if (!empty($api_key)) {
            $args['headers']['Authorization'] = 'Bearer ' . $api_key;
        }
        
        // Make request
        $response = wp_remote_post($settings['llm_url'], $args);
        
        // Handle errors
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            
            // Check if it's an SSL error on an HTTPS URL
            if ((strpos($error_message, 'SSL') !== false || strpos($error_message, 'certificate') !== false) 
                && strpos($settings['llm_url'], 'https://') === 0) {
                $error_message .= ' ' . __('This is an SSL certificate issue with your HTTPS endpoint. You can enable "Disable SSL Verification" in Advanced Settings for testing (not recommended for production).', 'aightbot');
            }
            
            wp_send_json_error(sprintf(
                __('Connection error: %s', 'aightbot'),
                $error_message
            ));
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($status_code !== 200) {
            $error_data = json_decode($body, true);
            $error_message = __('API returned an error.', 'aightbot');
            
            if (!empty($error_data['error']['message'])) {
                $error_message = sprintf(
                    __('API Error: %s', 'aightbot'),
                    sanitize_text_field($error_data['error']['message'])
                );
            } elseif (!empty($error_data['message'])) {
                $error_message = sprintf(
                    __('API Error: %s', 'aightbot'),
                    sanitize_text_field($error_data['message'])
                );
            } else {
                $error_message = sprintf(
                    __('HTTP %d: %s', 'aightbot'),
                    $status_code,
                    wp_remote_retrieve_response_message($response)
                );
            }
            
            wp_send_json_error($error_message);
        }
        
        // Parse response
        $data = json_decode($body, true);
        
        // Check JSON decode errors
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(sprintf(
                __('Invalid JSON response: %s', 'aightbot'),
                json_last_error_msg()
            ));
        }
        
        // Extract response content
        // Priority: content > reasoning_content > text > delta.content
        $response_text = null;
        
        if (isset($data['choices'][0]['message'])) {
            $message = $data['choices'][0]['message'];
            
            // Standard: content field (final answer for both standard and reasoning models)
            if (isset($message['content']) && !empty($message['content'])) {
                $response_text = $message['content'];
            }
            // Fallback for reasoning models that didn't generate final answer
            // (e.g., hit token limit during reasoning phase)
            elseif (isset($message['reasoning_content']) && !empty($message['reasoning_content'])) {
                $response_text = $message['reasoning_content'];
            }
            elseif (isset($message['reasoning']) && !empty($message['reasoning'])) {
                $response_text = $message['reasoning'];
            }
        }
        // Alternative formats
        elseif (isset($data['choices'][0]['text'])) {
            $response_text = $data['choices'][0]['text'];
        }
        elseif (isset($data['choices'][0]['delta']['content'])) {
            $response_text = $data['choices'][0]['delta']['content'];
        }
        
        if (empty($response_text)) {
            wp_send_json_error(__('No response content found in API response', 'aightbot'));
        }
        
        wp_send_json_success(
            sprintf(
                __('Connection successful! API responded: "%s"', 'aightbot'),
                esc_html(trim($response_text))
            )
        );
    }
    
    // ========================================
    // RAG Settings Validation
    // ========================================
    
    public function validate_rag_settings($input) {
        $validated = [];
        
        // Enable RAG
        $validated['enable_rag'] = isset($input['enable_rag']) && $input['enable_rag'] === 'yes' ? 'yes' : 'no';
        
        // Index settings
        $validated['index_posts'] = isset($input['index_posts']) && $input['index_posts'] === 'yes' ? 'yes' : 'no';
        $validated['index_pages'] = isset($input['index_pages']) && $input['index_pages'] === 'yes' ? 'yes' : 'no';
        
        // Validate custom post types
        $validated['index_custom_types'] = [];
        if (isset($input['index_custom_types']) && is_array($input['index_custom_types'])) {
            $valid_post_types = get_post_types(['public' => true], 'names');
            foreach ($input['index_custom_types'] as $post_type) {
                // Sanitize and validate against registered public post types
                $sanitized = sanitize_key($post_type);
                if (isset($valid_post_types[$sanitized]) && !in_array($sanitized, ['post', 'page', 'attachment'])) {
                    $validated['index_custom_types'][] = $sanitized;
                }
            }
        }
        
        // Content depth
        $validated['content_depth'] = in_array($input['content_depth'] ?? 'full', ['full', 'excerpt', 'title']) 
            ? $input['content_depth'] 
            : 'full';
        
        // Chunking
        $validated['enable_chunking'] = isset($input['enable_chunking']) && $input['enable_chunking'] === 'yes' ? 'yes' : 'no';
        // Add min/max validation for chunk_size (100-2000 words)
        $chunk_size = absint($input['chunk_size'] ?? 500);
        $validated['chunk_size'] = max(100, min(2000, $chunk_size));
        
        // Results count
        $validated['results_count'] = max(1, min(20, absint($input['results_count'] ?? 5)));
        
        // Min relevance
        $validated['min_relevance'] = max(0, min(1, floatval($input['min_relevance'] ?? 0.3)));
        
        // Cite sources
        $validated['cite_sources'] = isset($input['cite_sources']) && $input['cite_sources'] === 'yes' ? 'yes' : 'no';
        
        // Only indexed content
        $validated['only_indexed_content'] = isset($input['only_indexed_content']) && $input['only_indexed_content'] === 'yes' ? 'yes' : 'no';
        
        // Auto reindex
        $validated['auto_reindex'] = isset($input['auto_reindex']) && $input['auto_reindex'] === 'yes' ? 'yes' : 'no';
        
        return $validated;
    }
    
    // ========================================
    // RAG Section Render Methods
    // ========================================
    
    public function render_rag_enable_section() {
        echo '<p>' . __('Enable and configure Retrieval-Augmented Generation to let the chatbot access your website content.', 'aightbot') . '</p>';
    }
    
    public function render_rag_content_section() {
        echo '<p>' . __('Select which content to index for the chatbot.', 'aightbot') . '</p>';
    }
    
    public function render_rag_behavior_section() {
        echo '<p>' . __('Configure how the chatbot uses indexed content.', 'aightbot') . '</p>';
    }
    
    public function render_rag_management_section() {
        $indexer = new AightBot_Content_Indexer();
        $status = $indexer->get_index_status();
        
        ?>
        <div class="rag-index-status">
            <h4><?php _e('Current Index Status', 'aightbot'); ?></h4>
            <table class="widefat">
                <tr>
                    <th><?php _e('Items Indexed:', 'aightbot'); ?></th>
                    <td><strong id="index-count"><?php echo esc_html($status['count']); ?></strong></td>
                </tr>
                <tr>
                    <th><?php _e('Index Size:', 'aightbot'); ?></th>
                    <td><strong id="index-size"><?php echo esc_html($status['size_mb']); ?> MB</strong></td>
                </tr>
                <tr>
                    <th><?php _e('Last Indexed:', 'aightbot'); ?></th>
                    <td><strong id="last-indexed"><?php echo esc_html($status['last_indexed_human']); ?></strong></td>
                </tr>
            </table>
            
            <p style="margin-top: 20px;">
                <button type="button" id="reindex-content-btn" class="button button-primary">
                    <span class="dashicons dashicons-update"></span>
                    <?php _e('Reindex All Content', 'aightbot'); ?>
                </button>
                
                <button type="button" id="clear-index-btn" class="button">
                    <span class="dashicons dashicons-trash"></span>
                    <?php _e('Clear Index', 'aightbot'); ?>
                </button>
            </p>
            
            <div id="index-result" style="margin-top: 15px;"></div>
        </div>
        <?php
    }
    
    // ========================================
    // RAG Field Render Methods
    // ========================================
    
    public function render_enable_rag_field() {
        $settings = get_option(AIGHTBOT_OPTION_PREFIX . 'rag_settings', []);
        $enabled = isset($settings['enable_rag']) && $settings['enable_rag'] === 'yes';
        
        ?>
        <label>
            <input type="checkbox" 
                   name="<?php echo esc_attr(AIGHTBOT_OPTION_PREFIX . 'rag_settings[enable_rag]'); ?>" 
                   value="yes" 
                   <?php checked($enabled); ?>>
            <?php _e('Enable RAG to use site content in chatbot responses', 'aightbot'); ?>
        </label>
        <p class="description">
            <?php _e('When enabled, the chatbot will search your indexed content and use relevant information in its responses.', 'aightbot'); ?>
        </p>
        <?php
    }
    
    public function render_index_posts_field() {
        $settings = get_option(AIGHTBOT_OPTION_PREFIX . 'rag_settings', []);
        $enabled = isset($settings['index_posts']) && $settings['index_posts'] === 'yes';
        
        ?>
        <label>
            <input type="checkbox" 
                   name="<?php echo esc_attr(AIGHTBOT_OPTION_PREFIX . 'rag_settings[index_posts]'); ?>" 
                   value="yes" 
                   <?php checked($enabled); ?>>
            <?php _e('Index published posts', 'aightbot'); ?>
        </label>
        <?php
    }
    
    public function render_index_pages_field() {
        $settings = get_option(AIGHTBOT_OPTION_PREFIX . 'rag_settings', []);
        $enabled = isset($settings['index_pages']) && $settings['index_pages'] === 'yes';
        
        ?>
        <label>
            <input type="checkbox" 
                   name="<?php echo esc_attr(AIGHTBOT_OPTION_PREFIX . 'rag_settings[index_pages]'); ?>" 
                   value="yes" 
                   <?php checked($enabled); ?>>
            <?php _e('Index published pages', 'aightbot'); ?>
        </label>
        <?php
    }
    
    public function render_content_depth_field() {
        $settings = get_option(AIGHTBOT_OPTION_PREFIX . 'rag_settings', []);
        $depth = $settings['content_depth'] ?? 'full';
        
        ?>
        <select name="<?php echo esc_attr(AIGHTBOT_OPTION_PREFIX . 'rag_settings[content_depth]'); ?>">
            <option value="full" <?php selected($depth, 'full'); ?>>
                <?php _e('Full Content (best accuracy)', 'aightbot'); ?>
            </option>
            <option value="excerpt" <?php selected($depth, 'excerpt'); ?>>
                <?php _e('Excerpts/Summaries (faster, smaller)', 'aightbot'); ?>
            </option>
            <option value="title" <?php selected($depth, 'title'); ?>>
                <?php _e('Titles Only (minimal)', 'aightbot'); ?>
            </option>
        </select>
        <p class="description">
            <?php _e('How much content to index from each post/page.', 'aightbot'); ?>
        </p>
        <?php
    }
    
    public function render_enable_chunking_field() {
        $settings = get_option(AIGHTBOT_OPTION_PREFIX . 'rag_settings', []);
        $enabled = isset($settings['enable_chunking']) && $settings['enable_chunking'] === 'yes';
        $chunk_size = $settings['chunk_size'] ?? 500;
        
        ?>
        <label>
            <input type="checkbox" 
                   name="<?php echo esc_attr(AIGHTBOT_OPTION_PREFIX . 'rag_settings[enable_chunking]'); ?>" 
                   value="yes" 
                   <?php checked($enabled); ?>>
            <?php _e('Split long content into chunks', 'aightbot'); ?>
        </label>
        <p>
            <label>
                <?php _e('Chunk size (words):', 'aightbot'); ?>
                <input type="number" 
                       name="<?php echo esc_attr(AIGHTBOT_OPTION_PREFIX . 'rag_settings[chunk_size]'); ?>" 
                       value="<?php echo esc_attr($chunk_size); ?>" 
                       min="100" 
                       max="2000" 
                       step="50">
            </label>
        </p>
        <p class="description">
            <?php _e('Chunking helps with very long articles by splitting them into smaller, more focused pieces.', 'aightbot'); ?>
        </p>
        <?php
    }
    
    public function render_results_count_field() {
        $settings = get_option(AIGHTBOT_OPTION_PREFIX . 'rag_settings', []);
        $count = $settings['results_count'] ?? 5;
        
        ?>
        <input type="number" 
               name="<?php echo esc_attr(AIGHTBOT_OPTION_PREFIX . 'rag_settings[results_count]'); ?>" 
               value="<?php echo esc_attr($count); ?>" 
               min="1" 
               max="20" 
               step="1">
        <p class="description">
            <?php _e('Number of relevant content pieces to retrieve per query (1-20).', 'aightbot'); ?>
        </p>
        <?php
    }
    
    public function render_min_relevance_field() {
        $settings = get_option(AIGHTBOT_OPTION_PREFIX . 'rag_settings', []);
        $min_rel = $settings['min_relevance'] ?? 0.3;
        
        ?>
        <input type="number" 
               name="<?php echo esc_attr(AIGHTBOT_OPTION_PREFIX . 'rag_settings[min_relevance]'); ?>" 
               value="<?php echo esc_attr($min_rel); ?>" 
               min="0" 
               max="1" 
               step="0.1">
        <p class="description">
            <?php _e('Minimum relevance score (0-1). Higher = more strict matching.', 'aightbot'); ?>
        </p>
        <?php
    }
    
    public function render_cite_sources_field() {
        $settings = get_option(AIGHTBOT_OPTION_PREFIX . 'rag_settings', []);
        $enabled = isset($settings['cite_sources']) && $settings['cite_sources'] === 'yes';
        
        ?>
        <label>
            <input type="checkbox" 
                   name="<?php echo esc_attr(AIGHTBOT_OPTION_PREFIX . 'rag_settings[cite_sources]'); ?>" 
                   value="yes" 
                   <?php checked($enabled); ?>>
            <?php _e('Include source URLs when chatbot uses indexed content', 'aightbot'); ?>
        </label>
        <?php
    }
    
    public function render_only_indexed_content_field() {
        $settings = get_option(AIGHTBOT_OPTION_PREFIX . 'rag_settings', []);
        $enabled = isset($settings['only_indexed_content']) && $settings['only_indexed_content'] === 'yes';
        
        ?>
        <label>
            <input type="checkbox" 
                   name="<?php echo esc_attr(AIGHTBOT_OPTION_PREFIX . 'rag_settings[only_indexed_content]'); ?>" 
                   value="yes" 
                   <?php checked($enabled); ?>>
            <?php _e('Restrict chatbot to only use indexed content (no general knowledge)', 'aightbot'); ?>
        </label>
        <p class="description">
            <?php _e('When enabled, the chatbot will only answer questions based on your website content.', 'aightbot'); ?>
        </p>
        <?php
    }
}
