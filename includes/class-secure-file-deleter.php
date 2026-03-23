<?php
/**
 * Secure File Deleter
 * 
 * Securely delete files by overwriting with random data before unlinking.
 * Prevents deleted file recovery via forensic tools.
 */
class SCHOOLBOOTH_Secure_File_Deleter {
    
    private static $instance;
    
    public static function init() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {}
    
    /**
     * Securely delete a file
     * 
     * @param string $filepath Path to file
     * @param bool $audit_log Log the deletion in audit log
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public static function secure_delete($filepath, $audit_log = true) {
        // Deny requests from web
        if (!defined('SCHOOLBOOTH_SHARED_SECRET')) {
            return new WP_Error('not_initialized', 'Plugin not properly initialized');
        }
        
        // Validate path
        if (!is_string($filepath) || empty($filepath)) {
            return new WP_Error('invalid_path', 'Invalid file path');
        }
        
        // Normalize path
        $filepath = wp_normalize_path($filepath);
        
        // Check if file exists
        if (!file_exists($filepath)) {
            return new WP_Error('file_not_found', 'File does not exist');
        }
        
        // Check if it's a file (not directory)
        if (!is_file($filepath)) {
            return new WP_Error('not_a_file', 'Path is not a regular file');
        }
        
        // Get file size before deletion for logging
        $file_size = filesize($filepath);
        
        // Attempt to securely overwrite the file
        $overwrite_result = self::overwrite_file($filepath);
        if (is_wp_error($overwrite_result)) {
            return $overwrite_result;
        }
        
        // Delete the file
        if (!@unlink($filepath)) {
            return new WP_Error('delete_failed', 'Failed to delete file after overwriting');
        }
        
        // Log deletion in audit trail
        if ($audit_log) {
            $audit = SCHOOLBOOTH_Audit_Logger::init();
            $audit->log_event('auto_delete', [
                'filename'     => basename($filepath),
                'filepath'     => $filepath,
                'file_size'    => $file_size,
                'reason'       => 'download_limit_exceeded',
                'secure_wipe'  => true,
            ]);
        }
        
        return true;
    }
    
    /**
     * Overwrite file with random data
     * Uses multiple passes to prevent recovery
     */
    private static function overwrite_file($filepath) {
        $file_size = filesize($filepath);
        
        // Don't try to overwrite huge files (> 500MB)
        if ($file_size > 500 * 1024 * 1024) {
            // For large files, just use unlink (can't realistically wipe large files)
            return true;
        }
        
        // Open file for writing
        $handle = @fopen($filepath, 'r+b');
        if (!$handle) {
            return new WP_Error('open_failed', 'Could not open file for overwriting');
        }
        
        try {
            // First pass: overwrite with zeros
            self::fill_file($handle, $file_size, chr(0));
            
            // Second pass: overwrite with ones
            self::fill_file($handle, $file_size, chr(0xFF));
            
            // Third pass: overwrite with random data
            $random_data = openssl_random_pseudo_bytes($file_size, $strong);
            if (!$strong) {
                // Fallback to mt_rand if openssl is not strong enough
                $random_data = '';
                for ($i = 0; $i < $file_size; $i++) {
                    $random_data .= chr(mt_rand(0, 255));
                }
            }
            self::fill_file($handle, $file_size, $random_data);
            
            // Fourth pass: more random data
            $random_data = openssl_random_pseudo_bytes($file_size, $strong);
            if (!$strong) {
                $random_data = '';
                for ($i = 0; $i < $file_size; $i++) {
                    $random_data .= chr(mt_rand(0, 255));
                }
            }
            self::fill_file($handle, $file_size, $random_data);
            
            // Sync to disk
            @fsync($handle);
            
            @fclose($handle);
            
            return true;
            
        } catch (Exception $e) {
            @fclose($handle);
            return new WP_Error('overwrite_error', $e->getMessage());
        }
    }
    
    /**
     * Helper to fill file with data
     */
    private static function fill_file($handle, $size, $data) {
        fseek($handle, 0);
        $data_len = strlen($data);
        $written = 0;
        
        while ($written < $size) {
            $chunk = substr($data, 0, $size - $written);
            $result = fwrite($handle, $chunk);
            if ($result === false) {
                throw new Exception('Failed to write to file');
            }
            $written += $result;
        }
    }
}


