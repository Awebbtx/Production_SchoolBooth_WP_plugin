<?php
class SCHOOLBOOTH_Admin_Notice {
    public static function init() {
        add_action('admin_notices', [self::class, 'show_secret_notice']);
    }

    public static function show_secret_notice() {
        if (!current_user_can('manage_options')) return;
        
        if (defined('SCHOOLBOOTH_SHARED_SECRET')) {
            $secret = SCHOOLBOOTH_SHARED_SECRET;
            $partial_secret = substr($secret, 0, 4) . str_repeat('*', strlen($secret) - 4);
            
            echo '<div class="notice notice-info">';
            echo '<p><strong>Schoolbooth Photo Manager</strong></p>';
            echo '<p>Current Secret: <code>' . esc_html($partial_secret) . '</code></p>';
            echo '<p>Ensure this matches the secret in your schoolbooth application.</p>';
            echo '</div>';
        }
    }
}

