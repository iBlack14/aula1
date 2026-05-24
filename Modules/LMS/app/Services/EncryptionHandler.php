<?php

namespace Modules\LMS\Services;

class EncryptionHandler
{
    private $key;
    private $cipher = 'aes-256-cbc';
    private $initTime;
    private $userContext;

    public function __construct()
    {
        $this->initTime = '2025-02-12 07:16:29';
        $this->userContext = 'maab16';
        $this->key = $this->generateKey();
    }

    public function encrypt($data)
    {
        // Generate a random IV
        $ivLength = openssl_cipher_iv_length($this->cipher);
        $iv = openssl_random_pseudo_bytes($ivLength);

        // Encrypt the data
        $encrypted = openssl_encrypt(
            json_encode($data),
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv
        );

        // Combine IV and encrypted data
        $combined = base64_encode($iv . $encrypted);

        // Add verification hash
        return [
            'data' => $combined,
            'hash' => $this->generateHash($combined),
            'time' => $this->initTime
        ];
    }

    public function decrypt($encryptedData)
    {
        try {
            // Verify hash first
            if (!$this->verifyHash($encryptedData)) {
                return false;
            }

            $combined = base64_decode($encryptedData['data']);
            
            // Extract IV and encrypted data
            $ivLength = openssl_cipher_iv_length($this->cipher);
            $iv = substr($combined, 0, $ivLength);
            $encrypted = substr($combined, $ivLength);

            // Decrypt
            $decrypted = openssl_decrypt(
                $encrypted,
                $this->cipher,
                $this->key,
                OPENSSL_RAW_DATA,
                $iv
            );

            return json_decode($decrypted, true);
        } catch (\Exception $e) {
            return false;
        }
    }

    private function generateKey()
    {
        // Generate key using user context and time
        $salt = hash('sha256', $this->userContext . $this->initTime);
        return hash_pbkdf2(
            'sha256',
            $this->userContext,
            $salt,
            10000,
            32,
            true
        );
    }

    private function generateHash($data)
    {
        return hash_hmac('sha256', $data . $this->initTime, $this->key);
    }

    private function verifyHash($encryptedData)
    {
        $expectedHash = $this->generateHash($encryptedData['data']);
        return hash_equals($expectedHash, $encryptedData['hash']);
    }
}