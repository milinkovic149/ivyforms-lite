<?php

namespace IvyForms\Services\Integrations;

/**
 * Encrypts/decrypts sensitive tokens at rest in WordPress options.
 *
 * Uses AES-256-GCM (authenticated encryption), so tampered or truncated
 * ciphertext fails to decrypt instead of producing garbage plaintext.
 */
class TokenEncryptionService
{
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LENGTH = 16;
    private const KEY_OPTION = 'ivyforms_token_encryption_key';

    public function encrypt(string $plainText): string
    {
        if ($plainText === '') {
            return '';
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = openssl_random_pseudo_bytes($ivLength);
        $tag = '';

        $encrypted = openssl_encrypt(
            $plainText,
            self::CIPHER,
            $this->getKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($encrypted === false) {
            return '';
        }

        return base64_encode($iv . $tag . $encrypted);
    }

    public function decrypt(string $cipherText): string
    {
        if ($cipherText === '') {
            return '';
        }

        $decoded = base64_decode($cipherText, true);
        if ($decoded === false) {
            return '';
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        if (strlen($decoded) <= $ivLength + self::TAG_LENGTH) {
            return '';
        }

        $iv = substr($decoded, 0, $ivLength);
        $tag = substr($decoded, $ivLength, self::TAG_LENGTH);
        $encrypted = substr($decoded, $ivLength + self::TAG_LENGTH);

        $plain = $this->decryptPayload($encrypted, $iv, $tag, $this->getKey());
        if ($plain !== '') {
            return $plain;
        }

        // Tokens encrypted before the dedicated key was introduced used wp_salt('auth').
        return $this->decryptPayload($encrypted, $iv, $tag, $this->getLegacyKey());
    }

    private function decryptPayload(string $encrypted, string $iv, string $tag, string $key): string
    {
        $plain = openssl_decrypt(
            $encrypted,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return $plain !== false ? $plain : '';
    }

    private function getKey(): string
    {
        return hash('sha256', $this->resolveEncryptionSecret(), true);
    }

    private function resolveEncryptionSecret(): string
    {
        if (defined('IVYFORMS_TOKEN_ENCRYPTION_KEY') && IVYFORMS_TOKEN_ENCRYPTION_KEY !== '') {
            return (string) IVYFORMS_TOKEN_ENCRYPTION_KEY;
        }

        $secret = get_option(self::KEY_OPTION);
        if (is_string($secret) && $secret !== '') {
            return $secret;
        }

        add_option(self::KEY_OPTION, wp_generate_password(64, true, true), '', false);

        $secret = get_option(self::KEY_OPTION);

        return is_string($secret) ? $secret : '';
    }

    private function getLegacyKey(): string
    {
        return hash('sha256', wp_salt('auth'), true);
    }
}
