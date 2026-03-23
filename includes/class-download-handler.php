<?php
class SCHOOLBOOTH_Download_Handler {
    private static $instance;
    protected $options;
    
    public static function init() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->options = get_option('schoolbooth_settings');
        add_action('template_redirect', [$this, 'handle_download']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
    }
    
    public function enqueue_scripts() {
        wp_enqueue_style(
            'schoolbooth-permissions-style',
            SCHOOLBOOTH_DOWNLOAD_URL . 'assets/css/permissions-form.css',
            [],
            SCHOOLBOOTH_DOWNLOAD_VERSION
        );

        wp_enqueue_script(
            'schoolbooth-js',
            SCHOOLBOOTH_DOWNLOAD_URL . 'assets/js/script.js',
            ['jquery'],
            SCHOOLBOOTH_DOWNLOAD_VERSION,
            true
        );

        wp_enqueue_script(
            'schoolbooth-permissions-js',
            SCHOOLBOOTH_DOWNLOAD_URL . 'assets/js/permissions-form.js',
            ['jquery'],
            SCHOOLBOOTH_DOWNLOAD_VERSION,
            true
        );
        
        wp_localize_script('schoolbooth-js', 'schoolbooth_vars', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('schoolbooth_ajax'),
            'delete_confirm' => __('Are you sure you want to delete this photo?', 'schoolbooth'),
            'no_photos' => __('No photos available', 'schoolbooth'),
            'delete_error' => __('Failed to delete photo', 'schoolbooth')
        ]);

