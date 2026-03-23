<?php
/**
 * Plugin Name: Schoolbooth Photo Manager
 * Description: Secure photo downloads with access codes and portal interface
 * Version: 3.2.0
 * Author: IKAP Systems
 * Text Domain: pta-schoolbooth
 */

defined('ABSPATH') or die('No direct access allowed!');

// Security Configuration
if (!defined('PTASB_SHARED_SECRET')) {
    $settings = function_exists('get_option') ? get_option('pta_schoolbooth_settings') : [];
    $generated_secret = function_exists('wp_generate_password')
        ? wp_generate_password(32, true, true)
        : bin2hex(random_bytes(24));
    $secret = isset($settings['shared_secret'])
        ? $settings['shared_secret']
        : $generated_secret;
    define('PTASB_SHARED_SECRET', $secret);
}

if (!function_exists('ptasb_normalize_access_code')) {
    function ptasb_normalize_access_code($code) {
        $code = strtoupper((string) $code);
        return preg_replace('/[^A-Z0-9]/', '', $code);
    }
}

// Production safety: rely on WordPress defaults for application password availability.

// Plugin Setup
define('PTASB_DOWNLOAD_VERSION', '3.2.0');
define('PTASB_DOWNLOAD_PATH', plugin_dir_path(__FILE__));
define('PTASB_DOWNLOAD_URL', plugin_dir_url(__FILE__));

register_activation_hook(__FILE__, function() {
    $settings = get_option('pta_schoolbooth_settings');
    $secret = isset($settings['shared_secret']) ? $settings['shared_secret'] : PTASB_SHARED_SECRET;
    
    if (strlen($secret) < 32) {
        wp_die(
            '<strong>Security Error:</strong> Shared Secret must be at least 32 characters.<br>'
            . 'Please set a strong secret in the plugin settings before activation.'
        );
    }
    
    $upload_dir = wp_upload_dir();
    $ptasb_dir = $upload_dir['basedir'] . '/pta-schoolbooth';
    $photos_dir = $ptasb_dir . '/photos';
    $data_dir = $ptasb_dir . '/data';
    
    wp_mkdir_p($photos_dir);
    wp_mkdir_p($data_dir);

    if (!is_dir($photos_dir) || !is_dir($data_dir)) {
        wp_die(
            '<strong>Activation Error:</strong> Unable to create required upload directories.<br>'
            . 'Please verify WordPress upload permissions and try again.'
        );
    }

    if (@file_put_contents($data_dir . '/.htaccess', 'Deny from all', LOCK_EX) === false) {
        wp_die(
            '<strong>Activation Error:</strong> Unable to write security file for access code storage.<br>'
            . 'Please verify WordPress upload permissions and try again.'
        );
    }
    
    if (@file_put_contents($ptasb_dir . '/.htaccess', 
        "<FilesMatch \"\.(jpg|jpeg|png|gif)$\">\n" .
        "   Order Allow,Deny\n" .
        "   Allow from all\n" .
        "</FilesMatch>\n" .
        "Deny from all",
        LOCK_EX
    ) === false) {
        wp_die(
            '<strong>Activation Error:</strong> Unable to write upload directory access rules.<br>'
            . 'Please verify WordPress upload permissions and try again.'
        );
    }
});

// Include all plugin classes
require_once PTASB_DOWNLOAD_PATH . 'includes/class-audit-logger.php';
require_once PTASB_DOWNLOAD_PATH . 'includes/class-rate-limiter.php';
require_once PTASB_DOWNLOAD_PATH . 'includes/class-secure-file-deleter.php';
require_once PTASB_DOWNLOAD_PATH . 'includes/class-permissions-form-handler.php';
require_once PTASB_DOWNLOAD_PATH . 'includes/class-upload-api.php';
require_once PTASB_DOWNLOAD_PATH . 'includes/class-download-handler.php';
require_once PTASB_DOWNLOAD_PATH . 'includes/class-admin-settings.php';
require_once PTASB_DOWNLOAD_PATH . 'includes/class-admin-audit-viewer.php';
require_once PTASB_DOWNLOAD_PATH . 'includes/class-shortcode-handler.php';

add_action('init', function() {
    load_plugin_textdomain(
        'pta-schoolbooth',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages/'
    );
    
    // Initialize security systems first
    PTASB_Audit_Logger::init();
    PTASB_Rate_Limiter::init();
    PTASB_Secure_File_Deleter::init();
    PTASB_Permissions_Form_Handler::init();
    PTASB_Upload_API::init();
    
    // Then initialize main plugin functionality
    PTASB_Download_Handler::init();
    PTASB_Download_Admin_Settings::init();
    PTASB_Admin_Audit_Viewer::init();
    PTASB_Download_Shortcode_Handler::init();
});

add_action('wp_ajax_ptasb_delete_photo', 'ptasb_handle_photo_deletion');

function ptasb_handle_photo_deletion() {
    if (!is_user_logged_in() || !current_user_can('manage_options')) {
        wp_send_json_error(__('You are not authorized to delete photos', 'pta-schoolbooth'), 403);
    }

    check_ajax_referer('ptasb_ajax', 'security');
    
    $file = sanitize_text_field(isset($_POST['file']) ? $_POST['file'] : '');
    $code = ptasb_normalize_access_code(sanitize_text_field(isset($_POST['code']) ? $_POST['code'] : ''));
    $delete_token = sanitize_text_field(isset($_POST['delete_token']) ? $_POST['delete_token'] : '');
    $delete_expires = absint(isset($_POST['delete_expires']) ? $_POST['delete_expires'] : 0);
    
    $handler = PTASB_Download_Handler::init();
    $result = $handler->delete_photo($file, $code, $delete_token, $delete_expires);
    
    if ($result) {
        wp_send_json_success();
    } else {
        wp_send_json_error(__('Failed to delete photo', 'pta-schoolbooth'));
    }
}
