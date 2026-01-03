<?php
/**
 * Uninstall Script
 * 
 * Fired when the plugin is uninstalled.
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Define plugin constants if not already defined
if (!defined('AIGHTBOT_OPTION_PREFIX')) {
    define('AIGHTBOT_OPTION_PREFIX', 'aightbot_');
}

// Load the Install class
require_once plugin_dir_path(__FILE__) . 'includes/class-install.php';

// Run uninstall
AightBot_Install::uninstall();
