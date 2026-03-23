<?php
/**
 * Admin Audit Log Viewer
 * 
 * Displays immutable audit trail for administrators and event organizers
 */
class PTASB_Admin_Audit_Viewer {
    
    private static $instance;
    
    public static function init() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }
    
    /**
     * Register admin menu
     */
    public function register_menu() {
        $capability = current_user_can('manage_options') ? 'manage_options' : 'ptasb_audit_read';
        
        add_submenu_page(
            'pta-schoolbooth',
            __('Photo Audit Log', 'pta-schoolbooth'),
            __('Photo Audit Log', 'pta-schoolbooth'),
            $capability,
            'pta-schoolbooth-audit-log',
            [$this, 'render_audit_page']
        );
    }
    
    /**
     * Render the audit log page
     */
    public function render_audit_page() {
        // Check capabilities
        if (!current_user_can('manage_options') && !current_user_can('ptasb_audit_read')) {
            wp_die(__('You do not have permission to view audit logs.', 'pta-schoolbooth'));
        }
        
        ?>
        <div class="wrap">
            <h1><?php _e('Photo System Audit Log', 'pta-schoolbooth'); ?></h1>
            
            <div class="nav-tab-wrapper">
                <a href="#events" class="nav-tab nav-tab-active" data-tab="events">
                    <?php _e('All Events', 'pta-schoolbooth'); ?>
                </a>
                <a href="#timeline" class="nav-tab" data-tab="timeline">
                    <?php _e('Timeline', 'pta-schoolbooth'); ?>
                </a>
                <a href="#integrity" class="nav-tab" data-tab="integrity">
                    <?php _e('Chain Integrity', 'pta-schoolbooth'); ?>
                </a>
            </div>
            
            <div id="events" class="tab-content">
                <h2><?php _e('Event Log', 'pta-schoolbooth'); ?></h2>
                <?php $this->render_events_table(); ?>
            </div>
            
            <div id="timeline" class="tab-content" style="display:none;">
                <h2><?php _e('Activity Timeline', 'pta-schoolbooth'); ?></h2>
                <?php $this->render_timeline(); ?>
            </div>
            
            <div id="integrity" class="tab-content" style="display:none;">
                <h2><?php _e('Chain Integrity Verification', 'pta-schoolbooth'); ?></h2>
                <?php $this->render_integrity_check(); ?>
            </div>
        </div>
        
        <style>
            .nav-tab-wrapper {
                margin-bottom: 20px;
                border-bottom: 1px solid #ccc;
            }
            
            .nav-tab {
                padding: 10px 15px;
                border: 1px solid #ccc;
                margin-right: -1px;
                background: #f5f5f5;
                cursor: pointer;
                text-decoration: none;
                color: #333;
                border-bottom: none;
            }
            
            .nav-tab:hover {
                background: #e5e5e5;
            }
            
            .nav-tab.nav-tab-active {
                background: white;
                color: #0073aa;
                border-color: #0073aa;
            }
            
            .tab-content {
                background: white;
                padding: 20px;
                border: 1px solid #ccc;
            }
            
            .audit-table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 20px;
            }
            
            .audit-table th {
                background: #f5f5f5;
                padding: 10px;
                text-align: left;
                border-bottom: 2px solid #ddd;
                font-weight: bold;
            }
            
            .audit-table td {
                padding: 10px;
                border-bottom: 1px solid #ddd;
            }
            
            .audit-table tr:hover {
                background: #f9f9f9;
            }
            
            .event-type {
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: bold;
                text-transform: uppercase;
                display: inline-block;
            }
            
            .event-type.upload { background: #d4edda; color: #155724; }
            .event-type.access_code_gen { background: #d1ecf1; color: #0c5460; }
            .event-type.download_attempt { background: #e2e3e5; color: #383d41; }
            .event-type.form_submission { background: #fff3cd; color: #856404; }
            .event-type.manual_delete { background: #f8d7da; color: #721c24; }
            .event-type.auto_delete { background: #f5c6cb; color: #721c24; }
            
            .success { color: #155724; }
            .failure { color: #721c24; }
            
            .integrity-valid { color: #155724; font-weight: bold; }
            .integrity-invalid { color: #721c24; font-weight: bold; }
            
            .info-box {
                background: #e8f4f8;
                border-left: 4px solid #0073aa;
                padding: 12px;
                margin-bottom: 20px;
            }
            
            .timeline {
                position: relative;
                padding: 20px 0;
            }
            
            .timeline-item {
                margin-bottom: 20px;
                padding: 15px;
                border-left: 3px solid #0073aa;
                background: #f9f9f9;
            }
            
            .timeline-item.success {
                border-left-color: #155724;
            }
            
            .timeline-item.failure {
                border-left-color: #721c24;
            }
            
            .timeline-time {
                display: block;
                font-weight: bold;
                color: #333;
                font-size: 14px;
            }
            
            .timeline-event {
                display: block;
                color: #666;
                font-size: 13px;
                margin-top: 5px;
            }
            
            .timeline-ip {
                display: block;
                color: #999;
                font-size: 12px;
                margin-top: 3px;
            }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            $('.nav-tab').on('click', function(e) {
                e.preventDefault();
                const tab = $(this).data('tab');
                
                $('.nav-tab').removeClass('nav-tab-active');
                $('.tab-content').hide();
                
                $(this).addClass('nav-tab-active');
                $('#' + tab).show();
            });
        });
        </script>
        
        <?php
    }
    
    /**
     * Render events table
     */
    private function render_events_table() {
        $audit = PTASB_Audit_Logger::init();
        $events = $audit->get_events();
        
        if (is_wp_error($events)) {
            echo '<div class="error"><p>' . esc_html($events->get_error_message()) . '</p></div>';
            return;
        }
        
        if (empty($events)) {
            echo '<p>' . __('No audit events recorded yet.', 'pta-schoolbooth') . '</p>';
            return;
        }
        
        // Reverse to show newest first
        $events = array_reverse($events);
        
        ?>
        <table class="audit-table">
            <thead>
                <tr>
                    <th><?php _e('Timestamp', 'pta-schoolbooth'); ?></th>
                    <th><?php _e('Event Type', 'pta-schoolbooth'); ?></th>
                    <th><?php _e('Photo', 'pta-schoolbooth'); ?></th>
                    <th><?php _e('User/IP', 'pta-schoolbooth'); ?></th>
                    <th><?php _e('Status', 'pta-schoolbooth'); ?></th>
                    <th><?php _e('Details', 'pta-schoolbooth'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($events as $event): ?>
                    <tr>
                        <td><?php echo esc_html(date_i18n('Y-m-d H:i:s', strtotime($event['timestamp']))); ?></td>
                        <td>
                            <span class="event-type <?php echo esc_attr($event['event_type']); ?>">
                                <?php echo esc_html($event['event_type']); ?>
                            </span>
                        </td>
                        <td>
                            <?php 
                            if (!empty($event['data']['file'])) {
                                echo esc_html(basename($event['data']['file']));
                            } else if (!empty($event['data']['filename'])) {
                                echo esc_html(basename($event['data']['filename']));
                            } else {
                                echo '--';
                            }
                            ?>
                        </td>
                        <td>
                            <?php 
                            $user = get_userdata($event['user_id']);
                            if ($user) {
                                echo esc_html($user->user_email);
                            } else {
                                echo esc_html($event['ip_address']);
                            }
                            ?>
                        </td>
                        <td>
                            <?php 
                            $success = $this->is_event_success($event);
                            $class = $success ? 'success' : 'failure';
                            $text = $success ? __('Success', 'pta-schoolbooth') : __('Failed', 'pta-schoolbooth');
                            ?>
                            <span class="<?php echo esc_attr($class); ?>">
                                <?php echo esc_html($text); ?>
                            </span>
                        </td>
                        <td>
                            <?php 
                            $reason = isset($event['data']['reason']) ? $event['data']['reason'] : '';
                            if ($event['event_type'] === 'form_submission') {
                                $consent_name = isset($event['data']['consent_name']) ? $event['data']['consent_name'] : '';
                                $consent_email = isset($event['data']['consent_email']) ? $event['data']['consent_email'] : '';
                                $email_domain = isset($event['data']['email_domain']) ? $event['data']['email_domain'] : '';

                                if ($consent_name !== '' || $consent_email !== '') {
                                    $parts = [];
                                    if ($consent_name !== '') {
                                        $parts[] = sprintf(__('Name: %s', 'pta-schoolbooth'), $consent_name);
                                    }
                                    if ($consent_email !== '') {
                                        $parts[] = sprintf(__('Email: %s', 'pta-schoolbooth'), $consent_email);
                                    }
                                    echo esc_html(implode(' | ', $parts));
                                } elseif ($email_domain !== '') {
                                    echo esc_html(sprintf(__('Email domain: %s', 'pta-schoolbooth'), $email_domain));
                                } elseif ($reason) {
                                    echo esc_html($reason);
                                } else {
                                    echo '--';
                                }
                            } else if ($reason) {
                                echo esc_html($reason);
                            } else if (!empty($event['data']['downloads_used'])) {
                                echo sprintf(
                                    __('Download %d of %d', 'pta-schoolbooth'),
                                    (int)$event['data']['downloads_used'],
                                    (int)$event['data']['downloads_used']
                                );
                            } else {
                                echo '--';
                            }
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
    
    /**
     * Render timeline view
     */
    private function render_timeline() {
        $audit = PTASB_Audit_Logger::init();
        $events = $audit->get_events();
        
        if (is_wp_error($events)) {
            echo '<div class="error"><p>' . esc_html($events->get_error_message()) . '</p></div>';
            return;
        }
        
        if (empty($events)) {
            echo '<p>' . __('No audit events recorded yet.', 'pta-schoolbooth') . '</p>';
            return;
        }
        
        // Reverse to show newest first
        $events = array_reverse($events);
        
        echo '<div class="timeline">';
        
        foreach ($events as $event) {
            $success = $this->is_event_success($event);
            $class = $success ? 'success' : 'failure';
            
            echo '<div class="timeline-item ' . esc_attr($class) . '">';
            echo '<span class="timeline-time">' . esc_html(date_i18n('Y-m-d H:i:s', strtotime($event['timestamp']))) . '</span>';
            echo '<span class="timeline-event">';
            
            // Format event description
            switch ($event['event_type']) {
                case 'download_attempt':
                    if ($success) {
                        $event_file = isset($event['data']['file']) ? $event['data']['file'] : '';
                        echo sprintf(
                            __('Download of %s completed', 'pta-schoolbooth'),
                            esc_html(basename($event_file))
                        );
                    } else {
                        $event_reason = isset($event['data']['reason']) ? $event['data']['reason'] : 'unknown';
                        echo sprintf(
                            __('Download attempt failed: %s', 'pta-schoolbooth'),
                            esc_html($event_reason)
                        );
                    }
                    break;
                    
                case 'form_submission':
                    $consent_name = isset($event['data']['consent_name']) ? $event['data']['consent_name'] : '';
                    $consent_email = isset($event['data']['consent_email']) ? $event['data']['consent_email'] : '';
                    $email_domain = isset($event['data']['email_domain']) ? $event['data']['email_domain'] : 'unknown';
                    if ($consent_name !== '' || $consent_email !== '') {
                        echo sprintf(
                            __('Release form submitted by %s (%s)', 'pta-schoolbooth'),
                            esc_html($consent_name !== '' ? $consent_name : __('Unknown', 'pta-schoolbooth')),
                            esc_html($consent_email !== '' ? $consent_email : $email_domain)
                        );
                    } else {
                        echo sprintf(
                            __('Release form submitted from %s', 'pta-schoolbooth'),
                            esc_html($email_domain)
                        );
                    }
                    break;
                    
                case 'auto_delete':
                    $delete_reason = isset($event['data']['reason']) ? $event['data']['reason'] : 'unknown';
                    echo sprintf(
                        __('File auto-deleted: %s', 'pta-schoolbooth'),
                        esc_html($delete_reason)
                    );
                    break;
                    
                case 'manual_delete':
                    $manual_file = isset($event['data']['file']) ? $event['data']['file'] : '';
                    echo sprintf(
                        __('File manually deleted: %s', 'pta-schoolbooth'),
                        esc_html(basename($manual_file))
                    );
                    break;
                    
                case 'upload':
                    $upload_filename = isset($event['data']['filename']) ? $event['data']['filename'] : '';
                    echo sprintf(
                        __('Photo uploaded: %s', 'pta-schoolbooth'),
                        esc_html(basename($upload_filename))
                    );
                    break;
                    
                default:
                    echo esc_html($event['event_type']);
            }
            
            echo '</span>';
            echo '<span class="timeline-ip">' . esc_html($event['ip_address']) . '</span>';
            echo '</div>';
        }
        
        echo '</div>';
    }

    /**
     * Infer event success for legacy and new log records.
     */
    private function is_event_success($event) {
        if (isset($event['data']['success'])) {
            return (bool) $event['data']['success'];
        }

        $event_type = isset($event['event_type']) ? (string) $event['event_type'] : '';
        $reason = isset($event['data']['reason']) ? (string) $event['data']['reason'] : '';

        if ($event_type === 'download_attempt') {
            return $reason === '';
        }

        // Legacy events often omitted "success" even when they succeeded.
        return true;
    }
    
    /**
     * Render chain integrity check
     */
    private function render_integrity_check() {
        $audit = PTASB_Audit_Logger::init();
        $results = $audit->verify_chain_integrity();
        
        if (empty($results)) {
            echo '<p>' . __('No audit events to verify.', 'pta-schoolbooth') . '</p>';
            return;
        }
        
        $all_valid = true;
        foreach ($results as $result) {
            if (!$result['digest_valid'] || !$result['chain_valid']) {
                $all_valid = false;
                break;
            }
        }
        
        if ($all_valid) {
            echo '<div class="info-box">';
            echo '<strong>' . __('Audit trail integrity verified', 'pta-schoolbooth') . '</strong>';
            echo '<p>' . __('All events are signed correctly and chain is unbroken.', 'pta-schoolbooth') . '</p>';
            echo '</div>';
        } else {
            echo '<div style="background: #f8d7da; border-left: 4px solid #721c24; padding: 12px; margin-bottom: 20px;">';
            echo '<strong style="color: #721c24;">' . __('Warning: Integrity issues detected', 'pta-schoolbooth') . '</strong>';
            echo '<p style="color: #721c24;">' . __('Some events may have been tampered with.', 'pta-schoolbooth') . '</p>';
            echo '</div>';
        }
        
        ?>
        <table class="audit-table">
            <thead>
                <tr>
                    <th><?php _e('Event', 'pta-schoolbooth'); ?></th>
                    <th><?php _e('Timestamp', 'pta-schoolbooth'); ?></th>
                    <th><?php _e('Digest Valid', 'pta-schoolbooth'); ?></th>
                    <th><?php _e('Chain Valid', 'pta-schoolbooth'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($results as $result): ?>
                    <tr>
                        <td>#<?php echo (int)$result['post_id']; ?></td>
                        <td><?php echo esc_html(date_i18n('Y-m-d H:i:s', strtotime($result['timestamp']))); ?></td>
                        <td>
                            <span class="<?php echo $result['digest_valid'] ? 'integrity-valid' : 'integrity-invalid'; ?>">
                                <?php echo $result['digest_valid'] ? 'Valid' : 'Invalid'; ?>
                            </span>
                        </td>
                        <td>
                            <span class="<?php echo $result['chain_valid'] ? 'integrity-valid' : 'integrity-invalid'; ?>">
                                <?php echo $result['chain_valid'] ? 'Valid' : 'Broken'; ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
    
    /**
     * Register settings (for future API use)
     */
    public function register_settings() {
        // Future: add settings page here
    }
}

