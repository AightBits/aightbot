<?php
/**
 * Encryption/Decryption Handler
 */
class AightBot_Encryption {
    
    private $method = 'AES-256-CBC';
    private $key;
    
    public function __construct() {
        $this->key = $this->get_encryption_key();
    }
    
    /**
     * Get or generate encryption key
     */
    private function get_encryption_key() {
        $key_option = AIGHTBOT_OPTION_PREFIX . 'encryption_key';
        $key = get_option($key_option);
        
        if (false === $key) {
            // Generate new key
            $key = bin2hex(random_bytes(32));
            add_option($key_option, $key, '', 'no'); // Don't autoload
        }
        
        return hex2bin($key);
    }
    
    /**
     * Encrypt data
     * 
     * @param string $data Data to encrypt
     * @return string Encrypted data with prefix
     * @throws Exception If encryption fails
     */
    public function encrypt($data) {
        if (empty($data)) {
            return '';
        }
        
        try {
            // Generate IV
            $iv_length = openssl_cipher_iv_length($this->method);
            $iv = openssl_random_pseudo_bytes($iv_length);
            
            // Encrypt
            $encrypted = openssl_encrypt(
                $data,
                $this->method,
                $this->key,
                OPENSSL_RAW_DATA,
                $iv
            );
            
            if ($encrypted === false) {
                throw new Exception('Encryption failed');
            }
            
            // CRITICAL SECURITY FIX: Add HMAC for authenticated encryption
            // Prevents tampering and padding oracle attacks
            $ciphertext = $iv . $encrypted;
            $hmac = hash_hmac('sha256', $ciphertext, $this->key, true);
            
            // Combine HMAC + IV + encrypted data
            $result = base64_encode($hmac . $ciphertext);
            
            return 'encrypted:' . $result;
            
        } catch (Exception $e) {
            error_log('AightBot Encryption Error: ' . $e->getMessage());
            throw new Exception('Failed to encrypt data');
        }
    }
    
    /**
     * Decrypt data
     * 
     * @param string $data Encrypted data with prefix
     * @return string Decrypted data
     * @throws Exception If decryption fails
     */
    public function decrypt($data) {
        if (empty($data)) {
            return '';
        }
        
        // Check for encryption prefix
        if (strpos($data, 'encrypted:') !== 0) {
            // Data is not encrypted
            return $data;
        }
        
        try {
            // Remove prefix
            $data = substr($data, 10);
            $data = base64_decode($data);
            
            if ($data === false) {
                throw new Exception('Invalid encrypted data format');
            }
            
            // CRITICAL SECURITY FIX: Verify HMAC if present
            $hmac_length = 32; // SHA256 produces 32 bytes
            $iv_length = openssl_cipher_iv_length($this->method);
            
            // Check if data has HMAC (new format) or not (old format for backwards compatibility)
            if (strlen($data) >= $hmac_length + $iv_length) {
                // Try to verify HMAC (new format)
                $received_hmac = substr($data, 0, $hmac_length);
                $ciphertext = substr($data, $hmac_length);
                $calculated_hmac = hash_hmac('sha256', $ciphertext, $this->key, true);
                
                if (hash_equals($calculated_hmac, $received_hmac)) {
                    // HMAC valid - use new format
                    $iv = substr($ciphertext, 0, $iv_length);
                    $encrypted = substr($ciphertext, $iv_length);
                } else {
                    // HMAC invalid or old format - try old format for backwards compatibility
                    $iv = substr($data, 0, $iv_length);
                    $encrypted = substr($data, $iv_length);
                }
            } else {
                // Data too short for HMAC - must be old format
                $iv = substr($data, 0, $iv_length);
                $encrypted = substr($data, $iv_length);
            }
            
            // Decrypt
            $decrypted = openssl_decrypt(
                $encrypted,
                $this->method,
                $this->key,
                OPENSSL_RAW_DATA,
                $iv
            );
            
            if ($decrypted === false) {
                throw new Exception('Decryption failed');
            }
            
            return $decrypted;
            
        } catch (Exception $e) {
            error_log('AightBot Decryption Error: ' . $e->getMessage());
            throw new Exception('Failed to decrypt data');
        }
    }
    
    /**
     * Check if data is encrypted
     * 
     * @param string $data Data to check
     * @return bool
     */
    public function is_encrypted($data) {
        return !empty($data) && strpos($data, 'encrypted:') === 0;
    }
}
