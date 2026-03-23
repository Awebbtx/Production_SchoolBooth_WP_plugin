<?php
class PTASB_Download_Admin_Settings {
    private static $instance;
    protected $options;

    public static function init() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->options = get_option('pta_schoolbooth_settings');
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'settings_init']);
        add_action('admin_enqueue_scripts', [$this, 'admin_styles']);
    }

    public function admin_styles() {
        wp_enqueue_style(
            'pta-schoolbooth-admin-css',
            PTASB_DOWNLOAD_URL . 'assets/css/admin.css',
            [],
            PTASB_DOWNLOAD_VERSION
        );
    }

    public function add_admin_menu() {
        add_menu_page(
            __('Schoolbooth', 'pta-schoolbooth'),
            __('Schoolbooth', 'pta-schoolbooth'),
            'manage_options',
            'pta-schoolbooth',
            [$this, 'general_page'],
            'dashicons-format-gallery',
            56
        );

        add_submenu_page(
            'pta-schoolbooth',
            __('General Settings', 'pta-schoolbooth'),
            __('General', 'pta-schoolbooth'),
            'manage_options',
            'pta-schoolbooth',
            [$this, 'general_page']
        );

        add_submenu_page(
            'pta-schoolbooth',
            __('Access & Security', 'pta-schoolbooth'),
            __('Access & Security', 'pta-schoolbooth'),
            'manage_options',
            'pta-schoolbooth-security',
            [$this, 'security_page']
        );

        add_submenu_page(
            'pta-schoolbooth',
            __('Consent Policy', 'pta-schoolbooth'),
            __('Consent Policy', 'pta-schoolbooth'),
            'manage_options',
            'pta-schoolbooth-consent',
            [$this, 'consent_page']
        );

        add_submenu_page(
            'pta-schoolbooth',
            __('Portal Setup', 'pta-schoolbooth'),
            __('Portal Setup', 'pta-schoolbooth'),
            'manage_options',
            'pta-schoolbooth-portal',
            [$this, 'portal_setup_page']
        );
    }

    public function settings_init() {
        register_setting('pta_schoolbooth_download', 'pta_schoolbooth_settings', [$this, 'sanitize_settings']);
    }

    public function sanitize_settings($input) {
        $existing = get_option('pta_schoolbooth_settings', []);
        $input = is_array($input) ? $input : [];
        $sanitized = array_merge($existing, $input);

        if (isset($input['shared_secret'])) {
            $sanitized['shared_secret'] = sanitize_text_field($input['shared_secret']);

            if (!empty($sanitized['shared_secret']) && strlen($sanitized['shared_secret']) < 32) {
                add_settings_error(
                    'pta_schoolbooth_settings',
                    'ptasb_short_secret',
                    __('Shared Secret must be at least 32 characters', 'pta-schoolbooth'),
                    'error'
                );
                $sanitized['shared_secret'] = isset($existing['shared_secret']) ? $existing['shared_secret'] : PTASB_SHARED_SECRET;
            }
        }

        if (isset($input['download_limit'])) {
            $sanitized['download_limit'] = max(1, absint($input['download_limit']));
        }

        if (isset($input['expiry_days'])) {
            $sanitized['expiry_days'] = max(1, absint($input['expiry_days']));
        }

        if (isset($input['upload_path'])) {
            $sanitized['upload_path'] = trim(sanitize_text_field($input['upload_path']), " /\\");
            if ($sanitized['upload_path'] === '') {
                $sanitized['upload_path'] = 'pta-schoolbooth';
            }
        }

        if (isset($input['consent_policy_html'])) {
            $sanitized['consent_policy_html'] = wp_kses_post($input['consent_policy_html']);
        }

        if (isset($input['consent_checkbox_label'])) {
            $sanitized['consent_checkbox_label'] = sanitize_text_field($input['consent_checkbox_label']);
        }

        if (isset($input['privacy_policy_page_title'])) {
            $sanitized['privacy_policy_page_title'] = sanitize_text_field($input['privacy_policy_page_title']);
        }

        $entity_fields = [
            'entity_school_name',
            'entity_district_name',
            'entity_association_name',
            'entity_service_provider_name',
            'entity_privacy_policy_url',
            'entity_governing_law_state',
            'entity_arbitration_county',
        ];
        foreach ($entity_fields as $field_key) {
            if (isset($input[$field_key])) {
                if ($field_key === 'entity_privacy_policy_url') {
                    $sanitized[$field_key] = esc_url_raw($input[$field_key]);
                } else {
                    $sanitized[$field_key] = sanitize_text_field($input[$field_key]);
                }
            }
        }

        if (isset($_POST['ptasb_load_consent_template'])) {
            $sanitized['consent_policy_html'] = $this->get_default_consent_template();
            if (empty($sanitized['consent_checkbox_label'])) {
                $sanitized['consent_checkbox_label'] = __('I certify that I have read and agree to the photo release terms.', 'pta-schoolbooth');
            }
            add_settings_error(
                'pta_schoolbooth_settings',
                'ptasb_consent_template_loaded',
                __('Consent policy template loaded. Review and save to apply.', 'pta-schoolbooth'),
                'updated'
            );
        }

        if (isset($_POST['ptasb_build_privacy_policy_page'])) {
            $builder_result = $this->build_privacy_policy_page($sanitized, $existing);
            $sanitized = array_merge($sanitized, $builder_result['options']);

            if (!empty($builder_result['error'])) {
                add_settings_error(
                    'pta_schoolbooth_settings',
                    'ptasb_privacy_page_error',
                    $builder_result['error'],
                    'error'
                );
            } else {
                add_settings_error(
                    'pta_schoolbooth_settings',
                    'ptasb_privacy_page_success',
                    sprintf(
                        __('Privacy Policy page saved: %s', 'pta-schoolbooth'),
                        '<a href="' . esc_url($builder_result['edit_url']) . '">' . esc_html($builder_result['page_title']) . '</a> | <a href="' . esc_url($builder_result['view_url']) . '" target="_blank">' . esc_html__('View', 'pta-schoolbooth') . '</a>'
                    ),
                    'updated'
                );
            }
        }

        // REST API endpoint is installed/selected server-side only.
        if (isset($_POST['ptasb_install_api_path'])) {
            $sanitized['rest_api_endpoint'] = $this->detect_rest_api_endpoint();
            add_settings_error(
                'pta_schoolbooth_settings',
                'ptasb_api_path_installed',
                sprintf(
                    __('API path installed: %s', 'pta-schoolbooth'),
                    esc_html($sanitized['rest_api_endpoint'])
                ),
                'updated'
            );
        } elseif (empty($existing['rest_api_endpoint'])) {
            $sanitized['rest_api_endpoint'] = $this->detect_rest_api_endpoint();
        } else {
            $sanitized['rest_api_endpoint'] = $existing['rest_api_endpoint'];
        }

        return $sanitized;
    }

    private function detect_rest_api_endpoint() {
        $routes = rest_get_server()->get_routes();

        if (isset($routes['/pta-schoolbooth/v1/ingest'])) {
            return '/wp-json/pta-schoolbooth/v1/ingest';
        }

        return '/wp-json/pta-schoolbooth/v1/ingest';
    }

    public function general_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions', 'pta-schoolbooth'));
        }

        $options = get_option('pta_schoolbooth_settings', []);
        ?>
        <div class="wrap">
            <h1><?php _e('Schoolbooth: General', 'pta-schoolbooth'); ?></h1>
            <?php settings_errors('pta_schoolbooth_settings'); ?>

            <form action="options.php" method="post">
                <?php settings_fields('pta_schoolbooth_download'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="pta_schoolbooth_download_limit"><?php _e('Max Downloads', 'pta-schoolbooth'); ?></label></th>
                        <td>
                            <input id="pta_schoolbooth_download_limit" type="number" name="pta_schoolbooth_settings[download_limit]"
                                value="<?php echo esc_attr(isset($options['download_limit']) ? $options['download_limit'] : 3); ?>" min="1" class="small-text">
                            <p class="description"><?php _e('Maximum successful downloads allowed per photo.', 'pta-schoolbooth'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ptasb_expiry_days"><?php _e('Days Until Auto-Delete', 'pta-schoolbooth'); ?></label></th>
                        <td>
                            <input id="ptasb_expiry_days" type="number" name="pta_schoolbooth_settings[expiry_days]"
                                value="<?php echo esc_attr(isset($options['expiry_days']) ? $options['expiry_days'] : 7); ?>" min="1" class="small-text">
                            <p class="description"><?php _e('Photos and access entries expire after this many days.', 'pta-schoolbooth'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ptasb_storage_path"><?php _e('Storage Path', 'pta-schoolbooth'); ?></label></th>
                        <td>
                            <input id="ptasb_storage_path" type="text" name="pta_schoolbooth_settings[upload_path]"
                                value="<?php echo esc_attr(isset($options['upload_path']) ? $options['upload_path'] : 'pta-schoolbooth'); ?>" class="regular-text">
                            <p class="description"><?php _e('Relative to wp-content/uploads/. Example: pta-schoolbooth', 'pta-schoolbooth'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Save General Settings', 'pta-schoolbooth')); ?>
            </form>
        </div>
        <?php
    }

    public function security_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions', 'pta-schoolbooth'));
        }

        $options = get_option('pta_schoolbooth_settings', []);
        $endpoint = isset($options['rest_api_endpoint']) ? $options['rest_api_endpoint'] : '/wp-json/pta-schoolbooth/v1/ingest';
        $full_url = home_url($endpoint);
        $enrolled_devices = isset($options['enrolled_devices']) && is_array($options['enrolled_devices'])
            ? $options['enrolled_devices']
            : [];
        ?>
        <div class="wrap">
            <h1><?php _e('Schoolbooth: Access & Security', 'pta-schoolbooth'); ?></h1>
            <?php settings_errors('pta_schoolbooth_settings'); ?>

            <form action="options.php" method="post">
                <?php settings_fields('pta_schoolbooth_download'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php _e('REST API Endpoint', 'pta-schoolbooth'); ?></th>
                        <td>
                            <input type="text" value="<?php echo esc_attr($full_url); ?>" class="regular-text" readonly>
                            <button type="button" class="button" style="margin-left:8px;" onclick="(function(btn){var i=btn.previousElementSibling;if(!i){return false;}i.focus();i.select();if(i.setSelectionRange){i.setSelectionRange(0,99999);}if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(i.value);}else{document.execCommand('copy');}var t=btn.textContent;btn.textContent='Copied';setTimeout(function(){btn.textContent=t;},1200);return false;})(this); return false;"><?php esc_html_e('Copy', 'pta-schoolbooth'); ?></button>
                            <button type="submit" name="ptasb_install_api_path" value="1" class="button" style="margin-left:8px;"><?php esc_html_e('Install API Path', 'pta-schoolbooth'); ?></button>
                            <p class="description"><?php _e('Read-only ingest URL for clients. Install API Path auto-detects supported namespaces.', 'pta-schoolbooth'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Save Security Settings', 'pta-schoolbooth')); ?>
            </form>

            <hr>
            <h2><?php _e('Enrolled Devices', 'pta-schoolbooth'); ?></h2>
            <?php if (empty($enrolled_devices)) : ?>
                <p><?php _e('No devices have enrolled yet. Use the Schoolbooth app and click "Enroll via WordPress Login" to provision a device.', 'pta-schoolbooth'); ?></p>
            <?php else : ?>
                <table class="widefat striped" style="max-width:900px;">
                    <thead>
                        <tr>
                            <th><?php _e('App Name', 'pta-schoolbooth'); ?></th>
                            <th><?php _e('Instance ID', 'pta-schoolbooth'); ?></th>
                            <th><?php _e('Enrolled By', 'pta-schoolbooth'); ?></th>
                            <th><?php _e('Enrolled At (UTC)', 'pta-schoolbooth'); ?></th>
                            <th><?php _e('Method', 'pta-schoolbooth'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($enrolled_devices as $device) :
                            $app_name_d    = isset($device['app_name'])          ? esc_html($device['app_name'])          : '—';
                            $instance_d    = isset($device['instance_id'])       ? esc_html($device['instance_id'])       : '—';
                            $enrolled_by_d = isset($device['enrolled_by'])       ? esc_html($device['enrolled_by'])       : '—';
                            $enrolled_at_d = isset($device['enrolled_at'])       ? esc_html($device['enrolled_at'])       : '—';
                            $method_d      = isset($device['enrollment_method']) ? esc_html($device['enrollment_method']) : '—';
                        ?>
                        <tr>
                            <td><?php echo $app_name_d; ?></td>
                            <td><code><?php echo $instance_d; ?></code></td>
                            <td><?php echo $enrolled_by_d; ?></td>
                            <td><?php echo $enrolled_at_d; ?></td>
                            <td><?php echo $method_d; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="description" style="margin-top:8px;"><?php _e('Devices are recorded each time an app completes enrollment. Keyed by instance ID when provided, up to 20 anonymous entries retained.', 'pta-schoolbooth'); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    public function consent_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions', 'pta-schoolbooth'));
        }

        $options = get_option('pta_schoolbooth_settings', []);
        $default_policy = '<p>' . __('By checking this box and submitting this form, I consent to the photo release policy for this event.', 'pta-schoolbooth') . '</p>';
        $policy_html = isset($options['consent_policy_html']) && $options['consent_policy_html'] !== ''
            ? $options['consent_policy_html']
            : $default_policy;
        $checkbox_label = isset($options['consent_checkbox_label']) && $options['consent_checkbox_label'] !== ''
            ? $options['consent_checkbox_label']
            : __('I certify that I have read and agree to the photo release terms.', 'pta-schoolbooth');

        $school_name = isset($options['entity_school_name']) ? $options['entity_school_name'] : '';
        $district_name = isset($options['entity_district_name']) ? $options['entity_district_name'] : '';
        $association_name = isset($options['entity_association_name']) ? $options['entity_association_name'] : 'School Parent Association';
        $provider_name = isset($options['entity_service_provider_name']) ? $options['entity_service_provider_name'] : 'IKAP System Schoolbooth Software';
        $privacy_policy_url = isset($options['entity_privacy_policy_url']) ? $options['entity_privacy_policy_url'] : '';
        $privacy_policy_page_title = isset($options['privacy_policy_page_title']) && $options['privacy_policy_page_title'] !== ''
            ? $options['privacy_policy_page_title']
            : sprintf(__('%s Photo Privacy Policy', 'pta-schoolbooth'), ($school_name !== '' ? $school_name : __('Our School', 'pta-schoolbooth')));
        $law_state = isset($options['entity_governing_law_state']) ? $options['entity_governing_law_state'] : 'Texas';
        $arbitration_county = isset($options['entity_arbitration_county']) ? $options['entity_arbitration_county'] : 'Comal County';
        ?>
        <div class="wrap">
            <h1><?php _e('Schoolbooth: Consent Policy', 'pta-schoolbooth'); ?></h1>
            <?php settings_errors('pta_schoolbooth_settings'); ?>

            <form action="options.php" method="post">
                <?php settings_fields('pta_schoolbooth_download'); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php _e('Template Autofill Entities', 'pta-schoolbooth'); ?></th>
                        <td>
                            <p class="description"><?php _e('These values populate placeholders inside the consent policy template.', 'pta-schoolbooth'); ?></p>
                            <table class="widefat striped" style="max-width:900px;margin-top:8px;">
                                <tbody>
                                    <tr>
                                        <td style="width:220px;"><label for="ptasb_entity_school_name"><strong><?php _e('School Name', 'pta-schoolbooth'); ?></strong></label></td>
                                        <td><input id="ptasb_entity_school_name" type="text" name="pta_schoolbooth_settings[entity_school_name]" value="<?php echo esc_attr($school_name); ?>" class="regular-text"></td>
                                    </tr>
                                    <tr>
                                        <td><label for="ptasb_entity_district_name"><strong><?php _e('District Name', 'pta-schoolbooth'); ?></strong></label></td>
                                        <td><input id="ptasb_entity_district_name" type="text" name="pta_schoolbooth_settings[entity_district_name]" value="<?php echo esc_attr($district_name); ?>" class="regular-text"></td>
                                    </tr>
                                    <tr>
                                        <td><label for="ptasb_entity_association_name"><strong><?php _e('Association Name', 'pta-schoolbooth'); ?></strong></label></td>
                                        <td><input id="ptasb_entity_association_name" type="text" name="pta_schoolbooth_settings[entity_association_name]" value="<?php echo esc_attr($association_name); ?>" class="regular-text"></td>
                                    </tr>
                                    <tr>
                                        <td><label for="ptasb_entity_service_provider_name"><strong><?php _e('Service Provider', 'pta-schoolbooth'); ?></strong></label></td>
                                        <td><input id="ptasb_entity_service_provider_name" type="text" name="pta_schoolbooth_settings[entity_service_provider_name]" value="<?php echo esc_attr($provider_name); ?>" class="regular-text"></td>
                                    </tr>
                                    <tr>
                                        <td><label for="ptasb_entity_privacy_policy_url"><strong><?php _e('Privacy Policy URL', 'pta-schoolbooth'); ?></strong></label></td>
                                        <td><input id="ptasb_entity_privacy_policy_url" type="url" name="pta_schoolbooth_settings[entity_privacy_policy_url]" value="<?php echo esc_attr($privacy_policy_url); ?>" class="regular-text"></td>
                                    </tr>
                                    <tr>
                                        <td><label for="ptasb_entity_governing_law_state"><strong><?php _e('Governing Law State', 'pta-schoolbooth'); ?></strong></label></td>
                                        <td><input id="ptasb_entity_governing_law_state" type="text" name="pta_schoolbooth_settings[entity_governing_law_state]" value="<?php echo esc_attr($law_state); ?>" class="regular-text"></td>
                                    </tr>
                                    <tr>
                                        <td><label for="ptasb_entity_arbitration_county"><strong><?php _e('Arbitration County', 'pta-schoolbooth'); ?></strong></label></td>
                                        <td><input id="ptasb_entity_arbitration_county" type="text" name="pta_schoolbooth_settings[entity_arbitration_county]" value="<?php echo esc_attr($arbitration_county); ?>" class="regular-text"></td>
                                    </tr>
                                </tbody>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ptasb_consent_checkbox_label"><?php _e('Consent Checkbox Label', 'pta-schoolbooth'); ?></label></th>
                        <td>
                            <input id="ptasb_consent_checkbox_label" type="text" name="pta_schoolbooth_settings[consent_checkbox_label]"
                                value="<?php echo esc_attr($checkbox_label); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Consent Policy Content', 'pta-schoolbooth'); ?></th>
                        <td>
                            <?php
                            wp_editor(
                                $policy_html,
                                'ptasb_consent_policy_html',
                                [
                                    'textarea_name' => 'pta_schoolbooth_settings[consent_policy_html]',
                                    'textarea_rows' => 12,
                                    'media_buttons' => false,
                                    'teeny' => false,
                                ]
                            );
                            ?>
                            <p class="description"><?php _e('Shown above the consent checkbox on the permissions form.', 'pta-schoolbooth'); ?></p>
                            <p class="description"><strong><?php _e('Available placeholders:', 'pta-schoolbooth'); ?></strong><br>
                                <code>{{school_name}}</code>, <code>{{district_name}}</code>, <code>{{association_name}}</code>, <code>{{service_provider_name}}</code>,
                                <code>{{privacy_policy_url}}</code>, <code>{{governing_law_state}}</code>, <code>{{arbitration_county}}</code>, <code>{{expiry_days}}</code>
                            </p>
                        </td>
                    </tr>
                </table>

                <p>
                    <button type="submit" name="ptasb_load_consent_template" value="1" class="button button-secondary"><?php esc_html_e('Load Recommended Template', 'pta-schoolbooth'); ?></button>
                </p>

                <hr>

                <h2><?php _e('Privacy Policy Page Builder', 'pta-schoolbooth'); ?></h2>
                <p class="description"><?php _e('Create or update a WordPress Privacy Policy page using your consent entity settings.', 'pta-schoolbooth'); ?></p>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="ptasb_privacy_policy_page_title"><?php _e('Privacy Policy Page Title', 'pta-schoolbooth'); ?></label></th>
                        <td>
                            <input id="ptasb_privacy_policy_page_title" type="text" name="pta_schoolbooth_settings[privacy_policy_page_title]"
                                value="<?php echo esc_attr($privacy_policy_page_title); ?>" class="regular-text">
                            <p class="description"><?php _e('Used when creating or updating the privacy policy page.', 'pta-schoolbooth'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Use IKAP Template', 'pta-schoolbooth'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="ptasb_use_privacy_template" value="1" checked>
                                <?php _e('Build with the recommended privacy policy template and auto-filled consent entities.', 'pta-schoolbooth'); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <p>
                    <button type="submit" name="ptasb_build_privacy_policy_page" value="1" class="button button-secondary"><?php esc_html_e('Build / Update Privacy Policy Page', 'pta-schoolbooth'); ?></button>
                </p>

                <?php submit_button(__('Save Consent Policy', 'pta-schoolbooth')); ?>
            </form>
        </div>
        <?php
    }

    private function get_default_consent_template() {
        return '<div class="ptasb-consent-template">'
            . '<h3>Terms and Conditions</h3>'
            . '<p>By accessing these photos, you acknowledge that {{school_name}} is exercising its rights as stated below: <a href="{{privacy_policy_url}}" target="_blank" rel="noopener">View our full Privacy Policy</a>.</p>'
            . '<h4>Template Fields</h4>'
            . '<ul>'
            . '<li><strong>School:</strong> {{school_name}}</li>'
            . '<li><strong>District:</strong> {{district_name}}</li>'
            . '<li><strong>Association:</strong> {{association_name}}</li>'
            . '<li><strong>Service Provider:</strong> {{service_provider_name}}</li>'
            . '<li><strong>Privacy Policy URL:</strong> {{privacy_policy_url}}</li>'
            . '<li><strong>Governing Law:</strong> {{governing_law_state}}</li>'
            . '<li><strong>Arbitration County:</strong> {{arbitration_county}}</li>'
            . '<li><strong>Photo Retention Window:</strong> {{expiry_days}} days</li>'
            . '</ul>'
            . '<h4>IKAP SYSTEM SCHOOLBOOTH SOFTWARE COMPREHENSIVE CONSENT, RELEASE, AND LIABILITY WAIVER AGREEMENT</h4>'
            . '<p>This legally binding Agreement ("Agreement") is made between the legal guardian ("Guardian") and {{school_name}} ("School"), {{district_name}} ("District"), {{association_name}} ("Association"), and {{service_provider_name}} ("Service Provider").</p>'
            . '<h4>1. EXPRESS CONSENT FOR LIMITED USE</h4>'
            . '<ol>'
            . '<li>Digital image capture of the below-named Student(s) during school-sponsored events.</li>'
            . '<li>Secure transmission and temporary storage via {{service_provider_name}} software.</li>'
            . '<li>Exclusive electronic delivery to Guardian\'s verified email address.</li>'
            . '<li>Storage duration not to exceed {{expiry_days}} calendar days.</li>'
            . '</ol>'
            . '<p><strong>NO PUBLIC DISPLAY RIGHTS ARE GRANTED UNDER THIS AGREEMENT.</strong></p>'
            . '<h4>2. LEGAL GUARDIAN WARRANTY &amp; REPRESENTATIONS</h4>'
            . '<p>Guardian expressly warrants and represents under penalty of perjury that they are the legal guardian and have authority to execute this Agreement.</p>'
            . '<h4>3. COMPREHENSIVE LIABILITY RELEASE</h4>'
            . '<p>Guardian releases, discharges, indemnifies, and holds harmless the School, District, Association, Service Provider, and their representatives from claims or losses related to technical failure, unauthorized access, service interruptions, misuse after delivery, and privacy-related claims, except for gross negligence or willful misconduct.</p>'
            . '<h4>4. DATA SECURITY ACKNOWLEDGMENTS</h4>'
            . '<p>Guardian acknowledges that no transmission system is 100% secure and that industry-standard security controls are used, including encryption in transit and at rest.</p>'
            . '<h4>5. TERM &amp; TERMINATION</h4>'
            . '<p>This Agreement remains in effect for the current academic year and renews annually unless revoked in writing.</p>'
            . '<h4>6. GOVERNING LAW &amp; DISPUTE RESOLUTION</h4>'
            . '<p>This Agreement is governed by {{governing_law_state}} law. Disputes are resolved through binding arbitration in {{arbitration_county}}, with venue in local courts if arbitration fails.</p>'
            . '<h4>7. COMPLETE AGREEMENT</h4>'
            . '<p>This is the entire agreement between parties regarding this subject matter.</p>'
            . '<h4>ACCEPTANCE &amp; ACKNOWLEDGMENT</h4>'
            . '<p>By signing below, Guardian certifies they have read and understood this Agreement and voluntarily agree to all terms.</p>'
            . '</div>';
    }

    private function build_privacy_policy_page($settings, $existing) {
        $result = [
            'error' => '',
            'edit_url' => '',
            'view_url' => '',
            'page_title' => '',
            'options' => [],
        ];

        $school_name = isset($settings['entity_school_name']) && $settings['entity_school_name'] !== ''
            ? $settings['entity_school_name']
            : __('Our School', 'pta-schoolbooth');
        $district_name = isset($settings['entity_district_name']) ? $settings['entity_district_name'] : '';
        $association_name = isset($settings['entity_association_name']) && $settings['entity_association_name'] !== ''
            ? $settings['entity_association_name']
            : __('School Parent Association', 'pta-schoolbooth');
        $service_provider_name = isset($settings['entity_service_provider_name']) && $settings['entity_service_provider_name'] !== ''
            ? $settings['entity_service_provider_name']
            : __('IKAP System Schoolbooth Software', 'pta-schoolbooth');
        $retention_days = isset($settings['expiry_days']) ? max(1, absint($settings['expiry_days'])) : 7;

        $page_title = isset($settings['privacy_policy_page_title']) && $settings['privacy_policy_page_title'] !== ''
            ? $settings['privacy_policy_page_title']
            : sprintf(__('%s Photo Privacy Policy', 'pta-schoolbooth'), $school_name);

        $use_template = isset($_POST['ptasb_use_privacy_template']);
        $page_content = $use_template
            ? $this->get_default_privacy_policy_template($school_name, $district_name, $association_name, $service_provider_name, $retention_days)
            : $this->get_basic_privacy_policy_content($school_name);

        $page_id = isset($existing['privacy_policy_page_id']) ? absint($existing['privacy_policy_page_id']) : 0;
        $page_data = [
            'post_title' => $page_title,
            'post_content' => $page_content,
            'post_status' => 'publish',
            'post_type' => 'page',
        ];

        if ($page_id > 0 && get_post($page_id)) {
            $page_data['ID'] = $page_id;
            $saved_page_id = wp_update_post($page_data, true);
        } else {
            $saved_page_id = wp_insert_post($page_data, true);
        }

        if (is_wp_error($saved_page_id)) {
            $result['error'] = __('Failed to create privacy policy page:', 'pta-schoolbooth') . ' ' . $saved_page_id->get_error_message();
            return $result;
        }

        $view_url = get_permalink($saved_page_id);
        $result['page_title'] = $page_title;
        $result['view_url'] = $view_url;
        $result['edit_url'] = get_edit_post_link($saved_page_id);
        $result['options']['privacy_policy_page_id'] = $saved_page_id;
        $result['options']['privacy_policy_page_title'] = $page_title;
        $result['options']['entity_privacy_policy_url'] = $view_url ? esc_url_raw($view_url) : '';

        return $result;
    }

    private function get_default_privacy_policy_template($school_name, $district_name, $association_name, $service_provider_name, $retention_days) {
        $district_line = $district_name !== ''
            ? '<p><strong>District:</strong> ' . esc_html($district_name) . '</p>'
            : '';

        return '<h2>' . esc_html($school_name) . ' Photo Privacy Policy</h2>'
            . $district_line
            . '<p><strong>Last Updated:</strong> ' . esc_html(date_i18n(get_option('date_format'))) . '</p>'
            . '<h3>1. Who We Are</h3>'
            . '<p>This policy describes how ' . esc_html($school_name) . ', ' . esc_html($association_name) . ', and ' . esc_html($service_provider_name) . ' process event photo data.</p>'
            . '<h3>2. What We Collect</h3>'
            . '<ul>'
            . '<li>Student photo images created during approved school events.</li>'
            . '<li>Guardian form data needed to deliver and verify photo access.</li>'
            . '<li>System logs used for security, anti-abuse, and troubleshooting.</li>'
            . '</ul>'
            . '<h3>3. Why We Use Data</h3>'
            . '<ul>'
            . '<li>Deliver photos to authorized guardians.</li>'
            . '<li>Protect access using time-limited links and access controls.</li>'
            . '<li>Maintain service reliability and auditability.</li>'
            . '</ul>'
            . '<h3>4. Retention and Deletion</h3>'
            . '<p>Photo files and related access records are retained for up to ' . esc_html((string) $retention_days) . ' days unless required by law to retain longer.</p>'
            . '<h3>5. Sharing</h3>'
            . '<p>We do not sell personal information. Data is shared only with authorized school personnel and contracted service providers needed to operate this program.</p>'
            . '<h3>6. Security</h3>'
            . '<p>We apply technical and organizational safeguards including authentication controls, logging, and encrypted transport.</p>'
            . '<h3>7. Guardian Choices</h3>'
            . '<p>Guardians may contact the school to request updates, corrections, or deletion requests where permitted.</p>'
            . '<h3>8. Contact</h3>'
            . '<p>For privacy questions, contact ' . esc_html($school_name) . ' administration.</p>';
    }

    private function get_basic_privacy_policy_content($school_name) {
        return '<h2>' . esc_html($school_name) . ' Privacy Policy</h2>'
            . '<p>Add your privacy policy text here.</p>';
    }

    public function portal_setup_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions', 'pta-schoolbooth'));
        }

        $this->maybe_create_page();
        ?>
        <div class="wrap">
            <h1><?php _e('Schoolbooth: Portal Setup', 'pta-schoolbooth'); ?></h1>
            <?php settings_errors('ptasb_messages'); ?>

            <div class="pta-schoolbooth-quick-setup">
                <h2><?php _e('Shortcode', 'pta-schoolbooth'); ?></h2>
                <code>[pta_schoolbooth_download_portal]</code>
                <p><?php _e('Use this shortcode on any page to display the download portal.', 'pta-schoolbooth'); ?></p>

                <hr>

                <form method="post" action="">
                    <?php wp_nonce_field('ptasb_create_page', 'ptasb_nonce'); ?>
                    <h2><?php _e('Create Download Page', 'pta-schoolbooth'); ?></h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="ptasb_page_title"><?php _e('Page Title', 'pta-schoolbooth'); ?></label></th>
                            <td>
                                <input type="text" name="ptasb_page_title" id="ptasb_page_title"
                                       value="<?php esc_attr_e('Photo Downloads', 'pta-schoolbooth'); ?>" class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="ptasb_page_template"><?php _e('Template', 'pta-schoolbooth'); ?></label></th>
                            <td>
                                <select name="ptasb_page_template" id="ptasb_page_template">
                                    <?php foreach ($this->get_page_templates() as $name => $template): ?>
                                        <option value="<?php echo esc_attr($template); ?>"><?php echo esc_html($name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button(__('Create Page', 'pta-schoolbooth'), 'primary', 'ptasb_create_page'); ?>
                </form>
            </div>
        </div>
        <?php
    }

    protected function get_page_templates() {
        $templates = get_page_templates();
        return array_merge([
            __('Default Template', 'pta-schoolbooth') => 'default'
        ], $templates);
    }

    protected function maybe_create_page() {
        if (!isset($_POST['ptasb_create_page'])) {
            return;
        }

        check_admin_referer('ptasb_create_page', 'ptasb_nonce');

        $page_title = sanitize_text_field(isset($_POST['ptasb_page_title']) ? $_POST['ptasb_page_title'] : __('Photo Downloads', 'pta-schoolbooth'));
        $template = sanitize_text_field(isset($_POST['ptasb_page_template']) ? $_POST['ptasb_page_template'] : 'default');

        if (get_page_by_title($page_title)) {
            add_settings_error(
                'ptasb_messages',
                'ptasb_page_error',
                sprintf(__('Page "%s" already exists', 'pta-schoolbooth'), $page_title),
                'error'
            );
            return;
        }

        $page_id = wp_insert_post([
            'post_title' => $page_title,
            'post_content' => '[pta_schoolbooth_download_portal]',
            'post_status' => 'publish',
            'post_type' => 'page',
            'page_template' => $template
        ]);

        if (is_wp_error($page_id)) {
            add_settings_error(
                'ptasb_messages',
                'ptasb_page_error',
                __('Failed to create page:', 'pta-schoolbooth') . ' ' . $page_id->get_error_message(),
                'error'
            );
            return;
        }

        $options = get_option('pta_schoolbooth_settings', []);
        $options['download_page_id'] = $page_id;
        update_option('pta_schoolbooth_settings', $options);

        add_settings_error(
            'ptasb_messages',
            'ptasb_page_success',
            sprintf(
                __('Page created: %s', 'pta-schoolbooth'),
                '<a href="' . esc_url(get_edit_post_link($page_id)) . '">' . esc_html($page_title) . '</a> | <a href="' . esc_url(get_permalink($page_id)) . '" target="_blank">' . esc_html__('View', 'pta-schoolbooth') . '</a>'
            ),
            'updated'
        );
    }
}
