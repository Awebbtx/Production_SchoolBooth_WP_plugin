<?php
/**
 * Admin Audit Log Viewer
 *
 * Displays the immutable audit trail with search, filters, pagination,
 * expandable detail rows, and CSV export.
 */
class SCHOOLBOOTH_Admin_Audit_Viewer {

    const PER_PAGE = 50;
    const EXPORT_ACTION = 'schoolbooth_audit_export_csv';

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
        add_action('admin_post_' . self::EXPORT_ACTION, [$this, 'handle_csv_export']);
    }

    public function register_menu() {
        $capability = current_user_can('manage_options') ? 'manage_options' : 'schoolbooth_audit_read';

        add_submenu_page(
            'schoolbooth',
            __('Photo Audit Log', 'schoolbooth'),
            __('Photo Audit Log', 'schoolbooth'),
            $capability,
            'schoolbooth-audit-log',
            [$this, 'render_audit_page']
        );
    }

    public function register_settings() {
        // Reserved for future use.
    }

    /**
     * Read filter values from $_GET.
     */
    private function get_filters() {
        $allowed_types = [
            'upload',
            'access_code_gen',
            'download_attempt',
            'form_submission',
            'manual_delete',
            'auto_delete',
        ];

        $type = isset($_GET['event_type']) ? sanitize_key($_GET['event_type']) : '';
        if ($type !== '' && !in_array($type, $allowed_types, true)) {
            $type = '';
        }

        $status = isset($_GET['status']) ? sanitize_key($_GET['status']) : '';
        if (!in_array($status, ['', 'success', 'failure'], true)) {
            $status = '';
        }

        return [
            'event_type' => $type,
            'status'     => $status,
            'search'     => isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '',
            'from'       => isset($_GET['from']) ? sanitize_text_field(wp_unslash($_GET['from'])) : '',
            'to'         => isset($_GET['to']) ? sanitize_text_field(wp_unslash($_GET['to'])) : '',
            'paged'      => max(1, isset($_GET['paged']) ? (int) $_GET['paged'] : 1),
        ];
    }

    /**
     * Apply filters in PHP to the array of events returned by the logger.
     */
    private function filter_events(array $events, array $filters) {
        $needle  = strtolower($filters['search']);
        $from_ts = $filters['from'] !== '' ? strtotime($filters['from'] . ' 00:00:00 UTC') : null;
        $to_ts   = $filters['to']   !== '' ? strtotime($filters['to']   . ' 23:59:59 UTC') : null;

        $out = [];
        foreach ($events as $event) {
            if ($filters['event_type'] !== '' && (isset($event['event_type']) ? $event['event_type'] : '') !== $filters['event_type']) {
                continue;
            }

            if ($filters['status'] !== '') {
                $is_success = $this->is_event_success($event);
                if ($filters['status'] === 'success' && !$is_success) continue;
                if ($filters['status'] === 'failure' &&  $is_success) continue;
            }

            $ts = isset($event['timestamp']) ? strtotime($event['timestamp']) : false;
            if ($from_ts !== null && $ts !== false && $ts < $from_ts) continue;
            if ($to_ts   !== null && $ts !== false && $ts > $to_ts)   continue;

            if ($needle !== '') {
                $haystack = strtolower(wp_json_encode($event));
                if (strpos($haystack, $needle) === false) {
                    continue;
                }
            }

            $out[] = $event;
        }

        return $out;
    }

    public function render_audit_page() {
        if (!current_user_can('manage_options') && !current_user_can('schoolbooth_audit_read')) {
            wp_die(__('You do not have permission to view audit logs.', 'schoolbooth'));
        }

        $filters = $this->get_filters();

        $audit  = SCHOOLBOOTH_Audit_Logger::init();
        $events = $audit->get_events();

        $error_msg = '';
        if (is_wp_error($events)) {
            $error_msg = $events->get_error_message();
            $events = [];
        }

        // Newest first.
        $events   = array_reverse($events);
        $filtered = $this->filter_events($events, $filters);

        $total      = count($filtered);
        $per_page   = self::PER_PAGE;
        $page_count = max(1, (int) ceil($total / $per_page));
        $paged      = min($filters['paged'], $page_count);
        $offset     = ($paged - 1) * $per_page;
        $page_slice = array_slice($filtered, $offset, $per_page);

        $export_url = wp_nonce_url(
            add_query_arg(
                array_merge(
                    ['action' => self::EXPORT_ACTION],
                    array_intersect_key($filters, array_flip(['event_type','status','search','from','to']))
                ),
                admin_url('admin-post.php')
            ),
            self::EXPORT_ACTION
        );
        ?>
        <div class="wrap schoolbooth-audit">
            <h1><?php esc_html_e('Photo System Audit Log', 'schoolbooth'); ?></h1>

            <?php if ($error_msg): ?>
                <div class="notice notice-error"><p><?php echo esc_html($error_msg); ?></p></div>
            <?php endif; ?>

            <h2 class="nav-tab-wrapper">
                <a href="#events"    class="nav-tab nav-tab-active" data-tab="events"><?php esc_html_e('All Events', 'schoolbooth'); ?></a>
                <a href="#timeline"  class="nav-tab"                data-tab="timeline"><?php esc_html_e('Timeline', 'schoolbooth'); ?></a>
                <a href="#integrity" class="nav-tab"                data-tab="integrity"><?php esc_html_e('Chain Integrity', 'schoolbooth'); ?></a>
            </h2>

            <div id="events" class="tab-content">
                <?php $this->render_filter_bar($filters, $total, $export_url); ?>
                <?php $this->render_events_table($page_slice); ?>
                <?php $this->render_pagination($paged, $page_count); ?>
            </div>

            <div id="timeline" class="tab-content" style="display:none;">
                <?php $this->render_timeline($filtered); ?>
            </div>

            <div id="integrity" class="tab-content" style="display:none;">
                <?php $this->render_integrity_check(); ?>
            </div>
        </div>

        <?php $this->render_styles_and_scripts(); ?>
        <?php
    }

    private function render_filter_bar(array $filters, $total, $export_url) {
        $page_slug = 'schoolbooth-audit-log';
        $event_labels = [
            'upload'           => __('Upload', 'schoolbooth'),
            'access_code_gen'  => __('Access code generated', 'schoolbooth'),
            'download_attempt' => __('Download attempt', 'schoolbooth'),
            'form_submission'  => __('Consent form submission', 'schoolbooth'),
            'manual_delete'    => __('Manual delete', 'schoolbooth'),
            'auto_delete'      => __('Auto delete', 'schoolbooth'),
        ];
        ?>
        <form method="get" class="schoolbooth-filter-bar">
            <input type="hidden" name="page" value="<?php echo esc_attr($page_slug); ?>" />

            <label>
                <span class="screen-reader-text"><?php esc_html_e('Search', 'schoolbooth'); ?></span>
                <input type="search" name="s" value="<?php echo esc_attr($filters['search']); ?>"
                       placeholder="<?php esc_attr_e('Search file, code, email, IP, name…', 'schoolbooth'); ?>"
                       style="min-width:280px;" />
            </label>

            <label>
                <span class="screen-reader-text"><?php esc_html_e('Event type', 'schoolbooth'); ?></span>
                <select name="event_type">
                    <option value=""><?php esc_html_e('Any event type', 'schoolbooth'); ?></option>
                    <?php foreach ($event_labels as $value => $label): ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($filters['event_type'], $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span class="screen-reader-text"><?php esc_html_e('Status', 'schoolbooth'); ?></span>
                <select name="status">
                    <option value=""        <?php selected($filters['status'], ''); ?>><?php esc_html_e('Any status', 'schoolbooth'); ?></option>
                    <option value="success" <?php selected($filters['status'], 'success'); ?>><?php esc_html_e('Success', 'schoolbooth'); ?></option>
                    <option value="failure" <?php selected($filters['status'], 'failure'); ?>><?php esc_html_e('Failure', 'schoolbooth'); ?></option>
                </select>
            </label>

            <label>
                <?php esc_html_e('From', 'schoolbooth'); ?>
                <input type="date" name="from" value="<?php echo esc_attr($filters['from']); ?>" />
            </label>

            <label>
                <?php esc_html_e('To', 'schoolbooth'); ?>
                <input type="date" name="to" value="<?php echo esc_attr($filters['to']); ?>" />
            </label>

            <button type="submit" class="button button-primary"><?php esc_html_e('Apply', 'schoolbooth'); ?></button>
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . $page_slug)); ?>"><?php esc_html_e('Reset', 'schoolbooth'); ?></a>
            <a class="button button-secondary" href="<?php echo esc_url($export_url); ?>"><?php esc_html_e('Export CSV', 'schoolbooth'); ?></a>

            <span class="schoolbooth-result-count">
                <?php echo esc_html(sprintf(
                    /* translators: %s: number of matching audit events */
                    _n('%s matching event', '%s matching events', $total, 'schoolbooth'),
                    number_format_i18n($total)
                )); ?>
            </span>
        </form>
        <?php
    }

    private function render_events_table(array $events) {
        if (empty($events)) {
            echo '<p>' . esc_html__('No matching audit events.', 'schoolbooth') . '</p>';
            return;
        }
        ?>
        <table class="widefat striped audit-table">
            <thead>
                <tr>
                    <th style="width:24px;"></th>
                    <th><?php esc_html_e('Timestamp (UTC)', 'schoolbooth'); ?></th>
                    <th><?php esc_html_e('Event', 'schoolbooth'); ?></th>
                    <th><?php esc_html_e('Photo', 'schoolbooth'); ?></th>
                    <th><?php esc_html_e('Access code', 'schoolbooth'); ?></th>
                    <th><?php esc_html_e('User / IP', 'schoolbooth'); ?></th>
                    <th><?php esc_html_e('Status', 'schoolbooth'); ?></th>
                    <th><?php esc_html_e('Summary', 'schoolbooth'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($events as $event) {
                    $this->render_event_row($event);
                } ?>
            </tbody>
        </table>
        <?php
    }

    private function render_event_row(array $event) {
        $success    = $this->is_event_success($event);
        $type       = isset($event['event_type']) ? $event['event_type'] : 'unknown';
        $timestamp  = isset($event['timestamp']) ? esc_html(date_i18n('Y-m-d H:i:s', strtotime($event['timestamp']))) : '--';
        $data       = isset($event['data']) ? $event['data'] : [];
        $file       = isset($data['file']) ? $data['file'] : (isset($data['filename']) ? $data['filename'] : '');
        $code       = isset($data['code']) ? $data['code'] : (isset($data['access_code']) ? $data['access_code'] : '');
        $ip         = isset($event['ip_address']) ? $event['ip_address'] : '';
        $user_id    = isset($event['user_id']) ? (int) $event['user_id'] : 0;
        $user_label = $ip;
        if ($user_id > 0) {
            $user = get_userdata($user_id);
            if ($user) {
                $user_label = $user->user_email . ' (' . $ip . ')';
            }
        }
        $detail_id = 'audit-detail-' . (isset($event['post_id']) ? (int) $event['post_id'] : mt_rand());
        ?>
        <tr class="audit-row">
            <td>
                <button type="button" class="button-link audit-toggle"
                        aria-expanded="false" aria-controls="<?php echo esc_attr($detail_id); ?>"
                        title="<?php esc_attr_e('Show full event JSON', 'schoolbooth'); ?>">+</button>
            </td>
            <td><?php echo $timestamp; ?></td>
            <td>
                <span class="event-type <?php echo esc_attr($type); ?>"><?php echo esc_html($type); ?></span>
                <?php if (!empty($event['post_id'])): ?>
                    <div class="audit-postid">#<?php echo (int) $event['post_id']; ?></div>
                <?php endif; ?>
            </td>
            <td><?php echo $file !== '' ? esc_html(basename($file)) : '--'; ?></td>
            <td><?php echo $code !== '' ? '<code>' . esc_html($code) . '</code>' : '--'; ?></td>
            <td><?php echo esc_html($user_label !== '' ? $user_label : '--'); ?></td>
            <td>
                <span class="<?php echo $success ? 'success' : 'failure'; ?>">
                    <?php echo $success ? esc_html__('Success', 'schoolbooth') : esc_html__('Failed', 'schoolbooth'); ?>
                </span>
            </td>
            <td><?php echo esc_html($this->summarize_event($event)); ?></td>
        </tr>
        <tr id="<?php echo esc_attr($detail_id); ?>" class="audit-detail" style="display:none;">
            <td></td>
            <td colspan="7">
                <pre><?php echo esc_html(wp_json_encode($event, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
            </td>
        </tr>
        <?php
    }

    private function summarize_event(array $event) {
        $type   = isset($event['event_type']) ? $event['event_type'] : '';
        $data   = isset($event['data']) ? $event['data'] : [];
        $reason = isset($data['reason']) ? $data['reason'] : '';

        switch ($type) {
            case 'form_submission':
                $name  = isset($data['consent_name'])  ? $data['consent_name']  : '';
                $email = isset($data['consent_email']) ? $data['consent_email'] : '';
                if ($name !== '' || $email !== '') {
                    return trim($name . ($email !== '' ? " <{$email}>" : ''));
                }
                if (!empty($data['email_domain'])) {
                    return 'Domain: ' . $data['email_domain'];
                }
                return $reason !== '' ? $reason : '--';

            case 'download_attempt':
                if (!empty($data['success'])) {
                    $used = isset($data['downloads_used']) ? (int) $data['downloads_used'] : null;
                    return $used !== null ? sprintf('Download #%d', $used) : 'Download successful';
                }
                return $reason !== '' ? 'Failed: ' . $reason : 'Failed';

            case 'upload':
                $src = isset($data['source']) ? $data['source'] : '';
                return $src !== '' ? 'Source: ' . $src : '--';

            case 'access_code_gen':
                return 'Code: ' . (isset($data['code']) ? $data['code'] : '--');

            case 'manual_delete':
            case 'auto_delete':
                return $reason !== '' ? $reason : '--';
        }
        return $reason !== '' ? $reason : '--';
    }

    private function render_pagination($paged, $page_count) {
        if ($page_count <= 1) {
            return;
        }
        $base = remove_query_arg('paged');
        echo '<div class="tablenav"><div class="tablenav-pages">';
        echo '<span class="displaying-num">' . esc_html(sprintf(
            /* translators: 1: current page, 2: total pages */
            __('Page %1$s of %2$s', 'schoolbooth'),
            number_format_i18n($paged),
            number_format_i18n($page_count)
        )) . '</span> ';
        for ($p = 1; $p <= $page_count; $p++) {
            $url = add_query_arg('paged', $p, $base);
            if ($p === $paged) {
                echo '<span class="page-numbers current">' . (int) $p . '</span> ';
            } else {
                echo '<a class="page-numbers" href="' . esc_url($url) . '">' . (int) $p . '</a> ';
            }
        }
        echo '</div></div>';
    }

    private function render_timeline(array $events) {
        if (empty($events)) {
            echo '<p>' . esc_html__('No matching audit events.', 'schoolbooth') . '</p>';
            return;
        }
        echo '<div class="timeline">';
        foreach (array_slice($events, 0, 200) as $event) {
            $success = $this->is_event_success($event);
            $class   = $success ? 'success' : 'failure';
            $type    = isset($event['event_type']) ? $event['event_type'] : '?';
            echo '<div class="timeline-item ' . esc_attr($class) . '">';
            echo '<span class="timeline-time">' . esc_html(date_i18n('Y-m-d H:i:s', strtotime($event['timestamp']))) . '</span>';
            echo '<span class="timeline-event">' . esc_html($type . ' — ' . $this->summarize_event($event)) . '</span>';
            echo '<span class="timeline-ip">' . esc_html(isset($event['ip_address']) ? $event['ip_address'] : '') . '</span>';
            echo '</div>';
        }
        echo '</div>';
    }

    private function is_event_success($event) {
        if (isset($event['data']['success'])) {
            return (bool) $event['data']['success'];
        }
        $type   = isset($event['event_type']) ? $event['event_type'] : '';
        $reason = isset($event['data']['reason']) ? $event['data']['reason'] : '';
        if ($type === 'download_attempt') {
            return $reason === '';
        }
        return true;
    }

    private function render_integrity_check() {
        $audit   = SCHOOLBOOTH_Audit_Logger::init();
        $results = $audit->verify_chain_integrity();

        if (empty($results)) {
            echo '<p>' . esc_html__('No audit events to verify.', 'schoolbooth') . '</p>';
            return;
        }

        $all_valid = true;
        foreach ($results as $r) {
            if (!$r['digest_valid'] || !$r['chain_valid']) { $all_valid = false; break; }
        }

        if ($all_valid) {
            echo '<div class="notice notice-success inline"><p><strong>' . esc_html__('Audit trail integrity verified', 'schoolbooth') . '</strong> — ' . esc_html__('all events are signed correctly and chain is unbroken.', 'schoolbooth') . '</p></div>';
        } else {
            echo '<div class="notice notice-error inline"><p><strong>' . esc_html__('Integrity issues detected', 'schoolbooth') . '</strong> — ' . esc_html__('one or more events may have been tampered with.', 'schoolbooth') . '</p></div>';
        }
        ?>
        <table class="widefat striped audit-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Post ID', 'schoolbooth'); ?></th>
                    <th><?php esc_html_e('Timestamp', 'schoolbooth'); ?></th>
                    <th><?php esc_html_e('Digest', 'schoolbooth'); ?></th>
                    <th><?php esc_html_e('Chain', 'schoolbooth'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($results as $r): ?>
                <tr>
                    <td>#<?php echo (int) $r['post_id']; ?></td>
                    <td><?php echo esc_html(date_i18n('Y-m-d H:i:s', strtotime($r['timestamp']))); ?></td>
                    <td><span class="<?php echo $r['digest_valid'] ? 'integrity-valid' : 'integrity-invalid'; ?>"><?php echo $r['digest_valid'] ? esc_html__('Valid', 'schoolbooth') : esc_html__('Invalid', 'schoolbooth'); ?></span></td>
                    <td><span class="<?php echo $r['chain_valid'] ? 'integrity-valid' : 'integrity-invalid'; ?>"><?php echo $r['chain_valid'] ? esc_html__('Valid', 'schoolbooth') : esc_html__('Broken', 'schoolbooth'); ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * CSV export handler. Hooked to admin_post_<action>.
     */
    public function handle_csv_export() {
        if (!current_user_can('manage_options') && !current_user_can('schoolbooth_audit_read')) {
            wp_die(__('You do not have permission to export audit logs.', 'schoolbooth'));
        }
        check_admin_referer(self::EXPORT_ACTION);

        $filters = $this->get_filters();
        $audit   = SCHOOLBOOTH_Audit_Logger::init();
        $events  = $audit->get_events();
        if (is_wp_error($events)) {
            wp_die(esc_html($events->get_error_message()));
        }
        $events   = array_reverse($events);
        $filtered = $this->filter_events($events, $filters);

        $filename = 'schoolbooth-audit-' . gmdate('Ymd-His') . '.csv';

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        // BOM so Excel opens UTF-8 cleanly.
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [
            'post_id','timestamp_utc','event_type','status','user_id','ip_address',
            'file','access_code','consent_name','consent_email','email_domain',
            'reason','source','downloads_used','digest','prev_digest','data_json',
        ]);
        foreach ($filtered as $e) {
            $d = isset($e['data']) ? $e['data'] : [];
            fputcsv($out, [
                isset($e['post_id'])     ? $e['post_id']     : '',
                isset($e['timestamp'])   ? $e['timestamp']   : '',
                isset($e['event_type'])  ? $e['event_type']  : '',
                $this->is_event_success($e) ? 'success' : 'failure',
                isset($e['user_id'])     ? (int) $e['user_id'] : 0,
                isset($e['ip_address'])  ? $e['ip_address']  : '',
                isset($d['file']) ? $d['file'] : (isset($d['filename']) ? $d['filename'] : ''),
                isset($d['code']) ? $d['code'] : (isset($d['access_code']) ? $d['access_code'] : ''),
                isset($d['consent_name'])   ? $d['consent_name']   : '',
                isset($d['consent_email'])  ? $d['consent_email']  : '',
                isset($d['email_domain'])   ? $d['email_domain']   : '',
                isset($d['reason'])         ? $d['reason']         : '',
                isset($d['source'])         ? $d['source']         : '',
                isset($d['downloads_used']) ? $d['downloads_used'] : '',
                isset($e['digest'])         ? $e['digest']         : '',
                isset($e['prev_digest'])    ? $e['prev_digest']    : '',
                wp_json_encode($d),
            ]);
        }
        fclose($out);
        exit;
    }

    private function render_styles_and_scripts() {
        ?>
        <style>
            .schoolbooth-audit .nav-tab-wrapper { margin-bottom: 0; }
            .schoolbooth-audit .tab-content { background: #fff; padding: 16px; border: 1px solid #c3c4c7; border-top: 0; }
            .schoolbooth-filter-bar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 12px; }
            .schoolbooth-filter-bar label { display: flex; align-items: center; gap: 4px; }
            .schoolbooth-result-count { margin-left: auto; color: #50575e; }
            .audit-table .audit-row td { vertical-align: top; }
            .audit-table .audit-detail pre {
                background:#1d2327; color:#dcdcaa; padding:12px;
                margin:0; overflow:auto; max-height:400px;
                font-size:12px; line-height:1.45;
            }
            .audit-table .audit-postid { color:#646970; font-size: 11px; margin-top:2px; }
            .audit-toggle {
                font-family: monospace; font-size: 14px; line-height: 1;
                padding: 0 6px; color: #2271b1;
            }
            .event-type {
                padding: 2px 6px; border-radius: 3px;
                font-size: 11px; font-weight: 600; text-transform: uppercase;
                display: inline-block;
            }
            .event-type.upload           { background: #d4edda; color: #155724; }
            .event-type.access_code_gen  { background: #d1ecf1; color: #0c5460; }
            .event-type.download_attempt { background: #e2e3e5; color: #383d41; }
            .event-type.form_submission  { background: #fff3cd; color: #856404; }
            .event-type.manual_delete    { background: #f8d7da; color: #721c24; }
            .event-type.auto_delete      { background: #f5c6cb; color: #721c24; }
            .success { color: #155724; font-weight: 600; }
            .failure { color: #721c24; font-weight: 600; }
            .integrity-valid   { color: #155724; font-weight: 600; }
            .integrity-invalid { color: #721c24; font-weight: 600; }
            .timeline { padding: 12px 0; }
            .timeline-item { margin-bottom: 12px; padding: 12px; border-left: 3px solid #2271b1; background: #f6f7f7; }
            .timeline-item.success { border-left-color: #155724; }
            .timeline-item.failure { border-left-color: #721c24; }
            .timeline-time  { display: block; font-weight: 600; }
            .timeline-event { display: block; color: #50575e; font-size: 13px; margin-top: 4px; }
            .timeline-ip    { display: block; color: #8c8f94; font-size: 12px; margin-top: 2px; }
        </style>
        <script>
        jQuery(function($){
            $('.nav-tab').on('click', function(e){
                e.preventDefault();
                var tab = $(this).data('tab');
                $('.nav-tab').removeClass('nav-tab-active');
                $('.tab-content').hide();
                $(this).addClass('nav-tab-active');
                $('#' + tab).show();
            });
            $('.schoolbooth-audit').on('click', '.audit-toggle', function(){
                var $btn = $(this);
                var id = $btn.attr('aria-controls');
                var $row = $('#' + id);
                var isOpen = $row.is(':visible');
                $row.toggle(!isOpen);
                $btn.attr('aria-expanded', !isOpen);
                $btn.text(isOpen ? '+' : '−');
            });
        });
        </script>
        <?php
    }
}
