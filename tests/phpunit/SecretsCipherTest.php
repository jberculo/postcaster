<?php

declare(strict_types=1);

use Justbee\PostCaster\Support\SecretsCipher;

final class SecretsCipherTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();

        if (!SecretsCipher::isAvailable()) {
            $this->markTestSkipped('libsodium not available in this PHP build.');
        }
    }

    public function test_round_trip_returns_original_plaintext(): void
    {
        $plaintext = 'super-secret-token-abc123!@#';

        $cipher = SecretsCipher::encrypt($plaintext);

        $this->assertNotSame($plaintext, $cipher, 'ciphertext must differ from plaintext');
        $this->assertTrue(SecretsCipher::isEncrypted($cipher), 'output must carry the ciphertext prefix');
        $this->assertSame($plaintext, SecretsCipher::decrypt($cipher));
    }

    public function test_encrypt_uses_fresh_nonce_for_each_call(): void
    {
        $plaintext = 'identical-input';

        $first = SecretsCipher::encrypt($plaintext);
        $second = SecretsCipher::encrypt($plaintext);

        $this->assertNotSame($first, $second, 'each encryption must produce a unique nonce');
        $this->assertSame($plaintext, SecretsCipher::decrypt($first));
        $this->assertSame($plaintext, SecretsCipher::decrypt($second));
    }

    public function test_encrypt_passes_through_already_encrypted_value(): void
    {
        $cipher = SecretsCipher::encrypt('value');

        $this->assertSame($cipher, SecretsCipher::encrypt($cipher), 'encrypting a ciphertext must be a no-op');
    }

    /**
     * @dataProvider passthroughProvider
     */
    public function test_no_op_passthroughs(string $method, string $input, $expected): void
    {
        $this->assertSame($expected, SecretsCipher::{$method}($input));
    }

    /** @return array<string, array{0:string,1:string,2:mixed}> */
    public function passthroughProvider(): array
    {
        return [
            'encrypt empty string returns empty string' => ['encrypt', '', ''],
            'decrypt empty string returns empty string' => ['decrypt', '', ''],
            'decrypt legacy plaintext returns input' => ['decrypt', 'plain-old-token', 'plain-old-token'],
            'isEncrypted detects pcsec1 prefix' => ['isEncrypted', 'pcsec1:whatever', true],
            'isEncrypted rejects plain text' => ['isEncrypted', 'plain', false],
            'isEncrypted rejects empty input' => ['isEncrypted', '', false],
        ];
    }

    public function test_decrypt_returns_input_on_tampered_ciphertext(): void
    {
        $cipher = SecretsCipher::encrypt('original');
        // Flip a byte inside the base64 payload.
        $tampered = substr($cipher, 0, -3) . 'AAA';

        $result = SecretsCipher::decrypt($tampered);

        $this->assertNotSame('original', $result, 'tampered ciphertext must not yield the original plaintext');
        $this->assertSame($tampered, $result, 'failed decryption falls back to the input value');
    }

}
