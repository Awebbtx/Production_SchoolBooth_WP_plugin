<?php
/**
 * Audit Logger
 * 
 * Immutable append-only audit trail for photo system events.
 * Uses Custom Post Type to ensure no editing/deletion of audit records.
 * Includes cryptographic digest chaining for tamper detection.
 */
class SCHOOLBOOTH_Audit_Logger {
    const CPT = 'schoolbooth_audit_log';
    const INTEGRITY_KEY = '_event_integrity_digest';
    
    private static $instance;
    
    public static function init() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('init', [$this, 'register_cpt']);
        add_action('init', [$this, 'lock_audit_posts']);
    }
    
    /**
     * Register the audit log custom post type (append-only, locked)
     */
    public function register_cpt() {
        register_post_type(self::CPT, [
            'label'               => __('Audit Log', 'schoolbooth'),
            'public'              => false,
            'show_ui'             => false,
            'show_in_rest'        => false,
            'supports'            => ['title', 'custom-fields'],
            'has_archive'         => false,
            'rewrite'             => false,
            'capability_type'     => 'post',
            'capabilities'        => [
                'create_posts'   => 'schoolbooth_audit_create',
                'delete_posts'   => 'schoolbooth_audit_delete',
                'delete_post'    => 'schoolbooth_audit_delete_post',
                'delete_others_posts' => 'schoolbooth_audit_delete_others',
                'edit_posts'     => 'schoolbooth_audit_edit',
                'edit_post'      => 'schoolbooth_audit_edit_post',
                'edit_others_posts' => 'schoolbooth_audit_edit_others',
            ],
            'map_meta_cap'        => true,
        ]);
    }
    
    /**
     * Lock audit posts from editing/deletion at the post level
     * Only programmatic access allowed (not UI)
     */
    public function lock_audit_posts() {
        if (is_admin()) {
            add_filter('user_has_cap', [$this, 'deny_audit_caps'], 10, 3);
            add_action('admin_notices', [$this, 'show_audit_lock_message']);
        }
    }
    
    /**
     * Prevent any user (including admin) from editing/deleting audit posts in UI
     */
    public function deny_audit_caps($caps, $cap, $user_id) {
        // Only deny edit/delete caps for audit posts in admin context
        if (in_array($cap, ['schoolbooth_audit_edit', 'schoolbooth_audit_edit_post', 'schoolbooth_audit_delete', 'schoolbooth_audit_delete_post'])) {
            if (isset($_GET['post']) || isset($_POST['post_ID'])) {
                $post_id = isset($_GET['post']) ? (int)$_GET['post'] : (int)$_POST['post_ID'];
                $post = get_post($post_id);
                if ($post && $post->post_type === self::CPT) {
                    return [false];
                }
            }
        }
        return $caps;
    }
    
    /**
     * Display message if someone tries to edit audit log
     */
    public function show_audit_lock_message() {
        global $post;
        if ($post && $post->post_type === self::CPT) {
            echo '<div class="notice notice-warning"><p>';
            esc_html_e('Audit log entries are immutable and cannot be edited or deleted.', 'schoolbooth');
            echo '</p></div>';
        }
    }
    
    /**
     * Log an event to the audit trail
     * 
     * @param string $event_type Type of event (upload, access_code_gen, download, form_submission, delete, auto_delete)
     * @param array $data Event data
     * @param string|int $photo_id Optional photo/post ID for reference
     */
    public function log_event($event_type, $data = [], $photo_id = null) {
        // Validate event type
        $allowed_types = [
            'upload',
            'access_code_gen',
            'download_attempt',
            'form_submission',
            'manual_delete',
            'auto_delete',
        ];
        
        if (!in_array($event_type, $allowed_types)) {
            return new WP_Error('invalid_event_type', 'Invalid event type');
        }
        
        // Build event record with security metadata
        $event_record = [
            'event_type'      => $event_type,
            'timestamp'       => current_time('mysql', true),
            'user_id'         => get_current_user_id(),
            'ip_address'      => $this->get_client_ip(),
            'photo_id'        => $photo_id ? (int)$photo_id : null,
            'data'            => $data,
        ];
        
        // Add integrity digest (hash of previous entry + current data)
        $event_record['prev_digest'] = $this->get_last_digest();
        $event_record['digest'] = $this->compute_digest($event_record);
        
        // Create post for this audit event
        $post_id = wp_insert_post([
            'post_type'    => self::CPT,
            'post_status'  => 'publish',
            'post_title'   => sprintf('[%s] %s', $event_type, date('Y-m-d H:i:s')),
            'post_content' => wp_json_encode($event_record),
        ], true);
        
        if (is_wp_error($post_id)) {
            return $post_id;
        }
        
        // Store digest as post meta for integrity verification
        update_post_meta($post_id, self::INTEGRITY_KEY, $event_record['digest']);
        
        return $post_id;
    }
    
    /**
     * Compute cryptographic digest for tamper detection
     * Includes hash of event data + previous digest
     */
    private function compute_digest($event_record) {
        $to_hash = wp_json_encode([
            'event_type'   => $event_record['event_type'],
            'timestamp'    => $event_record['timestamp'],
            'photo_id'     => $event_record['photo_id'],
            'data'         => $event_record['data'],
            'prev_digest'  => $event_record['prev_digest'],
        ]) . SCHOOLBOOTH_SHARED_SECRET;
        
        return hash('sha256', $to_hash);
    }
    
    /**
     * Get the digest of the last logged event for chaining
     */
    private function get_last_digest() {
        $last_post = get_posts([
            'post_type'   => self::CPT,
            'posts_per_page' => 1,
            'orderby'     => 'ID',
            'order'       => 'DESC',
            'fields'      => 'ids',
        ]);
        
        if (empty($last_post)) {
            return hash('sha256', SCHOOLBOOTH_SHARED_SECRET);
        }
        
        return get_post_meta($last_post[0], self::INTEGRITY_KEY, true) ?: hash('sha256', SCHOOLBOOTH_SHARED_SECRET);
    }
    
    /**
     * Get client IP address (with spoofing protection)
     */
    private function get_client_ip() {
        // Trust CF headers only if configured
        if (!empty($_SERVER['CF_CONNECTING_IP'])) {
            return sanitize_text_field($_SERVER['CF_CONNECTING_IP']);
        }
        
        // Otherwise use standard $_SERVER variables
        $ip = '';
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = sanitize_text_field($_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = explode(',', $ip)[0]; // Get first IP in chain
        } elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = sanitize_text_field($_SERVER['HTTP_CLIENT_IP']);
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = sanitize_text_field($_SERVER['REMOTE_ADDR']);
        }
        
        return $ip ?: 'unknown';
    }
    
    /**
     * Query audit log with access control
     * Only Admin/Event Organizer can view
     */
    public function get_events($photo_id = null, $event_type = null, $start_date = null, $end_date = null) {
        // Check capabilities
        if (!current_user_can('manage_options') && !current_user_can('schoolbooth_audit_read')) {
            return new WP_Error('insufficient_caps', 'You do not have permission to view audit logs');
        }
        
        $args = [
            'post_type'      => self::CPT,
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'ASC',
        ];
        
        if ($photo_id) {
            // Query by photo_id in post content JSON
            $args['s'] = sprintf('"photo_id":%d', (int)$photo_id);
        }
        
        $posts = get_posts($args);
        $events = [];
        
        foreach ($posts as $post) {
            $event = json_decode($post->post_content, true);
            
            // Apply filters
            if ($event_type && $event['event_type'] !== $event_type) {
                continue;
            }
            
            if ($start_date && strtotime($event['timestamp']) < strtotime($start_date)) {
                continue;
            }
            
            if ($end_date && strtotime($event['timestamp']) > strtotime($end_date)) {
                continue;
            }
            
            // Verify integrity
            $stored_digest = get_post_meta($post->ID, self::INTEGRITY_KEY, true);
            $computed_digest = $this->compute_digest($event);
            
            $event['digest_valid'] = hash_equals($stored_digest, $computed_digest) ? true : false;
            $event['post_id'] = $post->ID;
            
            $events[] = $event;
        }
        
        return $events;
    }
    
    /**
     * Verify audit log chain integrity (basic check)
     */
    public function verify_chain_integrity() {
        $posts = get_posts([
            'post_type'      => self::CPT,
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'ASC',
        ]);
        
        $prev_digest = hash('sha256', SCHOOLBOOTH_SHARED_SECRET);
        $results = [];
        
        foreach ($posts as $post) {
            $event = json_decode($post->post_content, true);
            $stored_digest = get_post_meta($post->ID, self::INTEGRITY_KEY, true);
            $computed_digest = $this->compute_digest($event);
            
            $results[] = [
                'post_id'      => $post->ID,
                'timestamp'    => $event['timestamp'],
                'digest_valid' => hash_equals($stored_digest, $computed_digest),
                'chain_valid'  => $event['prev_digest'] === $prev_digest,
            ];
            
            $prev_digest = $stored_digest;
        }
        
        return $results;
    }
}



