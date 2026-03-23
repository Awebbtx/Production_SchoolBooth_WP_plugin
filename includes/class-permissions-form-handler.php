<?php
/**
 * Permissions Form Handler
 * 
 * Handles the permissions form submission with:
 * - PII hashing (never stores plain name/email)
 * - Rate limiting
 * - CSRF protection (nonce)
 * - Audit logging
 */
class SCHOOLBOOTH_Permissions_Form_Handler {
    
    private static $instance;
    
    public static function init() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('wp_ajax_nopriv_schoolbooth_submit_permissions_form', [$this, 'handle_form_submission']);
        add_action('wp_ajax_schoolbooth_submit_permissions_form', [$this, 'handle_form_submission']);
    }
    
    /**
     * Render the permissions form
     */
    public static function render_form($access_code) {
        $access_code = schoolbooth_normalize_access_code($access_code);
        $options = get_option('schoolbooth_settings', []);
        $replacements = [
            '{{school_name}}' => isset($options['entity_school_name']) ? $options['entity_school_name'] : '',
            '{{district_name}}' => isset($options['entity_district_name']) ? $options['entity_district_name'] : '',
            '{{association_name}}' => isset($options['entity_association_name']) ? $options['entity_association_name'] : 'School Parent Association',
            '{{service_provider_name}}' => isset($options['entity_service_provider_name']) ? $options['entity_service_provider_name'] : 'IKAP System Schoolbooth Software',
            '{{privacy_policy_url}}' => isset($options['entity_privacy_policy_url']) ? $options['entity_privacy_policy_url'] : '',
            '{{governing_law_state}}' => isset($options['entity_governing_law_state']) ? $options['entity_governing_law_state'] : 'Texas',
            '{{arbitration_county}}' => isset($options['entity_arbitration_county']) ? $options['entity_arbitration_county'] : 'Comal County',
            '{{expiry_days}}' => (string) (isset($options['expiry_days']) ? max(1, absint($options['expiry_days'])) : 7),
        ];
        $policy_html = isset($options['consent_policy_html']) && $options['consent_policy_html'] !== ''
            ? wp_kses_post($options['consent_policy_html'])
            : '<p>' . esc_html__('By checking this box and submitting this form, I consent to the photo release policy for this event.', 'schoolbooth') . '</p>';
        $policy_html = strtr($policy_html, array_map('esc_html', $replacements));
        if (!empty($replacements['{{privacy_policy_url}}'])) {
            $policy_html = str_replace(esc_html('{{privacy_policy_url}}'), esc_url($replacements['{{privacy_policy_url}}']), $policy_html);
        }
        $consent_checkbox_label = isset($options['consent_checkbox_label']) && $options['consent_checkbox_label'] !== ''
            ? sanitize_text_field($options['consent_checkbox_label'])
            : __('I certify that I have read and agree to the photo release terms.', 'schoolbooth');
        // Verify nonce
        $nonce = wp_create_nonce('schoolbooth_permissions_form_' . sanitize_key($access_code));
        
        ob_start();
        ?>
        <div class="schoolbooth-permissions-form-wrapper">
            <div class="permissions-form-container">
                <h2><?php _e('Release Form', 'schoolbooth'); ?></h2>
                <p><?php _e('Please complete this form before accessing your photos.', 'schoolbooth'); ?></p>

                <div class="consent-policy-text">
                    <?php echo $policy_html; ?>
                </div>
                
                <form id="schoolbooth-permissions-form" class="schoolbooth-permissions-form">
                    <input type="hidden" name="action" value="schoolbooth_submit_permissions_form">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr($nonce); ?>">
                    <input type="hidden" name="access_code" value="<?php echo esc_attr($access_code); ?>">
                    
                    <div class="form-group">
                        <label for="first_name">
                            <?php _e('First Name', 'schoolbooth'); ?> <span class="required">*</span>
                        </label>
                        <input 
                            type="text" 
                            id="first_name" 
                            name="first_name" 
                            required 
                            pattern="[a-zA-Z\s'-]{2,50}"
                            placeholder="<?php esc_attr_e('Enter your first name', 'schoolbooth'); ?>"
                            autocomplete="given-name"
                        >
                        <small class="form-error" style="display:none;"></small>
                    </div>
                    
                    <div class="form-group">
                        <label for="last_name">
                            <?php _e('Last Name', 'schoolbooth'); ?> <span class="required">*</span>
                        </label>
                        <input 
                            type="text" 
                            id="last_name" 
                            name="last_name" 
                            required 
                            pattern="[a-zA-Z\s'-]{2,50}"
                            placeholder="<?php esc_attr_e('Enter your last name', 'schoolbooth'); ?>"
                            autocomplete="family-name"
                        >
                        <small class="form-error" style="display:none;"></small>
                    </div>
                    
                    <div class="form-group">
                        <label for="email">
                            <?php _e('Email Address', 'schoolbooth'); ?> <span class="required">*</span>
                        </label>
                        <input 
                            type="email" 
                            id="email" 
                            name="email" 
                            required 
                            placeholder="<?php esc_attr_e('you@example.com', 'schoolbooth'); ?>"
                            autocomplete="email"
                        >
                        <small class="form-error" style="display:none;"></small>
                    </div>
                    
                    <div class="form-group checkbox-group">
                        <label for="consent_checkbox">
                            <input 
                                type="checkbox" 
                                id="consent_checkbox" 
                                name="consent" 
                                value="1" 
                                required
                            >
                            <span class="checkbox-label">
                                <?php echo esc_html($consent_checkbox_label); ?>
                            </span>
                        </label>
                        <small class="form-error" style="display:none;"></small>
                    </div>
                    
                    <div class="form-actions">
                        <button 
                            type="submit" 
                            class="schoolbooth-btn schoolbooth-btn-primary"
                            id="submit-consent"
                        >
                            <?php _e('I Consent to Release', 'schoolbooth'); ?>
                        </button>
                    </div>
                    
                    <div class="form-status" style="display:none;"></div>
                </form>
            </div>
        </div>
        
        <style>
            .schoolbooth-permissions-form-wrapper {
                padding: 20px;
                background: #f9f9f9;
                border-radius: 5px;
                max-width: 900px;
                margin: 20px auto;
            }
            
            .permissions-form-container h2 {
                color: #333;
                margin-bottom: 10px;
                text-align: center;
            }
            
            .permissions-form-container p {
                text-align: center;
                color: #666;
                margin-bottom: 20px;
            }

            .consent-policy-text {
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 4px;
                padding: 16px;
                margin-bottom: 16px;
                color: #333;
                max-height: 380px;
                overflow: auto;
            }

            .consent-policy-text p {
                text-align: left;
                margin: 0 0 10px;
                color: #333;
            }

            .consent-policy-text h3,
            .consent-policy-text h4 {
                margin: 14px 0 8px;
                color: #111827;
            }

            .consent-policy-text ol,
            .consent-policy-text ul {
                margin: 8px 0 12px 22px;
            }
            
            .form-group {
                margin-bottom: 20px;
            }
            
            .form-group label {
                display: block;
                margin-bottom: 8px;
                font-weight: 500;
                color: #333;
            }
            
            .required {
                color: #cc1818;
            }
            
            .form-group input[type="text"],
            .form-group input[type="email"] {
                width: 100%;
                padding: 10px;
                border: 1px solid #ddd;
                border-radius: 4px;
                font-size: 14px;
                box-sizing: border-box;
            }
            
            .form-group input[type="text"]:focus,
            .form-group input[type="email"]:focus {
                outline: none;
                border-color: #2271b1;
                box-shadow: 0 0 0 2px rgba(34, 113, 177, 0.1);
            }
            
            .checkbox-group {
                background: white;
                padding: 15px;
                border: 1px solid #ddd;
                border-radius: 4px;
            }
            
            .checkbox-group input[type="checkbox"] {
                margin-right: 10px;
                cursor: pointer;
            }
            
            .checkbox-label {
                cursor: pointer;
                color: #333;
            }
            
            .form-error {
                display: block;
                color: #cc1818;
                font-size: 12px;
                margin-top: 5px;
            }
            
            .form-actions {
                margin-top: 20px;
                text-align: center;
            }
            
            .schoolbooth-btn {
                padding: 12px 30px;
                font-size: 16px;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                font-weight: 500;
                transition: all 0.3s ease;
            }
            
            .schoolbooth-btn-primary {
                background-color: #2271b1;
                color: white;
            }
            
            .schoolbooth-btn-primary:hover {
                background-color: #1d5fa0;
            }
            
            .schoolbooth-btn-primary:disabled {
                background-color: #ccc;
                cursor: not-allowed;
            }
            
            .form-status {
                margin-top: 15px;
                padding: 12px;
                border-radius: 4px;
                text-align: center;
                font-weight: 500;
            }
            
            .form-status.success {
                background-color: #d4edda;
                color: #155724;
                border: 1px solid #c3e6cb;
            }
            
            .form-status.error {
                background-color: #f8d7da;
                color: #721c24;
                border: 1px solid #f5c6cb;
            }
            
            .form-status.loading {
                background-color: #e7f3ff;
                color: #004085;
                border: 1px solid #b8daff;
            }
        </style>
        
        <?php
        return ob_get_clean();
    }
    
    /**
     * Handle form submission via AJAX
     */
    public function handle_form_submission() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !isset($_POST['access_code'])) {
            wp_send_json_error([
                'message' => __('Invalid request', 'schoolbooth'),
            ], 400);
        }
        
        $access_code = schoolbooth_normalize_access_code(sanitize_text_field($_POST['access_code']));
        $nonce = sanitize_text_field($_POST['nonce']);
        
        // Verify nonce
        if (!wp_verify_nonce($nonce, 'schoolbooth_permissions_form_' . sanitize_key($access_code))) {
            wp_send_json_error([
                'message' => __('Security verification failed. Please refresh the page.', 'schoolbooth'),
            ], 403);
        }
        
        // Check rate limit
        $rate_limiter = SCHOOLBOOTH_Rate_Limiter::init();
        $rate_check = $rate_limiter->check_form_submission($access_code);
        if (is_wp_error($rate_check)) {
            wp_send_json_error([
                'message' => $rate_check->get_error_message(),
                'retry_after' => $rate_check->get_error_data(),
            ], 429);
        }
        
        // Validate input
        $first_name = sanitize_text_field(isset($_POST['first_name']) ? $_POST['first_name'] : '');
        $last_name = sanitize_text_field(isset($_POST['last_name']) ? $_POST['last_name'] : '');
        $email = sanitize_email(isset($_POST['email']) ? $_POST['email'] : '');
        $consent = isset($_POST['consent']) ? (bool)$_POST['consent'] : false;
        
        // Validate required fields
        $errors = [];
        
        if (empty($first_name) || strlen($first_name) < 2) {
            $errors['first_name'] = __('First name is required and must be at least 2 characters', 'schoolbooth');
        }
        
        if (empty($last_name) || strlen($last_name) < 2) {
            $errors['last_name'] = __('Last name is required and must be at least 2 characters', 'schoolbooth');
        }
        
        if (empty($email) || !is_email($email)) {
            $errors['email'] = __('Valid email address is required', 'schoolbooth');
        }
        
        if (!$consent) {
            $errors['consent'] = __('You must agree to the release form', 'schoolbooth');
        }
        
        if (!empty($errors)) {
            $audit = SCHOOLBOOTH_Audit_Logger::init();
            $audit->log_event('form_submission', [
                'access_code'   => $access_code,
                'success'       => false,
                'reason'        => 'validation_failed',
                'invalid_fields'=> array_keys($errors),
                'consent'       => $consent,
                'email_domain'  => $this->extract_email_domain($email),
                'consent_name'  => trim($first_name . ' ' . $last_name),
                'consent_email' => $email,
            ]);

            wp_send_json_error([
                'message' => __('Please correct the errors below', 'schoolbooth'),
                'errors' => $errors,
            ], 400);
        }
        
        // Hash PII (one-way) - never store plain text
        $pii_hash = $this->hash_pii($first_name, $last_name, $email);
        
        // Log form submission in audit trail
        $audit = SCHOOLBOOTH_Audit_Logger::init();
        $audit_result = $audit->log_event('form_submission', [
            'access_code'    => $access_code,
            'success'        => true,
            'pii_hash'       => $pii_hash,
            'email_domain'   => $this->extract_email_domain($email),
            'consent_name'   => trim($first_name . ' ' . $last_name),
            'consent_email'  => $email,
            'consent'        => true,
            'ip_address'     => $this->get_client_ip(),
        ]);
        
        if (is_wp_error($audit_result)) {
            wp_send_json_error([
                'message' => __('Failed to process consent form. Please try again.', 'schoolbooth'),
            ], 500);
        }
        
        // Record form submission for rate limiting
        $rate_limiter->record_form_submission($access_code);
        
        // Set cookie/session flag indicating form was completed
        $form_token = wp_hash($pii_hash . $access_code);
        setcookie(
            'schoolbooth_form_' . sanitize_key($access_code),
            $form_token,
            time() + HOUR_IN_SECONDS,
            COOKIEPATH,
            COOKIE_DOMAIN,
            is_ssl(),
            true // httponly
        );
        
        wp_send_json_success([
            'message' => __('Thank you! Your consent has been recorded. You can now access your photos.', 'schoolbooth'),
            'token' => $form_token,
        ]);
    }
    
    /**
     * Check if user has completed permissions form for this access code
     */
    public static function has_completed_form($access_code) {
        $cookie_name = 'schoolbooth_form_' . sanitize_key(schoolbooth_normalize_access_code($access_code));
        return isset($_COOKIE[$cookie_name]);
    }
    
    /**
     * Hash PII one-way for audit logging
     * Uses salted hash so it's not reversible
     */
    private function hash_pii($first_name, $last_name, $email) {
        $to_hash = strtolower(trim($first_name)) . 
                   '|' . strtolower(trim($last_name)) . 
                   '|' . strtolower(trim($email)) . 
                   '|' . SCHOOLBOOTH_SHARED_SECRET;
        
        return hash('sha256', $to_hash);
    }
    
    /**
     * Extract domain from email (for grouping/auditing without exposing full email)
     */
    private function extract_email_domain($email) {
        $parts = explode('@', $email);
        return isset($parts[1]) ? strtolower($parts[1]) : 'unknown';
    }
    
    /**
     * Get client IP address
     */
    private function get_client_ip() {
        if (!empty($_SERVER['CF_CONNECTING_IP'])) {
            return sanitize_text_field($_SERVER['CF_CONNECTING_IP']);
        }
        
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = sanitize_text_field($_SERVER['HTTP_X_FORWARDED_FOR']);
            return explode(',', $ip)[0];
        }
        
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return sanitize_text_field($_SERVER['HTTP_CLIENT_IP']);
        }
        
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            return sanitize_text_field($_SERVER['REMOTE_ADDR']);
        }
        
        return 'unknown';
    }
}



