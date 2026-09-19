<?php

declare(strict_types=1);

namespace App\Tests\Unit\Panel\TestPanel;

use App\Panel\TestPanel\AddressCodec;
use App\Panel\TestPanel\TestAddressGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TestAddressGeneratorTest extends TestCase
{
    private TestAddressGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new TestAddressGenerator();
    }

    public function testCodecRecognisesRealValidAddresses(): void
    {
        // Guards the "generated addresses fail their checksum" claim: the
        // validators must accept genuine addresses, or that claim is vacuous.
        self::assertTrue(AddressCodec::isValidBase58Check('TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t'));
        self::assertTrue(AddressCodec::isValidBase58Check('1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa'));
        self::assertTrue(AddressCodec::isValidBech32('bc1qar0srrr7xfkvy5l643lydnw9re59gtzzwf5mdq'));
        self::assertTrue(AddressCodec::isValidBech32('BC1QW508D6QEJXTDG4Y5R3ZARVARY0C5XW7KV8F3T4'));
        self::assertFalse(AddressCodec::isValidBase58Check('TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6u'));
    }

    #[DataProvider('networks')]
    public function testIsDeterministic(string $currency, ?string $network): void
    {
        $first = $this->generator->generate($currency, $network, 0);
        $second = $this->generator->generate($currency, $network, 0);

        self::assertEquals($first, $second);
        self::assertNotEquals($first->address, $this->generator->generate($currency, $network, 1)->address);
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function networks(): iterable
    {
        yield 'trc20' => ['USDT', 'TRC20'];
        yield 'erc20' => ['USDT', 'ERC20'];
        yield 'bep20' => ['USDT', 'BEP20'];
        yield 'btc' => ['BTC', 'BTC'];
        yield 'ltc' => ['LTC', 'LTC'];
        yield 'doge' => ['DOGE', 'DOGE'];
        yield 'sol' => ['SOL', 'SOL'];
        yield 'fiat' => ['UAH', null];
        yield 'unknown' => ['XYZ', 'WEIRDNET'];
    }

    #[DataProvider('okeanNetworks')]
    public function testEveryOkeanNetworkGetsAnAddressAndDistinctSlotsDiffer(string $currency, string $network): void
    {
        $slot0 = $this->generator->generate($currency, $network, 0);
        $slot1 = $this->generator->generate($currency, $network, 1);

        self::assertNotSame('', $slot0->address);
        self::assertNotSame($slot0->address, $slot1->address);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function okeanNetworks(): iterable
    {
        foreach (['ARBITRUM', 'BSC', 'BTC', 'ETH', 'LTC', 'POLYGON', 'SOL', 'TON', 'TRX'] as $network) {
            yield $network => ['USDT', $network];
        }
    }

    public function testDifferentCurrencyOrNetworkGivesDifferentAddress(): void
    {
        self::assertNotSame(
            $this->generator->generate('USDT', 'ERC20', 0)->address,
            $this->generator->generate('ETH', 'ERC20', 0)->address,
        );
        self::assertNotSame(
            $this->generator->generate('USDT', 'ERC20', 0)->address,
            $this->generator->generate('USDT', 'BEP20', 0)->address,
        );
    }

    public function testEvmFormatAndMarker(): void
    {
        foreach (['ERC20', 'BEP20', 'ARBITRUM', 'POLYGON'] as $network) {
            $address = $this->generator->generate('USDT', $network, 0)->address;

            self::assertMatchesRegularExpression('/^0x[0-9a-f]{40}$/', $address);
            self::assertStringStartsWith('0x7e577e57', $address);
        }
    }

    public function testTronFormatAndInvalidChecksumForManySlots(): void
    {
        for ($slot = 0; $slot < 200; ++$slot) {
            $address = $this->generator->generate('USDT', 'TRC20', $slot)->address;

            self::assertMatchesRegularExpression('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $address);
            self::assertStringStartsWith('TTEST', $address);
            self::assertFalse(AddressCodec::isValidBase58Check($address), $address);
        }
    }

    public function testBitcoinAndLitecoinAreBech32ShapedButFailChecksum(): void
    {
        for ($slot = 0; $slot < 200; ++$slot) {
            $btc = $this->generator->generate('BTC', 'BTC', $slot)->address;
            $ltc = $this->generator->generate('LTC', 'LTC', $slot)->address;

            self::assertMatchesRegularExpression('/^bc1q[02-9ac-hj-np-z]{38}$/', $btc);
            self::assertMatchesRegularExpression('/^ltc1q[02-9ac-hj-np-z]{38}$/', $ltc);
            self::assertStringContainsString('testtest', $btc);
            self::assertFalse(AddressCodec::isValidBech32($btc), $btc);
            self::assertFalse(AddressCodec::isValidBech32($ltc), $ltc);
        }
    }

    public function testDogeFormatAndInvalidChecksum(): void
    {
        $address = $this->generator->generate('DOGE', 'DOGE', 0)->address;

        self::assertMatchesRegularExpression('/^D[1-9A-HJ-NP-Za-km-z]{33}$/', $address);
        self::assertFalse(AddressCodec::isValidBase58Check($address));
    }

    public function testSolanaFormat(): void
    {
        $address = $this->generator->generate('SOL', 'SOL', 0)->address;

        self::assertMatchesRegularExpression('/^TEST[1-9A-HJ-NP-Za-km-z]{40}$/', $address);
    }

    public function testNativeCoinWithoutNetworkUsesItsOwnChain(): void
    {
        self::assertStringStartsWith('bc1q', $this->generator->generate('BTC', null, 0)->address);
        self::assertStringStartsWith('0x7e577e57', $this->generator->generate('ETH', null, 0)->address);
        self::assertStringStartsWith('TTEST', $this->generator->generate('TRX', null, 0)->address);
    }

    public function testNetworkCodeIsCaseInsensitive(): void
    {
        self::assertEquals(
            $this->generator->generate('USDT', 'TRC20', 0),
            $this->generator->generate('usdt', 'trc20', 0),
        );
    }

    public function testFiatGetsClearlyMarkedTestCardDetails(): void
    {
        $result = $this->generator->generate('UAH', null, 0);

        self::assertStringStartsWith('TEST CARD 4242 4242 4242 4242', $result->address);
        self::assertSame(TestAddressGenerator::FIAT_TAG, $result->addressTag);
        self::assertStringContainsString('TEST', (string) $result->addressTag);
    }

    public function testUnknownNetworkFallsBackToObviouslyInvalidPlaceholder(): void
    {
        $result = $this->generator->generate('XYZ', 'WEIRDNET', 0);

        self::assertStringStartsWith('TEST-NOT-A-REAL-ADDRESS-XYZ-WEIRDNET-', $result->address);
        self::assertNull($result->addressTag);
    }

    public function testNeverProducesATag(): void
    {
        foreach (self::networks() as [$currency, $network]) {
            if ('UAH' === $currency) {
                continue;
            }
            self::assertNull($this->generator->generate($currency, $network, 0)->addressTag);
        }
    }
}
