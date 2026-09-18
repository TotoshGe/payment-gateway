<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\PanelCredentialsEncryptor;
use PHPUnit\Framework\TestCase;

final class PanelCredentialsEncryptorTest extends TestCase
{
    private function makeEncryptor(): PanelCredentialsEncryptor
    {
        return new PanelCredentialsEncryptor(base64_encode(str_repeat('a', SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    }

    public function testEncryptThenDecryptRoundTrips(): void
    {
        $encryptor = $this->makeEncryptor();
        $credentials = ['apiKey' => 'abc123', 'apiSecret' => 'xyz789'];

        $encrypted = $encryptor->encrypt($credentials);

        self::assertStringNotContainsString('abc123', $encrypted, 'ciphertext must not contain the plaintext secret');
        self::assertSame($credentials, $encryptor->decrypt($encrypted));
    }

    public function testEncryptingTwiceProducesDifferentCiphertext(): void
    {
        $encryptor = $this->makeEncryptor();
        $credentials = ['apiKey' => 'abc123'];

        self::assertNotSame($encryptor->encrypt($credentials), $encryptor->encrypt($credentials), 'nonce must be randomized per call');
    }

    public function testWrongKeyFailsToDecrypt(): void
    {
        $encryptor = $this->makeEncryptor();
        $encrypted = $encryptor->encrypt(['apiKey' => 'abc123']);

        $otherEncryptor = new PanelCredentialsEncryptor(base64_encode(str_repeat('b', SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));

        $this->expectException(\RuntimeException::class);
        $otherEncryptor->decrypt($encrypted);
    }

    public function testMalformedKeyFailsLazilyNotInConstructor(): void
    {
        $encryptor = new PanelCredentialsEncryptor('not-valid-base64-key');

        $this->expectException(\InvalidArgumentException::class);
        $encryptor->encrypt(['apiKey' => 'x']);
    }
}
