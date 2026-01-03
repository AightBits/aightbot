<?php
/**
 * Plugin Name: AightBot
 * Plugin URI: https://ziegler.us
 * Description: Customizable AI chatbot for WordPress with RAG support
 * Version: 0.5.0
 * Author: Ziegler Technical Solutions
 * Text Domain: aightbot
 * Domain Path: /languages
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('AIGHTBOT_VERSION', '0.5.0');
define('AIGHTBOT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AIGHTBOT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('AIGHTBOT_OPTION_PREFIX', 'aightbot_');

// Check for required PHP extensions
if (!extension_loaded('openssl')) {
    add_action('admin_notices', function() {
        ?>
        <div class="notice notice-error is-dismissible">
            <p><?php _e('AightBot requires the OpenSSL PHP extension. Please enable it to use the plugin.', 'aightbot'); ?></p>
        </div>
        <?php
    });
}

// Autoloader for classes
spl_autoload_register(function ($class_name) {
    $prefix = 'AightBot_';
    $base_dir = AIGHTBOT_PLUGIN_DIR . 'includes/';
    
    if (strpos($class_name, $prefix) !== 0) {
        return;
    }
    
    $relative_class = substr($class_name, strlen($prefix));
    $file = $base_dir . 'class-' . strtolower(str_replace('_', '-', $relative_class)) . '.php';
    
    if (file_exists($file)) {
        require_once $file;
    }
});

/**
 * Main plugin class
 */
class AightBot_Plugin {
    
    private static $instance = null;
    private $admin_settings;
    private $encryption;
    private $api_handler;
    private $session_manager;
    private $frontend_widget;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init_hooks();
    }
    
    private function init_hooks() {
        // Activation/Deactivation hooks
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        
        // Initialize components
        add_action('plugins_loaded', [$this, 'init_components']);
        
        // Load text domain
        add_action('init', [$this, 'load_textdomain']);
    }
    
    public function activate() {
        require_once AIGHTBOT_PLUGIN_DIR . 'includes/class-install.php';
        AightBot_Install::activate();
    }
    
    public function deactivate() {
        require_once AIGHTBOT_PLUGIN_DIR . 'includes/class-install.php';
        AightBot_Install::deactivate();
    }
    
    public function load_textdomain() {
        load_plugin_textdomain(
            'aightbot',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages/'
        );
    }
    
    public function init_components() {
        // Initialize encryption first
        $this->encryption = new AightBot_Encryption();
        
        // Initialize admin settings (always needed in admin)
        if (is_admin()) {
            $this->admin_settings = new AightBot_Admin_Settings($this->encryption);
            
            // Initialize content indexer for admin
            new AightBot_Content_Indexer();
        }
        
        // Only initialize frontend if enabled
        $settings = get_option(AIGHTBOT_OPTION_PREFIX . 'settings', []);
        if (!empty($settings['enabled']) && $settings['enabled'] === 'yes') {
            $this->api_handler = new AightBot_API_Handler($this->encryption);
            $this->session_manager = new AightBot_Session_Manager();
            $this->frontend_widget = new AightBot_Frontend_Widget();
        }
    }
}

// Initialize the plugin
AightBot_Plugin::get_instance();
