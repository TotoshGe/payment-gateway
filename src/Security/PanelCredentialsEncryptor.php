<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Envelope-encrypts Panel API credentials (trading/withdrawal keys, not
 * read-only market data -- see ARCHITECTURE.md 6) with a master key that
 * only ever lives in env/secret-manager, never in the DB or in git. Plain
 * credentials only ever exist in memory, for the duration of building a
 * panel's HTTP client.
 */
final class PanelCredentialsEncryptor
{
    /**
     * Not validated/decoded in the constructor on purpose: this service is
     * eagerly instantiated at container compile time (it's a dependency of
     * an EasyAdmin CRUD controller), so a placeholder/unset
     * PANEL_CREDENTIALS_KEY in an environment that has no panels configured
     * yet must not prevent the app from booting at all -- only actually
     * encrypting/decrypting credentials should fail loudly.
     */
    public function __construct(private readonly string $panelCredentialsKey)
    {
    }

    /**
     * @param array<string, mixed> $credentials
     */
    public function encrypt(array $credentials): string
    {
        $key = $this->resolveKey();
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = json_encode($credentials, \JSON_THROW_ON_ERROR);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);

        return base64_encode($nonce.$ciphertext);
    }

    /**
     * @return array<string, mixed>
     */
    public function decrypt(string $encrypted): array
    {
        $key = $this->resolveKey();
        $raw = base64_decode($encrypted, true);
        if (false === $raw || \strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Malformed encrypted panel credentials.');
        }

        $nonce = substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        if (false === $plaintext) {
            throw new \RuntimeException('Failed to decrypt panel credentials (wrong key or corrupted data).');
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($plaintext, true, flags: \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function resolveKey(): string
    {
        $key = base64_decode($this->panelCredentialsKey, true);
        if (false === $key || \SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== \strlen($key)) {
            throw new \InvalidArgumentException(sprintf(
                'PANEL_CREDENTIALS_KEY must be a base64-encoded %d-byte key.',
                \SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            ));
        }

        return $key;
    }
}
