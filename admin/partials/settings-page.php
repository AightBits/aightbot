<?php
/**
 * Admin Settings Page Template
 */
if (!defined('ABSPATH')) {
    exit;
}

$settings = get_option(AIGHTBOT_OPTION_PREFIX . 'settings', []);
$is_configured = !empty($settings['llm_url']);
$active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general';
?>
<div class="wrap aightbot-settings">
    <h1>
        <?php echo esc_html(get_admin_page_title()); ?>
        <?php if ($is_configured && $active_tab === 'general'): ?>
            <span class="page-title-action">
                <button type="button" id="test-connection-btn" class="button">
                    <span class="dashicons dashicons-networking"></span>
                    <?php _e('Test Connection', 'aightbot'); ?>
                </button>
            </span>
        <?php endif; ?>
    </h1>
    
    <h2 class="nav-tab-wrapper">
        <a href="?page=aightbot-settings&tab=general" 
           class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>">
            <?php _e('General Settings', 'aightbot'); ?>
        </a>
        <a href="?page=aightbot-settings&tab=rag" 
           class="nav-tab <?php echo $active_tab === 'rag' ? 'nav-tab-active' : ''; ?>">
            <?php _e('RAG Settings', 'aightbot'); ?>
        </a>
    </h2>
    
    <?php if (!$is_configured && $active_tab === 'general'): ?>
        <div class="notice notice-info">
            <p>
                <strong><?php _e('Welcome to AightBot!', 'aightbot'); ?></strong>
                <?php _e('Please configure your LLM API connection below to get started.', 'aightbot'); ?>
            </p>
        </div>
    <?php endif; ?>
    
    <?php settings_errors(); ?>
    
    <div id="test-result"></div>
    
    <?php if ($active_tab === 'general'): ?>
        <form method="post" action="options.php" id="aightbot-settings-form">
            <?php 
            settings_fields('aightbot_settings_group');
            do_settings_sections('aightbot-settings');
            submit_button(__('Save Settings', 'aightbot')); 
            ?>
        </form>
        
        <div class="aightbot-help">
            <h2><?php _e('Need Help?', 'aightbot'); ?></h2>
            <ul>
                <li>
                    <strong><?php _e('LLM API URL:', 'aightbot'); ?></strong>
                    <?php _e('Must be an OpenAI-compatible endpoint that accepts chat completion requests.', 'aightbot'); ?>
                </li>
                <li>
                    <strong><?php _e('API Key:', 'aightbot'); ?></strong>
                    <?php _e('Only required if your API endpoint requires authentication. The key is encrypted before storage.', 'aightbot'); ?>
                </li>
                <li>
                    <strong><?php _e('Sampler Parameters:', 'aightbot'); ?></strong>
                    <?php _e('Common parameters include: temperature (0-2), max_tokens, top_p (0-1), frequency_penalty (-2 to 2), presence_penalty (-2 to 2).', 'aightbot'); ?>
                </li>
            </ul>
        </div>
    <?php else: ?>
        <form method="post" action="options.php" id="aightbot-rag-settings-form">
            <?php 
            settings_fields('aightbot_rag_settings_group');
            do_settings_sections('aightbot-settings_rag');
            submit_button(__('Save RAG Settings', 'aightbot')); 
            ?>
        </form>
        
        <div class="aightbot-help">
            <h2><?php _e('About RAG', 'aightbot'); ?></h2>
            <p><?php _e('Retrieval-Augmented Generation (RAG) allows the chatbot to access and reference your website content when answering questions.', 'aightbot'); ?></p>
            <ul>
                <li><strong><?php _e('How it works:', 'aightbot'); ?></strong> <?php _e('The plugin indexes your public content, then searches it when users ask questions.', 'aightbot'); ?></li>
                <li><strong><?php _e('Privacy:', 'aightbot'); ?></strong> <?php _e('Only published, non-password-protected content is indexed.', 'aightbot'); ?></li>
                <li><strong><?php _e('Performance:', 'aightbot'); ?></strong> <?php _e('Uses MySQL FULLTEXT search for fast, efficient content retrieval.', 'aightbot'); ?></li>
            </ul>
        </div>
    <?php endif; ?>
</div>
