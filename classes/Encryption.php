<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Encryption service for sensitive data
 *
 * Uses libsodium for secure encryption/decryption
 */
class Encryption {
    /**
     * Option key for encryption key storage
     */
    private const OPTION_KEY = 'geweb_aisearch_encryption_key';

    private const API_KEY = 'geweb_aisearch_api_key_encrypted';

    /**
     * Encrypt data using libsodium
     *
     * @param string $data Data to encrypt
     * @return string Base64 encoded encrypted data
     */
    public static function encrypt(string $data): string {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $encrypted = sodium_crypto_secretbox($data, $nonce, self::getHashKey());
        return base64_encode($nonce . $encrypted);
    }

    /**
     * Decrypt data using libsodium
     *
     * @param string $encrypted Base64 encoded encrypted data
     * @return string Decrypted data or empty string on failure
     */
    public static function decrypt(string $encrypted): string {
        if(empty($encrypted)){
            return '';
        }

        $decoded = base64_decode($encrypted, true);
        if ($decoded === false || strlen($decoded) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }

        $nonce = mb_substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, '8bit');
        $ciphertext = mb_substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, null, '8bit');

        $decrypted = sodium_crypto_secretbox_open($ciphertext, $nonce, self::getHashKey());
        return $decrypted !== false ? $decrypted : '';
    }

    /**
     * Get or generate encryption key
     *
     * @return string Binary encryption key
     */
    private static function getHashKey(): string {
        $key = get_option(self::OPTION_KEY);
        if (!$key) {
            $key = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
            update_option(self::OPTION_KEY, $key, false);
        }
        return base64_decode($key);
    }
    
    /**
     * Save encrypted API key to options
     *
     * @param string $apiKey Plain API key
     * @return bool Success status
     */
    public function saveApiKey(string $apiKey): bool {
        $apiKey = trim($apiKey);
        return empty($apiKey) ? false : update_option(self::API_KEY, $this->encrypt($apiKey));
    }

    /**
     * Get decrypted API key from options
     *
     * @return string|null Decrypted API key or null if not found
     */
    public function getApiKey(): string {
        return $this->decrypt(get_option(self::API_KEY, ''));
    }
}
