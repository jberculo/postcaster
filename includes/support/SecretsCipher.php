<?php

namespace Justbee\PostCaster\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class SecretsCipher
{
    private const PREFIX = 'pcsec1:';
    private const CONTEXT = 'postcaster-secrets-v1';
    public const KEY_OPTION = 'justbee_postcaster_encryption_key';

    public static function isAvailable(): bool
    {
        return extension_loaded('sodium')
            && function_exists('sodium_crypto_secretbox')
            && function_exists('sodium_crypto_secretbox_open')
            && function_exists('sodium_crypto_generichash')
            && function_exists('random_bytes')
            && self::deriveKey() !== null;
    }

    public static function isEncrypted(string $value): bool
    {
        return strncmp($value, self::PREFIX, strlen(self::PREFIX)) === 0;
    }

    /**
     * Encrypt a plaintext value.
     *
     * Returns the ciphertext, the original value if it is empty or already
     * encrypted, or null when encryption is not possible. Callers must treat
     * null as a hard failure and never persist the plaintext as a fallback.
     */
    public static function encrypt(string $plaintext): ?string
    {
        if ($plaintext === '' || self::isEncrypted($plaintext)) {
            return $plaintext;
        }

        if (!self::sodiumLoaded()) {
            return null;
        }

        $key = self::deriveKey();
        if ($key === null) {
            return null;
        }

        try {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);
        } catch (\Throwable $e) {
            return null;
        } finally {
            sodium_memzero($key);
        }

        return self::PREFIX . base64_encode($nonce . $ciphertext);
    }

    public static function decrypt(string $value): string
    {
        $plaintext = self::tryDecrypt($value);

        return $plaintext === null ? $value : $plaintext;
    }

    public static function tryDecrypt(string $value): ?string
    {
        if ($value === '' || !self::isEncrypted($value)) {
            return $value;
        }

        if (!self::sodiumLoaded()) {
            return null;
        }

        $payload = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($payload === false || strlen($payload) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $nonce = substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = self::deriveKey();
        if ($key === null) {
            return null;
        }

        try {
            $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        } catch (\Throwable $e) {
            return null;
        } finally {
            sodium_memzero($key);
        }

        return $plaintext === false ? null : $plaintext;
    }

    /**
     * Generate and persist a fresh encryption key if none exists yet.
     * Called from the plugin activation hook. Returns true when a key is
     * available (existing or freshly generated), false otherwise.
     */
    public static function ensureKey(): bool
    {
        if (defined('JUSTBEE_POSTCASTER_ENCRYPTION_KEY') && is_string(JUSTBEE_POSTCASTER_ENCRYPTION_KEY) && JUSTBEE_POSTCASTER_ENCRYPTION_KEY !== '') {
            return true;
        }

        if (!self::sodiumLoaded()) {
            return false;
        }

        $existing = get_option(self::KEY_OPTION);
        if (is_string($existing) && $existing !== '') {
            return true;
        }

        try {
            $raw = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        } catch (\Throwable $e) {
            return false;
        }

        // Store autoload=no so the key is not loaded on every request.
        add_option(self::KEY_OPTION, base64_encode($raw), '', 'no');

        return true;
    }

    /**
     * True when the PHP sodium extension is loaded and exposes the symbols
     * we need. This says nothing about whether an encryption key is
     * available — use isAvailable() for the full readiness check.
     */
    public static function sodiumExtensionLoaded(): bool
    {
        return self::sodiumLoaded();
    }

    private static function sodiumLoaded(): bool
    {
        return extension_loaded('sodium')
            && function_exists('sodium_crypto_secretbox')
            && function_exists('sodium_crypto_secretbox_open')
            && function_exists('sodium_crypto_generichash')
            && function_exists('random_bytes');
    }

    private static function deriveKey(): ?string
    {
        if (defined('JUSTBEE_POSTCASTER_ENCRYPTION_KEY') && is_string(JUSTBEE_POSTCASTER_ENCRYPTION_KEY) && JUSTBEE_POSTCASTER_ENCRYPTION_KEY !== '') {
            $material = JUSTBEE_POSTCASTER_ENCRYPTION_KEY;
        } else {
            $stored = get_option(self::KEY_OPTION);
            if (!is_string($stored) || $stored === '') {
                return null;
            }
            $decoded = base64_decode($stored, true);
            if ($decoded === false || $decoded === '') {
                return null;
            }
            $material = $decoded;
        }

        if (!self::sodiumLoaded()) {
            return null;
        }

        try {
            return sodium_crypto_generichash(
                $material,
                self::CONTEXT,
                SODIUM_CRYPTO_SECRETBOX_KEYBYTES
            );
        } catch (\Throwable $e) {
            return null;
        }
    }
}