        wp_localize_script('schoolbooth-permissions-js', 'schoolbooth_form_vars', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'processing' => __('Processing...', 'schoolbooth'),
            'thanks' => __('Thank you! Your consent has been recorded.', 'schoolbooth'),
            'redirecting' => __('Redirecting to your photos...', 'schoolbooth'),
            'generic_error' => __('An error occurred while processing your form. Please try again.', 'schoolbooth'),
            'timeout_error' => __('Request timed out. Please try again.', 'schoolbooth'),
            'rate_limit_error' => __('Too many attempts. Please try again in one hour.', 'schoolbooth'),
            'first_name_error' => __('First name is required and must be at least 2 characters', 'schoolbooth'),
            'last_name_error' => __('Last name is required and must be at least 2 characters', 'schoolbooth'),
            'email_error' => __('A valid email address is required', 'schoolbooth'),
            'consent_error' => __('You must agree to the release form', 'schoolbooth'),
            'submit_error' => __('Failed to submit form. Please try again.', 'schoolbooth')
        ]);
    }
    
    public function handle_download() {
        if (!isset($_GET['schoolbooth_download']) || !isset($_GET['code']) || !isset($_GET['hash'])) {
            return;
        }

        $file = sanitize_text_field($_GET['schoolbooth_download']);
        $code = schoolbooth_normalize_access_code(sanitize_text_field($_GET['code']));
        $received_hash = sanitize_text_field($_GET['hash']);
        $is_app_view = $this->is_app_view_request($file, $code);

        if (!$this->validate_hash($file, $code, $received_hash)) {
            // Log failed validation attempt
            $audit = SCHOOLBOOTH_Audit_Logger::init();
            $audit->log_event('download_attempt', [
                'file'     => $file,
                'code'     => $code,
                'success'  => false,
                'reason'   => 'invalid_hash',
            ]);
            
            wp_die(__('Invalid security token', 'schoolbooth'), 403);
        }
        
        // Check rate limiting on access code
        $rate_limiter = SCHOOLBOOTH_Rate_Limiter::init();
        $rate_check = $rate_limiter->check_access_code_attempt($code);
        if (is_wp_error($rate_check)) {
            $audit = SCHOOLBOOTH_Audit_Logger::init();
            $audit->log_event('download_attempt', [
                'file'     => $file,
                'code'     => $code,
                'success'  => false,
                'reason'   => 'rate_limited',
            ]);
            
            wp_die(__('Too many failed attempts. Please try again later.', 'schoolbooth'), 429);
        }

        // Check if permissions form was completed
        if (!$is_app_view && !SCHOOLBOOTH_Permissions_Form_Handler::has_completed_form($code)) {
            $audit = SCHOOLBOOTH_Audit_Logger::init();
            $audit->log_event('download_attempt', [
                'file'     => $file,
                'code'     => $code,
                'success'  => false,
                'reason'   => 'form_not_completed',
            ]);
            
            wp_die(__('You must complete the permissions form before downloading.', 'schoolbooth'), 403);
        }

        if (isset($_GET['force_download'])) {
            $this->process_download($file, $code);
        } elseif ($is_app_view) {
            if (isset($_GET['schoolbooth_preview_asset'])) {
                $this->process_download($file, $code, false);
            }

            $this->render_app_preview($file, $code, $received_hash);
        } else {
            $this->redirect_to_portal($file, $code);
        }
    }
    
    protected function validate_hash($file, $code, $received_hash) {
        if (!defined('SCHOOLBOOTH_SHARED_SECRET')) {
            return false;
        }

        $expected_hash = hash_hmac('sha256', $file . '|' . $code, SCHOOLBOOTH_SHARED_SECRET);
        return hash_equals($expected_hash, $received_hash);
    }

    protected function normalize_requested_file($file) {
        $file = wp_normalize_path((string) $file);
        $file = ltrim($file, '/');

        if ($file === '' || strpos($file, "\0") !== false) {
            return '';
        }

        if (preg_match('#(^|/)\.\.(/|$)#', $file)) {
            return '';
        }

        return $file;
    }

    protected function get_photos_root() {
        $upload_dir = wp_upload_dir();
        $base_path = isset($this->options['upload_path']) ? $this->options['upload_path'] : 'schoolbooth';
        return wp_normalize_path(path_join($upload_dir['basedir'], $base_path . '/photos'));
    }

    protected function resolve_photo_path($file) {
        $normalized_file = $this->normalize_requested_file($file);
        if ($normalized_file === '') {
            return '';
        }

        $photos_root = $this->get_photos_root();
        $candidate_path = wp_normalize_path(path_join($photos_root, $normalized_file));
        $real_root = realpath($photos_root);
        $real_candidate = realpath($candidate_path);

        if ($real_root === false || $real_candidate === false) {
            return '';
        }

        $real_root = wp_normalize_path($real_root);
        $real_candidate = wp_normalize_path($real_candidate);

        if ($real_candidate !== $real_root && strpos($real_candidate, $real_root . '/') !== 0) {
            return '';
        }

        return $real_candidate;
    }

    protected function get_codes_file_path() {
        $upload_dir = wp_upload_dir();
        $base_path = isset($this->options['upload_path']) ? $this->options['upload_path'] : 'schoolbooth';
        return path_join($upload_dir['basedir'], $base_path . '/access_codes.json');
    }

    protected function load_codes() {
        $codes_file = $this->get_codes_file_path();
        if (!file_exists($codes_file)) {
            return [];
        }

        $codes = json_decode(file_get_contents($codes_file), true);
        return is_array($codes) ? $codes : [];
    }

    protected function is_record_expired($record) {
        $created_date = isset($record['created']) ? $record['created'] : '';
        if (empty($created_date)) {
            return true;
        }

        try {
            $expiry_days = (int) (isset($this->options['expiry_days']) ? $this->options['expiry_days'] : 7);
            $created = new DateTime($created_date);
            $expires = $created->add(new DateInterval("P{$expiry_days}D"));
            $now = new DateTime();
            return $now >= $expires;
        } catch (Exception $e) {
            return true;
        }
    }

    protected function generate_signature($file, $code) {
        return hash_hmac('sha256', $file . '|' . $code, SCHOOLBOOTH_SHARED_SECRET);
    }

    protected function generate_app_view_signature($file, $code, $timestamp) {
        return hash_hmac('sha256', $timestamp . '|' . $file . '|' . $code . '|app-view', SCHOOLBOOTH_SHARED_SECRET);
    }

    protected function is_app_timestamp_valid($timestamp) {
        if (!ctype_digit((string) $timestamp)) {
            return false;
        }

        return abs(time() - (int) $timestamp) <= 900;
    }

    protected function is_app_view_request($file, $code) {
        if (!isset($_GET['schoolbooth_app'], $_GET['schoolbooth_ts'], $_GET['schoolbooth_sig'])) {
            return false;
        }

        if (sanitize_text_field($_GET['schoolbooth_app']) !== '1') {
            return false;
        }

        $timestamp = sanitize_text_field($_GET['schoolbooth_ts']);
        $received_signature = sanitize_text_field($_GET['schoolbooth_sig']);

        if (!$this->is_app_timestamp_valid($timestamp)) {
            return false;
        }

        $expected_signature = $this->generate_app_view_signature($file, $code, $timestamp);
        return hash_equals($expected_signature, $received_signature);
    }

    protected function generate_delete_token($file, $code, $expires_at) {
        return hash_hmac('sha256', $file . '|' . $code . '|' . (string) $expires_at, SCHOOLBOOTH_SHARED_SECRET);
    }

    protected function verify_delete_token($file, $code, $expires_at, $received_token) {
        $expires_at = (int) $expires_at;
        if ($expires_at <= time()) {
            return false;
        }

        $expected_token = $this->generate_delete_token($file, $code, $expires_at);
        return hash_equals($expected_token, (string) $received_token);
    }
    
    protected function process_download($file, $code, $as_attachment = true) {
        $file = $this->normalize_requested_file($file);
        if ($file === '') {
            wp_die(__('Invalid file path', 'schoolbooth'), 400);
        }

        $codes = $this->load_codes();
        $stored_code = isset($codes[$file]['code']) ? schoolbooth_normalize_access_code($codes[$file]['code']) : '';
        if (!isset($codes[$file]) || $stored_code !== $code) {
            $audit = SCHOOLBOOTH_Audit_Logger::init();
            $audit->log_event('download_attempt', [
                'file'     => $file,
                'code'     => $code,
                'success'  => false,
                'reason'   => 'invalid_code',
            ]);
            
            wp_die(__('Invalid access code', 'schoolbooth'), 403);
        }

        $record = $codes[$file];
        if ($this->is_record_expired($record)) {
            $audit = SCHOOLBOOTH_Audit_Logger::init();
            $audit->log_event('download_attempt', [
                'file'     => $file,
                'code'     => $code,
                'success'  => false,
                'reason'   => 'expired',
            ]);
            
            wp_die(__('This download link has expired', 'schoolbooth'), 410);
        }

        $download_limit = (int) (isset($this->options['download_limit']) ? $this->options['download_limit'] : 3);
        $downloads = (int) (isset($record['downloads']) ? $record['downloads'] : 0);
        if ($downloads >= $download_limit) {
            $audit = SCHOOLBOOTH_Audit_Logger::init();
            $audit->log_event('download_attempt', [
                'file'     => $file,
                'code'     => $code,
                'success'  => false,
                'reason'   => 'download_limit_exceeded',
            ]);
            
            wp_die(__('Download limit reached', 'schoolbooth'), 403);
        }

        $file_path = $this->resolve_photo_path($file);
        if ($file_path === '' || !is_file($file_path)) {
            $audit = SCHOOLBOOTH_Audit_Logger::init();
            $audit->log_event('download_attempt', [
                'file'     => $file,
                'code'     => $code,
                'success'  => false,
                'reason'   => 'file_not_found',
            ]);
            
            wp_die(__('File not found', 'schoolbooth'), 404);
        }

        // Update download count (atomic operation with transaction)
        $update_result = $this->update_download_count($file, $code);
        if (!$update_result) {
            $audit = SCHOOLBOOTH_Audit_Logger::init();
            $audit->log_event('download_attempt', [
                'file'     => $file,
                'code'     => $code,
                'success'  => false,
                'reason'   => 'update_count_failed',
            ]);
            
            wp_die(__('Unable to authorize download', 'schoolbooth'), 403);
        }

        // Log successful download
        $audit = SCHOOLBOOTH_Audit_Logger::init();
        $audit->log_event('download_attempt', [
            'file'     => $file,
            'code'     => $code,
            'success'  => true,
            'downloads_used' => $downloads + 1,
        ]);
        
        // Clear rate limit counter on successful access
        $rate_limiter = SCHOOLBOOTH_Rate_Limiter::init();
        $rate_limiter->clear_access_code_attempts($code);

        $mime_type = mime_content_type($file_path);
        if ($mime_type === false) {
            $mime_type = 'application/octet-stream';
        }

        header('Content-Type: ' . $mime_type);
        $disposition = $as_attachment ? 'attachment' : 'inline';
        header('Content-Disposition: ' . $disposition . '; filename="' . basename($file_path) . '"');
        header('Content-Length: ' . filesize($file_path));
        readfile($file_path);
        exit;
    }

    protected function render_app_preview($file, $code, $received_hash) {
        $codes = $this->load_codes();
        $downloads_used = 0;
        $download_limit = (int) (isset($this->options['download_limit']) ? $this->options['download_limit'] : 3);
        if (isset($codes[$file])) {
            $downloads_used = (int) (isset($codes[$file]['downloads']) ? $codes[$file]['downloads'] : 0);
        }
        $downloads_remaining = max(0, $download_limit - $downloads_used);
        $download_url = add_query_arg([
            'schoolbooth_download' => $file,
            'code' => $code,
            'hash' => $received_hash,
            'force_download' => 1,
        ], home_url('/'));
        $share_url = add_query_arg([
            'schoolbooth_download' => $file,
            'code' => $code,
            'hash' => $received_hash,
        ], home_url('/'));
        $can_delete = is_user_logged_in() && current_user_can('manage_options');
        $delete_expires = time() + 900;
        $delete_token = $this->generate_delete_token($file, $code, $delete_expires);

        $preview_url = add_query_arg([
            'schoolbooth_download' => $file,
            'code' => $code,
            'hash' => $received_hash,
            'schoolbooth_app' => 1,
            'schoolbooth_ts' => sanitize_text_field(isset($_GET['schoolbooth_ts']) ? $_GET['schoolbooth_ts'] : ''),
            'schoolbooth_sig' => sanitize_text_field(isset($_GET['schoolbooth_sig']) ? $_GET['schoolbooth_sig'] : ''),
            'schoolbooth_preview_asset' => 1,
        ], home_url('/'));

        $title = sprintf(
            __('Photo Preview: %s', 'schoolbooth'),
            basename($file)
        );

        nocache_headers();
        status_header(200);
        header('Content-Type: text/html; charset=' . get_bloginfo('charset'));

        echo '<!doctype html>';
        echo '<html lang="en"><head><meta charset="' . esc_attr(get_bloginfo('charset')) . '">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>' . esc_html($title) . '</title>';
        echo '<style>';
        echo 'body{margin:0;background:#111827;color:#f9fafb;font-family:Arial,sans-serif;}';
        echo '.wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;box-sizing:border-box;}';
        echo '.panel{max-width:1100px;width:100%;background:#1f2937;border-radius:16px;padding:20px;box-sizing:border-box;box-shadow:0 20px 50px rgba(0,0,0,.35);}';
        echo '.meta{margin:0 0 16px;font-size:14px;color:#d1d5db;}';
        echo '.meta strong{color:#fff;}';
        echo '.meta-line{margin-top:8px;}';
        echo '.image{display:block;max-width:100%;max-height:80vh;margin:0 auto;border-radius:12px;background:#0b1220;}';
        echo '.actions{margin-top:16px;display:flex;flex-wrap:wrap;gap:10px;}';
        echo '.btn{display:inline-block;padding:10px 14px;border-radius:10px;border:1px solid #374151;background:#111827;color:#fff;text-decoration:none;cursor:pointer;font-size:14px;}';
        echo '.btn:hover{background:#0b1220;}';
        echo '.btn-danger{background:#7f1d1d;border-color:#b91c1c;}';
        echo '.btn-danger:hover{background:#991b1b;}';
        echo '.status{margin-top:10px;font-size:13px;color:#93c5fd;}';
        echo '</style></head><body>';
        echo '<div class="wrap"><div class="panel">';
        echo '<p class="meta"><strong>' . esc_html__('Upload verified', 'schoolbooth') . '</strong><br>' . esc_html(basename($file)) . '</p>';
        echo '<p class="meta meta-line">' . esc_html__('Downloads used:', 'schoolbooth') . ' ' . (int) $downloads_used . ' / ' . (int) $download_limit . ' &middot; ' . esc_html__('Remaining:', 'schoolbooth') . ' ' . (int) $downloads_remaining . '</p>';
        echo '<img class="image" src="' . esc_url($preview_url) . '" alt="' . esc_attr(basename($file)) . '">';
        echo '<div class="actions">';
        echo '<a class="btn" href="' . esc_url($download_url) . '">' . esc_html__('Download', 'schoolbooth') . '</a>';
        echo '<button class="btn" id="schoolbooth-print-btn" type="button">' . esc_html__('Print', 'schoolbooth') . '</button>';
        echo '<button class="btn" id="schoolbooth-share-btn" type="button" data-share-url="' . esc_attr($share_url) . '">' . esc_html__('Share', 'schoolbooth') . '</button>';
        if ($can_delete) {
            echo '<button class="btn btn-danger" id="schoolbooth-delete-btn" type="button" data-file="' . esc_attr($file) . '" data-code="' . esc_attr($code) . '" data-delete-token="' . esc_attr($delete_token) . '" data-delete-expires="' . esc_attr((string) $delete_expires) . '">' . esc_html__('Delete', 'schoolbooth') . '</button>';
        }
        echo '</div>';
        echo '<div id="schoolbooth-preview-status" class="status"></div>';
        echo '<script>';
        echo '(function(){';
        echo 'var printBtn=document.getElementById("schoolbooth-print-btn");';
        echo 'if(printBtn){printBtn.addEventListener("click",function(){window.print();});}';
        echo 'var shareBtn=document.getElementById("schoolbooth-share-btn");';
        echo 'var statusEl=document.getElementById("schoolbooth-preview-status");';
        echo 'if(shareBtn){shareBtn.addEventListener("click",function(){var url=shareBtn.getAttribute("data-share-url");if(navigator.share){navigator.share({title:"Photo",url:url}).catch(function(){});}else if(navigator.clipboard){navigator.clipboard.writeText(url).then(function(){statusEl.textContent="Share link copied to clipboard.";});}});}';
        echo 'var delBtn=document.getElementById("schoolbooth-delete-btn");';
        echo 'if(delBtn){delBtn.addEventListener("click",function(){if(!confirm("Delete this photo?")){return;} var confirmText=window.prompt("Type DELETE to confirm permanent deletion.", ""); if(confirmText!=="DELETE"){statusEl.textContent="Deletion canceled."; return;} var data=new FormData(); data.append("action","schoolbooth_delete_photo"); data.append("file",delBtn.getAttribute("data-file")); data.append("code",delBtn.getAttribute("data-code")); data.append("delete_token",delBtn.getAttribute("data-delete-token")); data.append("delete_expires",delBtn.getAttribute("data-delete-expires")); data.append("security","' . esc_js(wp_create_nonce('schoolbooth_ajax')) . '"); fetch("' . esc_url(admin_url('admin-ajax.php')) . '",{method:"POST",body:data,credentials:"same-origin"}).then(function(r){return r.json();}).then(function(res){if(res&&res.success){statusEl.textContent="Photo deleted."; setTimeout(function(){window.location.href="' . esc_url(home_url('/')) . '";},800);}else{statusEl.textContent="Delete failed.";}}).catch(function(){statusEl.textContent="Delete failed.";});});}';
        echo '})();';
        echo '</script>';
        echo '</div></div></body></html>';
        exit;
    }
    
    protected function redirect_to_portal($file, $code) {
        $portal_page_id = isset($this->options['download_page_id']) ? $this->options['download_page_id'] : $this->find_shortcode_page();
        
        if ($portal_page_id) {
            $portal_url = add_query_arg([
                'code' => $code,
                'file' => $file
            ], get_permalink($portal_page_id));
            
            wp_redirect($portal_url);
            exit;
        }
        
        $this->process_download($file, $code);
    }
    
    protected function find_shortcode_page() {
        global $wpdb;
        
        $page_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ID FROM $wpdb->posts 
                WHERE post_type = 'page' 
                AND post_status = 'publish' 
                AND post_content LIKE %s 
                LIMIT 1",
                '%[schoolbooth_download_portal]%'
            )
        );

        if ($page_id) {
            $options = get_option('schoolbooth_settings');
            $options['download_page_id'] = $page_id;
            update_option('schoolbooth_settings', $options);
        }

        return $page_id;
    }
    
    protected function get_capture_label_from_file($file) {
        $filename = basename($file);
        if (preg_match('/^([^,_]+)/', $filename, $matches)) {
            return str_replace('_', ' ', $matches[1]);
        }
        return __('Capture', 'schoolbooth');
    }
    
    public function get_photos_data($code) {
        $codes = $this->load_codes();
        $matched_photos = [];
        $download_limit = (int) (isset($this->options['download_limit']) ? $this->options['download_limit'] : 3);
        
        foreach ($codes as $file => $data) {
            if (schoolbooth_normalize_access_code(isset($data['code']) ? $data['code'] : '') === $code) {
                $capture_label = $this->get_capture_label_from_file(isset($data['filename']) ? $data['filename'] : $file);

                if ($this->is_record_expired($data)) {
                    continue;
                }

                $downloads = (int) (isset($data['downloads']) ? $data['downloads'] : 0);
                if ($downloads >= $download_limit) {
                    continue;
                }

                $file_path = $this->resolve_photo_path($file);
                if ($file_path !== '' && file_exists($file_path)) {
                    $delete_expires = time() + 900;
                    $matched_photos[] = [
                        'url' => $this->generate_file_url($file_path),
                        'thumbnail_url' => $this->generate_thumbnail($file_path),
                        'filename' => $file,
                        'label' => $capture_label,
                        'code' => $code,
                        'delete_token' => $this->generate_delete_token($file, $code, $delete_expires),
                        'delete_expires' => $delete_expires,
                        'downloads_remaining' => max(0, $download_limit - $downloads),
                        'expiry_days' => $this->calculate_expiry_days(isset($data['created']) ? $data['created'] : current_time('mysql')),
                        'download_url' => add_query_arg([
                            'schoolbooth_download' => $file,
                            'code' => $code,
                            'hash' => $this->generate_signature($file, $code),
                            'force_download' => 1
                        ], home_url('/'))
                    ];
                }
            }
        }
        
        return $matched_photos;
    }
    
    protected function generate_file_url($file_path) {
        $upload_dir = wp_upload_dir();
        $relative_path = str_replace($upload_dir['basedir'] . '/', '', $file_path);
        $relative_path = str_replace('\\', '/', $relative_path);
        return $upload_dir['baseurl'] . '/' . $relative_path;
    }
    
    protected function generate_thumbnail($file_path) {
        return $this->generate_file_url($file_path);
    }
    
    public function delete_photo($file, $code, $delete_token = '', $delete_expires = 0) {
        $file = $this->normalize_requested_file($file);
        $code = schoolbooth_normalize_access_code($code);
        if ($file === '') {
            return false;
        }

        if (!$this->verify_delete_token($file, $code, $delete_expires, $delete_token)) {
            return false;
        }

        $codes_file = $this->get_codes_file_path();
        $codes = $this->load_codes();
        if (isset($codes[$file]) && schoolbooth_normalize_access_code($codes[$file]['code']) === $code) {
            $file_path = $this->resolve_photo_path($file);
            if (!empty($file_path) && file_exists($file_path)) {
                // Use secure deletion
                $deleter = SCHOOLBOOTH_Secure_File_Deleter::init();
                $delete_result = $deleter::secure_delete($file_path, true);
                
                if (is_wp_error($delete_result)) {
                    return false;
                }
            }
            
            // Log the manual deletion
            $audit = SCHOOLBOOTH_Audit_Logger::init();
            $audit->log_event('manual_delete', [
                'file'     => $file,
                'code'     => $code,
                'deleted_by' => 'user',
            ]);
            
            unset($codes[$file]);
            return file_put_contents($codes_file, json_encode($codes, JSON_PRETTY_PRINT), LOCK_EX) !== false;
        }
        
        return false;
    }
    
    protected function update_download_count($file, $code) {
        $file = $this->normalize_requested_file($file);
        $code = schoolbooth_normalize_access_code($code);
        if ($file === '') {
            return false;
        }

        $codes_file = $this->get_codes_file_path();
        if (!file_exists($codes_file)) {
            return false;
        }

        $codes = $this->load_codes();
        
        if (isset($codes[$file]) && schoolbooth_normalize_access_code($codes[$file]['code']) === $code) {
            if ($this->is_record_expired($codes[$file])) {
                return false;
            }

            $download_limit = (int) (isset($this->options['download_limit']) ? $this->options['download_limit'] : 3);
            $downloads = (int) (isset($codes[$file]['downloads']) ? $codes[$file]['downloads'] : 0);
            
            if ($downloads < $download_limit) {
                $codes[$file]['downloads'] = $downloads + 1;
                
                // Write update atomically
                $write_result = file_put_contents($codes_file, json_encode($codes, JSON_PRETTY_PRINT), LOCK_EX);
                if ($write_result === false) {
                    return false;
                }
                
                // Check if we've hit the download limit - if so, auto-delete the file
                if ($codes[$file]['downloads'] >= $download_limit) {
                    $file_path = $this->resolve_photo_path($file);
                    if (!empty($file_path) && file_exists($file_path)) {
                        // Use secure deletion
                        $deleter = SCHOOLBOOTH_Secure_File_Deleter::init();
                        $delete_result = $deleter::secure_delete($file_path, true);
                        
                        if (!is_wp_error($delete_result)) {
                            // Remove from access codes file
                            unset($codes[$file]);
                            file_put_contents($codes_file, json_encode($codes, JSON_PRETTY_PRINT), LOCK_EX);
                        }
                    }
                }
                
                return true;
            }
        }
        
        return false;
    }
    
    protected function calculate_expiry_days($created_date) {
        $expiry_days = isset($this->options['expiry_days']) ? $this->options['expiry_days'] : 7;
        try {
            $created = new DateTime($created_date);
            $expires = $created->add(new DateInterval("P{$expiry_days}D"));
            $now = new DateTime();

            return $now < $expires ? $expires->diff($now)->days : 0;
        } catch (Exception $e) {
            return 0;
        }
    }
}


