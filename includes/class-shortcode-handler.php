<?php
class PTASB_Download_Shortcode_Handler {
    public static function init() {
        add_shortcode('pta_schoolbooth_download_portal', [self::class, 'download_portal_shortcode']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_assets']);
    }
    
    public static function enqueue_assets() {
        wp_enqueue_style(
            'pta-schoolbooth-style',
            PTASB_DOWNLOAD_URL . 'assets/css/style.css',
            [],
            PTASB_DOWNLOAD_VERSION
        );

        wp_enqueue_style(
            'pta-schoolbooth-permissions-style',
            PTASB_DOWNLOAD_URL . 'assets/css/permissions-form.css',
            ['pta-schoolbooth-style'],
            PTASB_DOWNLOAD_VERSION
        );
        
        wp_enqueue_script(
            'pta-schoolbooth-script',
            PTASB_DOWNLOAD_URL . 'assets/js/script.js',
            ['jquery'],
            PTASB_DOWNLOAD_VERSION,
            true
        );
        
        // Enqueue permissions form JavaScript
        wp_enqueue_script(
            'pta-schoolbooth-permissions-form-js',
            PTASB_DOWNLOAD_URL . 'assets/js/permissions-form.js',
            ['jquery'],
            PTASB_DOWNLOAD_VERSION,
            true
        );
        
        // Localize permissions form script with AJAX URL
        wp_localize_script('pta-schoolbooth-permissions-form-js', 'ptasb_permissions_vars', [
            'ajax_url' => admin_url('admin-ajax.php'),
        ]);
    }
    
    public static function download_portal_shortcode($atts) {
        ob_start();
        include PTASB_DOWNLOAD_PATH . 'templates/download-portal.php';
        return ob_get_clean();
    }
}
