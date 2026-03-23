<?php
/**
 * Plugin Name: Schoolbooth Photo Manager
 * Description: Secure photo downloads with access codes and portal interface
 * Version: 3.2.1
 * Author: IKAP Systems
 * Text Domain: schoolbooth
 */

defined('ABSPATH') or die('No direct access allowed!');

// Security Configuration
if (!defined('SCHOOLBOOTH_SHARED_SECRET')) {
    $settings = function_exists('get_option') ? get_option('schoolbooth_settings') : [];
    $generated_secret = function_exists('wp_generate_password')
        ? wp_generate_password(32, true, true)
        : bin2hex(random_bytes(24));
    $secret = isset($settings['shared_secret'])
        ? $settings['shared_secret']
        : $generated_secret;
    define('SCHOOLBOOTH_SHARED_SECRET', $secret);
}

if (!function_exists('schoolbooth_normalize_access_code')) {
    function schoolbooth_normalize_access_code($code) {
        $code = strtoupper((string) $code);
        return preg_replace('/[^A-Z0-9]/', '', $code);
    }
}

// Production safety: rely on WordPress defaults for application password availability.

// Plugin Setup
define('SCHOOLBOOTH_DOWNLOAD_VERSION', '3.2.1');
define('SCHOOLBOOTH_DOWNLOAD_PATH', plugin_dir_path(__FILE__));
define('SCHOOLBOOTH_DOWNLOAD_URL', plugin_dir_url(__FILE__));

register_activation_hook(__FILE__, function() {
    $settings = get_option('schoolbooth_settings', []);
    if (!is_array($settings)) {
        $settings = [];
    }

    $activation_issues = [];
    $secret = isset($settings['shared_secret']) ? (string) $settings['shared_secret'] : (string) SCHOOLBOOTH_SHARED_SECRET;

    if (strlen($secret) < 32) {
        $settings['shared_secret'] = wp_generate_password(64, true, true);
        update_option('schoolbooth_settings', $settings, false);
        $activation_issues[] = __('Shared secret was too short and has been regenerated automatically. Re-enroll client devices after activation.', 'schoolbooth');
    }
    
    $upload_dir = wp_upload_dir();
    $schoolbooth_dir = $upload_dir['basedir'] . '/schoolbooth';
    $photos_dir = $schoolbooth_dir . '/photos';
    $data_dir = $schoolbooth_dir . '/data';
    
    wp_mkdir_p($photos_dir);
    wp_mkdir_p($data_dir);

    if (!is_dir($photos_dir) || !is_dir($data_dir)) {
        $activation_issues[] = __('Could not create one or more upload directories. Check WordPress upload permissions.', 'schoolbooth');
    }

    if (@file_put_contents($data_dir . '/.htaccess', 'Deny from all', LOCK_EX) === false) {
        $activation_issues[] = __('Could not write data directory protection file. This can happen on non-Apache hosts.', 'schoolbooth');
    }
    
    if (@file_put_contents($schoolbooth_dir . '/.htaccess', 
        "<FilesMatch \"\.(jpg|jpeg|png|gif)$\">\n" .
        "   Order Allow,Deny\n" .
        "   Allow from all\n" .
        "</FilesMatch>\n" .
        "Deny from all",
        LOCK_EX
    ) === false) {
        $activation_issues[] = __('Could not write upload directory access rules file. This can happen on non-Apache hosts.', 'schoolbooth');
    }

    if (!empty($activation_issues)) {
        set_transient('schoolbooth_activation_issues', $activation_issues, 5 * MINUTE_IN_SECONDS);
    }
});

add_action('admin_notices', function() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $activation_issues = get_transient('schoolbooth_activation_issues');
    if (!is_array($activation_issues) || empty($activation_issues)) {
        return;
    }

    delete_transient('schoolbooth_activation_issues');

    echo '<div class="notice notice-warning"><p><strong>' . esc_html__('Schoolbooth activation completed with warnings:', 'schoolbooth') . '</strong></p><ul style="margin-left:20px;list-style:disc;">';
    foreach ($activation_issues as $issue) {
        echo '<li>' . esc_html($issue) . '</li>';
    }
    echo '</ul></div>';
});

// Include all plugin classes
require_once SCHOOLBOOTH_DOWNLOAD_PATH . 'includes/class-audit-logger.php';
require_once SCHOOLBOOTH_DOWNLOAD_PATH . 'includes/class-rate-limiter.php';
require_once SCHOOLBOOTH_DOWNLOAD_PATH . 'includes/class-secure-file-deleter.php';
require_once SCHOOLBOOTH_DOWNLOAD_PATH . 'includes/class-permissions-form-handler.php';
require_once SCHOOLBOOTH_DOWNLOAD_PATH . 'includes/class-upload-api.php';
require_once SCHOOLBOOTH_DOWNLOAD_PATH . 'includes/class-download-handler.php';
require_once SCHOOLBOOTH_DOWNLOAD_PATH . 'includes/class-admin-settings.php';
require_once SCHOOLBOOTH_DOWNLOAD_PATH . 'includes/class-admin-audit-viewer.php';
require_once SCHOOLBOOTH_DOWNLOAD_PATH . 'includes/class-shortcode-handler.php';

add_action('init', function() {
    load_plugin_textdomain(
        'schoolbooth',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages/'
    );
    
    // Initialize security systems first
    SCHOOLBOOTH_Audit_Logger::init();
    SCHOOLBOOTH_Rate_Limiter::init();
    SCHOOLBOOTH_Secure_File_Deleter::init();
    SCHOOLBOOTH_Permissions_Form_Handler::init();
    SCHOOLBOOTH_Upload_API::init();
    
    // Then initialize main plugin functionality
    SCHOOLBOOTH_Download_Handler::init();
    SCHOOLBOOTH_Download_Admin_Settings::init();
    SCHOOLBOOTH_Admin_Audit_Viewer::init();
    SCHOOLBOOTH_Download_Shortcode_Handler::init();
});

add_action('wp_ajax_schoolbooth_delete_photo', 'schoolbooth_handle_photo_deletion');

function schoolbooth_handle_photo_deletion() {
    if (!is_user_logged_in() || !current_user_can('manage_options')) {
        wp_send_json_error(__('You are not authorized to delete photos', 'schoolbooth'), 403);
    }

    check_ajax_referer('schoolbooth_ajax', 'security');
    
    $file = sanitize_text_field(isset($_POST['file']) ? $_POST['file'] : '');
    $code = schoolbooth_normalize_access_code(sanitize_text_field(isset($_POST['code']) ? $_POST['code'] : ''));
    $delete_token = sanitize_text_field(isset($_POST['delete_token']) ? $_POST['delete_token'] : '');
    $delete_expires = absint(isset($_POST['delete_expires']) ? $_POST['delete_expires'] : 0);
    
    $handler = SCHOOLBOOTH_Download_Handler::init();
    $result = $handler->delete_photo($file, $code, $delete_token, $delete_expires);
    
    if ($result) {
        wp_send_json_success();
    } else {
        wp_send_json_error(__('Failed to delete photo', 'schoolbooth'));
    }
}


