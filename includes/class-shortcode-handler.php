<?php
class SCHOOLBOOTH_Download_Shortcode_Handler {
    public static function init() {
        add_shortcode('schoolbooth_download_portal', [self::class, 'download_portal_shortcode']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_assets']);
    }
    
    public static function enqueue_assets() {
        wp_enqueue_style(
            'schoolbooth-style',
            SCHOOLBOOTH_DOWNLOAD_URL . 'assets/css/style.css',
            [],
            SCHOOLBOOTH_DOWNLOAD_VERSION
        );

        wp_enqueue_style(
            'schoolbooth-permissions-style',
            SCHOOLBOOTH_DOWNLOAD_URL . 'assets/css/permissions-form.css',
            ['schoolbooth-style'],
            SCHOOLBOOTH_DOWNLOAD_VERSION
        );
        
        wp_enqueue_script(
            'schoolbooth-script',
            SCHOOLBOOTH_DOWNLOAD_URL . 'assets/js/script.js',
            ['jquery'],
            SCHOOLBOOTH_DOWNLOAD_VERSION,
            true
        );
        
        // Enqueue permissions form JavaScript
        wp_enqueue_script(
            'schoolbooth-permissions-form-js',
            SCHOOLBOOTH_DOWNLOAD_URL . 'assets/js/permissions-form.js',
            ['jquery'],
            SCHOOLBOOTH_DOWNLOAD_VERSION,
            true
        );
        
        // Localize permissions form script with AJAX URL
        wp_localize_script('schoolbooth-permissions-form-js', 'schoolbooth_permissions_vars', [
            'ajax_url' => admin_url('admin-ajax.php'),
        ]);
    }
    
    public static function download_portal_shortcode($atts) {
        ob_start();
        include SCHOOLBOOTH_DOWNLOAD_PATH . 'templates/download-portal.php';
        return ob_get_clean();
    }
}


