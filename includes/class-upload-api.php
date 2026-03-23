<?php

class PTASB_Upload_API {
    private static $instance;

    public static function init() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes() {
        register_rest_route('pta-schoolbooth/v1', '/ping', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_ping'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('pta-schoolbooth/v1', '/enroll', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_enroll'],
            'permission_callback' => [$this, 'can_enroll'],
        ]);

        register_rest_route('pta-schoolbooth/v1', '/ingest', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_ingest'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function can_enroll() {
        if (!is_user_logged_in()) {
            return new WP_Error('ptasb_enroll_auth_required', __('Authentication required for enrollment', 'pta-schoolbooth'), ['status' => 401]);
        }

        if (!current_user_can('manage_options')) {
            return new WP_Error('ptasb_enroll_forbidden', __('Insufficient permissions for enrollment', 'pta-schoolbooth'), ['status' => 403]);
        }

        return true;
    }

    public function handle_enroll(WP_REST_Request $request) {
        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            $payload = [];
        }

        $provided_secret = isset($payload['shared_secret'])
            ? sanitize_text_field((string) $payload['shared_secret'])
            : '';

        if ($provided_secret !== '' && strlen($provided_secret) < 32) {
            return new WP_Error('ptasb_secret_weak', __('Shared secret must be at least 32 characters', 'pta-schoolbooth'), ['status' => 400]);
        }

        $settings = get_option('pta_schoolbooth_settings', []);
        if (!is_array($settings)) {
            $settings = [];
        }

        $shared_secret = $provided_secret !== '' ? $provided_secret : $this->generate_shared_secret();
        $settings['shared_secret'] = $shared_secret;

        // Record enrollment metadata.
        $current_user = wp_get_current_user();
        $app_name = isset($payload['app_name']) ? sanitize_text_field((string) $payload['app_name']) : 'Schoolbooth App';
        $instance_id = isset($payload['app_instance_id']) ? sanitize_text_field((string) $payload['app_instance_id']) : '';

        $enrolled_devices = isset($settings['enrolled_devices']) && is_array($settings['enrolled_devices'])
            ? $settings['enrolled_devices']
            : [];

        // Key by instance_id if provided, otherwise append.
        $device_entry = [
            'app_name'          => $app_name,
            'instance_id'       => $instance_id,
            'enrolled_by'       => $current_user->user_login,
            'enrolled_at'       => gmdate('Y-m-d H:i:s'),
            'enrolled_at_ts'    => time(),
            'enrollment_method' => $provided_secret !== '' ? 'client_provided' : 'server_generated',
        ];

        if ($instance_id !== '') {
            $enrolled_devices[$instance_id] = $device_entry;
        } else {
            array_unshift($enrolled_devices, $device_entry);
            // Keep no more than 20 anonymous enrollments.
            $enrolled_devices = array_slice($enrolled_devices, 0, 20);
        }

        $settings['enrolled_devices'] = $enrolled_devices;
        $settings['last_enrolled_at'] = $device_entry['enrolled_at'];
        $settings['last_enrolled_by'] = $current_user->user_login;
        update_option('pta_schoolbooth_settings', $settings, false);

        $endpoint = isset($settings['rest_api_endpoint'])
            ? (string) $settings['rest_api_endpoint']
            : '/wp-json/pta-schoolbooth/v1/ingest';
        $endpoint = '/' . ltrim($endpoint, '/');

        return rest_ensure_response([
            'success' => true,
            'wp_url' => home_url('/'),
            'wp_api_endpoint' => $endpoint,
            'wp_api_timeout' => 20,
            'wp_shared_secret' => $shared_secret,
            'enrollment_method' => $device_entry['enrollment_method'],
        ]);
    }

    public function handle_ping(WP_REST_Request $request) {
        $auth = $this->authenticate_ping($request);
        if (is_wp_error($auth)) {
            return $auth;
        }

        $settings = get_option('pta_schoolbooth_settings', []);
            return rest_ensure_response([
                'success' => true,
                'message' => 'Schoolbooth upload API reachable',
            'upload_path' => isset($settings['upload_path']) ? $settings['upload_path'] : 'pta-schoolbooth',
        ]);
    }

    public function handle_ingest(WP_REST_Request $request) {
        $payload = $request->get_json_params();

        $file_rel_path = isset($payload['file_rel_path']) ? sanitize_text_field($payload['file_rel_path']) : '';
        $access_code_raw = isset($payload['access_code']) ? sanitize_text_field($payload['access_code']) : '';
        $access_code = ptasb_normalize_access_code($access_code_raw);
        $image_b64 = isset($payload['image_b64']) ? (string) $payload['image_b64'] : '';

        if ($file_rel_path === '' || $access_code === '' || $image_b64 === '') {
            return new WP_Error('ptasb_bad_request', __('Missing required upload fields', 'pta-schoolbooth'), ['status' => 400]);
        }

        $normalized_rel_path = $this->normalize_relative_path($file_rel_path);
        if ($normalized_rel_path === '') {
            return new WP_Error('ptasb_invalid_path', __('Invalid file path', 'pta-schoolbooth'), ['status' => 400]);
        }

        $image_bytes = base64_decode($image_b64, true);
        if ($image_bytes === false || $image_bytes === '') {
            return new WP_Error('ptasb_invalid_image', __('Invalid image payload', 'pta-schoolbooth'), ['status' => 400]);
        }

        $auth = $this->authenticate_upload($request, $normalized_rel_path, $access_code_raw, $access_code, $image_bytes);
        if (is_wp_error($auth)) {
            return $auth;
        }

        $upload_dir = wp_upload_dir();
        $settings = get_option('pta_schoolbooth_settings', []);
        $base_path = isset($settings['upload_path']) ? $settings['upload_path'] : 'pta-schoolbooth';

        $photos_root = wp_normalize_path(path_join($upload_dir['basedir'], $base_path . '/photos'));
        $target_path = wp_normalize_path(path_join($photos_root, $normalized_rel_path));

        if (strpos($target_path, $photos_root . '/') !== 0 && $target_path !== $photos_root) {
            return new WP_Error('ptasb_invalid_target', __('Upload target is outside allowed directory', 'pta-schoolbooth'), ['status' => 400]);
        }

        if (!wp_mkdir_p(dirname($target_path))) {
            return new WP_Error('ptasb_mkdir_failed', __('Failed to create upload directory', 'pta-schoolbooth'), ['status' => 500]);
        }

        if (file_put_contents($target_path, $image_bytes, LOCK_EX) === false) {
            return new WP_Error('ptasb_write_failed', __('Failed to write uploaded image', 'pta-schoolbooth'), ['status' => 500]);
        }

        $save_result = $this->save_access_code_record($normalized_rel_path, $access_code);
        if (is_wp_error($save_result)) {
            return $save_result;
        }

        $audit = PTASB_Audit_Logger::init();
        $audit->log_event('upload', [
            'file' => $normalized_rel_path,
            'code' => $access_code,
            'source' => 'api_ingest',
        ]);
        $audit->log_event('access_code_gen', [
            'file' => $normalized_rel_path,
            'code' => $access_code,
            'source' => 'api_ingest',
        ]);

        $download_url = add_query_arg([
            'pta_schoolbooth_download' => $normalized_rel_path,
            'code' => $access_code,
            'hash' => hash_hmac('sha256', $normalized_rel_path . '|' . $access_code, PTASB_SHARED_SECRET),
        ], home_url('/'));

        $app_timestamp = (string) time();
        $app_download_url = add_query_arg([
            'pta_schoolbooth_download' => $normalized_rel_path,
            'code' => $access_code,
            'hash' => hash_hmac('sha256', $normalized_rel_path . '|' . $access_code, PTASB_SHARED_SECRET),
            'ptasb_app' => 1,
            'ptasb_ts' => $app_timestamp,
            'ptasb_sig' => hash_hmac('sha256', $app_timestamp . '|' . $normalized_rel_path . '|' . $access_code . '|app-view', PTASB_SHARED_SECRET),
        ], home_url('/'));

        return rest_ensure_response([
            'success' => true,
            'file' => $normalized_rel_path,
            'code' => $access_code,
            'download_url' => $download_url,
            'app_download_url' => $app_download_url,
        ]);
    }

    private function get_shared_secret() {
        $settings = get_option('pta_schoolbooth_settings', []);
        $secret = isset($settings['shared_secret'])
            ? $settings['shared_secret']
            : (defined('PTASB_SHARED_SECRET') ? PTASB_SHARED_SECRET : '');
        return (string) $secret;
    }

    private function generate_shared_secret() {
        try {
            return bin2hex(random_bytes(32));
        } catch (Exception $e) {
            return wp_generate_password(64, true, true);
        }
    }

    private function authenticate_ping(WP_REST_Request $request) {
        $secret = $this->get_shared_secret();
        if (strlen($secret) < 32) {
            return new WP_Error('ptasb_secret_invalid', __('Server secret is not configured', 'pta-schoolbooth'), ['status' => 500]);
        }

        $timestamp = (string) $request->get_header('x-ptasb-timestamp');
        $signature = (string) $request->get_header('x-ptasb-signature');

        if ($timestamp === '' || $signature === '') {
            return new WP_Error('ptasb_auth_missing', __('Missing authentication headers', 'pta-schoolbooth'), ['status' => 401]);
        }

        if (!$this->is_timestamp_valid($timestamp)) {
            return new WP_Error('ptasb_timestamp_invalid', __('Invalid or expired timestamp', 'pta-schoolbooth'), ['status' => 401]);
        }

        $expected = hash_hmac('sha256', $timestamp . '|ping', $secret);
        if (!hash_equals($expected, $signature)) {
            return new WP_Error('ptasb_auth_invalid', __('Invalid signature', 'pta-schoolbooth'), ['status' => 401]);
        }

        return true;
    }

    private function authenticate_upload(WP_REST_Request $request, $file_rel_path, $access_code_raw, $access_code_normalized, $image_bytes) {
        $secret = $this->get_shared_secret();
        if (strlen($secret) < 32) {
            return new WP_Error('ptasb_secret_invalid', __('Server secret is not configured', 'pta-schoolbooth'), ['status' => 500]);
        }

        $timestamp = (string) $request->get_header('x-ptasb-timestamp');
        $signature = (string) $request->get_header('x-ptasb-signature');

        if ($timestamp === '' || $signature === '') {
            return new WP_Error('ptasb_auth_missing', __('Missing authentication headers', 'pta-schoolbooth'), ['status' => 401]);
        }

        if (!$this->is_timestamp_valid($timestamp)) {
            return new WP_Error('ptasb_timestamp_invalid', __('Invalid or expired timestamp', 'pta-schoolbooth'), ['status' => 401]);
        }

        $payload_hash = hash('sha256', $image_bytes);
        $message_raw = $timestamp . '|' . $file_rel_path . '|' . $access_code_raw . '|' . $payload_hash;
        $message_normalized = $timestamp . '|' . $file_rel_path . '|' . $access_code_normalized . '|' . $payload_hash;
        $expected_raw = hash_hmac('sha256', $message_raw, $secret);
        $expected_normalized = hash_hmac('sha256', $message_normalized, $secret);

        if (!hash_equals($expected_raw, $signature) && !hash_equals($expected_normalized, $signature)) {
            return new WP_Error('ptasb_auth_invalid', __('Invalid signature', 'pta-schoolbooth'), ['status' => 401]);
        }

        return true;
    }

    private function is_timestamp_valid($timestamp) {
        if (!ctype_digit((string) $timestamp)) {
            return false;
        }

        $drift = abs(time() - (int) $timestamp);
        return $drift <= 300;
    }

    private function normalize_relative_path($file_path) {
        $file_path = wp_normalize_path((string) $file_path);
        $file_path = ltrim($file_path, '/');

        if ($file_path === '' || strpos($file_path, "\0") !== false) {
            return '';
        }

        if (preg_match('#(^|/)\.\.(/|$)#', $file_path)) {
            return '';
        }

        $allowed_extensions = ['jpg', 'jpeg', 'png'];
        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_extensions, true)) {
            return '';
        }

        return $file_path;
    }

    private function save_access_code_record($file_rel_path, $access_code) {
        $upload_dir = wp_upload_dir();
        $settings = get_option('pta_schoolbooth_settings', []);
        $base_path = isset($settings['upload_path']) ? $settings['upload_path'] : 'pta-schoolbooth';
        $codes_file = path_join($upload_dir['basedir'], $base_path . '/access_codes.json');

        $codes_dir = dirname($codes_file);
        if (!wp_mkdir_p($codes_dir)) {
            return new WP_Error('ptasb_codes_dir_failed', __('Failed to create access code directory', 'pta-schoolbooth'), ['status' => 500]);
        }

        $codes = [];
        if (file_exists($codes_file)) {
            $decoded = json_decode(file_get_contents($codes_file), true);
            if (is_array($decoded)) {
                $codes = $decoded;
            }
        }

        $codes[$file_rel_path] = [
            'code' => $access_code,
            'downloads' => 0,
            'created' => current_time('mysql'),
            'filename' => basename($file_rel_path),
        ];

        if (file_put_contents($codes_file, wp_json_encode($codes, JSON_PRETTY_PRINT), LOCK_EX) === false) {
            return new WP_Error('ptasb_codes_write_failed', __('Failed to write access codes file', 'pta-schoolbooth'), ['status' => 500]);
        }

        return true;
    }
}

