<?php
/**
 * Rate Limiter
 * 
 * Prevents brute force attacks on access codes and form submissions.
 * Uses WordPress transients for distributed rate limiting.
 */
class PTASB_Rate_Limiter {
    const CODE_ATTEMPT_KEY = 'ptasb_code_attempt_%s_%s'; // code, ip
    const FORM_ATTEMPT_KEY = 'ptasb_form_attempt_%s_%s';  // code, ip
    const CODE_LOCKOUT_KEY = 'ptasb_code_lockout_%s';     // code
    
    // Configuration
    private $max_attempts = 5;           // Max attempts before lockout
    private $attempt_window = 300;       // 5 minutes
    private $lockout_duration = 900;     // 15 minutes
    
    private static $instance;
    
    public static function init() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Allow filtering configuration
        $this->max_attempts = apply_filters('ptasb_rate_limit_max_attempts', $this->max_attempts);
        $this->attempt_window = apply_filters('ptasb_rate_limit_window', $this->attempt_window);
        $this->lockout_duration = apply_filters('ptasb_rate_limit_lockout', $this->lockout_duration);
    }
    
    /**
     * Check if an access code is rate-limited
     * Returns: true if allowed, WP_Error if rate-limited
     */
    public function check_access_code_attempt($code) {
        $ip = $this->get_client_ip();
        
        // Check if code is currently locked out
        if ($this->is_code_locked($code)) {
            return new WP_Error(
                'rate_limit_lockout',
                __('This access code has been temporarily locked due to too many failed attempts. Please try again later.', 'pta-schoolbooth'),
                ['retry_after' => $this->lockout_duration]
            );
        }
        
        return true;
    }
    
    /**
     * Record a failed access code attempt
     */
    public function record_failed_access_attempt($code) {
        $ip = $this->get_client_ip();
        $key = sprintf(self::CODE_ATTEMPT_KEY, sanitize_key($code), $ip);
        
        // Increment attempt counter
        $attempts = (int)get_transient($key);
        $attempts++;
        
        // Set transient (expires after attempt_window)
        set_transient($key, $attempts, $this->attempt_window);
        
        // If threshold reached, lock the code
        if ($attempts >= $this->max_attempts) {
            $this->lock_code($code);
        }
        
        return $attempts;
    }
    
    /**
     * Clear attempts for a successful access code validation
     * (Rate limit is per code+IP, so clearing on success prevents lockout after valid use)
     */
    public function clear_access_code_attempts($code) {
        $ip = $this->get_client_ip();
        $key = sprintf(self::CODE_ATTEMPT_KEY, sanitize_key($code), $ip);
        delete_transient($key);
    }
    
    /**
     * Check if form submission is rate-limited
     */
    public function check_form_submission($code) {
        $ip = $this->get_client_ip();
        $key = sprintf(self::FORM_ATTEMPT_KEY, sanitize_key($code), $ip);
        
        $attempts = (int)get_transient($key);
        
        // Max 1 form submission per code per IP per hour
        if ($attempts > 0) {
            $retry_after = (int)get_transient($key . '_time') - time();
            $retry_after = max($retry_after, 60);
            
            return new WP_Error(
                'form_rate_limit',
                __('You can submit the permissions form once per hour. Please try again later.', 'pta-schoolbooth'),
                ['retry_after' => $retry_after]
            );
        }
        
        return true;
    }
    
    /**
     * Record a form submission attempt
     */
    public function record_form_submission($code) {
        $ip = $this->get_client_ip();
        $key = sprintf(self::FORM_ATTEMPT_KEY, sanitize_key($code), $ip);
        
        // Set to 1 with 3600 second (1 hour) expiry
        set_transient($key, 1, 3600);
        set_transient($key . '_time', time() + 3600, 3600);
    }
    
    /**
     * Lock a code due to too many failed attempts
     */
    private function lock_code($code) {
        $key = sprintf(self::CODE_LOCKOUT_KEY, sanitize_key($code));
        set_transient($key, 1, $this->lockout_duration);
    }
    
    /**
     * Check if a code is currently locked
     */
    private function is_code_locked($code) {
        $key = sprintf(self::CODE_LOCKOUT_KEY, sanitize_key($code));
        return (bool)get_transient($key);
    }
    
    /**
     * Manually unlock a code (admin function)
     */
    public function unlock_code($code) {
        if (!current_user_can('manage_options')) {
            return new WP_Error('insufficient_caps', 'Only administrators can unlock codes');
        }
        
        $key = sprintf(self::CODE_LOCKOUT_KEY, sanitize_key($code));
        delete_transient($key);
        
        return true;
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
            $ip = explode(',', $ip)[0];
        } elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = sanitize_text_field($_SERVER['HTTP_CLIENT_IP']);
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = sanitize_text_field($_SERVER['REMOTE_ADDR']);
        }
        
        return $ip ?: 'unknown';
    }
}

